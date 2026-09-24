<?php
declare(strict_types=1);
/* GenZHype | reach_usage.php — X + Reddit usage citations from the runner cache.
 *
 * OWNER 2026-08-29: the burner channels have been live on the reach-runner
 * since Aug 5 but only fed discovery. This turns their per-term searches
 * (reach_cache.json['term_usage'], written by reach_ingest.php from the
 * runner's term-usage.json) into citation candidates shaped exactly like the
 * drafter's $citeStore rows. NOTHING here bypasses the gate:
 *   - X rows: the gate's own link_tweet_alive() re-verifies every id against
 *     the syndication CDN (fail closed), and the DATE is derived from the
 *     snowflake id — X's own clock, not a model's guess.
 *   - Reddit rows: date from created_utc, URL from the permalink.
 *   - Both: gate_term_valid_citations() still requires the term IN the quote.
 */

/** Tweet ids are snowflakes: ms timestamp = (id >> 22) + epoch 2010-11-04. */
function reach_tweet_date(string $id): string {
    $id = preg_replace('/\D+/', '', $id);
    if ($id === '' || strlen($id) < 10) return '';
    $ms = ((int)$id >> 22) + 1288834974657;
    if ($ms < 1288834974657 || $ms > (time() + 86400) * 1000) return '';
    return gmdate('Y-m-d', intdiv($ms, 1000));
}

/**
 * Citation candidates for $term from the cached runner harvest, best first
 * (X posts carry likes; Reddit posts carry ups). [] when the cache has
 * nothing — every caller must survive that.
 */
function reach_usage_citations(string $term, int $max = 4): array {
    $cache = json_decode((string)@file_get_contents(__DIR__ . '/reach_cache.json'), true);
    $sets  = (array)($cache['term_usage'] ?? []);
    // THE SCOUT (2026-08-30): a scout-born term carries its own birth-evidence
    // posts in scout_cache.json — same shape, merged in so the page that the
    // Scout caused can cite the very posts that revealed the term.
    foreach ((array)json_decode((string)@file_get_contents(__DIR__ . '/scout_cache.json'), true) as $t => $e) {
        if (!isset($sets[$t])) { $sets[$t] = $e; continue; }
        foreach (['x', 'reddit', 'youtube', 'steam'] as $k) {
            $sets[$t][$k] = array_merge((array)($sets[$t][$k] ?? []), (array)($e[$k] ?? []));
        }
    }
    // the feed lowercases terms; match case-insensitively
    $entry = null;
    foreach ($sets as $t => $e) {
        if (mb_strtolower((string)$t) === mb_strtolower($term)) { $entry = (array)$e; break; }
    }
    if ($entry === null) return [];

    $cands = [];
    foreach ((array)($entry['x'] ?? []) as $p) {
        $id   = preg_replace('/\D+/', '', (string)($p['id'] ?? ''));
        $user = trim((string)($p['author'] ?? ''), "@ \t");
        $text = preg_replace('/\s+/u', ' ', trim((string)($p['text'] ?? '')));
        $date = reach_tweet_date($id);
        if ($id === '' || $user === '' || $text === '' || $date === '') continue;
        if (mb_stripos($text, $term) === false) continue;
        $cands[] = ['w' => (int)($p['likes'] ?? 0), 'row' => [
            'platform'    => 'X',
            'handle'      => '@' . $user,
            'publication' => '',
            'title'       => '',
            'date'        => $date,
            'url'         => 'https://x.com/' . $user . '/status/' . $id,
            'quote'       => mb_substr($text, 0, 300),
        ]];
    }
    foreach ((array)($entry['reddit'] ?? []) as $p) {
        $perma = (string)($p['permalink'] ?? '');
        $user  = trim((string)($p['author'] ?? ''));
        $ts    = (int)($p['created_utc'] ?? 0);
        $text  = preg_replace('/\s+/u', ' ', trim(((string)($p['title'] ?? '')) . ' ' . ((string)($p['text'] ?? ''))));
        if ($perma === '' || $user === '' || $user === '[deleted]' || $ts <= 0) continue;
        if (mb_stripos($text, $term) === false) continue;
        $at    = (int)mb_stripos($text, $term);
        $cands[] = ['w' => (int)($p['ups'] ?? 0), 'row' => [
            'platform'    => 'Reddit',
            'handle'      => 'u/' . $user,
            'publication' => '',
            'title'       => '',
            'date'        => gmdate('Y-m-d', $ts),
            'url'         => 'https://www.reddit.com' . $perma,
            'quote'       => mb_substr(trim(mb_substr($text, max(0, $at - 80), 300)), 0, 300),
        ]];
    }

    // YouTube comments (Scout's gaming ear, 2026-08-30): date from YouTube's
    // own publishedAt; the &lc= URL opens the video with that comment pinned.
    foreach ((array)($entry['youtube'] ?? []) as $p) {
        $vid  = (string)($p['video'] ?? '');
        $user = trim((string)($p['author'] ?? ''), "@ \t");
        $text = preg_replace('/\s+/u', ' ', trim((string)($p['text'] ?? '')));
        $date = substr((string)($p['published'] ?? ''), 0, 10);
        if ($vid === '' || $user === '' || $text === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        if (mb_stripos($text, $term) === false) continue;
        $at = (int)mb_stripos($text, $term);
        $cands[] = ['w' => (int)($p['likes'] ?? 0), 'row' => [
            'platform'    => 'YouTube',
            'handle'      => '@' . $user,
            'publication' => '',
            'title'       => '',
            'date'        => $date,
            'url'         => 'https://www.youtube.com/watch?v=' . $vid
                           . (($p['comment_id'] ?? '') !== '' ? '&lc=' . $p['comment_id'] : ''),
            'quote'       => mb_substr(trim(mb_substr($text, max(0, $at - 80), 300)), 0, 300),
        ]];
    }

    // Steam reviews (the Scout's gaming ear, 2026-08-30): a review carries the
    // author's steamid + the app it is on, which resolves to a public permalink,
    // and Steam's own timestamp_created gives the date. Same bar as every other
    // tier — the term must appear in the review text.
    foreach ((array)($entry['steam'] ?? []) as $p) {
        $sid  = (string)($p['steamid'] ?? '');
        $app  = (int)($p['appid'] ?? 0);
        $user = trim((string)($p['author'] ?? ''));
        $text = preg_replace('/\s+/u', ' ', trim((string)($p['text'] ?? '')));
        $date = substr((string)($p['published'] ?? ''), 0, 10);
        if ($sid === '' || $app <= 0 || $user === '' || $text === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        if (mb_stripos($text, $term) === false) continue;
        $at = (int)mb_stripos($text, $term);
        $cands[] = ['w' => (int)($p['likes'] ?? 0), 'row' => [
            'platform'    => 'Steam',
            'handle'      => $user,
            'publication' => '',
            'title'       => '',
            'date'        => $date,
            'url'         => 'https://steamcommunity.com/profiles/' . $sid . '/recommended/' . $app . '/',
            'quote'       => mb_substr(trim(mb_substr($text, max(0, $at - 80), 300)), 0, 300),
        ]];
    }

    usort($cands, fn($a, $b) => $b['w'] <=> $a['w']);
    $out = $seen = [];
    foreach ($cands as $c) {
        $k = mb_strtolower($c['row']['handle']);
        if (isset($seen[$k])) continue;     // three rows = three different people
        $seen[$k] = true;
        $out[] = $c['row'];
        if (count($out) >= $max) break;
    }
    if ($out) echo '  reach usage: ' . count($out) . " cached X/Reddit posts for \"$term\"\n";
    return $out;
}
