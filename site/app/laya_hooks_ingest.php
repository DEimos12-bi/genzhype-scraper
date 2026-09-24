<?php
/**
 * r190 HOOK LEARNING LOOP - the ingest half (see app/laya_hooks.php).
 *
 * Reads <drop>/hooks/scores.json + report.json from the laya-drop branch:
 *   1. every waiting video's current hook gets its score (video_scripts.hook_score);
 *   2. where alternatives were scored, the best one replaces the hook ONLY if it
 *      beats the current one by LAYA_HOOK_SWAP_MARGIN (the original is kept in
 *      hook_original, so every swap is reversible and measurable);
 *   3. up to LAYA_HOOK_ALT_PER_RUN weak hooks (score < 0.5, i.e. the model
 *      expects below-median views) get three alternatives written by the AI;
 *      the next round scores them.
 * Only the ON-SCREEN hook changes. The spoken script never does, so a shot
 * list planned against the script's word positions stays valid.
 */
require_once __DIR__ . '/laya_hooks.php';

function laya_hooks_ingest(PDO $pdo, string $dir): array {
    laya_hooks_install($pdo);
    $scores = json_decode((string)@file_get_contents($dir . '/hooks/scores.json'), true);
    $report = json_decode((string)@file_get_contents($dir . '/hooks/report.json'), true);
    if (!is_array($scores)) return ['ok' => false, 'why' => 'no hooks/scores.json in the drop'];
    $out = ['scored' => 0, 'swapped' => 0, 'alternatives_written' => 0,
            'scorer' => $report['scorer'] ?? '?', 'model_cv_auc' => $report['model_cv_auc'] ?? null,
            'zero_shot_cv_auc' => $report['zero_shot_cv_auc'] ?? null];

    $byPage = [];
    foreach ($scores as $s) {
        if (!isset($s['page_id'], $s['hook'], $s['score'])) continue;
        $byPage[(int)$s['page_id']][$s['kind'] ?? 'current'][] = $s;
    }
    $cur = $pdo->prepare("SELECT hook, hook_original FROM video_scripts WHERE page_id=? AND video_status='pending'");
    foreach ($byPage as $pid => $k) {
        $cur->execute([$pid]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue;                                   // rendered or dropped meanwhile
        $now = null;
        foreach ((array)($k['current'] ?? []) as $c) {
            if (trim($c['hook']) === trim((string)$row['hook'])) $now = $c;
        }
        if ($now === null) continue;                           // hook changed since export; next round
        $pdo->prepare("UPDATE video_scripts SET hook_score=?, hook_scored_at=NOW() WHERE page_id=?")
            ->execute([(float)$now['score'], $pid]);
        $out['scored']++;
        $alts = (array)($k['candidate'] ?? []);
        if (!$alts) continue;
        usort($alts, fn($a, $b) => $b['score'] <=> $a['score']);
        $best = $alts[0];
        if ((float)$best['score'] >= (float)$now['score'] + LAYA_HOOK_SWAP_MARGIN) {
            // OWNER RULE 2026-09-24: nothing switches to Laya before he has
            // seen its agreement numbers on past real cases. Until he approves,
            // swaps are REPORT-ONLY: logged as WOULD-SWAP, the hook untouched.
            // Approval = create app/LAYA_HOOK_SWAP (delete it to stop again).
            if (!is_file(__DIR__ . '/LAYA_HOOK_SWAP')) {
                laya_hooks_log("WOULD-SWAP p{$pid}: \"{$row['hook']}\" ({$now['score']}) -> \"{$best['hook']}\" ({$best['score']}) - report-only, owner approval pending");
                $out['would_swap'] = ($out['would_swap'] ?? 0) + 1;
                continue;
            }
            $pdo->prepare("UPDATE video_scripts SET hook=?, hook_score=?, hook_original=COALESCE(hook_original, ?),
                                  hook_candidates=NULL, hook_scored_at=NOW() WHERE page_id=?")
                ->execute([mb_substr($best['hook'], 0, 190), (float)$best['score'], (string)$row['hook'], $pid]);
            laya_hooks_log("SWAP p{$pid}: \"{$row['hook']}\" ({$now['score']}) -> \"{$best['hook']}\" ({$best['score']})");
            $out['swapped']++;
        } else {
            // alternatives tried and none clearly better: keep the hook, stop retrying
            $pdo->prepare("UPDATE video_scripts SET hook_candidates='[]' WHERE page_id=?")->execute([$pid]);
            laya_hooks_log("KEEP p{$pid}: \"{$row['hook']}\" ({$now['score']}); best alternative \"{$best['hook']}\" ({$best['score']}) not clearly better");
        }
    }
    // OWNER 2026-09-24: "if we're doing work we don't need, stop it". Writing
    // alternatives spends AI calls on a scorer not yet proven, so it runs only
    // once he approves the loop (same switch as the swaps). Scoring continues:
    // it is the measurement that decides whether Laya earns a place at all.
    $out['alternatives_written'] = is_file(__DIR__ . '/LAYA_HOOK_SWAP')
        ? laya_hooks_write_alternatives($pdo, LAYA_HOOK_ALT_PER_RUN) : 0;
    laya_hooks_log('INGEST ' . json_encode($out));
    return $out;
}

/** Three alternative on-screen hooks for the weakest waiting hooks that have none yet. */
function laya_hooks_write_alternatives(PDO $pdo, int $limit): int {
    $rows = $pdo->query("SELECT page_id, title, hook, script FROM video_scripts
                         WHERE video_status='pending' AND hook_score IS NOT NULL AND hook_score < 0.5
                           AND hook_candidates IS NULL AND hook_original IS NULL
                         ORDER BY hook_score ASC LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;
    require_once __DIR__ . '/ai.php';
    $n = 0;
    foreach ($rows as $r) {
        $opening = implode(' ', array_slice(preg_split('/(?<=[.!?])\s+/u', trim((string)$r['script'])) ?: [], 0, 2));
        $res = ai_chat([['role' => 'user', 'content' =>
            "You write the ON-SCREEN text hook for a short vertical video (TikTok/Reels/Shorts): the words shown in "
            . "the first frame that decide whether a scrolling viewer stops. Story: \"{$r['title']}\". The voiceover "
            . "opens: \"" . mb_substr($opening, 0, 300) . "\". Current hook: \"{$r['hook']}\".\n"
            . "Write 3 DIFFERENT alternatives, each 3-6 words, ALL CAPS, built on the most specific fact in the story "
            . "(a real name, a number, a dollar amount, the shocking action). No emojis, no hashtags, no question "
            . "that asks the viewer's opinion, nothing the story does not support. "
            . "STRICT JSON: {\"hooks\": [\"...\", \"...\", \"...\"]}"]], ['gemini', 'openrouter', 'nvidia'], 0.8);
        $j = isset($res['error']) ? null : ai_json($res['content'] ?? '');
        $alts = [];
        foreach ((array)($j['hooks'] ?? []) as $h) {
            $h = trim((string)$h);
            $w = count(preg_split('/\s+/', $h));
            if ($h !== '' && $w >= 2 && $w <= 8 && mb_strtolower($h) !== mb_strtolower(trim((string)$r['hook']))) $alts[] = $h;
        }
        if (!$alts) continue;
        $pdo->prepare("UPDATE video_scripts SET hook_candidates=? WHERE page_id=?")
            ->execute([json_encode(array_slice(array_values(array_unique($alts)), 0, 3), JSON_UNESCAPED_UNICODE), (int)$r['page_id']]);
        laya_hooks_log("ALTS p{$r['page_id']}: \"{$r['hook']}\" -> " . json_encode($alts, JSON_UNESCAPED_UNICODE));
        $n++;
    }
    return $n;
}
