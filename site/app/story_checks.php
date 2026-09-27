<?php
// GenZHype | THE CHECKS A STORY GETS AFTER IT IS WRITTEN (2026-09-26). The build ran these inline in
// cli.php; a story made again from the beginning (rebuild.php, owner 2026-09-26: "run the pipeline to
// make them again from the beginning") must get exactly the same ones, so they live here, once.
// Framing repair, fact check, editor, top-3 test (+ the original posts the top 3 show and we lack),
// both sides, confirmed vs claimed, our read of the evidence, then the index rules. Narrates for the log.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gate.php';
require_once __DIR__ . '/verify.php';
require_once __DIR__ . '/quality.php';

/** ['v' => verify, 'q' => editor, 'g' => gate, 'ok' => all three passed]. $engine: 'exa' (new) or 'tavily' (old pages). */
function story_checks(PDO $pdo, int $pageId, string $engine = 'exa'): array {
    // 2026-09-24 the legal framing check failed both stories built at 22:30
    // (4 unframed events each), one of which the editor had passed. Drama
    // deepen already chains the framing repair; the build now runs it on the
    // new page before any check, so the checks see the page readers will.
    try {
        require_once __DIR__ . '/framing_repair.php';
        $fr = framing_repair_run($pdo, 16, $pageId);
        if (!empty($fr['repaired'])) echo "    framing: {$fr['repaired']} unconfirmed event(s) given alleged/according-to framing\n";
    } catch (Throwable $e) { echo "    framing repair failed: " . $e->getMessage() . "\n"; }
    // accuracy (owner 2026-09-27, accuracy.php): real past dates; every sentence backed by a source (a sentence no
    // source passage supports comes out); then the fact check, whose result the build uses
    require_once __DIR__ . '/accuracy.php';
    $acc = acc_run($pdo, $pageId);
    $v = $acc['verify'];
    $moved = array_sum($acc['dates']);
    if ($acc['removal']['removed'] || $moved)
        echo "    accuracy: {$acc['removal']['removed']} unsupported sentence(s) out, {$moved} timeline date(s) put right"
           . ($acc['removal']['why_dropped'] ? ", 'Why it matters' dropped" : '') . "\n";
    if (isset($acc['tie']['error'])) echo "    accuracy: sentence check not run ({$acc['tie']['error']}); the page holds until it runs\n";
    $q = quality_check_drama($pageId);
    // the top-3 test (owner rule): what this page has that the top results do not; stored, read by the gate.
    // It needs a key: the keyless Exa tier (shared by 8 features: slang drafts, deepen, clips...) hit
    // its rate limit after ~20 searches in an hour on 2026-09-25, so builds must not spend it.
    if (!empty($GLOBALS['CONFIG'][$engine]['key'])) {
        try {
            require_once __DIR__ . '/top3.php';
            $t3 = top3_check($pdo, $pageId, $engine);
            if (isset($t3['error'])) echo "    top 3: not checked ({$t3['error']})\n";
            // step 3: the original posts the top 3 show and we do not join our timeline, then the test runs again
            elseif (($t3['missing_posts'] ?? 0) > 0) {
                $fp = top3_add_missing_posts($pdo, $pageId);
                echo "    top 3 posts: added {$fp['added']}, attached {$fp['attached']}" . (isset($fp['error']) ? " ({$fp['error']})" : '') . "\n";
                if ($fp['added'] || $fp['attached']) top3_check($pdo, $pageId, $engine);
            }
        } catch (Throwable $e) { echo "    top 3: failed (" . $e->getMessage() . ")\n"; }
    } else {
        echo "    top 3: skipped (needs a {$engine} key in config.php)\n";
    }
    // both sides in their own words (both_sides.php), when the sources hold them
    try {
        require_once __DIR__ . '/both_sides.php';
        $bsr = bs_find($pdo, $pageId);
        if (!isset($bsr['error'])) { bs_save($pdo, $pageId, $bsr['sides']); if ($bsr['sides']) echo "    both sides: " . $bsr['sides'][0]['who'] . ' / ' . $bsr['sides'][1]['who'] . "\n"; }
    } catch (Throwable $e) { echo "    both sides failed: " . $e->getMessage() . "\n"; }
    // confirmed vs claimed: events a primary source proves (identity links usually arrive at 6:00, so the daily pass does most)
    try {
        $cf = gate_confirm_story($pdo, (int)$pdo->query("SELECT id FROM dramas WHERE page_id=" . $pageId)->fetchColumn());
        if ($cf) echo "    confirmed: {$cf} event(s) by a primary source\n";
    } catch (Throwable $e) { echo "    confirm failed: " . $e->getMessage() . "\n"; }
    // our read of the evidence (verdict.php), from what the page now holds
    try {
        require_once __DIR__ . '/verdict.php';
        $vdr = vd_write($pdo, $pageId);
        echo "    evidence read: " . (isset($vdr['error']) ? "not written ({$vdr['error']})" : $vdr['rating']) . "\n";
    } catch (Throwable $e) { echo "    evidence read failed: " . $e->getMessage() . "\n"; }
    $g = gate_check_drama($pageId);
    return ['v' => $v, 'q' => $q, 'g' => $g, 'ok' => ($v['pass'] ?? false) && ($q['pass'] ?? false) && ($g['pass'] ?? false)];
}
