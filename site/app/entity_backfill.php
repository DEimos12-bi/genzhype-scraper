<?php
/**
 * Entity backfill (Citation Engine). For each published DRAMA, extract the real
 * people and resolve them to Wikidata/Wikipedia/socials -> store in dramas.people_json
 * (this also populates the "Who is involved" section that was empty). For each TERM,
 * resolve its authoritative reference pages -> entities cache. Time-budgeted.
 *   timeout 290 php app/entity_backfill.php
 */
$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/entity.php';
require_once __DIR__ . '/drama_image.php';   // drama_people_ai
$pdo = db();
entity_init($pdo);
$budget = 250; $start = time();

echo "== DRAMAS: people -> entities ==\n";
$dramas = $pdo->query("SELECT p.id, p.h1, p.summary, d.id AS did, d.people_json
                       FROM pages p JOIN dramas d ON d.page_id=p.id
                       WHERE p.type='drama' AND p.status='published' AND p.robots='index'
                       ORDER BY (d.people_json IS NULL) DESC, p.id")->fetchAll();
foreach ($dramas as $r) {
    if (time() - $start > $budget) { echo "  ...budget hit; rerun to finish\n"; break; }
    if (!empty($r['people_json']) && $r['people_json'] !== '[]') { echo "  skip (done): " . substr($r['h1'], 0, 40) . "\n"; continue; }
    $out = [];
    try {
        foreach (array_slice(drama_people_ai($r['h1'], $r['summary'] ?? ''), 0, 4) as $name) {
            $e = entity_resolve_person($pdo, $name);
            if (!empty($e['sameAs'])) $out[] = ['name' => $name, 'role' => $e['description'] ?: 'Featured', 'sameAs' => $e['sameAs']];
        }
    } catch (Throwable $ex) { echo "  ERR " . substr($r['h1'], 0, 30) . ": " . $ex->getMessage() . "\n"; continue; }
    $pdo->prepare("UPDATE dramas SET people_json=? WHERE id=?")->execute([json_encode($out, JSON_UNESCAPED_SLASHES), $r['did']]);
    echo "  " . str_pad(count($out) . " ent", 6) . " " . substr($r['h1'], 0, 50) . "\n";
}

echo "== TERMS: reference entities ==\n";
$terms = $pdo->query("SELECT t.term, t.sources FROM pages p JOIN terms t ON t.page_id=p.id
                      WHERE p.type='term' AND p.status='published' AND p.robots='index'")->fetchAll();
$n = 0; $withSame = 0;
foreach ($terms as $t) {
    $e = entity_resolve_term($pdo, $t['term'], json_decode($t['sources'] ?: '[]', true) ?: []);
    $n++; if (!empty($e['sameAs'])) $withSame++;
}
echo "  resolved $n terms ($withSame have authoritative sameAs)\n";
echo "done\n";
