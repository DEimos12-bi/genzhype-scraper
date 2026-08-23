<?php
// GenZHype | image-beast backfill: redo featured images for the given slugs.
// PRODUCTION paths ({slug}-featured.webp + DB cover/featured_img/credits) and
// the real-meme gallery for specific_meme pages. Good pages are NOT passed in.
// Usage: php app/beast_backfill.php slug1 slug2 ...
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }

$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/image_beast.php';

$pdo = db();
$slugs = array_slice($argv, 1);
if (!$slugs) exit("usage: php app/beast_backfill.php <slug> [slug...]\n");

foreach ($slugs as $slug) {
    $st = $pdo->prepare("SELECT t.page_id, t.term, t.short_def, t.first_seen, t.origin, t.examples FROM terms t JOIN pages p ON p.id=t.page_id WHERE p.slug=?");
    $st->execute([$slug]);
    $row = $st->fetch();
    if (!$row) { echo "== {$slug}: not found\n"; continue; }
    echo "== {$slug}\n";
    try {
        $or = json_decode($row['origin'] ?? '[]', true) ?: [];
        $ex = json_decode($row['examples'] ?? '[]', true) ?: [];
        $ctx = trim(implode(' | ', array_filter([$row['first_seen'] ?? '', $or[0] ?? '', $ex[0]['text'] ?? ''])));
        $r = fetch_featured_image([
            'page_type' => 'term', 'subject' => $row['term'],
            'meaning' => $row['short_def'], 'slug' => $slug, 'people' => [],
            'context' => $ctx,
        ]);
        if (!$r) { echo "   NULL (all sources down) - left untouched\n"; continue; }
        $pdo->prepare("UPDATE pages SET cover=?, featured_img=?, cover_credit=?, cover_credit_url=? WHERE id=?")
            ->execute([$r['img'], $r['img'], $r['credit'], $r['credit_url'], (int)$row['page_id']]);
        echo "   {$r['bucket']} via {$r['source']} fit=" . var_export($r['fit_score'], true) . " ({$r['secs']}s) | {$r['credit']}\n";
        if (($r['bucket'] ?? '') === 'specific_meme') {
            $gal = beast_meme_gallery($row['term'], $slug, 3, [$r['credit_url'] ?? '']);
            echo "   gallery: " . count($gal) . " stills\n";
        }
    } catch (Throwable $e) {
        echo "   ERROR: " . $e->getMessage() . " - left untouched\n";
    }
}
echo "done\n";
