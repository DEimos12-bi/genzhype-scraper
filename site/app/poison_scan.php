<?php
// GenZHype | READ-ONLY scanner for known content-bleed fingerprints in term pages.
// The source-fetcher once scraped the wrong meme's text (the amogus -> ma-got-pranked
// bug), leaving a handful of pages with an origin/history bled from a DIFFERENT topic.
// This finds any page still carrying such a fingerprint so it can be re-drafted with
// app/term_redraft.php. Add new fingerprints to $poison as new bleeds are discovered.
// Changes NOTHING. Usage: php app/poison_scan.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
$pdo = db();

$poison = ['ma got pranked','ybg.wallace','ybg wallace','tuff mask','wallace & gromit',
           'wallace and gromit','grok to remix','two-panel video','two panel video'];

$rows = $pdo->query("SELECT t.page_id,p.slug,p.status,t.lane,t.term,
   CONCAT_WS(' ',t.origin,t.meaning,t.why_trending,t.examples,t.usage_note,t.short_def) AS txt
   FROM terms t JOIN pages p ON p.id=t.page_id WHERE p.type='term'")->fetchAll();

echo "Scanning " . count($rows) . " term pages for known content-bleed fingerprints...\n\n";
$live = []; $hidden = [];
foreach ($rows as $r) {
    $b = mb_strtolower((string)$r['txt']);
    $found = [];
    foreach ($poison as $p) if (strpos($b, $p) !== false) $found[] = $p;
    if (!$found) continue;
    $rec = sprintf("  [%s/%s] %-24s /%s  <- %s", $r['status'], $r['lane'], $r['term'], $r['slug'], implode(',', array_unique($found)));
    if ($r['status'] === 'published') $live[] = $rec; else $hidden[] = $rec;
}
printf("LIVE (published) poisoned pages: %d  <-- these are the emergencies\n", count($live));
foreach ($live as $l) echo $l . "\n";
printf("\nHidden (draft/archived, not in Google): %d\n", count($hidden));
foreach ($hidden as $h) echo $h . "\n";
echo "\nFix a page with:  php app/term_redraft.php <slug>\n";
