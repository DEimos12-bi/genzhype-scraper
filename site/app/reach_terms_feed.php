<?php
declare(strict_types=1);
/* GenZHype | reach_terms_feed.php — publish "which terms need usage evidence"
 * for the 6-hourly reach-runner (OWNER 2026-08-29: "work with them that alr
 * in our systems and wire them" — the X + Reddit burner channels have been
 * LIVE on the runner since 2026-08-05 but only ever fed discovery, never
 * citations). The runner cannot ask the server (bitninja blackholes runner
 * IPs — the whole reason the git bus exists), so the bridge pushes this file
 * to the reach-feed branch and the runner searches each term on X + Reddit.
 *
 *   Usage: php app/reach_terms_feed.php <outdir>     (writes terms-wanted.json)
 *
 * Who gets on the list (cap 12 — burner-account courtesy, 2 searches/term):
 *   1. the build queue front: selected candidates the next ticks will draft,
 *      so evidence is CACHED before the drafter ever asks;
 *   2. existing term pages with < 3 gate-valid citations — the repair tail.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
$out = rtrim((string)($argv[1] ?? ''), '/');
if ($out === '' || !is_dir($out)) { fwrite(STDERR, "usage: reach_terms_feed.php <outdir>\n"); exit(2); }

$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/gate_term.php';
$pdo = db();

$want = [];   // term => why (first writer wins; queue beats repair)

foreach ($pdo->query("SELECT name FROM candidates
                       WHERE status='selected' AND type IN ('term','meme','gaming','music')
                         AND heat_score >= 50
                       ORDER BY heat_score DESC, id ASC LIMIT 8") as $r) {
    $t = trim((string)$r['name']);
    // vocabulary only — a headline-shaped candidate ("xqc furious after being
    // forced to...") makes a useless phrase search and burns a courtesy slot
    if (mb_strlen($t) < 3 || mb_strlen($t) > 40 || str_word_count($t) > 4) continue;
    $want[mb_strtolower($t)] = 'queue';
}

foreach ($pdo->query("SELECT t.term, t.citations FROM terms t
                        JOIN pages p ON p.id = t.page_id
                       WHERE p.status IN ('draft','review','published')
                       ORDER BY p.updated_at DESC LIMIT 200") as $r) {
    if (count($want) >= 12) break;
    $t = trim((string)$r['term']);
    if (mb_strlen($t) < 3 || isset($want[mb_strtolower($t)])) continue;
    $cites = (array)json_decode((string)$r['citations'], true);
    if (count(gate_term_valid_citations($cites, $t)) < 3) $want[mb_strtolower($t)] = 'repair';
}

file_put_contents($out . '/terms-wanted.json', json_encode(
    ['at' => date('c'), 'terms' => array_map(
        fn($t, $why) => ['term' => $t, 'why' => $why],
        array_keys($want), array_values($want))],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
printf("terms feed: %d term(s) wanted\n", count($want));
