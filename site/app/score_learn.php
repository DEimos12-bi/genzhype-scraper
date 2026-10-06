<?php
// GenZHype | STEP 4: LEARNING (owner 2026-10-01): "After 2 and 7 days, compare each idea's trend score with its real
// results (views, clicks, indexing). Weekly: which signals predicted well + proposed adjusted weights. HE approves."
//
//   sl_rows()     every story built from a scored idea, with the idea's five parts and the page's 2-day / 7-day results
//                 (laya_log: Search Console impressions, clicks, index state; GA4 views)
//   sl_stats()    PURE: for each part, did a higher part go with a better result? (rank correlation, and the average
//                 result above vs below 50) -> proposed weights, scaled to 100, next to the weights in use
//   sl_report()   the weekly report in plain words. NOTHING here changes a weight: `cli.php score weights set ...`
//                 writes app/score_weights.json only when the owner says so, and is_score() reads that file.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/idea_score.php';

/** The result of one page as one number: views + 3 x clicks + 20 if Google indexed it (clicks are rarer and worth more). [OURS] */
function sl_result(array $r): ?float {
    if (!$r) return null;
    $views = $r['views'] ?? null; $clicks = $r['clicks'] ?? null; $idx = (string)($r['index'] ?? '');
    if ($views === null && $clicks === null && $idx === '') return null;
    return (float)($views ?? 0) + 3 * (float)($clicks ?? 0) + (stripos($idx, 'indexed') !== false && stripos($idx, 'not indexed') === false ? 20 : 0);
}

/** Stories built from a scored idea, with the parts and the results. */
function sl_rows(PDO $pdo, int $days = 30): array {
    is_install($pdo);
    $rows = [];
    $q = $pdo->query("SELECT l.page_id, l.data, l.day2, l.day7, l.created_at, w.build FROM laya_log l JOIN work_record w ON w.kind='drama' AND w.page_id=l.page_id
                      WHERE l.kind='story' AND l.created_at >= UTC_TIMESTAMP() - INTERVAL " . (int)$days . " DAY AND (l.day2 IS NOT NULL OR l.day7 IS NOT NULL)");
    $sq = $pdo->prepare("SELECT score, parts FROM idea_scores WHERE cand_id=?");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $b = json_decode((string)$r['build'], true); if (isset($b[0])) $b = end($b);
        $cid = (int)($b['candidate_id'] ?? 0); if (!$cid) continue;
        $sq->execute([$cid]); $s = $sq->fetch(PDO::FETCH_ASSOC); if (!$s) continue;
        $d2 = json_decode((string)$r['day2'], true) ?: []; $d7 = json_decode((string)$r['day7'], true) ?: [];
        $rows[] = ['page_id' => (int)$r['page_id'], 'cand_id' => $cid, 'title' => (string)((json_decode((string)$r['data'], true) ?: [])['title'] ?? ''), 'score' => (int)$s['score'],
                   'parts' => json_decode((string)$s['parts'], true) ?: [], 'day2' => sl_result($d2), 'day7' => sl_result($d7),
                   'indexed' => stripos((string)($d7['index'] ?? $d2['index'] ?? ''), 'indexed') !== false && stripos((string)($d7['index'] ?? $d2['index'] ?? ''), 'not indexed') === false];
    }
    return $rows;
}

/** Pure: Spearman rank correlation of two equal-length lists (null when fewer than 5 pairs or no spread). */
function sl_rank_corr(array $x, array $y): ?float {
    $n = count($x); if ($n < 5 || $n !== count($y)) return null;
    $rank = function (array $v): array { $idx = array_keys($v); usort($idx, fn($a, $b) => $v[$a] <=> $v[$b]); $r = []; $i = 0;
        while ($i < $n = count($idx)) { $j = $i; while ($j + 1 < $n && $v[$idx[$j + 1]] == $v[$idx[$i]]) $j++; $avg = ($i + $j) / 2 + 1; for ($k = $i; $k <= $j; $k++) $r[$idx[$k]] = $avg; $i = $j + 1; }
        return $r; };
    $rx = $rank($x); $ry = $rank($y);
    $mx = array_sum($rx) / $n; $my = array_sum($ry) / $n; $num = 0; $dx = 0; $dy = 0;
    foreach (array_keys($x) as $k) { $num += ($rx[$k] - $mx) * ($ry[$k] - $my); $dx += ($rx[$k] - $mx) ** 2; $dy += ($ry[$k] - $my) ** 2; }
    if ($dx <= 0 || $dy <= 0) return null;
    return round($num / sqrt($dx * $dy), 3);
}

/**
 * Pure. $rows as sl_rows() gives them; $which = 'day7' (falls back to day2 when a page has no 7-day result yet).
 * ['n' => pages with a result, 'parts' => [name => ['n', 'corr', 'high_avg', 'low_avg', 'measured']], 'score_corr',
 *  'proposed' => [name => weight], 'current' => [name => weight], 'enough' => bool]
 */
function sl_stats(array $rows, string $which = 'day7', array $current = IS_WEIGHTS): array {
    $use = [];
    foreach ($rows as $r) { $v = $r[$which] ?? null; if ($v === null && $which === 'day7') $v = $r['day2'] ?? null; if ($v !== null) $use[] = $r + ['result' => (float)$v]; }
    $alive = count(array_filter($use, fn($r) => $r['result'] > 0));   // pages with any result at all
    // enough to act on: 30+ pages AND at least 10 with a result above zero (a week of all-zero results teaches nothing)
    $out = ['n' => count($use), 'alive' => $alive, 'parts' => [], 'score_corr' => null, 'proposed' => $current, 'current' => $current, 'enough' => count($use) >= 30 && $alive >= 10];
    if (!$use) return $out;
    $res = array_column($use, 'result');
    $out['score_corr'] = sl_rank_corr(array_map(fn($r) => (float)$r['score'], $use), $res);
    $strength = [];
    foreach (array_keys($current) as $k) {
        $x = []; $y = []; $hi = []; $lo = [];
        foreach ($use as $r) { $p = $r['parts'][$k] ?? null; if ($p === null) continue; $x[] = (float)$p; $y[] = (float)$r['result']; if ($p >= 50) $hi[] = (float)$r['result']; else $lo[] = (float)$r['result']; }
        $c = sl_rank_corr($x, $y);
        $out['parts'][$k] = ['n' => count($x), 'measured' => count($use) ? (int)round(100 * count($x) / count($use)) : 0, 'corr' => $c,
                             'high_avg' => $hi ? round(array_sum($hi) / count($hi), 1) : null, 'low_avg' => $lo ? round(array_sum($lo) / count($lo), 1) : null];
        $strength[$k] = max(0.0, (float)($c ?? 0));   // a part that predicts nothing, or the wrong way, earns no weight
    }
    if ($alive < 10) return $out;   // no signal yet: today's weights stay the proposal
    // proposed weights: half from the evidence, half from today's weights (one week never rewrites everything), scaled to 100
    $sum = array_sum($strength);
    foreach ($current as $k => $w) $out['proposed'][$k] = $sum > 0 ? 0.5 * $w + 0.5 * (100 * $strength[$k] / $sum) : (float)$w;
    $tot = array_sum($out['proposed']) ?: 1;
    foreach ($out['proposed'] as $k => $w) $out['proposed'][$k] = (int)round(100 * $w / $tot);
    $diff = 100 - array_sum($out['proposed']); if ($diff) { $kk = array_key_first($out['proposed']); $out['proposed'][$kk] += $diff; }
    return $out;
}

/** The weekly report in plain words. */
function sl_report(PDO $pdo, int $days = 30): string {
    $rows = sl_rows($pdo, $days);
    $st = sl_stats($rows, 'day7', is_weights());
    $names = ['rising' => 'rising', 'spread' => 'spread', 'reach' => 'reach', 'new' => 'new', 'open' => 'open field'];
    $s = "Trend score, learning (last {$days} days): {$st['n']} story page(s) built from a scored idea have a 2-day or 7-day result.\n";
    if (!$st['n']) return $s . "  Nothing to learn from yet: results arrive 2 and 7 days after a page goes live.\n";
    $s .= "  A result = views + 3 x clicks + 20 if Google indexed the page. Correlation: +1 = the part always went with a better result, 0 = no link, below 0 = the opposite.\n";
    $s .= "  the whole score against results: " . ($st['score_corr'] === null ? 'too few pages to say' : $st['score_corr']) . "\n";
    foreach ($st['parts'] as $k => $p)
        $s .= "  " . str_pad($names[$k], 11) . "measured on {$p['measured']}% | correlation " . ($p['corr'] === null ? '  n/a' : sprintf('%+.2f', $p['corr']))
            . " | average result when the part was 50+: " . ($p['high_avg'] ?? 'none') . ", under 50: " . ($p['low_avg'] ?? 'none') . "\n";
    $s .= "  weights in use:  " . implode(', ', array_map(fn($k, $w) => "{$names[$k]} {$w}", array_keys($st['current']), $st['current'])) . "\n";
    $s .= "  proposed:        " . implode(', ', array_map(fn($k, $w) => "{$names[$k]} {$w}", array_keys($st['proposed']), $st['proposed'])) . "\n";
    if (($st['alive'] ?? 0) < 10) $s .= "  Only {$st['alive']} page(s) had any views, clicks or an index entry: no signal yet, so no change is proposed.\n";
    $s .= $st['enough'] ? "  Enough pages to act on (30+). To apply: php app/cli.php score weights set " . implode(' ', array_map(fn($k, $w) => "{$k}={$w}", array_keys($st['proposed']), $st['proposed'])) . "\n"
                        : "  Fewer than 30 pages: a proposal to look at, not to apply yet.\n";
    $idx = count(array_filter($rows, fn($r) => $r['indexed']));
    $s .= "  Google indexed {$idx} of " . count($rows) . " within the window; most results so far are zeros, so early weeks say little.\n";
    return $s;
}
