<?php
/**
 * Commit step for the drama redeploy. Reads /tmp/deploy_results.json and writes the
 * cover/credit/featured_img to the DB ONLY for the slugs passed as argv (the ones I
 * eye-checked and approved). Branded results are always safe to commit.
 *   php app/redeploy_commit.php slug-a slug-b
 *   php app/redeploy_commit.php --branded   (commit every branded result, no eye-check needed)
 */
$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
$pdo = db();

$results = json_decode(@file_get_contents('/tmp/deploy_results.json'), true) ?: [];
$args = array_slice($argv, 1);
$brandedAll = in_array('--branded', $args, true);
$want = array_values(array_filter($args, fn($a) => $a !== '--branded'));

$done = 0;
foreach ($results as $slug => $m) {
    $take = in_array($slug, $want, true) || ($brandedAll && $m['kind'] === 'branded');
    if (!$take) continue;
    $feat = $m['kind'] === 'branded' ? null : $m['img'];
    $pdo->prepare("UPDATE pages SET cover=?, cover_credit=?, cover_credit_url=?, featured_img=?, updated_at=NOW() WHERE id=?")
        ->execute([$m['img'], $m['credit'], $m['credit_url'], $feat, $m['id']]);
    echo "  committed $slug -> " . strtoupper($m['kind']) . "\n";
    $done++;
}
echo "$done committed\n";
