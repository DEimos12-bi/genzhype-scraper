<?php
declare(strict_types=1);
/* GenZHype | reach_ig.php — Instagram usage evidence via the owner's own session.
 *
 * OWNER DECISION 2026-08-29: "i have accounts for all of those... i alr gave
 * the access with the cookies... work with them that alr in our systems."
 * The owner exported an Instagram session on 2026-08-11 for the clip
 * downloader (.secrets/ig_cookies.txt). Verified alive from THIS server on
 * 2026-08-29: the logged-in web API answered 200 with profile data, and the
 * hashtag endpoint returned 24 real dated posts for #delulu. That is exactly
 * the evidence class gate_term.php already accepts as `social_post`
 * (platform Instagram is in gate_term_platform_hosts) but was never fed,
 * because "casual usage lives on TikTok/Reddit/X, none of which we can
 * retrieve" (owner-decision comment of 2026-08-22). Now we can retrieve one.
 *
 * Fabrication is impossible by construction: every row here was READ from the
 * live API seconds or hours ago — the URL, date, handle and caption are
 * Instagram's own, never a model's. Rows still go through the unchanged
 * gate_term_valid_citations() at the call sites.
 *
 * Politeness (this is the owner's real session — burn it and we lose the
 * clip downloader too): ONE live API call per term, answers cached 6 hours,
 * minimum 5 seconds between live calls, and every failure returns [] so the
 * pipeline never blocks on Instagram.
 */

const IG_COOKIE_FILE = '/home/u219414635/.secrets/ig_cookies.txt';
const IG_CACHE_TTL   = 21600;   // 6h — a hashtag page does not change faster

function ig_available(): bool {
    return is_readable(IG_COOKIE_FILE);
}

/** Netscape cookies.txt -> "k=v; k=v" header, or '' when absent. */
function ig_cookie_header(): string {
    static $hdr = null;
    if ($hdr !== null) return $hdr;
    $hdr = '';
    if (!ig_available()) return $hdr;
    $pairs = [];
    foreach ((array)file(IG_COOKIE_FILE) as $l) {
        $l = trim((string)$l);
        if ($l === '' || $l[0] === '#') continue;
        $p = explode("\t", $l);
        if (count($p) >= 7) $pairs[] = $p[5] . '=' . $p[6];
    }
    $hdr = implode('; ', $pairs);
    return $hdr;
}

/** One throttled, cached GET against the logged-in web API. null on any failure. */
function ig_api_get(string $url): ?array {
    $cookie = ig_cookie_header();
    if ($cookie === '') return null;

    @mkdir(__DIR__ . '/cache', 0755);
    $cacheFile = __DIR__ . '/cache/ig_' . md5($url) . '.json';
    if (is_file($cacheFile) && time() - (int)filemtime($cacheFile) < IG_CACHE_TTL) {
        $j = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($j)) return $j;
    }

    // never hit Instagram faster than every 5s, whatever the caller does
    static $last = 0.0;
    $wait = 5.0 - (microtime(true) - $last);
    if ($wait > 0) usleep((int)($wait * 1e6));
    $last = microtime(true);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_COOKIE         => $cookie,
        CURLOPT_HTTPHEADER     => ['x-ig-app-id: 936619743392459', 'accept: */*'],
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        echo "  IG: HTTP $code — session challenged or expired; skipping (re-export cookies to fix)\n";
        return null;
    }
    $j = json_decode((string)$body, true);
    if (!is_array($j)) return null;
    @file_put_contents($cacheFile, json_encode($j, JSON_UNESCAPED_UNICODE));
    return $j;
}

/**
 * Real dated Instagram posts USING $term, shaped exactly like the drafter's
 * $citeStore rows so gate_term_valid_citations() can judge each one unchanged.
 * Ranking: captions that use the word in a sentence beat hashtag walls; one
 * citation per account so three rows mean three different people.
 */
function ig_usage_citations(string $term, int $max = 3): array {
    $tag = strtolower(preg_replace('/[^a-z0-9]/i', '', $term));
    if ($tag === '' || mb_strlen($tag) < 3) return [];

    $j = ig_api_get('https://www.instagram.com/api/v1/tags/web_info/?tag_name=' . rawurlencode($tag));
    if ($j === null) return [];

    $cands = [];
    foreach (['top', 'recent'] as $bucket) {
        foreach ((array)($j['data'][$bucket]['sections'] ?? []) as $s) {
            foreach ((array)($s['layout_content']['medias'] ?? []) as $m) {
                $md   = $m['media'] ?? [];
                $cap  = trim((string)($md['caption']['text'] ?? ''));
                $ts   = (int)($md['taken_at'] ?? 0);
                $code = (string)($md['code'] ?? '');
                $user = (string)($md['user']['username'] ?? '');
                if ($cap === '' || $ts <= 0 || $code === '' || $user === '') continue;
                if (mb_stripos($cap, $term) === false && mb_stripos($cap, $tag) === false) continue;

                $flat = preg_replace('/\s+/u', ' ', $cap);
                // usage in prose (not just "#term") ranks first — a hashtag
                // wall clears the gate but proves less to a reader
                $prose  = preg_replace('/[#@][\w.]+/u', '', $flat);
                $inProse = mb_stripos($prose, $term) !== false || mb_stripos($prose, $tag) !== false;

                // quote = the stretch of caption around the term, capped at 300
                $at = mb_stripos($flat, $term);
                if ($at === false) $at = (int)mb_stripos($flat, $tag);
                $quote = trim(mb_substr($flat, max(0, $at - 80), 300));

                $cands[] = [
                    'row' => [
                        'platform'    => 'Instagram',
                        'handle'      => '@' . $user,
                        'publication' => '',
                        'title'       => '',
                        'date'        => date('Y-m-d', $ts),
                        'url'         => 'https://www.instagram.com/p/' . $code . '/',
                        'quote'       => $quote,
                    ],
                    'user'  => strtolower($user),
                    'prose' => $inProse,
                    'ts'    => $ts,
                ];
            }
        }
    }

    usort($cands, fn($a, $b) => [$b['prose'], $b['ts']] <=> [$a['prose'], $a['ts']]);
    $out = $seen = [];
    foreach ($cands as $c) {
        if (isset($seen[$c['user']])) continue;
        $seen[$c['user']] = true;
        $out[] = $c['row'];
        if (count($out) >= $max) break;
    }
    if ($out) echo '  IG usage: ' . count($out) . " real posts for \"$term\" (of " . count($cands) . " candidates)\n";
    return $out;
}
