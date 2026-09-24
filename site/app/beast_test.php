<?php
// GenZHype | IMAGE BEAST test gate (Phase 3). Runs the full chain on the 3
// known-hard cases ONLY, writes {slug}-beast-test.webp previews, touches NO
// live file and NO database row. Usage: php app/beast_test.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }

$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/image_beast.php';

$pdo = db();
$cases = ['crash-out', 'gigachad', 'delulu'];

foreach ($cases as $slug) {
    $st = $pdo->prepare("SELECT t.term, t.short_def FROM terms t JOIN pages p ON p.id=t.page_id WHERE p.slug=?");
    $st->execute([$slug]);
    $row = $st->fetch();
    if (!$row) { echo "== {$slug}: not found\n"; continue; }
    echo "==================== {$slug} ====================\n";
    $r = fetch_featured_image([
        'page_type' => 'term', 'subject' => $row['term'],
        'meaning' => $row['short_def'], 'slug' => $slug, 'people' => [],
    ], ['suffix' => '-beast-test']);
    if (!$r) { echo "RESULT: NULL (all sources down)\n"; continue; }
    foreach ($r['trace'] as $t) echo "  | {$t}\n";
    echo "RESULT: bucket={$r['bucket']} source={$r['source']} fit=" . var_export($r['fit_score'], true)
       . " refines={$r['refines']} secs={$r['secs']}\n";
    echo "  pick reason: {$r['reason']}\n";
    echo "  credit: {$r['credit']}\n";
    echo "  preview: https://genzhype.com{$r['img']}\n\n";
}
