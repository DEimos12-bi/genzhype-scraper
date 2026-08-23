<?php
/**
 * GenZHype | r29 PEOPLE RE-RESOLVE — repair people_json across the library.
 *
 * WHY: entity_resolve_person's description whitelist listed 'athlete' but
 * Wikidata writes "American football player" / "basketball player", so athletes
 * (and many other public figures) resolved to an EMPTY sameAs and were silently
 * dropped by entity_backfill_run's `if (!empty($e['sameAs']))` gate. A story
 * titled "Tom Brady and Logan Paul's ..." stored ONLY Logan Paul, so the video
 * engine had one Brady photo and reused it ~10 times in a 66s video.
 *
 * entity_backfill_run only fills people_json when it is NULL/'' — already-written
 * (truncated) rows would never be revisited. This re-resolves them.
 *
 * SAFE BY DESIGN: only ever ADDS people. A drama's stored people are kept as-is;
 * newly-resolvable names are appended. If nothing new resolves, the row is left
 * untouched. Cap 4 people per drama (the feed slices to 4 anyway).
 *
 *   php app/people_reresolve.php [limit] [--dry]
 */
declare(strict_types=1);
$APP = __DIR__;
$GLOBALS['CONFIG'] = require $APP . '/config.php';
require $APP . '/helpers.php';
require $APP . '/db.php';
require_once $APP . '/entity.php';
require_once $APP . '/drama_image.php';   // drama_people_ai

$limit = (int)($argv[1] ?? 50);
$dry   = in_array('--dry', $argv, true);
$pdo   = db();
entity_init($pdo);

$rows = $pdo->query("SELECT p.id AS pid, p.h1, p.summary, d.id AS did, d.people_json
                     FROM pages p JOIN dramas d ON d.page_id=p.id
                     WHERE p.type='drama' AND p.status='published'
                     ORDER BY p.id DESC")->fetchAll();

$scanned = 0; $improved = 0; $added = 0;
foreach ($rows as $r) {
    if ($improved >= $limit) break;
    $scanned++;
    $have = json_decode((string)($r['people_json'] ?? ''), true);
    if (!is_array($have)) $have = [];
    $haveNames = [];
    foreach ($have as $h) if (!empty($h['name'])) $haveNames[mb_strtolower((string)$h['name'])] = true;

    try {
        $names = drama_people_ai((string)$r['h1'], (string)($r['summary'] ?? ''));
    } catch (Throwable $e) { continue; }

    $new = [];
    foreach (array_slice($names, 0, 4) as $name) {
        if (count($have) + count($new) >= 4) break;
        if (isset($haveNames[mb_strtolower($name)])) continue;      // already stored
        try { $e = entity_resolve_person($pdo, $name); } catch (Throwable $ex) { continue; }
        if (empty($e['sameAs'])) continue;                          // still unresolvable -> skip
        $new[] = ['name' => $name,
                  'role' => ($e['description'] ?? '') ?: 'Featured',
                  'sameAs' => $e['sameAs']];
    }
    if (!$new) continue;

    $out = array_merge($have, $new);
    printf("page %-5s +%d  %s\n", $r['pid'], count($new),
           implode(', ', array_map(fn($n) => $n['name'], $new)));
    if (!$dry) {
        $pdo->prepare("UPDATE dramas SET people_json=? WHERE id=?")
            ->execute([json_encode($out, JSON_UNESCAPED_SLASHES), $r['did']]);
    }
    $improved++; $added += count($new);
}
echo "\nscanned=$scanned improved=$improved people_added=$added" . ($dry ? " (DRY RUN)" : "") . "\n";
