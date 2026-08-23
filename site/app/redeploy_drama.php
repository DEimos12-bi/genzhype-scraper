<?php
/**
 * Drama image redeploy tool (2026-06-17). Re-runs the context-aware smart picker
 * on existing dramas, writes the hero to its real asset path + a /tmp preview, and
 * records the result to /tmp/deploy_results.json. Does NOT touch the DB — commit is
 * a separate, eye-checked step (redeploy_commit.php). Run in SMALL batches:
 *   timeout 280 php app/redeploy_drama.php slug-a slug-b slug-c
 */
$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/drama_image.php';
require_once __DIR__ . '/draft.php';

$slugs = array_slice($argv, 1);
if (!$slugs) { fwrite(STDERR, "usage: redeploy_drama.php slug...\n"); exit(1); }
$pdo = db();

$resFile = '/tmp/deploy_results.json';
$results = is_file($resFile) ? (json_decode(file_get_contents($resFile), true) ?: []) : [];

foreach ($slugs as $slug) {
    // "brand:<slug>" forces a branded card (used when I reject a picked image on eye-check).
    $forceBrand = false;
    if (str_starts_with($slug, 'brand:')) { $forceBrand = true; $slug = substr($slug, 6); }
    $r = $pdo->query("SELECT p.id,p.slug,p.h1,p.summary,d.mood,p.status FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.slug=" . $pdo->quote($slug))->fetch();
    if (!$r) { echo "  $slug -> NOT FOUND\n"; continue; }
    $mood = $r['mood'] ?: 'neutral';
    $meta = null;
    if (!$forceBrand) try {
        // fallbackPick=true: this is the eye-checked manual path, so a rate-limited
        // vision call still keeps a real on-topic thumb (I verify it on the sheet).
        $meta = drama_image_smart($r['slug'], $r['h1'], $r['summary'] ?? '', $mood, true);
    } catch (Throwable $e) { echo "  $slug -> ERR " . $e->getMessage() . "\n"; }

    if ($meta) {
        $kind = $meta['kind'];
        $img  = $meta['img'];
        $credit = $meta['credit']; $curl = $meta['credit_url'];
        echo "  $slug -> " . strtoupper($kind) . " via " . ($meta['via'] ?? '?') . " (pool {$meta['pool']}) | $credit\n";
    } else {
        // nothing safely fit -> branded card REPLACES whatever wrong image was there
        $big = mb_substr($r['h1'], 0, 22);
        $img = draft_make_cover($r['slug'], $big, 'the timeline, explained', $mood,
            $r['status'] === 'resolved' ? 'Resolved' : 'Ongoing');
        $kind = 'branded'; $credit = null; $curl = null;
        echo "  $slug -> BRANDED (no safe candidate)\n";
    }

    // preview jpg for eye-check
    $abs = dirname(__DIR__) . '/public_html' . $img;
    if (is_file($abs)) {
        $src = @imagecreatefromstring(@file_get_contents($abs));
        if ($src) { $short = substr($slug, 0, 24); imagejpeg($src, "/tmp/dep_$short.jpg", 88); imagedestroy($src); }
    }
    $results[$slug] = ['id' => (int)$r['id'], 'kind' => $kind, 'img' => $img, 'credit' => $credit, 'credit_url' => $curl];
    file_put_contents($resFile, json_encode($results, JSON_PRETTY_PRINT));  // incremental: a timeout never loses completed dramas
}
echo "wrote $resFile (" . count($results) . " entries)\n";
