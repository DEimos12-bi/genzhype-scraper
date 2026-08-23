<?php
// GenZHype | SERP-EVIDENCE OWNERSHIP SCREEN (owner decision 2026-08-05).
// Replaces the AI ownership judgement, which answered "31 owned" on one run and
// "24 owned" on the next over identical input. Ownership is not an opinion, it
// is a measurable fact: query the bare term the way a user would type it, look
// at page 1, and count how many results concern the slang/culture sense.
//   OWNED     - no page-1 result concerns the slang/culture sense
//   CONTESTED - the sense appears, but in under half of page 1
//   CLEAR     - the sense holds at least half of page 1
//   NO-DATA   - the engine returned nothing; never guessed, never counted
// The evidence (every ranking result, and which ones counted) is stored WITH
// the verdict so any single call can be audited later in seconds without
// re-running anything. Endpoints: the same DuckDuckGo HTML + Bing endpoints
// fetch_sources.php already uses from this host - no new dependency, no key.
if (!defined('GZH_APP')) define('GZH_APP', 1);
require_once __DIR__ . '/fetch_sources.php';

// Hosts whose page-1 presence for a bare term IS the informal sense by
// construction: these sites only rank for a word when the word is slang.
function so_sense_hosts(): array {
    return ['urbandictionary.com', 'knowyourmeme.com', 'slang.net', 'dictionary.com',
            'merriam-webster.com', 'cambridge.org', 'wiktionary.org', '7esl.com',
            'slangit.com', 'genzhype.com'];
}

// Textual markers of the slang/meme/gaming sense in a result title+snippet.
// Deterministic: a fixed regex, not a judgement. A result about H&M, the
// University of North Carolina or Meta Platforms carries none of these.
function so_sense_markers(): string {
    return '/\b(slang|meme|memes|emote|gen\s?z|internet culture|tiktok|twitch|streamer|'
         . 'urban dictionary|texting|acronym|abbreviation|gamer|gaming term|in gaming|'
         . 'video game slang|multiplayer|what does .{1,40} mean|meaning of|internet slang)\b/i';
}

function so_result_is_sense(array $r): bool {
    $host = strtolower((string)($r['host'] ?? ''));
    foreach (so_sense_hosts() as $h) {
        if ($host === $h || str_ends_with($host, '.' . $h)) return true;
    }
    $text = (string)($r['title'] ?? '') . ' ' . (string)($r['snippet'] ?? '');
    return (bool)preg_match(so_sense_markers(), $text);
}

// Page-1 results WITH titles and snippets. PRIMARY: Bing web-search RSS with
// mkt=en-US forced — measured from this host: 10 clean items per query, and
// without the mkt override Bing localises to the datacenter's region (query
// "sus" returned Marcello Mastroianni in Italian, which would have poisoned
// every verdict; the audience is US Gen Z, so the verdict must read the US
// SERP). FALLBACK: DuckDuckGo HTML — measured bot-walled right now (HTTP 202
// duck-CAPTCHA after one query), kept only as the second try.
function so_serp(string $query, int $max = 10): array {
    $xml = fs_http_get('https://www.bing.com/search?q=' . urlencode($query)
        . '&format=rss&mkt=en-US&cc=US&setlang=en', 20);
    $out = [];
    if ($xml && preg_match_all('#<item>(.*?)</item>#is', $xml, $items)) {
        foreach ($items[1] as $it) {
            if (!preg_match('#<link>([^<]+)</link>#i', $it, $l)) continue;
            $href = html_entity_decode(trim($l[1]), ENT_QUOTES, 'UTF-8');
            $host = strtolower(parse_url($href, PHP_URL_HOST) ?: '');
            if (!$host || preg_match('#bing\.com|microsoft\.com#i', $host)) continue;
            preg_match('#<title>(.*?)</title>#is', $it, $t);
            preg_match('#<description>(.*?)</description>#is', $it, $d);
            $out[] = [
                'url'     => $href,
                'host'    => $host,
                'title'   => trim(html_entity_decode(strip_tags($t[1] ?? ''), ENT_QUOTES, 'UTF-8')),
                'snippet' => mb_substr(trim(html_entity_decode(strip_tags($d[1] ?? ''), ENT_QUOTES, 'UTF-8')), 0, 300),
            ];
            if (count($out) >= $max) break;
        }
    }
    if ($out) return $out;
    // fallback: DuckDuckGo HTML (works when not bot-walled)
    $html = fs_http_get('https://html.duckduckgo.com/html/?q=' . urlencode($query), 20);
    if (!$html) return [];
    if (preg_match_all('#<a[^>]+class="result__a"[^>]+href="([^"]+)"[^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $a) {
            $href = html_entity_decode($a[1], ENT_QUOTES, 'UTF-8');
            if (preg_match('#[?&]uddg=([^&]+)#', $href, $u)) $href = urldecode($u[1]);
            $host = strtolower(parse_url($href, PHP_URL_HOST) ?: '');
            if (!$host || preg_match('#duckduckgo|bing\.com#i', $host)) continue;
            $out[] = ['url' => $href, 'host' => $host,
                      'title' => trim(html_entity_decode(strip_tags($a[2]), ENT_QUOTES, 'UTF-8')), 'snippet' => ''];
            if (count($out) >= $max) break;
        }
    }
    return $out;
}

// JUNK DETECTOR. Measured from this host: for some queries Bing serves a decoy
// SERP that has NOTHING to do with the query (query "mewing" answered Thomas
// Cook holidays, then a German bank, then Dutch recipes — three different decoys
// on three retries; "gacha" the same). A decoy scored normally would produce a
// FALSE "OWNED" verdict, exactly the class of wrong answer this rebuild exists
// to kill. A real page-1 for a bare term virtually always repeats the term in
// its results' url/title/snippet — so a SERP where fewer than 2 of the results
// mention the term is evidence of nothing and must never be scored.
function so_serp_is_junk(array $results, string $term): bool {
    $words = preg_split('/\s+/', trim(mb_strtolower($term))) ?: [];
    usort($words, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $needle = $words[0] ?? '';
    if ($needle === '') return true;
    $n = 0;
    foreach ($results as $r) {
        $hay = mb_strtolower(($r['url'] ?? '') . ' ' . ($r['title'] ?? '') . ' ' . ($r['snippet'] ?? ''));
        // short terms ("hm", "sus") need word boundaries: "ohmydish" contains "hm"
        $hit = mb_strlen($needle) <= 3
            ? (bool)preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $hay)
            : (mb_strpos($hay, $needle) !== false);
        if ($hit) $n++;
    }
    return $n < 2;
}

function so_verdict(string $term): array {
    $results = so_serp($term, 10);
    if (!$results || so_serp_is_junk($results, $term)) { sleep(4); $results = so_serp($term, 10); }
    if ($results && so_serp_is_junk($results, $term)) {
        // store the decoy as evidence, but never score it
        return ['verdict' => 'NO-DATA', 'sense_hits' => 0, 'total' => count($results),
                'results' => array_map(fn($r) => $r + ['junk' => true], $results), 'owner_hint' => ''];
    }
    $total = count($results);
    if ($total === 0) return ['verdict' => 'NO-DATA', 'sense_hits' => 0, 'total' => 0, 'results' => [], 'owner_hint' => ''];
    $hits = 0;
    foreach ($results as $i => $r) {
        $is = so_result_is_sense($r);
        $results[$i]['sense'] = $is;
        if ($is) $hits++;
    }
    if ($hits === 0)                 $v = 'OWNED';
    elseif ($hits * 2 < $total)      $v = 'CONTESTED';
    else                             $v = 'CLEAR';
    $ownerHint = '';
    if ($v === 'OWNED') {
        // evidence of WHO owns it: the top-ranked host
        $ownerHint = $results[0]['host'] . ' — ' . mb_substr($results[0]['title'], 0, 80);
    }
    return ['verdict' => $v, 'sense_hits' => $hits, 'total' => $total, 'results' => $results, 'owner_hint' => $ownerHint];
}

function so_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS serp_ownership (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_n TINYINT UNSIGNED NOT NULL,
        term VARCHAR(190) NOT NULL,
        slug VARCHAR(190) NOT NULL,
        lane VARCHAR(20) NOT NULL DEFAULT '',
        verdict VARCHAR(12) NOT NULL,
        sense_hits TINYINT UNSIGNED NOT NULL DEFAULT 0,
        total TINYINT UNSIGNED NOT NULL DEFAULT 0,
        owner_hint VARCHAR(300) NOT NULL DEFAULT '',
        results LONGTEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY run_slug (run_n, slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// CLI: php ownership_serp.php <run_n> [offset] [limit]
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
    require __DIR__ . '/helpers.php';
    require __DIR__ . '/db.php';
    $run    = max(1, (int)($argv[1] ?? 1));
    $offset = max(0, (int)($argv[2] ?? 0));
    $limit  = max(1, (int)($argv[3] ?? 30));
    $pdo = db();
    so_install($pdo);
    $rows = $pdo->query("SELECT t.term, t.lane, p.slug FROM terms t JOIN pages p ON p.id=t.page_id
                         WHERE p.status='published' ORDER BY p.slug LIMIT $limit OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
    $ins = $pdo->prepare("INSERT INTO serp_ownership (run_n,term,slug,lane,verdict,sense_hits,total,owner_hint,results)
                          VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($rows as $r) {
        // idempotent per run: skip slugs this run already measured
        $chk = $pdo->prepare("SELECT 1 FROM serp_ownership WHERE run_n=? AND slug=?");
        $chk->execute([$run, $r['slug']]);
        if ($chk->fetch()) { echo "skip {$r['slug']} (already in run $run)\n"; continue; }
        $v = so_verdict($r['term']);
        $ins->execute([$run, $r['term'], $r['slug'], $r['lane'], $v['verdict'], $v['sense_hits'],
                       $v['total'], $v['owner_hint'], json_encode($v['results'], JSON_UNESCAPED_UNICODE)]);
        printf("%-24s %-10s %d/%d  %s\n", $r['slug'], $v['verdict'], $v['sense_hits'], $v['total'], $v['owner_hint']);
        sleep(2);   // stay polite with the endpoint; determinism, not speed
    }
    echo "batch done (run $run, offset $offset, limit $limit)\n";
}
