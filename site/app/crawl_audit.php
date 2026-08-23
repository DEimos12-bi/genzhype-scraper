<?php
/**
 * Crawl/index self-audit (2026-06-17). Checks every indexable URL against the five
 * causes of "Discovered - currently not indexed": (3) blocked by robots/noindex,
 * (4) thin content, (5) crawl issues (status, canonical, redirects), plus consistency
 * of the sitemap vs the DB and crawl-budget waste. Read-only. Run:
 *   timeout 200 php app/crawl_audit.php
 */
$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
$pdo = db();
$base = rtrim($GLOBALS['CONFIG']['base_url'], '/');
$THIN = 450;   // article words below this = thin-content watch

function fetchInfo(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Googlebot/2.1)']);
    $html = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $redir = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    $robots = preg_match('#<meta\s+name=["\']robots["\']\s+content=["\']([^"\']+)#i', $html, $m) ? strtolower($m[1]) : '(none)';
    $canon  = preg_match('#<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)#i', $html, $m) ? $m[1] : '(none)';
    $body = preg_match('#<article[^>]*>(.*?)</article>#is', $html, $m) ? $m[1] : $html;
    $words = str_word_count(trim(preg_replace('/\s+/', ' ', strip_tags($body))));
    return ['code' => $code, 'redir' => $redir, 'robots' => $robots, 'canon' => $canon, 'words' => $words];
}

// ---- gather indexable URLs from the sitemap (what we ask Google to index) ----
$smc = curl_init($base . '/sitemap.xml');
curl_setopt_array($smc, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$sm = curl_exec($smc) ?: ''; curl_close($smc);
preg_match_all('#<loc>([^<]+)</loc>#', $sm, $mm);
$urls = $mm[1] ?? [];
echo "Auditing " . count($urls) . " sitemap URLs against the 5 'Discovered not indexed' causes\n\n";

$noindex = []; $bad = []; $thin = []; $canonMiss = []; $ok = 0;
foreach ($urls as $u) {
    $i = fetchInfo($u);
    if ($i['code'] !== 200)                    $bad[] = "$u -> HTTP {$i['code']}" . ($i['redir'] ? " ->{$i['redir']}" : '');
    if (strpos($i['robots'], 'noindex') !== false) $noindex[] = "$u  (robots: {$i['robots']})";
    $isHub = preg_match('#/(slang|meme|gaming|drama)/$#', $u) || $u === $base . '/';
    if (!$isHub && $i['words'] < $THIN)        $thin[] = "$u  ({$i['words']} words)";
    $cu = rtrim($i['canon'], '/'); $uu = rtrim($u, '/');
    if ($i['canon'] !== '(none)' && $cu !== $uu) $canonMiss[] = "$u  -> canonical: {$i['canon']}";
    if ($i['code'] === 200 && strpos($i['robots'], 'noindex') === false) $ok++;
}

// ---- consistency: sitemap should = published+index; nothing archived/noindex in it ----
$dbIndex = [];
foreach ($pdo->query("SELECT path FROM pages WHERE status='published' AND robots='index'")->fetchAll(PDO::FETCH_COLUMN) as $p) $dbIndex[rtrim($base . $p, '/')] = 1;
$smSet = array_flip(array_map(fn($u) => rtrim($u, '/'), $urls));
$inSmNotDb = array_values(array_filter(array_keys($smSet), fn($u) => !isset($dbIndex[$u]) && !preg_match('#/(slang|meme|gaming|drama)$#', $u) && $u !== $base));
$inDbNotSm = array_values(array_filter(array_keys($dbIndex), fn($u) => !isset($smSet[$u])));

echo "== (3) BLOCKED BY NOINDEX ==\n"; echo $noindex ? implode("\n", $noindex) . "\n" : "  none — no indexable URL emits noindex\n";
echo "\n== (5) CRAWL ISSUES (non-200 / redirects) ==\n"; echo $bad ? implode("\n", $bad) . "\n" : "  none — every sitemap URL is a clean 200\n";
echo "\n== (5) CANONICAL MISMATCH ==\n"; echo $canonMiss ? implode("\n", $canonMiss) . "\n" : "  none — all self-canonical\n";
echo "\n== (4) THIN CONTENT (<{$THIN} words in <article>) ==\n"; echo $thin ? implode("\n", $thin) . "\n" : "  none under {$THIN} words\n";
echo "\n== (2) CRAWL-BUDGET WASTE (sitemap/DB mismatch) ==\n";
echo "  URLs in sitemap but NOT published+index (stale/waste): " . (count($inSmNotDb) ? implode(', ', $inSmNotDb) : 'none') . "\n";
echo "  Pages published+index but MISSING from sitemap: " . (count($inDbNotSm) ? implode(', ', $inDbNotSm) : 'none') . "\n";
echo "\nSUMMARY: " . count($urls) . " sitemap URLs · $ok clean+indexable · " . count($bad) . " bad-status · " . count($noindex) . " noindex · " . count($thin) . " thin · " . count($canonMiss) . " canonical-mismatch\n";
