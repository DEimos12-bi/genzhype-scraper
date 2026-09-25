<?php
// GenZHype | operator CLI. Usage:
//   php app/cli.php list                  - all pages + status
//   php app/cli.php gate <slug>           - run the publish gate on a drama
//   php app/cli.php audit                 - gate every published drama
//   php app/cli.php publish <slug>        - gate, and if pass: robots=index + sitemap + IndexNow
//   php app/cli.php unpublish <slug>      - robots=noindex + sitemap
//   php app/cli.php sitemap               - regenerate sitemap.xml
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }

// r186 SILENT-BUG GUARD: PHP's own warnings were invisible here (log_errors
// Off, display_errors Off), so a deleted prompt branch ran for four days as an
// unseen "Undefined variable $sys" and simply stopped writing video scripts.
// Warnings now land in app/error_log beside our own lines; deprecations stay
// out so the file keeps signal.
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error_log');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/gate.php';
require_once __DIR__ . '/sitemap_lib.php';
require_once __DIR__ . '/indexnow.php';

$cmd  = $argv[1] ?? 'list';
$arg  = $argv[2] ?? null;
$pdo  = db();

function table(array $rows): void {
    foreach ($rows as $r) {
        $mark = $r['pass'] ? 'PASS' : 'FAIL';
        printf("  [%s] %s%s\n", $mark, $r['label'], $r['detail'] !== '' ? '  |  ' . $r['detail'] : '');
    }
}

/**
 * SELF-AUDIT: re-check every published page against the current gates.
 * Thin term pages auto-expand + re-index; pages that still fail are pulled
 * (noindex). Runs nightly from the cron tick = the site polices itself forever.
 */
function watchdog_run(int $fixLimit = 4): array {
    global $pdo;
    require_once __DIR__ . '/gate_term.php';
    require_once __DIR__ . '/gate.php';
    require_once __DIR__ . '/draft_term.php';
    $rows = $pdo->query("SELECT id, type, slug, robots FROM pages WHERE status='published'
                         ORDER BY (robots='noindex') DESC, updated_at ASC")->fetchAll();
    $ok = 0; $fixed = 0; $pulled = 0; $restored = 0;
    $setIndex = function ($id, $cur) use ($pdo, &$restored) {
        if (empty($GLOBALS['CONFIG']['auto_publish'])) return;   // publishing paused: never (re)index
        if ($cur !== 'index') { $pdo->prepare("UPDATE pages SET robots='index' WHERE id=?")->execute([$id]); $restored++; }
    };
    foreach ($rows as $r) {
        if ($r['type'] === 'term') {
            $g = gate_check_term((int)$r['id']);
            if ($g['pass']) { $setIndex($r['id'], $r['robots']); $ok++; continue; }
            $fails = array_column(array_filter($g['checks'], fn($c) => !$c['pass']), 'label');
            $thinOnly = (count($fails) === 1 && strpos($fails[0], 'depth') !== false);
            if ($thinOnly && $fixed < $fixLimit) {
                $e = expand_term((int)$r['id']);
                $g2 = isset($e['error']) ? ['pass' => false] : gate_check_term((int)$r['id']);
                if ($g2['pass']) { $setIndex($r['id'], $r['robots']); echo "  WD-FIXED {$r['slug']}\n"; $fixed++; continue; }
            }
            if ($r['robots'] === 'index') { $pdo->prepare("UPDATE pages SET robots='noindex' WHERE id=?")->execute([$r['id']]); echo "  WD-PULLED {$r['slug']} (" . implode(',', $fails) . ")\n"; $pulled++; }
        } elseif ($r['type'] === 'drama') {
            $g = gate_check_drama((int)$r['id']);
            // 2026-09-24 a noindex story earns index back only on the publish step's
            // own rules (framed, 2+ sources, latest editor verdict a pass). The code
            // gate alone re-indexed 64 stories the editor had failed in 7 days.
            if ($g['pass'] && $r['robots'] !== 'index' && drama_index_block($pdo, (int)$r['id']) !== '') { $ok++; continue; }
            if ($g['pass']) { $setIndex($r['id'], $r['robots']); $ok++; continue; }
            if ($r['robots'] === 'index') { $pdo->prepare("UPDATE pages SET robots='noindex' WHERE id=?")->execute([$r['id']]); echo "  WD-PULLED {$r['slug']} (drama)\n"; $pulled++; }
        }
    }
    // EMBED RE-VALIDATION: source posts die. Re-hit oEmbed for attached term
    // posts (cap 15/run); a dead post drops the page to imageless + logs it.
    require_once __DIR__ . '/embeds.php';
    $dropped = 0;
    $emb = $pdo->query("SELECT t.page_id, p.slug, t.scene_embed_url FROM terms t JOIN pages p ON p.id=t.page_id
                        WHERE p.status='published' AND t.scene_embed_url IS NOT NULL
                        ORDER BY p.updated_at ASC LIMIT 15")->fetchAll();
    foreach ($emb as $e2) {
        $chk = embed_for_url($e2['scene_embed_url']);
        if (!$chk || empty($chk['html'])) {
            $pdo->prepare("UPDATE terms SET scene_embed_html=NULL, scene_embed_provider=NULL, scene_embed_url=NULL WHERE page_id=?")
                ->execute([$e2['page_id']]);
            echo "  WD-EMBED-DROPPED {$e2['slug']} (post dead: {$e2['scene_embed_url']})\n";
            $dropped++;
        }
    }
    // story posts the platform did not serve at draft time: one second chance each
    $er = embeds_retry_missing($pdo, 20);
    if ($er['tried']) echo "  WD-EMBED-RETRY: {$er['embedded']} of {$er['tried']} story post(s) now embedded\n";
    // SEO DRIFT: snapshot SEO-critical elements (title/meta/canonical/robots/
    // schema/H1/links/featured/byline) per published page; flag changes vs the
    // last snapshot. First run seeds the baseline silently. Each page is
    // compared against ITS OWN baseline, so legacy pages are never re-judged
    // by newer publish-gate thresholds.
    $drift = 0; $seeded = 0;
    $pubRows = $pdo->query("SELECT id, slug, seo_snapshot FROM pages WHERE status='published'")->fetchAll();
    foreach ($pubRows as $pr) {
        try { $a = seo_audit_page((int)$pr['id']); } catch (Throwable $e) { continue; }
        if (empty($a['snap'])) continue;
        $snap = $a['snap'];
        if (!empty($pr['seo_snapshot'])) {
            $old = json_decode($pr['seo_snapshot'], true) ?: [];
            foreach ($snap as $k => $v) {
                if (!array_key_exists($k, $old)) continue;   // new field = schema upgrade, not drift
                $ov = $old[$k];
                if ($k === 'links') {
                    // related-link rotation moves this a little; only a drop below the floor is drift
                    if ((int)$v < 10 && (int)$v < (int)$ov) { echo "  WD-SEO-DRIFT {$pr['slug']}: links was {$ov} now {$v}\n"; $drift++; }
                    continue;
                }
                if ($k === 'schema') { $v = implode(',', (array)$v); $ov = implode(',', (array)$ov); }
                if (is_bool($v) || is_bool($ov)) { $v = $v ? 'yes' : 'no'; $ov = $ov ? 'yes' : 'no'; }
                if ((string)$v !== (string)$ov) { echo "  WD-SEO-DRIFT {$pr['slug']}: {$k} was {$ov} now {$v}\n"; $drift++; }
            }
        } else { $seeded++; }
        $pdo->prepare("UPDATE pages SET seo_snapshot=? WHERE id=?")->execute([json_encode($snap), $pr['id']]);
    }
    if ($seeded) echo "  WD-SEO-BASELINE seeded for {$seeded} pages\n";

    // TREND-INTELLIGENCE refresh (living document + history moat): re-run the engine
    // (pageviews + YouTube reach + real trend stats), store the numbers, drop a daily
    // snapshot into term_signals (our growing dataset), and bump dateModified ONLY
    // when the trend state flips or interest moves >15% so freshness stays honest.
    require_once __DIR__ . '/trend_engine.php';
    $dataRefreshed = 0; $dataChanged = 0;
    $withData = $pdo->query("SELECT te.id, te.term, te.page_id, te.data_json FROM terms te JOIN pages p ON p.id=te.page_id
                             WHERE p.status='published' AND te.data_json LIKE '%series%'")->fetchAll();
    $retry = $pdo->query("SELECT te.id, te.term, te.page_id, te.data_json FROM terms te JOIN pages p ON p.id=te.page_id
                          WHERE p.status='published' AND (te.data_json IS NULL OR te.data_json='{\"checked\":1}')
                          ORDER BY p.updated_at ASC LIMIT 8")->fetchAll();
    // PACING (2026-08-21) — MEASURED, not defensive. Refreshing every term
    // back-to-back gets throttled by Wikimedia, and a throttled response is
    // indistinguishable from "this article has no views": an unpaced run
    // returned data for 9 of 83 terms, the same run paced returned 60%%.
    // Silent throttling is what filled term_signals with "unknown".
    $first = true;
    foreach (array_merge($withData, $retry) as $d0) {
        if (!$first) { sleep(2); }
        $first = false;
        $new = term_build_intel($d0['term'], (int)$d0['page_id']);   // also snapshots
        $dataRefreshed++;
        if (!$new || empty($new['series'])) {
            if (empty($d0['data_json'])) $pdo->prepare("UPDATE terms SET data_json=? WHERE id=?")->execute([json_encode(['checked' => 1]), $d0['id']]);
            continue;   // transient miss or still too low: keep last-good value
        }
        $old = !empty($d0['data_json']) ? json_decode($d0['data_json'], true) : null;
        $pdo->prepare("UPDATE terms SET data_json=? WHERE id=?")->execute([json_encode($new), $d0['id']]);
        $meaningful = empty($old['trend_state'])
            || ($old['trend_state'] ?? '') !== ($new['trend_state'] ?? '')
            || abs((int)($new['avg_daily'] ?? 0) - (int)($old['avg_daily'] ?? 0)) > max(5, (int)($old['avg_daily'] ?? 0) * 0.15);
        if ($meaningful) { $pdo->prepare("UPDATE pages SET updated_at=NOW() WHERE id=?")->execute([$d0['page_id']]); $dataChanged++; }
    }
    if ($dataRefreshed) echo "  WD-TREND refreshed {$dataRefreshed} (dateModified bumped on {$dataChanged}, snapshots banked)\n";

    return ['scanned' => count($rows), 'ok' => $ok, 'fixed' => $fixed, 'restored' => $restored, 'pulled' => $pulled, 'embeds_dropped' => $dropped, 'seo_drift' => $drift, 'seo_baseline_seeded' => $seeded, 'data_refreshed' => $dataRefreshed, 'data_changed' => $dataChanged];
}

/**
 * THE ONLY path that may promote held (review/noindex) pages to live+indexed.
 * Hard guard: refuses outright when auto_publish is off — no caller can bypass.
 * $dry = report what WOULD happen without touching the DB (for drain-test).
 */
/** Breadcrumb into cron_events when a tick is running (no-op otherwise). */
function cnote(string $m): void {
    if (!empty($GLOBALS['__cnote'])) ($GLOBALS['__cnote'])($m);
}

/**
 * r153 PER-LANE QUOTA: lanes take turns. Rows keep their incoming order inside
 * their own lane, the lane of the first row goes first, and nothing is dropped.
 */
function lane_interleave(array $rows, callable $laneOf): array {
    $byLane = [];
    foreach ($rows as $r) $byLane[(string)$laneOf($r)][] = $r;
    $out = [];
    while ($byLane) {
        foreach (array_keys($byLane) as $k) {
            $out[] = array_shift($byLane[$k]);
            if (!$byLane[$k]) unset($byLane[$k]);
        }
    }
    return $out;
}

function velocity_drain(PDO $pdo, int &$slots, bool $dry = false): array {
    global $CONFIG;
    if (empty($CONFIG['auto_publish'])) {
        return ['refused' => true, 'why' => 'auto_publish=false (publishing paused)'];
    }
    if ($slots <= 0) return ['refused' => false, 'promoted' => 0, 'archived' => 0];
    require_once __DIR__ . '/gate_term.php';
    require_once __DIR__ . '/quality.php';
    $held = $pdo->query("SELECT id, type, path FROM pages WHERE status='review' AND robots='noindex' ORDER BY updated_at ASC LIMIT " . ($slots * 2))->fetchAll();
    $promoted = 0; $archived = 0; $retained = 0;
    require_once __DIR__ . '/dedupe.php';
    foreach ($held as $h) {
        if ($slots <= 0) break;

        // THE TWIN CHECK, first and free. A page retelling a story the site
        // already has can never pass the editor's 'intent' score - correctly, since
        // a second copy cannot answer a search better than the first. Nine such
        // pages were being re-judged on almost every tick: 31 of 65 recent quality
        // checks, 48% of the judging budget, spent on work that could not pass.
        // This costs no AI call and runs before the one that does. It DESTROYS
        // NOTHING - the page stays held; the 7-day sweep is still the only thing
        // that archives (owner decision, 2026-08-22). It just stops paying to ask
        // the same question every hour.
        // THE RETRY BUDGET. The twin check spares duplicates, but a page that is
        // genuinely unique and simply cannot pass is just as expensive: pages 633,
        // 687 and 627 had each been through the AI editor FORTY-FIVE times, 82% of
        // the whole judging budget spent re-asking a question already answered. It
        // is the same failure shape as the draft retry budget, where one candidate
        // failed 29 times before a cap existed.
        // The count is 'failures SINCE the page last changed', so a repair earns a
        // fresh hearing automatically - framing-repair runs hourly and does fix
        // some of these. Nothing is destroyed; it just stops paying to re-ask.
        try {
            $tries = $pdo->prepare(
                "SELECT COUNT(*) FROM ai_reviews r JOIN pages p ON p.id=r.page_id
                  WHERE r.page_id=? AND r.stage='quality' AND r.passed=0
                    AND r.created_at >= p.updated_at");
            $tries->execute([(int)$h['id']]);
            $failed = (int)$tries->fetchColumn();
            if ($failed >= 6) {
                echo "  HELD-EXHAUSTED {$h['path']}: failed the editor {$failed}x since it last changed - not re-judged until it is repaired\n";
                if (!$dry) cnote("HELD-EXHAUSTED {$h['path']} ({$failed} failures)");
                $retained++;
                continue;
            }
        } catch (Throwable $e) { /* budget is an optimisation, never a blocker */ }

        $twin = dup_redundant_copy($pdo, (int)$h['id']);
        if ($twin) {
            echo "  HELD-DUPLICATE {$h['path']}: {$twin['reason']} as /{$twin['slug']}/ - not re-judged\n";
            if (!$dry) cnote("HELD-DUPLICATE {$h['path']} -> {$twin['slug']}");
            // 2026-08-27 QUEUE JAM. The drain reads the OLDEST review pages
            // first (ORDER BY updated_at ASC, LIMIT slots*2). Duplicates are
            // the oldest rows in there, so every run spent its whole allowance
            // re-reading the same copies and never reached the publishable
            // pages behind them: 86 waiting, 0 published. Recognising a
            // duplicate is right; letting it hold the front of the line for
            // ever is not. Touch it so it goes to the BACK. Nothing is
            // deleted - it just stops blocking real work.
            if (!$dry) {
                $pdo->prepare("UPDATE pages SET updated_at=NOW() WHERE id=?")
                    ->execute([(int)$h['id']]);
            }
            $retained++;
            continue;
        }

        $okPromote = false;
        if ($h['type'] === 'term') {
            require_once __DIR__ . '/gate_quality.php';
            $structural = (gate_check_term((int)$h['id'])['pass'] ?? false);
            // THE QUALITY DEPARTMENT — final gatekeeper. Only CRITICAL issues block
            // (missing sources, invented facts, off-intent, unsafe, duplicate); the
            // soft 0-100 score is advisory and logged, never archives a decent page.
            $q = gate_quality((int)$h['id'], true);
            $okPromote = $structural && empty($q['hard_fails']);
            if ($structural && !empty($q['hard_fails']))
                echo "  QUALITY-HOLD {$h['path']}: score {$q['score']}/100 | " . implode('; ', array_slice($q['hard_fails'], 0, 3)) . "\n";
            elseif ($okPromote && $q['score'] < 80)
                echo "  QUALITY-NOTE {$h['path']}: passed but only {$q['score']}/100\n";
        } elseif ($h['type'] === 'drama') {
            $gg = gate_check_drama((int)$h['id']);
            $qq = quality_check_drama((int)$h['id']);
            $okPromote = ($gg['pass'] ?? false) && ($qq['pass'] ?? false);
        }
        if ($okPromote) {
            // SEO gate: same checks new pages face. Fail = stay held (review),
            // not archived — content gates passed, only rendering needs a fix.
            $seoP = seo_audit_page((int)$h['id']);
            if (!$seoP['pass']) {
                foreach ($seoP['fails'] as $sf) echo "  SEO-GATE-FAIL {$h['path']}: {$sf}\n";
                cnote("SEO-GATE-FAIL {$h['path']}: " . implode('; ', array_slice($seoP['fails'], 0, 3)));
                continue;
            }
            if (!$dry) {
                page_publish_live($pdo, (int)$h['id']);   // SEO-BATCH-1 choke point
                indexnow_ping([rtrim($CONFIG['base_url'], '/') . $h['path']]);
            }
            $slots--; $promoted++;
            echo "  " . ($dry ? 'WOULD-PROMOTE' : 'PROMOTED') . " {$h['path']}\n";
            if (!$dry) cnote("PROMOTED {$h['path']}");
        } else {
            // OWNER DECISION 2026-08-22: STOP DESTROYING PAGES HERE.
            // This branch used to set status='archived' on the FIRST quality
            // failure, and archived pages serve 410 Gone — we were telling
            // Google to permanently forget a story the moment it stumbled.
            // Two reasons that was wrong:
            //  1. The very same pipeline gives a DRAFT seven days of hourly
            //     retries before archiving it. A page that got further was
            //     treated far more harshly than one that got less far.
            //  2. The trigger is a thin-content/SEO judgement (flags like
            //     'padding', 'stale_status'), not a legal or safety one — and
            //     framing repair now runs every hour, so many of these fix
            //     themselves by the next tick if simply left alone.
            // Measured before the change: 5 archived vs 3 promoted at this
            // step — the drain was destroying more than it published.
            // The page now STAYS HELD (review + noindex): not public, not
            // indexed, not deleted. The 7-day sweep is the only thing that
            // may archive, and it applies one consistent rule to every page.
            $retained++;
            echo "  HELD {$h['path']} (quality not met — kept for repair, not archived)\n";
            if (!$dry) cnote("HELD {$h['path']} (quality fail at drain; retained)");
        }
    }
    return ['refused' => false, 'promoted' => $promoted, 'archived' => $archived, 'held' => $retained];
}

/** 2026-09-24: one page builder at a time (the hourly run vs the build worker). */
function build_lock_acquire(): bool {
    if (!empty($GLOBALS['__build_lock'])) return true;   // this run already holds it
    $GLOBALS['__build_lock'] = @fopen(__DIR__ . '/cache/build.lock', 'c');
    if ($GLOBALS['__build_lock'] && flock($GLOBALS['__build_lock'], LOCK_EX | LOCK_NB)) return true;
    if ($GLOBALS['__build_lock']) @fclose($GLOBALS['__build_lock']);
    $GLOBALS['__build_lock'] = null;
    return false;
}
function build_lock_release(): void {
    if (!empty($GLOBALS['__build_lock'])) { @flock($GLOBALS['__build_lock'], LOCK_UN); @fclose($GLOBALS['__build_lock']); }
    $GLOBALS['__build_lock'] = null;
}
switch ($cmd) {
    case 'list':
        $rows = $pdo->query("SELECT id, type, slug, status, robots, updated_at FROM pages ORDER BY type, id")->fetchAll();
        foreach ($rows as $r) {
            printf("%-4d %-8s %-30s %-10s %-8s %s\n", $r['id'], $r['type'], $r['slug'], $r['status'], $r['robots'], $r['updated_at']);
        }
        break;

    case 'gate':
        if (!$arg) exit("usage: gate <slug>\n");
        $id = $pdo->prepare("SELECT id FROM pages WHERE slug=? AND type='drama'");
        $id->execute([$arg]);
        $pid = $id->fetchColumn();
        if (!$pid) exit("no drama page with slug {$arg}\n");
        $res = gate_check_drama((int)$pid);
        echo ($res['pass'] ? "RESULT: PASS" : "RESULT: FAIL") . " | {$arg}\n";
        table($res['checks']);
        break;

    case 'audit':
        $rows = $pdo->query("SELECT p.id, p.slug FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.status='published'")->fetchAll();
        foreach ($rows as $r) {
            $res = gate_check_drama((int)$r['id']);
            $fails = array_filter($res['checks'], fn($c) => !$c['pass']);
            printf("%-35s %s", $r['slug'], $res['pass'] ? "PASS\n" : "FAIL (" . count($fails) . "):\n");
            foreach ($fails as $f) echo "    - {$f['label']}\n";
        }
        break;

    case 'quality':
        if (!$arg) exit("usage: quality <slug>\n");
        require_once __DIR__ . '/quality.php';
        $st = $pdo->prepare("SELECT id FROM pages WHERE slug=? AND type='drama'");
        $st->execute([$arg]);
        $pid = $st->fetchColumn();
        if (!$pid) exit("no drama page with slug {$arg}\n");
        $q = quality_check_drama((int)$pid);
        if (isset($q['error'])) { echo "QUALITY FAILED: {$q['error']}\n"; exit(1); }
        echo ($q['pass'] ? "QUALITY: PASS" : "QUALITY: FAIL") . " (via {$q['provider']})\n";
        echo "  scores: "; foreach ($q['scores'] as $k=>$v) echo "$k=$v "; echo "\n";
        if ($q['flags'])   echo "  red flags: " . implode(', ', $q['flags']) . "\n";
        if ($q['queries']) echo "  target queries: " . implode(' | ', array_slice($q['queries'],0,4)) . "\n";
        foreach ($q['fixes'] as $f) echo "  - fix: $f\n";
        echo "  verdict: {$q['verdict']}\n";
        break;

    case 'publish':
        if (!$arg) exit("usage: publish <slug>\n");
        require_once __DIR__ . '/quality.php';
        $st = $pdo->prepare("SELECT id, path FROM pages WHERE slug=? AND type='drama'");
        $st->execute([$arg]);
        $row = $st->fetch();
        if (!$row) exit("no drama page with slug {$arg}\n");
        $res = gate_check_drama((int)$row['id']);
        if (!$res['pass']) {
            echo "BLOCKED | gate failed:\n";
            table(array_filter($res['checks'], fn($c) => !$c['pass']));
            exit(1);
        }
        $q = quality_check_drama((int)$row['id']);
        if (isset($q['error']) || !$q['pass']) {
            echo "BLOCKED | quality controller " . (isset($q['error']) ? "error: {$q['error']}" : "failed") . "\n";
            if (!empty($q['scores'])) { echo "  scores: "; foreach ($q['scores'] as $k=>$v) echo "$k=$v "; echo "\n"; }
            foreach (($q['fixes'] ?? []) as $f) echo "  - fix: $f\n";
            exit(1);
        }
        echo "quality: PASS (" . implode(' ', array_map(fn($k,$v)=>"$k=$v", array_keys($q['scores']), $q['scores'])) . ")\n";
        $seo = seo_audit_page((int)$row['id']);
        if (!$seo['pass']) {
            echo "BLOCKED | seo gate failed:\n";
            foreach ($seo['fails'] as $sf) echo "  SEO-GATE-FAIL {$arg}: {$sf}\n";
            exit(1);
        }
        echo "seo gate: PASS\n";
        page_publish_live($pdo, (int)$row['id']);   // SEO-BATCH-1 choke point
        echo "robots=index set for {$arg}\n";
        echo "sitemap: " . sitemap_build() . "\n";
        echo indexnow_ping([rtrim($CONFIG['base_url'], '/') . $row['path']]) . "\n";
        break;

    case 'unpublish':
        if (!$arg) exit("usage: unpublish <slug>\n");
        $pdo->prepare("UPDATE pages SET robots='noindex' WHERE slug=?")->execute([$arg]);
        echo "robots=noindex set for {$arg}\n";
        echo "sitemap: " . sitemap_build() . "\n";
        break;

    case 'sitemap':
        echo "sitemap: " . sitemap_build() . "\n";
        break;

    case 'drain':
        // REAL run of the guarded publish path (same guard as the hourly tick:
        // refuses when auto_publish=off, respects the daily cap). First-class
        // operator command — added after the Jul-8..12 stall so unblocking never
        // again needs ad-hoc scripts.
        $cap = (int)($CONFIG['daily_publish_cap'] ?? 12);
        $today = (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE status='published' AND robots='index' AND published_at >= CURDATE()")->fetchColumn();
        $slots = max(0, $cap - $today);
        echo "drain (REAL) | auto_publish=" . (!empty($CONFIG['auto_publish']) ? 'true' : 'false') . " slots={$slots}\n";
        $r = velocity_drain($pdo, $slots, false);
        if (!empty($r['refused'])) { echo "REFUSED — {$r['why']}\n"; break; }
        echo "RESULT: promoted {$r['promoted']}, archived {$r['archived']}\n";
        if ($r['promoted'] > 0) echo "sitemap: " . sitemap_build() . "\n";
        break;

    case 'drain-test':
        // DRY-RUN of the leak path: exercises the REAL velocity_drain guard,
        // never writes. Used to prove the auto_publish gate holds.
        $cap = (int)($CONFIG['daily_publish_cap'] ?? 12);
        $today = (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE status='published' AND robots='index' AND published_at >= CURDATE()")->fetchColumn();
        $slots = max(0, $cap - $today);
        $held = (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE status='review' AND robots='noindex'")->fetchColumn();
        echo "drain-test (DRY RUN) | auto_publish=" . (!empty($CONFIG['auto_publish']) ? 'true' : 'false') . " slots={$slots} held={$held}\n";
        $r = velocity_drain($pdo, $slots, true);
        echo !empty($r['refused'])
            ? "RESULT: REFUSED — {$r['why']} | 0 pages touched\n"
            : "RESULT: would promote {$r['promoted']}, archive {$r['archived']} (dry: nothing written)\n";
        break;

    case 'draft':
        if (!$arg || !is_file($arg)) exit("usage: draft <candidate.json>  (see pipeline/sample-candidate.json)\n");
        require_once __DIR__ . '/draft.php';
        $input = json_decode(file_get_contents($arg), true);
        if (!$input) exit("invalid JSON in {$arg}\n");
        $r = draft_drama($input);
        if (isset($r['error'])) { echo "DRAFT FAILED: {$r['error']}\n" . (isset($r['raw']) ? "raw: {$r['raw']}\n" : ''); exit(1); }
        echo "DRAFTED | page_id={$r['page_id']} slug={$r['slug']} events={$r['events']} via {$r['provider']}\n";
        echo "preview: https://genzhype.com/drama/{$r['slug']}/ (draft = not routed until review/published)\n";
        echo "next: php app/cli.php verify {$r['slug']}\n";
        break;

    case 'verify':
        if (!$arg) exit("usage: verify <slug>\n");
        require_once __DIR__ . '/verify.php';
        $st = $pdo->prepare("SELECT id FROM pages WHERE slug=? AND type='drama'");
        $st->execute([$arg]);
        $pid = $st->fetchColumn();
        if (!$pid) exit("no drama page with slug {$arg}\n");
        $r = verify_drama((int)$pid);
        if (isset($r['error'])) { echo "VERIFY FAILED: {$r['error']}\n"; exit(1); }
        echo ($r['pass'] ? "VERIFY: PASS" : "VERIFY: ISSUES") . " (via {$r['provider']}) | status: {$r['status']}\n";
        foreach ($r['issues'] as $i) {
            printf("  - [%s] event %s: %s\n", $i['type'] ?? '?', $i['event'] ?? '-', $i['detail'] ?? '');
        }
        if ($r['pass']) echo "next: php app/cli.php gate {$arg}  then  publish {$arg}\n";
        break;

    // THE RECORD (organ 02): the joined story of one page/video, in one screen.
    case 'record':
        require_once __DIR__ . '/record.php';
        if (!$arg || !ctype_digit((string)$arg)) { echo "usage: record <page_id>\n"; break; }
        echo record_story($pdo, (int)$arg);
        break;

    case 'record-selftest':
        require_once __DIR__ . '/record.php';
        echo record_selftest($pdo);
        break;

    // ORGAN 05: what the Reflector will actually read, without spending an AI call.
    case 'reflect-dry':
        require_once __DIR__ . '/record.php';
        require_once __DIR__ . '/strategist.php';
        strategist_install($pdo);
        $tr = record_traces($pdo, ($arg && ctype_digit((string)$arg)) ? (int)$arg : 8);
        echo "traces the Reflector would read: " . count($tr) . "

";
        foreach ($tr as $t) {
            echo "  page {$t['page_id']}: \"" . mb_substr((string)($t['hook'] ?? ''), 0, 60) . "\"
";
            echo "    tpl=" . ($t['tpl'] ?? '?') . " shots=" . ($t['shots'] ?? '?')
               . " words=" . ($t['words'] ?? '?') . " status=" . ($t['status'] ?? '?') . "
";
            if (!empty($t['judge'])) {
                echo "    judge: " . (($t['judge']['pass'] ?? false) ? 'PASS' : 'REJECT')
                   . " scores=" . json_encode($t['judge']['scores'] ?? []) . "
";
            }
            if (!empty($t['OWNER_VERDICT'])) {
                echo "    OWNER: " . strtoupper((string)$t['OWNER_VERDICT']['verdict'])
                   . " (" . implode(', ', (array)$t['OWNER_VERDICT']['reasons']) . ") "
                   . (string)$t['OWNER_VERDICT']['note'] . "
";
            }
            if (!empty($t['results'])) echo "    results: " . json_encode($t['results']) . "
";
            if (!empty($t['skipped_why'])) echo "    skipped: {$t['skipped_why']}
";
            echo "
";
        }
        $qs = question_open($pdo, 10);
        echo "open questions: " . count($qs) . "
";
        foreach ($qs as $q) echo "  [{$q['status']}] {$q['question']}
";
        break;

    case 'reflect':
        require_once __DIR__ . '/strategist.php';
        $rep = strategist_run($pdo);
        if (!$rep) { echo "reflect: the brain returned nothing (dead AI chain?) — see ai-health
"; break; }
        echo "== {$rep['date']} ==
{$rep['state_summary']}

";
        foreach ($rep['recommendations'] as $r) {
            echo "  [{$r['type']}] {$r['title']}
    why: {$r['why']}
    evidence: {$r['evidence']}
";
            if (!empty($r['record_ids'])) echo "    from pages: {$r['record_ids']}
";
        }
        foreach (($rep['open_questions'] ?? []) as $q) echo "  [question] {$q['question']} — needs: {$q['needs']}
";
        break;

    case 'questions':
        require_once __DIR__ . '/record.php';
        foreach (question_open($pdo, 40) as $q) {
            echo "#{$q['id']} [{$q['status']}] {$q['question']}
";
            if ($q['needs']) echo "     needs: {$q['needs']}
";
        }
        break;

    // ORGAN 04: what the machine currently believes, and what the writers read.
    case 'memory':
        require_once __DIR__ . '/memory.php';
        $m = memory_all($pdo);
        echo "OWNER-APPROVED DIRECTIVES (" . count($m['owner_directives']) . ")\n";
        foreach ($m['owner_directives'] as $d) {
            echo "  [" . ($d['active'] ? 'on ' : 'OFF') . "] ({$d['surface']}) {$d['directive']}\n";
            echo "        key={$d['key']}  since {$d['since']}\n";
        }
        echo "\nMINED RULES (" . count($m['mined_rules']) . ")\n";
        foreach ($m['mined_rules'] as $r) {
            echo "  [" . ($r['active'] ? 'on ' : 'OFF') . "] {$r['key']}  confidence={$r['confidence']}\n";
        }
        echo "\nWHAT EACH WRITER WOULD READ RIGHT NOW:\n";
        foreach (['hook', 'script', 'director', 'caption'] as $sfc) {
            $b = memory_prompt_block($pdo, $sfc, 0);
            echo "  {$sfc}: " . ($b === '' ? '(nothing yet)' : trim(mb_substr($b, 0, 220)) . '...') . "\n";
        }
        break;

    case 'memory-off':
        require_once __DIR__ . '/memory.php';
        if (!$arg) { echo "usage: memory-off <rule_key>\n"; break; }
        echo memory_deactivate($pdo, (string)$arg) ? "deactivated {$arg}\n" : "failed\n";
        break;

    // ORGAN 11: find dates whose precision the sources never supported.
    // Read-only unless the second arg is the literal word 'apply'.
    case 'inspect-dates':
        require_once __DIR__ . '/inspector.php';
        $apply = (($argv[3] ?? '') === 'apply');
        $n = ($arg && ctype_digit((string)$arg)) ? (int)$arg : 500;
        $r = insp_sweep_dates($pdo, $n, $apply);
        echo "scanned {$r['scanned']} dated events, found {$r['found']} with invented precision"
           . ($apply ? ", FIXED {$r['fixed']}" : " (dry run — add 'apply' to fix)") . "\n";
        foreach ($r['samples'] as $s2) {
            echo "  #{$s2['id']}  {$s2['was']} -> {$s2['now']}  {$s2['title']}\n";
            echo "        {$s2['why']}\n";
        }
        break;

    // ORGAN 10: test a proposal against videos whose real outcome we know,
    // BEFORE the owner is asked to approve it.
    // ---- THE GOVERNOR (organ 13). Is anything still working?
    case 'watch':          // full round, records what it finds
    case 'watch-dry':      // reports only, writes nothing at all
        require_once __DIR__ . '/governor.php';
        $apply = ($cmd === 'watch');
        $r = gov_round($pdo, $apply);
        echo ($apply ? 'governor round' : 'governor DRY round') . ': ' . $r['checks']
           . ' checks in ' . $r['took_ms'] . "ms

";
        if (!$r['findings']) {
            // Law 5: 'nothing wrong' must not look like 'did not run'.
            echo "  all clear - every check ran and found nothing
";
        }
        foreach ($r['findings'] as $f) {
            echo '  [' . strtoupper($f['severity']) . '] ' . $f['title'] . "
";
            if ($f['detail'])   echo '      ' . $f['detail'] . "
";
            if ($f['evidence']) echo '      evidence: ' . $f['evidence'] . "
";
            echo "
";
        }
        foreach ($r['cleared'] as $c) echo "  cleared (stopped happening): {$c}
";
        break;

    case 'watch-selftest':
        require_once __DIR__ . '/governor.php';
        $r = gov_selftest();
        foreach ($r['notes'] as $n) echo $n . "
";
        echo "
  {$r['pass']} passed, {$r['fail']} failed
";
        break;

    case 'alarms':
        require_once __DIR__ . '/governor.php';
        $b = gov_board($pdo);
        echo 'governor last ran: ' . ($b['last_run'] ?: 'NEVER')
           . ($b['stale'] ? '  <- STALE, the watchman itself may be down' : '') . "
";
        echo '  open: ' . $b['open'] . "

";
        foreach ($b['alarms'] as $a)
            echo '  [' . strtoupper($a['severity']) . '] ' . $a['title']
               . ' (seen ' . $a['seen_count'] . "x)
      " . $a['evidence'] . "
";
        break;

    // Retire held pages whose story is ALREADY LIVE on the site. Read-only
    // unless 'apply' is passed. Only ever touches pages that are review+noindex
    // (never public, never indexed, so nothing is lost from search) and that have
    // a twin which is published RIGHT NOW. Prints the undo line.
    case 'retire-dupes':
        require_once __DIR__ . '/dedupe.php';
        $apply = ($arg === 'apply');
        $held = $pdo->query("SELECT id, slug FROM pages
                             WHERE status='review' AND robots='noindex' ORDER BY id")->fetchAll();
        $todo = [];
        foreach ($held as $h) {
            $t = dup_published_twin($pdo, (int)$h['id']);
            if (!$t) continue;
            $c = $pdo->prepare("SELECT status FROM pages WHERE id=?");
            $c->execute([$t['id']]);
            if ($c->fetchColumn() !== 'published') continue;   // twin must be live now
            $todo[] = $h + ['twin' => $t];
        }
        echo ($apply ? "RETIRING\n" : "DRY RUN - nothing changed (add: apply)\n");
        foreach ($todo as $x) {
            echo "  {$x['id']}  {$x['slug']}  -> already live at /{$x['twin']['slug']}/\n";
            if ($apply) {
                $pdo->prepare("UPDATE pages SET status='archived' WHERE id=?")->execute([$x['id']]);
                cnote("RETIRED-DUPLICATE {$x['slug']} -> {$x['twin']['slug']}");
            }
        }
        echo "  " . count($todo) . ($apply ? " retired\n" : " would be retired\n");
        if ($apply && $todo) {
            echo "  UNDO: UPDATE pages SET status='review' WHERE id IN (" . implode(',', array_column($todo, 'id')) . ");\n";
        }
        break;

    // ---- THE OUTSIDE INTAKE (organ 09). What the world is telling us, and
    // exactly how it reaches the writers.
    case 'outside':
        require_once __DIR__ . '/intake.php';
        $f = intake_all($pdo);
        echo count($f) . " readable finding(s)\n\n";
        foreach ($f as $x) {
            printf("  [%-6s] %-18s conf=%-3s surface=%s\n", $x['source'], $x['key'], $x['confidence'], $x['surface']);
            echo "      " . $x['line'] . "\n";
            echo "      evidence: " . $x['evidence'] . "\n\n";
        }
        require_once __DIR__ . '/memory.php';
        foreach (['hook','script','director','caption'] as $sf) {
            $b = memory_prompt_block($pdo, $sf);
            echo "--- what the " . $sf . " writer actually receives ---\n";
            echo ($b === "" ? "  (nothing - prompt unchanged)" : "  " . trim($b)) . "\n\n";
        }
        break;

    // ---- THE PLAYBOOK OF WINS (organ 12). What the machine knows how to DO,
    // with a win rate against our own median.
    case 'playbook':
        require_once __DIR__ . '/playbook.php';
        echo "seeded " . pb_seed($pdo) . " skill(s)\n";
        $w = pb_winrates($pdo);
        echo "our own median views: " . round($w['median']) . "\n\n";
        printf("  %-26s %-12s %4s %8s %9s %8s %s\n", "skill", "kind", "n", "median", "beats-med", "best", "verdict");
        foreach ($w['skills'] as $x) {
            printf("  %-26s %-12s %4d %8s %6d/%-3d %8d %s%s\n",
                $x['label'], $x['kind'], $x['n'], round((float)$x['median']),
                $x['above'], $x['n'], $x['best'], $x['verdict'],
                $x['active'] ? '' : ' (RETIRED)');
        }
        $c = pb_retirement_candidates($pdo);
        echo "\n";
        if (!$c) {
            echo "  nothing to retire: no skill is far enough behind on a big enough sample.\n";
            echo "  (that is the honest answer at this volume, not a failure)\n";
        } else {
            echo "  the evidence would support retiring (your call, not the machine's):\n";
            foreach ($c as $x) echo "    " . $x['label'] . " - " . $x['why'] . "\n";
        }
        break;

    case 'retire-skill':
        require_once __DIR__ . '/playbook.php';
        if (!$arg) { echo "usage: retire-skill <skill_key>\n"; break; }
        echo pb_retire($pdo, (string)$arg, "retired by the owner") ? "retired {$arg}\n" : "no active skill {$arg}\n";
        break;

    // ---- THE EXPERIMENT DESK (organ 06).
    case 'experiments':
        require_once __DIR__ . '/experiment.php';
        $b = xp_board($pdo);
        if (!$b) { echo "no experiments yet.\n"; break; }
        foreach ($b as $x) {
            printf("  #%-3s [%-8s] %-9s %s\n", $x['id'], $x['status'], $x['surface'], $x['name']);
            $r = $x['result'];
            printf("       %-22s control n=%d median=%s | variant n=%d median=%s\n",
                $r['verdict'], $r['control']['n'] ?? 0, round((float)($r['control']['median'] ?? 0)),
                $r['variant']['n'] ?? 0, round((float)($r['variant']['median'] ?? 0)));
            echo "       " . $r['why'] . "\n\n";
        }
        break;

    case 'xp-start':
        require_once __DIR__ . '/experiment.php';
        $r = xp_start($pdo, (int)$arg);
        echo ($r['ok'] ? "ok: " : "refused: ") . $r['why'] . "\n";
        break;

    case 'xp-stop':
        require_once __DIR__ . '/experiment.php';
        echo xp_stop($pdo, (int)$arg, "stopped by the owner") ? "stopped #{$arg}\n" : "not running\n";
        break;

    // ---- THE EYES (organ 03). Does the machine judge agree with the owner?
    case 'eyes':
        require_once __DIR__ . '/eyes.php';
        $a = eyes_agreement($pdo);
        echo "judge calibration: " . $a['state'] . "\n";
        echo "  compared on   : " . $a['n'] . " video(s)\n";
        if ($a['n']) {
            echo "  agrees        : " . $a['agree'] . "/" . $a['n'] . " (" . round((float)$a['rate'] * 100) . "%)\n";
            echo "  MISSED (passed what he rejected): " . $a['missed'] . "  <- the dangerous direction\n";
            echo "  harsh  (blocked what he liked)  : " . $a['harsh'] . "\n";
        }
        echo "  " . $a['why'] . "\n\n";
        $sh = eyes_shots($pdo);
        echo "worked examples ready for the judge: " . count($sh) . "\n";
        foreach ($sh as $s) {
            printf("  %-42s he said %-5s machine %s\n", mb_substr((string)$s['ref'],0,42),
                $s['owner'], $s['machine'] === null ? '-' : $s['machine']);
        }
        $blk = eyes_calibration_block($pdo);
        echo "\nwhat the judge would be shown:\n";
        echo ($blk === "" ? "  (nothing - the judge runs exactly as before)" : "  " . trim($blk)) . "\n";
        break;

    case 'prove':
        require_once __DIR__ . '/proving.php';
        if ($arg && ctype_digit((string)$arg)) {
            $r = pg_prove_reco($pdo, (int)$arg);
            if (!$r) { echo "no reco {$arg}\n"; break; }
            echo "#{$r['id']} {$r['title']}\n  => {$r['verdict']} ({$r['confidence']}% confident, tested on {$r['tested_on']} videos)\n";
            echo "  {$r['why']}\n" . ($r['detail'] ? "  evidence: {$r['detail']}\n" : '');
            break;
        }
        foreach (pg_prove_pending($pdo, 5) as $r) {
            echo "#{$r['id']} [{$r['verdict']}] {$r['title']}\n    {$r['why']}\n";
        }
        break;

    case 'golden':
        require_once __DIR__ . '/proving.php';
        $g = pg_cases($pdo);
        echo "golden set: " . count($g['cases']) . " posted videos with real numbers, own median {$g['median']} views\n\n";
        foreach (array_slice($g['cases'], 0, 8) as $c)
            printf("  %5d  %-14s %s\n", $c['views'], $c['label'], mb_substr((string)$c['hook'], 0, 52));
        echo "  ...\n";
        foreach (array_slice($g['cases'], -5) as $c)
            printf("  %5d  %-14s %s\n", $c['views'], $c['label'], mb_substr((string)$c['hook'], 0, 52));
        break;

    case 'record-backfill':
        require_once __DIR__ . '/record.php';
        $n = record_backfill($pdo, ($arg && ctype_digit((string)$arg)) ? (int)$arg : 30);
        echo "record-backfill: {$n['pages']} page(s), {$n['videos']} video(s) seeded\n";
        break;

    case 'desk': {
        // desk install | desk judge [cap] | desk report [n] | desk live | desk shadow
        require_once __DIR__ . '/desk.php';
        $pdo = db();
        $sub = $argv[2] ?? 'report';
        if ($sub === 'install') { desk_install($pdo); echo "desk tables ready\n"; break; }
        if ($sub === 'live')    { touch(DESK_LIVE_FLAG); echo "desk is LIVE (routing to candidates)\n"; break; }
        if ($sub === 'shadow')  { @unlink(DESK_LIVE_FLAG); echo "desk is in SHADOW mode (log only)\n"; break; }
        if ($sub === 'judge')   { print_r(desk_judge_run($pdo, (int)($argv[3] ?? 30), 240)); break; }
        $r = desk_report($pdo, (int)($argv[3] ?? 40));
        echo "agree={$r['agree']} DISAGREE={$r['disagree']} door_never_saw={$r['door_never_saw']} desk_dropped={$r['desk_dropped']}\n";
        foreach ($r['rows'] as $x) printf("  #%-5d %-46s desk=%-32s door=%-40s %s | %s\n", $x['id'], mb_substr($x['name'], 0, 46), $x['desk'], mb_substr($x['door'], 0, 40), $x['verdict'], $x['why']);
        break;
    }
    case 'brain': {
        // brain run [force] | shadow | live | off | levers | set <key> <value> | report | requests | measure
        require_once __DIR__ . '/brain.php';
        $pdo = db(); brain_install($pdo);
        $sub = $argv[2] ?? 'report';
        if ($sub === 'run')     { print_r(brain_run($pdo, ($argv[3] ?? '') === 'force')); break; }
        if ($sub === 'measure') { print_r(brain_measure($pdo)); break; }
        if ($sub === 'shadow')  { @unlink(BRAIN_OFF_FLAG); @unlink(BRAIN_LIVE_FLAG); echo "brain: SHADOW\n"; break; }
        if ($sub === 'live')    { @unlink(BRAIN_OFF_FLAG); touch(BRAIN_LIVE_FLAG); echo "brain: LIVE\n"; break; }
        if ($sub === 'off')     { touch(BRAIN_OFF_FLAG); echo "brain: OFF\n"; break; }
        if ($sub === 'levers')  { foreach (brain_levers_all($pdo) as $k => $v) printf("  %-22s %s\n", $k, $v); echo brain_levers_env(); break; }
        if ($sub === 'set')     { $v = brain_set_lever($pdo, (string)($argv[3] ?? ''), (float)($argv[4] ?? 0), 'owner', 'set from the CLI'); echo $v === null ? "refused (unknown lever)\n" : "set {$argv[3]} = $v\n"; break; }
        $r = brain_report($pdo);
        echo "mode: {$r['mode']}\n";
        foreach ($r['actions'] as $a) printf("  %s %-6s %-10s %-20s %s → %s  [%s] %s\n", substr($a['at'], 0, 16), $a['mode'], $a['kind'], (string)$a['lever_key'], (string)$a['before_val'], (string)$a['after_val'], $a['outcome'], mb_substr($a['why'], 0, 90));
        foreach ($r['requests'] as $q) printf("  REQUEST [%s] %s — %s\n", $q['status'], $q['title'], mb_substr($q['why'], 0, 100));
        break;
    }
    case 'vision': {
        // vision measure <file> | ours [n] | learn | summary
        require_once __DIR__ . '/video_eyes.php';
        $pdo = db();
        $sub = $argv[2] ?? 'summary';
        if ($sub === 'measure') { print_r(eyes_measure((string)($argv[3] ?? ''))); break; }
        if ($sub === 'ours')    { echo 'measured ' . eyes_backfill_ours($pdo, (int)($argv[3] ?? 3)) . " of ours\n"; break; }
        if ($sub === 'reconcile') { print_r(eyes_reconcile($pdo, (int)($argv[3] ?? 120), (int)($argv[4] ?? 5))); break; }
        if ($sub === 'verdict')  {
            $pid = (int)($argv[3] ?? 0);
            $vp = (string)$pdo->query("SELECT video_path FROM video_scripts WHERE page_id=" . $pid)->fetchColumn();
            $file = $vp ? dirname(__DIR__) . '/public_html' . $vp : '';
            if (!$file || !is_file($file)) { echo "no video file for page $pid\n"; break; }
            $r = eyes_measure_ours($pdo, $pid, $file);
            if (!$r) { echo "could not measure\n"; break; }
            printf("page %d: %s\n  moving footage %d%% | stills %d%% | cuts per minute %s | %.0fs long\n  %d distinct pictures across %d sampled seconds | worst picture comes back %dx | longest single hold %ds\n  VERDICT: %s - %s\n",
                   $pid, (string)$pdo->query("SELECT h1 FROM pages WHERE id=" . $pid)->fetchColumn(),
                   round($r['live_ratio'] * 100), round($r['still_ratio'] * 100), $r['cuts_per_min'], $r['duration_s'],
                   $r['pictures'], $r['sampled'], $r['worst_repeat'], $r['longest_hold'], strtoupper($r['verdict']), $r['note']);
            break;
        }
        if ($sub === 'rivals')  { echo 'measured ' . eyes_backfill_rivals($pdo, (int)($argv[3] ?? 6)) . " rival clip(s)\n"; break; }
        if ($sub === 'label')   {   // vision label <page_id> good|ok|bad
            $ok = eyes_label_set($pdo, (int)($argv[3] ?? 0), (string)($argv[4] ?? ''));
            echo $ok ? "labelled\n" : "usage: vision label <page_id> good|ok|bad\n"; break;
        }
        if ($sub === 'calib')   {   // what the owner's verdicts taught the judge
            $c = eyes_calibrate($pdo);
            printf("labelled=%d (good %d / bad %d)  ready=%s\n", $c['labelled'], $c['good'], $c['bad'], $c['ready'] ? 'YES' : 'no');
            foreach ($c['metrics'] as $k => $m) printf("  %-13s bad when %s %-7s  agrees %d%%  J=%.2f  misses=%d\n", $k, $m['dir'] === 'high' ? '>=' : '<=', $m['threshold'], round($m['acc'] * 100), $m['j'], $m['misses']);
            echo '  ' . $c['note'] . "\n"; break;
        }
        if ($sub === 'learn')   { print_r(eyes_learn($pdo)); break; }
        $s = eyes_summary($pdo);
        foreach ($s['by_kind'] as $r) printf("  %-6s n=%-3d live=%-5s cuts/min=%-5s motion=%s\n", $r['kind'], $r['n'], $r['live'], $r['cpm'], $r['yd']);
        if ($s['rule']) echo "  RULE: " . $s['rule']['value']['read'] . "  (conf " . $s['rule']['confidence'] . ", " . $s['rule']['evidence'] . ")\n";
        foreach ($s['top'] as $t) printf("  rival %-16s plays=%-9d live=%-5s cuts/min=%-5s %s\n", '@' . $t['author'], $t['plays'], $t['live_ratio'], $t['cuts_per_min'], mb_substr($t['title'], 0, 40));
        foreach ($s['ours'] as $t) printf("  ours  p%-5d live=%-5s cuts/min=%-5s %ss %s\n", $t['page_id'], $t['live_ratio'], $t['cuts_per_min'], $t['duration_s'], mb_substr((string)$t['title'], 0, 40));
        break;
    }
    case 'clips': {
        // clips probe | replan [n] | plan <page_id> | stats | fetch <url> [start]
        require_once __DIR__ . '/clip_supply.php';
        require_once __DIR__ . '/clip_fetch.php';
        $pdo = db();
        $sub = $argv[2] ?? 'stats';
        if ($sub === 'probe')  { print_r(clip_probe_routes($pdo)); break; }
        if ($sub === 'replan') { [$c, $p, $g] = clip_replan($pdo, (int)($argv[3] ?? 3), (int)($argv[4] ?? 600)); echo "replanned $c script(s), $g gained footage, $p fetchable clip(s) planned\n"; break; }
        if ($sub === 'plan')   { require_once __DIR__ . '/fetch_sources.php'; $did = (int)$pdo->query("SELECT id FROM dramas WHERE page_id=" . (int)($argv[3] ?? 0))->fetchColumn(); foreach (footage_clips_gather($did) as $c) printf("  %-8s start=%-3d %s\n", $c['platform'], $c['start'], $c['url']); break; }
        if ($sub === 'fetch')  { $p = cf_fetch((string)($argv[3] ?? ''), (int)($argv[4] ?? 0)); echo $p ? "$p (" . round(filesize($p) / 1048576, 1) . " MB)\n" : "no clip\n"; break; }
        if ($sub === 'hunt')   { $did = (int)$pdo->query("SELECT id FROM dramas WHERE page_id=" . (int)($argv[3] ?? 0))->fetchColumn(); foreach (clip_hunt_tiktok($pdo, $did, (int)($argv[4] ?? 4)) as $c) printf("  %-8s %-5s %s  %s\n", $c['platform'], !empty($c['hunted']) ? 'hunt' : 'src', $c['url'], mb_substr((string)($c['title'] ?? ''), 0, 50)); break; }
        $st = clip_supply_stats($pdo, 7);
        echo "ROUTES (daily probe):\n"; foreach ($st['probe'] as $r) printf("  %-10s %-4s %s (%s)\n", $r['route'], $r['ok'] ? 'ok' : 'DOWN', $r['detail'], $r['probed_at']);
        echo "FETCHES 7d:\n"; foreach ($st['fetch'] as $r) printf("  %-10s tries=%-3d ok=%-3d %s MB  last %s\n", $r['platform'], $r['tries'], $r['ok'], $r['mb'], $r['last']);
        echo "STAGED 7d:\n"; foreach ($st['stage'] as $r) printf("  %-10s planned=%-3d staged=%-3d %s MB across %s stories\n", $r['platform'], $r['planned'], $r['staged'], $r['mb'], $r['stories']);
        echo "LAST ERRORS:\n"; foreach ($st['last_errors'] as $r) printf("  %s %-8s %-18s %s  %s\n", $r['at'], $r['platform'], $r['route'], $r['error'], $r['url']);
        break;
    }
    case 'rolodex': {
        // rolodex install | discover | mentions | match <page_id> | note <person_id> <page_id> [FirstName] | list
        require_once __DIR__ . '/rolodex.php';
        $pdo = db();
        $sub = $argv[2] ?? 'list';
        if ($sub === 'install')  { rolodex_install($pdo); echo "rolodex tables ready\n"; break; }
        if ($sub === 'discover') { print_r(rolodex_discover($pdo, 220, 4)); break; }
        if ($sub === 'mentions') { print_r(rolodex_mentions($pdo)); break; }
        if ($sub === 'match')    { foreach (rolodex_match($pdo, (int)($argv[3] ?? 0)) as $p) printf("  %-3d #%-4d %-28s %-34s %s\n", $p['score'], $p['id'], mb_substr($p['name'] ?: '(no name)', 0, 28), mb_substr($p['outlet'], 0, 34), $p['status']); break; }
        if ($sub === 'note')     { $n = rolodex_note($pdo, (int)($argv[3] ?? 0), (int)($argv[4] ?? 0), (string)($argv[5] ?? '')); echo $n ? "SUBJECT: {$n['subject']}\n\n{$n['body']}\n" : "no note\n"; break; }
        foreach ($pdo->query("SELECT id,name,outlet,kind,status,email,contact_route,evidence_date FROM rolodex_people ORDER BY FIELD(status,'linked','replied','contacted','verified','new','dead'), last_seen DESC LIMIT 60") as $p)
            printf("  #%-4d %-9s %-10s %-24s %-30s %-28s %s\n", $p['id'], $p['status'], $p['kind'], mb_substr($p['name'] ?: '(no name)', 0, 24), mb_substr($p['outlet'], 0, 30), mb_substr($p['email'] ?: $p['contact_route'], 0, 28), $p['evidence_date'] ?? '');
        break;
    }
    case 'backup': {
        // Nightly DB dump (hPanel cron): keeps 7, gzipped, ~/backups/genzhype/.
        // shell_exec is disabled on this host; proc_open works (measured).
        $d = $GLOBALS['CONFIG']['db'];
        $dir = '/home/u219414635/backups/genzhype';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $out = $dir . '/db-' . date('Ymd-Hi') . '.sql.gz';
        $cmd = ['/usr/bin/mysqldump', '--single-transaction', '--quick', '-h', $d['host'], '-u', $d['user'], '-p' . $d['pass'], $d['name']];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) { echo "backup: proc_open failed\n"; break; }
        $gz = gzopen($out, 'wb6'); $bytes = 0;
        while (!feof($pipes[1])) { $chunk = fread($pipes[1], 1 << 16); if ($chunk === '' || $chunk === false) continue; gzwrite($gz, $chunk); $bytes += strlen($chunk); }
        gzclose($gz); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $rc = proc_close($p);
        if ($rc !== 0 || $bytes < 1000) { @unlink($out); echo "backup FAILED rc=$rc: " . trim($err) . "\n"; break; }
        $keep = glob($dir . '/db-*.sql.gz'); rsort($keep);
        foreach (array_slice($keep, 7) as $old) @unlink($old);
        echo "backup ok: $out (" . round($bytes / 1048576, 1) . " MB raw, " . round(filesize($out) / 1048576, 1) . " MB gz), keeping " . min(7, count($keep)) . "\n";
        break;
    }
    case 'candidates':
        $st = $arg ?: 'new';
        $rows = $pdo->prepare("SELECT id, heat_score, status, LEFT(name,68) name, reject_reason FROM candidates WHERE status=? ORDER BY heat_score DESC, id DESC LIMIT 40");
        $rows->execute([$st]);
        $r = $rows->fetchAll();
        if (!$r) { echo "no candidates with status '{$st}'\n"; break; }
        foreach ($r as $c) {
            printf("%-4d h%-3d %-9s %-68s %s\n", $c['id'], $c['heat_score'], $c['status'], $c['name'], $c['reject_reason'] ? "(reject: {$c['reject_reason']})" : '');
        }
        printf("-- %d candidates [status=%s]  (usage: candidates new|selected|rejected)\n", count($r), $st);
        break;

    case 'select':
        require_once __DIR__ . '/select.php';
        if ($arg && ctype_digit($arg)) {
            $res = select_candidate((int)$arg);
            if (isset($res['error'])) { echo "ERROR: {$res['error']}\n"; exit(1); }
            if (isset($res['skip']))  { echo "SKIP: {$res['skip']}\n"; break; }
            echo ($res['build'] ? "SELECTED" : "REJECTED") . " (via {$res['provider']}) | {$res['reason']}\n";
            if ($res['build']) { echo "  angle:  {$res['angle']}\n  people: " . implode(', ', $res['people']) . "\n  query:  {$res['query']}\n"; }
        } else {
            $lim = ($arg && ctype_digit((string)$arg)) ? (int)$arg : 25;
            $res = select_run($lim);
            echo "SELECT RUN | selected={$res['selected']} rejected={$res['rejected']} errors={$res['errors']}\n";
            foreach ($res['detail'] as $d) {
                printf("  [%s] #%d %s\n", $d['build'] ? 'BUILD ' : 'reject', $d['id'], $d['build'] ? $d['angle'] : $d['reason']);
            }
        }
        break;

    case 'pipeline':
        if (!$arg || !is_file($arg)) exit("usage: pipeline <candidate.json>  | runs draft -> verify -> gate\n");
        require_once __DIR__ . '/draft.php';
        require_once __DIR__ . '/verify.php';
        $input = json_decode(file_get_contents($arg), true);
        if (!$input) exit("invalid JSON in {$arg}\n");
        $r = draft_drama($input);
        if (isset($r['error'])) { echo "DRAFT FAILED: {$r['error']}\n"; exit(1); }
        echo "1/3 DRAFTED  | {$r['slug']} ({$r['events']} events, via {$r['provider']})\n";
        $v = verify_drama((int)$r['page_id']);
        if (isset($v['error'])) { echo "VERIFY FAILED: {$v['error']}\n"; exit(1); }
        echo "2/3 VERIFY   | " . ($v['pass'] ? 'PASS' : 'ISSUES') . " (via {$v['provider']})\n";
        foreach ($v['issues'] as $i) printf("      - [%s] event %s: %s\n", $i['type'] ?? '?', $i['event'] ?? '-', $i['detail'] ?? '');
        $g = gate_check_drama((int)$r['page_id']);
        echo "3/3 GATE     | " . ($g['pass'] ? 'PASS  -> ready: php app/cli.php publish ' . $r['slug'] : 'FAIL:') . "\n";
        foreach (array_filter($g['checks'], fn($c) => !$c['pass']) as $f) echo "      - {$f['label']}\n";
        break;

    case 'fetch':
        if (!$arg || !ctype_digit($arg)) exit("usage: fetch <candidate_id>\n");
        require_once __DIR__ . '/fetch_sources.php';
        $r = fetch_sources_for_candidate((int)$arg);
        if (isset($r['error'])) { echo "FETCH FAILED: {$r['error']}\n"; exit(1); }
        echo "fetched " . count($r['sources']) . " sources for: {$r['topic']}\n";
        foreach ($r['sources'] as $s) printf("  - %s (%d chars)\n", $s['publisher'], mb_strlen($s['excerpt']));
        break;

    case 'auto':
        // full chain for ONE selected candidate: fetch -> draft -> verify -> gate
        if (!$arg || !ctype_digit($arg)) exit("usage: auto <candidate_id>  (must be 'selected')\n");
        require_once __DIR__ . '/fetch_sources.php';
        require_once __DIR__ . '/draft.php';
        require_once __DIR__ . '/verify.php';
        $cid = (int)$arg;
        echo "0/4 FETCH    | ";
        $src = fetch_sources_for_candidate($cid);
        if (isset($src['error'])) { echo "FAILED: {$src['error']}\n"; $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason=? WHERE id=?")->execute(['no sources fetched', $cid]); exit(1); }
        echo count($src['sources']) . " sources\n";
        echo "1/4 DRAFT    | ";
        $d = draft_drama($src);
        if (isset($d['error'])) { echo "FAILED: {$d['error']}\n"; exit(1); }
        echo "{$d['slug']} ({$d['events']} events, via {$d['provider']})\n";
        echo "2/4 VERIFY   | ";
        $v = verify_drama((int)$d['page_id']);
        echo (isset($v['error']) ? "FAILED: {$v['error']}" : ($v['pass'] ? 'PASS' : 'ISSUES (' . count($v['issues']) . ')')) . "\n";
        foreach (($v['issues'] ?? []) as $i) printf("      - [%s] event %s: %s\n", $i['type'] ?? '?', $i['event'] ?? '-', $i['detail'] ?? '');
        echo "3/4 GATE     | ";
        $g = gate_check_drama((int)$d['page_id']);
        echo ($g['pass'] ? "PASS" : "FAIL") . "\n";
        foreach (array_filter($g['checks'], fn($c) => !$c['pass']) as $f) echo "      - {$f['label']}\n";
        // mark candidate built; link page
        $pdo->prepare("UPDATE candidates SET status=? WHERE id=?")->execute([$g['pass'] && ($v['pass'] ?? false) ? 'selected' : 'selected', $cid]);
        echo "4/4 RESULT   | page status: " . ($pdo->query("SELECT status FROM pages WHERE id=" . (int)$d['page_id'])->fetchColumn());
        echo ($g['pass'] && ($v['pass'] ?? false)) ? "  -> READY: php app/cli.php publish {$d['slug']}\n" : "  -> needs review before publish\n";
        break;

    case 'storyposts': {
        // r152 "Posts about this story": the owner-approved X posts on story pages.
        //   storyposts                            list what is showing / waiting / hidden
        //   storyposts refresh [page_id|all]      queue new finds, re-check approved posts on X
        //   storyposts approve|hide|pending <page_id> <tweet_id> [reason]
        require_once __DIR__ . '/story_posts.php';
        $sub = (string)($argv[2] ?? 'list');
        if ($sub === 'refresh') {
            $target = (string)($argv[3] ?? 'all');
            $r = $target === 'all' ? sp_refresh_all($pdo, 600) : sp_refresh_page($pdo, (int)$target);
            echo json_encode($r) . "\n";
            break;
        }
        if (in_array($sub, ['approve', 'hide', 'pending'], true)) {
            $to = ['approve' => 'approved', 'hide' => 'hidden', 'pending' => 'pending'][$sub];
            [$ok, $msg] = sp_set_status($pdo, (int)($argv[3] ?? 0), (string)($argv[4] ?? ''), $to, 'owner', (string)($argv[5] ?? ''));
            echo ($ok ? '' : 'NOT DONE: ') . $msg . "\n";
            break;
        }
        $c = sp_counts($pdo);
        echo "story posts: {$c['approved']} showing, {$c['pending']} waiting for approval, {$c['hidden']} hidden\n";
        foreach ($pdo->query("SELECT page_id, tweet_id, handle, likes, status, decided_by, reason FROM story_posts ORDER BY FIELD(status,'pending','approved','hidden'), page_id, likes DESC") as $r) {
            printf("  %-8s p%-5d %-20s @%-16s likes=%-6s %s\n", $r['status'], $r['page_id'], $r['tweet_id'], $r['handle'], $r['likes'] ?? '?', $r['decided_by'] !== '' ? "[{$r['decided_by']}] {$r['reason']}" : '');
        }
        break;
    }
    case 'proofs': {
        // r155 proof screenshots (owner 2026-09-11): the runner captures each cited source,
        // api/proofingest.php queues it, this safety-checks and publishes it.
        //   proofs               counts: cited source URLs, live, pending, failed, gave up
        //   proofs promote [n]   safety-check and publish up to n pending screenshots (default 6)
        if (!is_file(__DIR__ . '/proofs_engine.php')) { echo "proofs engine not installed yet\n"; break; }
        require_once __DIR__ . '/proofs_engine.php';
        //   proofs export <dir> [n]  write <dir>/jobs.json for the runner (proof-feed branch, git bus)
        //   proofs ingest-dir <dir>  take the runner's captures from a proof-drop checkout
        $sub = (string)($argv[2] ?? 'stats');
        if ($sub === 'promote') {
            echo json_encode(proofs_promote($pdo, max(1, min(50, (int)($argv[3] ?? 6))), 600)) . "\n";
            break;
        }
        if ($sub === 'export' || $sub === 'ingest-dir') {
            // r155 GIT BUS: the host firewall answers GitHub runner IPs with a 403 page, so jobs and captures
            // travel as files on the proof-feed / proof-drop branches (~/genzhype-proofs-bridge.sh).
            $dir = (string)($argv[3] ?? '');
            if ($dir === '' || !is_dir($dir)) { echo "usage: proofs {$sub} <existing dir>\n"; break; }
            if ($sub === 'ingest-dir') { echo json_encode(proofs_ingest_dir($pdo, $dir)) . "\n"; break; }
            $q = proofs_queue($pdo, max(1, min(40, (int)($argv[4] ?? 25))));
            file_put_contents(rtrim($dir, '/') . '/jobs.json', json_encode($q, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            echo 'proof jobs exported: ' . count($q['jobs'] ?? []) . ' (queue remaining ' . (int)($q['remaining'] ?? 0) . ")\n";
            break;
        }
        echo json_encode(proofs_stats($pdo)) . "\n";
        break;
    }
    case 'embeds':
        // backfill real embeds for a drama's social-post sources
        if (!$arg) exit("usage: embeds <slug>\n");
        require_once __DIR__ . '/embeds.php';
        $st = $pdo->prepare("SELECT d.id FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.slug=?");
        $st->execute([$arg]);
        $did = $st->fetchColumn();
        if (!$did) exit("no drama with slug {$arg}\n");
        $r = embeds_build_for_drama((int)$did);
        echo "embeds built: {$r['embeds']} | not embeddable (articles etc): {$r['not_embeddable']}\n";
        break;

    case 'embeds-articles': {
        // r157 real posts from the cited articles (owner 2026-09-11, see embeds_from_cited_articles):
        //   embeds-articles <slug> | recent <N> | since <YYYY-MM-DD>
        require_once __DIR__ . '/embeds.php';
        $mode = (string)($argv[2] ?? '');
        $base = "SELECT d.id, p.slug FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.status='published' AND p.type='drama'";
        if ($mode === 'recent') {
            $rows = $pdo->query($base . " ORDER BY p.published_at DESC LIMIT " . max(1, min(1000, (int)($argv[3] ?? 20))))->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($mode === 'since') {
            $q = $pdo->prepare($base . " AND p.published_at >= ? ORDER BY p.published_at DESC");
            $q->execute([(string)($argv[3] ?? date('Y-m-01'))]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($mode !== '') {
            $q = $pdo->prepare($base . " AND p.slug=?");
            $q->execute([$mode]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } else { echo "usage: embeds-articles <slug> | recent <N> | since <YYYY-MM-DD>\n"; break; }
        $tot = ['stories' => 0, 'stories_with_new_posts' => 0, 'posts_added' => 0];
        foreach ($rows as $row) {
            $r = embeds_from_cited_articles((int)$row['id']);
            $tot['stories']++;
            $tot['posts_added'] += $r['embeds'];
            if ($r['embeds']) { $tot['stories_with_new_posts']++; echo "  {$row['slug']}: +{$r['embeds']} post(s)\n"; }
            $pdo = db_alive();   // embed calls are network requests; keep the handle fresh
        }
        // The page cache version ignores embed changes (repo_data_version counts pages/ids, not embed_html),
        // so mark the cache stale: the next page view rebuilds it once (about 2 s). No page data changes.
        $cf = __DIR__ . '/cache/data.cache';
        if ($tot['posts_added'] > 0 && is_file($cf)) {
            $raw = (string)file_get_contents($cf);
            $nl = strpos($raw, "\n");
            if ($nl !== false && file_put_contents($cf . '.r157tmp', 'stale-' . time() . substr($raw, $nl)) !== false) rename($cf . '.r157tmp', $cf);
        }
        echo json_encode($tot) . "\n";
        break;
    }

    case 'top3':
        // 2026-09-25 the top-3 test for one story: "cli.php top3 <page id> [preview|fill]"
        // preview/fill: the original posts the top 3 show and we do not, as timeline events (step 3)
        if (!$arg || !ctype_digit($arg)) exit("usage: top3 <page id> [preview|fill]\n");
        require_once __DIR__ . '/top3.php';
        $mode = $argv[3] ?? '';
        if ($mode === 'preview' || $mode === 'fill') {
            $f = top3_add_missing_posts($pdo, (int)$arg, 4, $mode === 'fill');
            if (isset($f['error'])) echo "error: {$f['error']}\n";
            if (!empty($f['model'])) echo "written by: {$f['model']}\n";
            foreach ($f['would_add'] ?? [] as $w) echo "would add {$w['date']}: {$w['title']}\n    {$w['desc']}\n    {$w['url']} (shown by {$w['from']})\n";
            foreach ($f['would_attach'] ?? [] as $w) echo "would show {$w['url']} on our timeline event {$w['event']} (shown by {$w['from']})\n";
            if ($mode === 'fill') echo "added {$f['added']} post event(s), attached {$f['attached']} post(s) to events we had\n";
            foreach ($f['skipped'] as $sk) echo "skipped: {$sk}\n";
            if ($mode === 'preview' || !($f['added'] || $f['attached'])) break;
        }
        $r = top3_check($pdo, (int)$arg);
        if (isset($r['error'])) { echo "not checked: {$r['error']}\n"; break; }
        echo "query: {$r['query']}\n";
        foreach ($r['rivals'] as $x) echo "  vs {$x['host']} ({$x['read']}, {$x['chars']} chars)\n";
        echo "ours: {$r['our_dates']} dated developments, {$r['our_posts']} original posts\n";
        echo "new to the reader: " . count($r['new_dates']) . " dated development(s), {$r['new_posts']} post(s); the top 3 show {$r['missing_posts']} post(s) we do not\n";
        foreach ($r['new_dates'] as $n) echo "  new: {$n['date']} {$n['title']}\n";
        foreach ($r['covered_by'] as $c) echo "  already reported: {$c['event']} ({$c['host']}: \"" . mb_substr($c['quote'], 0, 120) . "\")\n";
        echo 'timeline strong: ' . ($r['timeline_strong'] ? 'yes' : 'no') . ($r['verified'] ? '' : ' (unverified: the AI reading did not run)') . "\n";
        break;

    case 'ov-report':
        // 2026-09-25 the owner's rule (1 strong or 2 weak) over live stories; see gate_original_value()
        require_once __DIR__ . '/gate.php';
        $rows = $pdo->query("SELECT d.id did, p.robots FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.type='drama' AND p.status='published'")->fetchAll(PDO::FETCH_ASSOC);
        $per = []; $pass = 0; $withReceipts = 0; $failIdx = 0;
        foreach ($rows as $r) {
            $ov = gate_original_value($pdo, (int)$r['did']);
            foreach (array_merge($ov['strong'], $ov['weak']) as $k) $per[$k] = ($per[$k] ?? 0) + 1;
            if ($ov['receipts'] > 0) $withReceipts++;
            if ($ov['pass']) $pass++; elseif ($r['robots'] === 'index') $failIdx++;
        }
        echo count($rows) . " live stories: {$pass} meet the rule, " . (count($rows) - $pass) . " do not ({$failIdx} of those in Google)\n";
        foreach (['numbers', 'timeline', 'confirmed_split', 'both_sides', 'verdict'] as $k) printf("  %-16s %d\n", $k, $per[$k] ?? 0);
        echo "  (original posts or primary sources on the timeline: {$withReceipts}; 'timeline' needs the top-3 comparison)\n";
        break;

    case 'status-refresh':
        // 2026-09-24 stories whose timeline is newer than their summary. "status-refresh 5" or "status-refresh 5 dry"
        require_once __DIR__ . '/status_refresh.php';
        $lim = ($arg && ctype_digit($arg)) ? (int)$arg : 4;
        $sr = sr_run($pdo, $lim, 270, ($argv[3] ?? '') !== 'dry');
        echo "status refresh: checked={$sr['checked']} refreshed={$sr['refreshed']} unchanged={$sr['unchanged']} kept-old={$sr['refused']} passed-editor={$sr['passed_editor']}\n";
        break;

    case 'imgbackfill':
        require_once __DIR__ . '/drama_image.php';
        $lim = ($arg && ctype_digit($arg)) ? (int)$arg : 5;
        $ib = drama_image_backfill_v2($pdo, $lim);   // 2026-09-24: cover policy v2
        echo "covers v2: tried={$ib['tried']} photo={$ib['photo']} back-to-card={$ib['back_to_card']} retry={$ib['retry']} (" . implode(', ', $ib['pages']) . ")\n";
        break;

    case 'linkaudit':
        // Outbound-link health check (lychee stand-in). --fix-dead blanks dead term links.
        require_once __DIR__ . '/monitor.php';
        $lr = monitor_links($pdo, 280, $arg === '--fix-dead');
        echo "done: {$lr['ok']} ok, {$lr['broken']} broken, {$lr['redirect']} redirect" . ($lr['complete'] ? '' : ' (partial — rerun)') . "\n";
        break;

    case 'snapshot':
        // r93 ARCHIVE AT CAPTURE, catch-up half. New sources are archived at
        // write time by draft.php / fetch_sources.php; this sweeps whatever
        // predates that (1,895 rows on the day it shipped) and then asks the
        // Wayback Machine for the public copies. Newest first: a page cited
        // today is the one most likely to vanish before anyone looks.
        require_once __DIR__ . '/source_archive.php';
        sa_install($pdo);
        $rows = $pdo->query(
            "SELECT s.id, s.url FROM sources s
               LEFT JOIN source_archive a ON a.source_id = s.id
              WHERE a.id IS NULL AND s.url LIKE 'http%'
              ORDER BY s.id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
        $t = [];
        foreach ($rows as $r) {
            [$st] = sa_capture($pdo, (int)$r['id'], (string)$r['url']);
            $t[$st] = ($t[$st] ?? 0) + 1;
        }
        $wb = sa_wayback_sweep($pdo, 15);
        $left = (int)$pdo->query(
            "SELECT COUNT(*) FROM sources s LEFT JOIN source_archive a ON a.source_id=s.id
              WHERE a.id IS NULL AND s.url LIKE 'http%'")->fetchColumn();
        echo "captured " . count($rows) . " (";
        foreach ($t as $k => $n) echo "{$k}:{$n} ";
        echo "), wayback {$wb['done']} saved, {$left} source(s) still uncopied\n";
        break;

    case 'psi':
        // Lighthouse scores via PageSpeed Insights API (Lighthouse-CI stand-in).
        require_once __DIR__ . '/monitor.php';
        $pr = monitor_psi($pdo, 280);
        echo "done: scored {$pr['checked']}/{$pr['targets']} pages" . ($pr['errors'] ? ', ' . count($pr['errors']) . ' errors' : '') . "\n";
        break;

    case 'entities':
        // Citation Engine: resolve Wikidata/Wikipedia/Wiktionary sameAs for unresolved pages.
        require_once __DIR__ . '/entity.php';
        $er = entity_backfill_run($pdo, 280);
        echo "done: resolved {$er['dramas']} dramas + {$er['terms']} terms\n";
        break;

    case 'build':
        // 2026-09-24 BUILD WORKER (owner: "25 to 30 new pages a day"). Measured
        // that week: ~12 pages a day, with 789 approved stories and 112 terms
        // waiting. Supply was never the limit; the one hourly run was: the build
        // stages were cut by the clock in 89 (stories) and 130 (terms) of 157
        // runs. This is a second schedule (hPanel, at :30) running the SAME
        // autopilot code with every stage but the page builds skipped. One
        // builder at a time (build_lock_acquire), today's count re-read under
        // the lock, the daily cap and every quality gate unchanged, and it stops
        // for the day at daily_page_target (default 30) indexable pages.
        $GLOBALS['BUILD_ONLY'] = true;
        // fall through to 'cron'
    case 'cron':
        // ===================================================================
        // SEO-BATCH-1 PAUSE SWITCH (2026-08-04).
        // The hPanel cron entry offers only "View Output" and "Delete" — there
        // is no disable toggle — and the entry must NOT be deleted. So the
        // pause lives here instead: drop a file named PAUSE next to this
        // script and the autopilot becomes a no-op. The cron keeps firing
        // hourly and keeps its log, it simply does nothing.
        //
        //   pause  :  touch app/PAUSE
        //   resume :  rm app/PAUSE
        //
        // Reason for the pause: Batch 1 is measuring a FIXED set of pages.
        // Anything published mid-measurement means the 8-week result cannot be
        // attributed to the gate. It also stops burning AI quota drafting
        // pages that the truth gate will reject.
        // ===================================================================
        if (is_file(__DIR__ . '/PAUSE')) {
            echo "[" . date('Y-m-d H:i:s') . "] cron PAUSED by app/PAUSE — no work done.\n";
            exit(0);
        }
        // THE AUTOPILOT | runs unattended on a schedule.
        // 1) AI-select fresh candidates  2) build top-N into ready drafts
        // 3) publish ONLY if config auto_publish=true  4) sitemap refresh
        set_time_limit(1800);
        $tickT0 = time();   // r145: every stage can ask how much of the 1800s is left
        // MUTUAL EXCLUSION: one tick at a time. A long tick (PSI/vision/AI) must not
        // overlap the next hourly fire — overlap = double-builds + a race on the
        // in-memory velocity $slots that could exceed the daily publish cap (the
        // anti scaled-content-abuse guard). The lock auto-releases on process exit.
        $BUILD_ONLY = !empty($GLOBALS['BUILD_ONLY']);
        // 2026-09-24 the build worker takes ONLY the build lock. It first took this
        // hourly lock too, so the 22:00 hourly run found it held and skipped the
        // whole hour (discovery, video, intelligence).
        if ($BUILD_ONLY) {
            if (!build_lock_acquire()) {
                echo "[" . date('c') . "] build worker: another builder is working; skipping this fire\n";
                break;
            }
        } else {
            $GLOBALS['__cron_lock'] = fopen(sys_get_temp_dir() . '/genzhype_cron.lock', 'c');
            if (!$GLOBALS['__cron_lock'] || !flock($GLOBALS['__cron_lock'], LOCK_EX | LOCK_NB)) {
                echo "[" . date('c') . "] a tick is already running; skipping this fire\n";
                break;
            }
        }
        // FLIGHT RECORDER: hPanel's cron discards stdout, which is how a 3-day publish
        // stall stayed invisible (SEO-GATE-FAIL echoed into the void, 2026-07-08..12).
        // Tee everything this tick prints into app/cron.log; rotate at ~2MB.
        $cronLog = __DIR__ . '/cron.log';
        if (is_file($cronLog) && filesize($cronLog) > 2 * 1024 * 1024) {
            file_put_contents($cronLog, "[" . date('c') . "] (rotated)\n" . substr((string)file_get_contents($cronLog), -400 * 1024));
        }
        ob_start(function ($buf) use ($cronLog) {
            if ($buf !== '') @file_put_contents($cronLog, $buf, FILE_APPEND | LOCK_EX);
            return $buf;   // still pass through to stdout
        }, 1);
        // DB BREADCRUMBS — the file tee above works from a normal shell but the
        // scheduler's environment silently swallowed it (cron.log stayed 0 bytes
        // while the 18:00 tick demonstrably ran). The DB is the one sink the tick
        // provably writes, so every important decision ALSO lands in cron_events;
        // the admin can then always answer "what did the last tick do".
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cron_events (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                msg VARCHAR(500) NOT NULL,
                at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("DELETE FROM cron_events WHERE at < DATE_SUB(NOW(), INTERVAL 14 DAY)");
        } catch (Throwable $e) {}
        $GLOBALS['__cnote'] = function (string $m) use ($pdo) {
            try { $pdo->prepare("INSERT INTO cron_events (msg) VALUES (?)")->execute([mb_substr($m, 0, 500)]); } catch (Throwable $e) {}
        };
        $GLOBALS['__cnote']('tick start (N=' . (($arg && ctype_digit($arg)) ? $arg : 2) . ')');
        require_once __DIR__ . '/select.php';
        require_once __DIR__ . '/fetch_sources.php';
        require_once __DIR__ . '/draft.php';
        require_once __DIR__ . '/verify.php';
        require_once __DIR__ . '/quality.php';
        require_once __DIR__ . '/sitemap_lib.php';
        require_once __DIR__ . '/indexnow.php';
        $N = ($arg && ctype_digit($arg)) ? max(1, min(10, (int)$arg)) : 2; // builds per tick (hourly cron x2 = quota-safe steady drip)
        if ($BUILD_ONLY && !($arg && ctype_digit($arg))) $N = 4;   // the build worker's own default
        if ($BUILD_ONLY) echo "[" . date('c') . "] build worker (N={$N})\n";
        echo "[" . date('c') . "] autopilot tick (N={$N}, auto_publish=" . (!empty($CONFIG['auto_publish']) ? 'ON' : 'off') . ")\n";
        // r186: one look at the render queue decides this tick's time-share.
        // An empty queue means every minute spent on terms or stories is a
        // minute the video line does not get (0 videos rendered on 16 Sep).
        $GLOBALS['VID_STARVED'] = false;
        if ($BUILD_ONLY) goto build_after_vid;   // video hours rule the hourly run, not the builder
        try {
            require_once __DIR__ . '/video_factory.php';
            $GLOBALS['VID_STARVED'] = video_queue_starved($pdo);
            if ($GLOBALS['VID_STARVED']) echo "  video queue empty: video stage gets this tick's time first\n";
            // 2026-09-24 VIDEO HOURS. Measured: 95 of the last 141 ticks skipped the
            // video step ("video: skipped at +22m") and 97 stopped the Director, so
            // no scripts were written on 09-21 or 09-24 and re-planned pages
            // (1142, 1210) never got a shot list back. The starved check did not
            // fire because parked pages still count as fresh work. Every third
            // hour now gives the video step the tick's time, whatever the queue says.
            elseif ((int)date('G') % 3 === 2) {
                $GLOBALS['VID_STARVED'] = true;
                $GLOBALS['VIDEO_HOUR'] = true;   // no term or story build may START this hour (a started one runs 5-10 min past any cutoff)
                echo "  video hour: video stage gets this tick's time first\n";
            }
        } catch (Throwable $e) { error_log('video_queue_starved: ' . $e->getMessage()); }
        build_after_vid:

        if ($BUILD_ONLY) goto build_velocity;
        // ---- REDDIT RADAR: pre-draft comments for new opportunities so the admin
        // never waits on a slow AI call (that caused a 504). Bounded batch; CLI has no
        // web timeout, so this uses the full robust provider chain (not the fast path).
        try {
            require_once __DIR__ . '/reddit_radar.php';
            // The tick is long and every step before this one can spend minutes in
            // AI or network calls; MySQL's wait_timeout is 300s. Revive before the
            // step's first query or it dies on a handle that is already closed -
            // which is exactly what took reddit, framing-repair and redrain out
            // together on 25 Aug, and with them the day's publishing.
            $pdo = db_alive();
            reddit_radar_install($pdo);
            $rids = $pdo->query("SELECT id FROM reddit_opps WHERE status='new' AND (draft_comment IS NULL OR draft_comment='') ORDER BY match_score DESC, created_utc DESC LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rids as $rid) reddit_draft_comment($pdo, (int)$rid);
            if ($rids) echo "  reddit: pre-drafted " . count($rids) . " opportunity comment(s)\n";
        } catch (Throwable $e) { echo "  reddit draft step failed: " . $e->getMessage() . "\n"; }

        // ---- DRAFT RE-DRAIN: a draft that failed verify/quality/gate ONCE used to be
        // orphaned forever (no retry path — a transient AI 429 at build time = a page
        // stuck in 'draft' for good; found 2026-07-11 with 71 stuck + 0 publishes for
        // 3 days). Each tick: recheck a few, promote passers into 'review' where the
        // normal velocity_drain re-gates and publishes them within the daily cap;
        // archive drafts >7 days old that still fail (honest-thin stories never rot).
        try {
            require_once __DIR__ . '/verify.php';
            // The tick is long and every step before this one can spend minutes in
            // AI or network calls; MySQL's wait_timeout is 300s. Revive before the
            // step's first query or it dies on a handle that is already closed -
            // which is exactly what took reddit, framing-repair and redrain out
            // together on 25 Aug, and with them the day's publishing.
            $pdo = db_alive();
            require_once __DIR__ . '/gate_term.php';
            // 2026-08-22 REPAIR BEFORE JUDGING. Fresh drama drafts were
            // failing the gate almost entirely on the alleged-framing check,
            // sitting unpublished, and archiving themselves after 7 days —
            // while the repair that fixes exactly that ran only on already
            // PUBLISHED pages. Repairing first turns a daily loss into a
            // publish. Small cap so a tick stays quick; non-fatal.
            try {
                require_once __DIR__ . '/framing_repair.php';
                $fr = framing_repair_run($pdo, 12);
                if ((int)($fr['repaired'] ?? 0) > 0) {
                    echo "  framing-repair: {$fr['repaired']} sentence(s) framed before the gate\n";
                    cnote("framing-repair: {$fr['repaired']} before gate");
                }
            } catch (Throwable $e) { echo "  framing-repair skipped: " . $e->getMessage() . "\n"; }
            $stuck = $pdo->query("SELECT id, type, created_at,
                                    (SELECT slug FROM pages p2 WHERE p2.id=pages.id) slug
                                  FROM pages WHERE status='draft' AND type IN ('drama','term')
                                  ORDER BY created_at DESC LIMIT 40")->fetchAll();
            $rdMoved = 0; $rdArch = 0; $rdChecked = 0;
            foreach ($stuck as $st) {
                if ($rdMoved >= 4 || $rdChecked >= 8) break;
                $isOld = (time() - strtotime($st['created_at'])) > 7 * 86400;
                if ($st['type'] === 'drama') {
                    $rdChecked++;
                    $gg = gate_check_drama((int)$st['id']);
                    if ($gg['pass']) {
                        $vv = verify_drama((int)$st['id']);
                        if ($vv['pass'] ?? false) {
                            $pdo->prepare("UPDATE pages SET status='review', robots='noindex', updated_at=NOW() WHERE id=?")->execute([$st['id']]);
                            echo "  REDRAIN {$st['slug']}: draft -> review (gate+verify pass)\n";
                            $rdMoved++; continue;
                        }
                    }
                } else {
                    $rdChecked++;
                    $gt = gate_check_term((int)$st['id']);
                    if ($gt['pass'] ?? false) {
                        $pdo->prepare("UPDATE pages SET status='review', robots='noindex', updated_at=NOW() WHERE id=?")->execute([$st['id']]);
                        echo "  REDRAIN {$st['slug']}: term draft -> review\n";
                        $rdMoved++; continue;
                    }
                }
                if ($isOld) {
                    $pdo->prepare("UPDATE pages SET status='archived' WHERE id=?")->execute([$st['id']]);
                    echo "  REDRAIN-ARCHIVE {$st['slug']}: still failing after 7d\n";
                    $rdArch++;
                }
            }
            // ONE CONSISTENT RULE FOR EVERY PAGE. The drain's own comment calls the
            // 7-day sweep 'the only thing that may archive, and it applies one
            // consistent rule to every page' - but the query above only ever selected
            // status='draft'. A page that reached 'review' had NO exit at all and was
            // re-judged forever. That gap is what let nine pages burn an AI call an
            // hour indefinitely. Held pages now age out on the same rule, bounded so a
            // backlog can never archive in one go.
            try {
                $oldHeld = $pdo->query("SELECT id, slug FROM pages
                                         WHERE status='review' AND robots='noindex'
                                           AND created_at < UTC_TIMESTAMP() - INTERVAL 7 DAY
                                         ORDER BY created_at ASC LIMIT 5")->fetchAll();
                foreach ($oldHeld as $oh) {
                    $pdo->prepare("UPDATE pages SET status='archived' WHERE id=?")->execute([$oh['id']]);
                    echo "  REDRAIN-ARCHIVE {$oh['slug']}: held 7 days without passing\n";
                    cnote("REDRAIN-ARCHIVE {$oh['slug']}: held 7d");
                    $rdArch++;
                }
            } catch (Throwable $e) { echo "  held-sweep failed: " . $e->getMessage() . "\n"; }

            if ($rdChecked) {
                echo "  draft-redrain: checked {$rdChecked}, moved {$rdMoved} to review, archived {$rdArch}\n";
                cnote("draft-redrain: checked {$rdChecked}, moved {$rdMoved}, archived {$rdArch}");
            }
        } catch (Throwable $e) { echo "  draft-redrain failed: " . $e->getMessage() . "\n"; }


        build_velocity:
        // ---- VELOCITY GUARDRAIL: cap pages going LIVE per day (anti scaled-abuse) ----
        require_once __DIR__ . '/gate_term.php';
        require_once __DIR__ . '/quality.php';
        $dailyCap = (int)($CONFIG['daily_publish_cap'] ?? 12);
        // The drain publishes pages, so it runs under the build lock too: the hourly
        // run and the build worker never publish against the same count at once.
        $drainLocked = build_lock_acquire();
        $pubToday = (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE status='published' AND robots='index' AND published_at >= CURDATE()")->fetchColumn();
        $slots = max(0, $dailyCap - $pubToday);
        // drain the HELD queue via the single guarded path (see velocity_drain)
        $dr = $drainLocked ? velocity_drain($pdo, $slots) : [];
        if (!$drainLocked) echo "  velocity-drain: the build worker is working; skipped this run\n";
        if (!empty($dr['refused'])) echo "  velocity-drain: REFUSED ({$dr['why']})\n";
        echo "velocity: cap={$dailyCap} published_today={$pubToday} slots_left={$slots}\n";
        if ($BUILD_ONLY) {
            $pageTarget = (int)($CONFIG['daily_page_target'] ?? 30);
            if ($pubToday >= $pageTarget) { echo "build worker: today's target of {$pageTarget} indexable pages is reached\n"; break; }
            goto build_terms;
        }

        // ---- GRADUAL REVEAL (owner decision 2026-08-28) -------------------
        // Repaired pages are already 'published' but noindex, so making them
        // visible bypasses the velocity cap above. Handing Google ~70 new
        // indexable URLs in one minute is exactly the burst the cap exists to
        // stop, so reveals get their own smaller budget and each page must
        // pass its own gate on the day it is revealed.
        try {
            require_once __DIR__ . '/reveal.php';
            $rv = reveal_run($pdo);
            if ((int)($rv['revealed'] ?? 0) > 0) {
                echo "  reveal: {$rv['revealed']} repaired page(s) now visible to Google\n";
                cnote("reveal: {$rv['revealed']} pages made visible");
            }
        } catch (Throwable $e) { echo "  reveal skipped: " . $e->getMessage() . "\n"; }

        // NIGHTLY SELF-AUDIT (3am tick): the site re-checks and heals itself,
        // forever, with no human involved. Bad pages get fixed or pulled.
        if ((int)date('G') === 3) {
            $wd = watchdog_run(4);
            echo "watchdog: healthy={$wd['ok']} fixed={$wd['fixed']} restored={$wd['restored']} pulled={$wd['pulled']} seo-drift={$wd['seo_drift']}\n";
        }

        // WEEKLY MONITORS — outbound-link health (lychee stand-in) + Lighthouse/PSI
        // scores. Staggered to separate off-peak ticks so neither bloats one run; each
        // self-gates to ~once a week. Results land in the admin Monitor tab.
        require_once __DIR__ . '/monitor.php';
        $hr = (int)date('G');
        if ($hr === 4 && monitor_days_since($pdo, 'link_issues') >= 6.5) {
            $lr = monitor_links($pdo, 200);
            echo "link audit: {$lr['ok']} ok, {$lr['broken']} broken, {$lr['redirect']} redirect" . ($lr['complete'] ? '' : ' (partial)') . "\n";
        }
        if ($hr === 5 && monitor_days_since($pdo, 'psi_scores') >= 6.5) {
            $pr = monitor_psi($pdo, 200);
            echo "psi: scored {$pr['checked']}/{$pr['targets']} pages\n";
        }
        // CITATION ENGINE: resolve Wikidata/Wikipedia entities for any newly-published
        // page (self-limits to unresolved pages, so cheap when nothing is new).
        if ($hr === 6) {
            require_once __DIR__ . '/entity.php';
            $er = entity_backfill_run($pdo, 180);
            if ($er['dramas'] || $er['terms']) echo "entities: +{$er['dramas']} dramas, +{$er['terms']} terms resolved\n";
        }
        // COVER POLICY v2 (owner 2026-09-24: "let go of that card... the best one that
        // fits"; right person guaranteed). Replaces the 3-a-day card retry at 7:00: 2
        // covers per tick outside video hours (~32/day, ~100 s each), YouTube covers
        // first (identity risk), then this week's pages, then older cards.
        if ($hr % 3 !== 2) {
            require_once __DIR__ . '/drama_image.php';
            $ib = drama_image_backfill_v2($pdo, 2);
            if ($ib['tried'])
                echo "covers v2: tried={$ib['tried']} photo={$ib['photo']} back-to-card={$ib['back_to_card']} retry={$ib['retry']} (" . implode(', ', $ib['pages']) . ")\n";
            // STATUS REFRESH (2026-09-24): stories whose timeline is newer than their
            // summary get the summary, status and status FAQ brought up to date, then
            // a fresh editor verdict. 4 per tick, ~2 min cap.
            try {
                require_once __DIR__ . '/status_refresh.php';
                $sr = sr_run($pdo, 4, 150);
                if ($sr['checked']) echo "status refresh: checked={$sr['checked']} refreshed={$sr['refreshed']} unchanged={$sr['unchanged']} kept-old={$sr['refused']} passed-editor={$sr['passed_editor']}\n";
            } catch (Throwable $e) { echo "  status refresh failed: " . $e->getMessage() . "\n"; }
        }
        // SOCIAL STUDIO: turn the newest pages into the per-platform post queue (admin Social tab).
        if ($hr === 8) {
            require_once __DIR__ . '/social_studio.php';
            $ss = social_studio_daily($pdo, 3);
            if (!empty($ss['pages_socialized'])) echo "social studio: +{$ss['pages_socialized']} pages socialized\n";
        }

        build_terms:
        // 2026-09-24 ONE BUILDER AT A TIME: the hourly run and the build worker
        // share this lock; whoever holds it builds, the other skips building.
        // Today's count is re-read under the lock, so the two can never push
        // past the daily cap between them.
        $tbuilt = 0; $built = 0; $ready = 0;
        $buildLocked = build_lock_acquire();
        if ($buildLocked) {
            $pubToday = (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE status='published' AND robots='index' AND published_at >= CURDATE()")->fetchColumn();
            $slots = max(0, $dailyCap - $pubToday);
        } else {
            echo "  builds: the other builder is working; this run skips building\n";
            goto build_after_terms;
        }
        // ---- LANE FAIRNESS (owner decision 2026-08-29): TERMS BEFORE DRAMA ----
        // The slang/meme/gaming stage sat AFTER drama drafting, and drama's
        // workload (a 300-deep drain, AI drafting, hourly re-judging) now eats
        // the whole 30-minute host limit - the term stage produced no log line
        // between Aug 26 23:00 and Aug 29 while its cleaned queue sat ready.
        // The owner's rule is that all four lanes are equal; drama was starving
        // the rest by position alone, exactly like the video stage before it.
        // Terms now run FIRST among the build stages. They are strictly
        // bounded (2 builds, ~12 attempts) so they cannot starve drama back.
        // ---- SLANG lane: discover fresh terms, then build from the backlog ----
        require_once __DIR__ . '/draft_term.php';
        require_once __DIR__ . '/gate_term.php';
        // BIN DRAIN (2026-09-01, owner: "I do not want to see anything still in
        // this bin"). The archive is no longer a dead end: each tick takes a few
        // pages that failed on MATERIAL (too few events / one source), hunts real
        // new coverage for the story, adds only events an article actually
        // carries, then re-gates and publishes the ones that now pass. Bounded
        // to 2 per tick because each page costs a live search plus fetches plus
        // an AI pass - the same starvation lesson the term and drama loops both
        // had to learn. A page with genuinely one source in the world stays put.
        try {
            $pdo = db_alive();
            require_once __DIR__ . '/drama_deepen.php';
            $dd = drama_deepen_run($pdo, 1, true);   // r147: 2 → 1 per tick, ~3 min saved per hour
            if ($dd['checked'] > 0) {
                echo "bin drain: checked {$dd['checked']}, deepened {$dd['deepened']}, published {$dd['published']}, stuck {$dd['stuck']}\n";
                cnote("bin drain: published {$dd['published']}");
            }
            $pdo = db_alive();   // searches + AI just ran
        } catch (Throwable $e2) { echo "  bin drain skipped: " . $e2->getMessage() . "\n"; try { $pdo = db_alive(); } catch (Throwable $e3) {} }
                // 2026-08-30 THE THIRD DEAD-HANDLE SITE. discover_terms_run() above
        // spends minutes in HTTP + an AI call; MySQL hangs up in the meantime,
        // and the very next line queried the dead handle OUTSIDE any try/catch
        // - the 20:00 and 21:00 ticks both printed 'term discovery' and then
        // died on the spot: no term builds, and (since the lane reorder) no
        // drama either. Same killer as the video step and the blitz worker;
        // every long stage must reconnect before touching the DB again.
        $pdo = db_alive();
        $TN = ($argv[3] ?? null) && ctype_digit((string)$argv[3]) ? max(0, min(10, (int)$argv[3])) : 2;
        // 2026-08-31 TWO BUGS IN ONE QUERY, both measured on the live queue:
        //  (a) it never excluded candidates that have already failed their 5
        //      attempts - the drama query beside it does - so dead entries were
        //      re-picked forever. Real case: #8584 "bowen yang oh mary" sat at
        //      THIRTEEN attempts and was still first in line every hour, each
        //      try costing five live source fetches and AI screens.
        //  (b) it never SELECTed draft_attempts, but the failure branch reads
        //      $tc['tries'] to decide when to retire - always null, so the log
        //      printed "attempt 1 of 5" on a 13th attempt and the retirement
        //      the comment promised could never fire for ANY term.
        // Together they kept a month of July junk (a French beach, a cricket
        // scorecard) permanently at the head of the queue.
        $terms = $pdo->query("SELECT id, name, type, COALESCE(draft_attempts,0) tries FROM candidates
                              WHERE status='selected' AND type IN ('term','meme','gaming','music')
                                AND heat_score >= 50 AND COALESCE(draft_attempts,0) < 5
                              ORDER BY heat_score DESC, id ASC LIMIT 40")->fetchAll();
        // r153 PER-LANE QUOTA (2026-09-11): the desk routes words in bulk (queue at the
        // switch-on: 14 gaming, 9 meme, 3 slang) and heat alone gave gaming words 8 of
        // the top 12 places. Lanes now take turns, each in heat order; the attempt cap
        // below still decides how many are tried this hour.
        $terms = lane_interleave($terms, fn($r) => (string)$r['type']);
        $tbuilt = 0; $tpub = 0;
        // 2026-08-31 THE STARVATION CAP, NOW ON THIS SIDE. The identical bug the
        // drama loop already carries a fix for (see DRAMA_MAX_TRIES below): this
        // guard counted only SUCCESSES, so a run of FAILURES was unbounded. The
        // term queue filled with junk that can never build - "cap ferret" (a
        // French beach), "nep vs ned" (a cricket scorecard), "gooseworks
        // slander recently" - and each one still costs five live source fetches
        // plus AI screens. Measured 2026-08-31: the last 12 ticks IN A ROW died
        // around 22 minutes in, right here, and never reached the drama stage:
        // drama built=0 for a whole day while 186 ready candidates waited.
        // Cap the attempts, exactly as drama does, so no lane can eat the tick.
        $ttried = 0;
        $TERM_MAX_TRIES = 5;
        $storiesWaiting = (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE status='selected' AND type='drama' AND COALESCE(draft_attempts,0) < 5")->fetchColumn();
        $termCutoff = ((int)date('G') % 2 === 0 && $storiesWaiting > 0) ? 360 : 840;   // r153 time-share, see below
        // r186: with an empty render queue the video stage comes first - terms
        // and stories are useless if nothing renders (0 videos on 16 Sep).
        if (!empty($GLOBALS['VID_STARVED'])) $termCutoff = min($termCutoff, 240);
        if (!empty($GLOBALS['VIDEO_HOUR'])) $termCutoff = 0;   // 2026-09-24: measured 20:00, one term started at +4m ran past +14m
        foreach ($terms as $tc) {
            if ($tbuilt >= $TN) break;
            if ($ttried >= $TERM_MAX_TRIES) {
                echo "  term build: stopping after {$ttried} attempts so drama and video get a turn\n";
                break;
            }
            // r147 TIME BUDGET (2026-09-08, measured: the 21:00 and 22:00 ticks never
            // printed 'tick done' — the core alone ran past 30 min). A term build costs
            // minutes; none may start after +14m so drama, video and the intelligence
            // stages keep their turn.
            // r153 TIME-SHARE (2026-09-11): +14m still starved stories. Redrain runs 2-4 min
            // before this stage and discovery + select run after it, so the drama build (no
            // start after +20m) was cut before its first attempt in 5 of the 7 runs from 11:00
            // to 17:00. On even hours, while stories wait, no term may start after +6m.
            if (time() - $tickT0 > $termCutoff) { echo '  term build: stopping at +' . (int)round((time() - $tickT0) / 60) . "m to keep time for drama, video and intelligence" . ($termCutoff < 840 ? ' (even hour: stories first)' : '') . "\n"; break; }
            $ttried++;
            $d = draft_term(['term' => $tc['name'], 'lane' => lane_for_cand_type($tc['type']) ?? 'slang']);
            $pdo = db_alive();   // draft_term = minutes of HTTP+AI; the handle may be dead
            if (isset($d['error'])) {
                echo "  term skip '{$tc['name']}': {$d['error']}\n";
                // A PERMANENT VERDICT IS NOT A TRANSIENT FAILURE. The old rule retired
                // only duplicates and left everything else queued 'in case it was
                // transient'. But the topical-fit gate saying 'Taylor Swift and Travis
                // Kelce are not a slang phrase' is a final answer, and it was being
                // re-asked 485 times. Retire those now; count the rest and let the
                // budget above retire them at 5.
                $err = (string)$d['error'];
                $permanent = strpos($err, 'already exists') !== false
                          || stripos($err, 'off-lane') !== false
                          || stripos($err, 'topical-fit gate') !== false;
                if ($permanent) {
                    $why = strpos($err, 'already exists') !== false ? 'dup' : 'not a slang/meme term';
                    $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason=? WHERE id=?")
                        ->execute([$why, $tc['id']]);
                    echo "    -> retired ({$why})\n";
                } else {
                    $pdo->prepare("UPDATE candidates SET draft_attempts=COALESCE(draft_attempts,0)+1,
                                   last_error=? WHERE id=?")->execute([mb_substr($err, 0, 255), $tc['id']]);
                    $try = (int)($tc['tries'] ?? 0) + 1;
                    // 2026-08-31: the message promised retirement at 5 but nothing
                    // ever performed it - say it AND do it.
                    if ($try >= 5) {
                        $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason=? WHERE id=?")
                            ->execute(['retired: 5 failed build attempts', $tc['id']]);
                        echo "    -> attempt {$try} of 5 - RETIRED, it cannot be built\n";
                    } else {
                        echo "    -> attempt {$try} of 5\n";
                    }
                }
                continue;
            }
            $g = gate_check_term((int)$d['page_id']);
            require_once __DIR__ . '/gate_quality.php';
            $q = gate_quality((int)$d['page_id'], true);          // THE QUALITY DEPARTMENT — final gate before live
            $qOk = empty($q['hard_fails']);
            echo "  term built {$d['slug']} | gate=" . ($g['pass'] ? 'PASS' : 'FAIL')
               . " | quality=" . ($qOk ? "{$q['score']}/100" : 'HOLD: ' . implode('; ', array_slice($q['hard_fails'], 0, 2)))
               . (($g['pass'] && $qOk) ? '  => READY' : '') . "\n";
            if (!$g['pass']) foreach (array_filter($g['checks'], fn($c) => !$c['pass']) as $f) echo "      - {$f['label']} ({$f['detail']})\n";
            $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason='built' WHERE id=?")->execute([$tc['id']]);
            if ($g['pass'] && $qOk && !empty($CONFIG['auto_publish'])) {
                $seo = seo_audit_page((int)$d['page_id']);
                if (!$seo['pass']) {
                    foreach ($seo['fails'] as $sf) echo "  SEO-GATE-FAIL {$d['slug']}: {$sf}\n";
                } else {
                    $pp = $pdo->prepare("SELECT path FROM pages WHERE id=?"); $pp->execute([(int)$d['page_id']]);
                    if ($slots > 0) {
                        page_publish_live($pdo, (int)$d['page_id']);   // SEO-BATCH-1 choke point
                        echo "  AUTO-PUBLISHED {$d['slug']}\n";
                        try { record_touch($pdo, 'drama', (int)$d['page_id'], '', 'build', ['published' => true, 'published_at' => gmdate('c')]); } catch (Throwable $e) {}
                        indexnow_ping([rtrim($CONFIG['base_url'],'/') . $pp->fetchColumn()]);
                        $slots--; $tpub++;
                    } else {
                        $pdo->prepare("UPDATE pages SET status='review', robots='noindex' WHERE id=?")->execute([(int)$d['page_id']]);
                        echo "  HELD {$d['slug']} (daily cap reached)\n";
                    }
                }
            }
            $tbuilt++;
        }
        build_after_terms:
        if ($BUILD_ONLY) goto build_dramas;
        // IMAGE QUEUE: the system redoes images by itself, one page per tick
        // (operator order 2026-06-12: bulk image work never runs in chat again).
        // Queue = app/img_queue.txt, one slug per line; anything may append.
        $qf = __DIR__ . '/img_queue.txt';
        if (is_file($qf)) {
            $q = array_values(array_filter(array_map('trim', file($qf))));
            if ($q) {
                $lineQ = array_shift($q);
                file_put_contents($qf, $q ? implode("\n", $q) . "\n" : '');
                // a queue line may carry an operator hint: "slug|||redo with X"
                [$slugQ, $hintQ] = array_pad(explode('|||', $lineQ, 2), 2, '');
                $slugQ = trim($slugQ); $hintQ = trim($hintQ);
                require_once __DIR__ . '/image_beast.php';
                $stq = $pdo->prepare("SELECT t.page_id, t.term, t.short_def, t.first_seen, t.origin, t.examples FROM terms t JOIN pages p ON p.id=t.page_id WHERE p.slug=?");
                $stq->execute([$slugQ]);
                if ($rq = $stq->fetch()) {
                    try {
                        // REAL images: GIPHY by term name + vision-picked best frame
                        // (memes + slang). Operator hint overrides the search term.
                        $searchTerm = $hintQ !== '' ? $hintQ : $rq['term'];
                        $mr = meme_real_images($searchTerm, $slugQ, $rq['short_def'] ?? '');
                        if ($mr) {
                            $pdo->prepare("UPDATE pages SET cover=?, featured_img=?, cover_credit=?, cover_credit_url=? WHERE id=?")
                                ->execute([$mr['img'], $mr['img'], $mr['credit'], $mr['credit_url'], (int)$rq['page_id']]);
                            echo "img-queue: {$slugQ} done ({$mr['count']} real images, " . count($q) . " left)\n";
                        } else {
                            // GIPHY empty -> fall to the beast (stock/generation)
                            $ctxQ = trim(implode(' | ', array_filter([$rq['first_seen'] ?? '',
                                (json_decode($rq['origin'] ?? '[]', true) ?: [''])[0] ?? '',
                                ((json_decode($rq['examples'] ?? '[]', true) ?: [['text' => '']])[0]['text'] ?? '')])));
                            $rB = fetch_featured_image(['page_type' => 'term', 'subject' => $rq['term'],
                                                        'meaning' => $rq['short_def'], 'slug' => $slugQ, 'people' => [],
                                                        'context' => $ctxQ, 'hint' => $hintQ], ['gallery' => true]);
                            if ($rB) {
                                $pdo->prepare("UPDATE pages SET cover=?, featured_img=?, cover_credit=?, cover_credit_url=? WHERE id=?")
                                    ->execute([$rB['img'], $rB['img'], $rB['credit'], $rB['credit_url'], (int)$rq['page_id']]);
                                echo "img-queue: {$slugQ} done (beast {$rB['source']}, " . count($q) . " left)\n";
                            }
                        }
                    } catch (Throwable $e) { echo "img-queue: {$slugQ} failed: " . $e->getMessage() . "\n"; }
                } else { echo "img-queue: {$slugQ} not found, dropped\n"; }
            }
        }
        // RECEIPTS ENGINE P1: promote pending receipt images (max 3/tick).
        // Safety vision check -> /assets/receipts/ -> renditions -> gallery JSON.
        $pendDir = dirname(__DIR__) . '/public_html/assets/receipts/pending';
        foreach (array_slice(glob("{$pendDir}/*.webp") ?: [], 0, 3) as $pf) {
            $mf = substr($pf, 0, -5) . '.json';
            $pm = is_file($mf) ? (json_decode((string)file_get_contents($mf), true) ?: []) : [];
            $slugR = $pm['slug'] ?? null;
            if (!$slugR) { @unlink($pf); @unlink($mf); continue; }
            require_once __DIR__ . '/image_beast.php';
            try {
                $b64 = beast_b64(['path' => $pf]);
                $safe = true;
                if ($b64) {
                    $vr = ai_chat([['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => 'General-audience site check. Does this image contain sexual content, gore, or visible slurs? STRICT JSON: {"unsafe":false}'],
                        ['type' => 'image_url', 'image_url' => ['url' => $b64]],
                    ]]], ['gemini'], 0.1);
                    $vj = isset($vr['error']) ? null : ai_json($vr['content']);
                    if (!$vj) $vj = vision_nvidia('Does this image contain sexual content, gore, or visible slurs? STRICT JSON: {"unsafe":false}', $b64);
                    if ($vj && !empty($vj['unsafe'])) $safe = false;
                }
                if (!$safe) { echo "  receipts: {$slugR} REJECTED (unsafe)\n"; @unlink($pf); @unlink($mf); continue; }
                $liveDir = dirname(__DIR__) . '/public_html/assets/receipts';
                if (!is_dir($liveDir)) @mkdir($liveDir, 0755, true);
                $rn = count(glob("{$liveDir}/{$slugR}-r*.webp") ?: []) + 1;
                $live = "{$liveDir}/{$slugR}-r{$rn}.webp";
                rename($pf, $live); @unlink($mf);
                img_renditions($live);
                // append to the page's gallery sidecar (rendered interspersed by term.php)
                $gf = dirname(__DIR__) . "/public_html/assets/memes/{$slugR}-gallery.json";
                $gal = is_file($gf) ? (json_decode((string)file_get_contents($gf), true) ?: []) : [];
                if (count($gal) < 4) {
                    $gal[] = ['img' => "/assets/receipts/{$slugR}-r{$rn}.webp",
                              'credit' => $pm['credit'] ?? 'Via ' . ucfirst($pm['platform'] ?? 'source'),
                              'credit_url' => $pm['source_url'] ?? ''];
                    @mkdir(dirname($gf), 0755, true);
                    file_put_contents($gf, json_encode($gal, JSON_UNESCAPED_SLASHES));
                }
                echo "  receipts: {$slugR} promoted (r{$rn})\n";
            } catch (Throwable $e) { echo "  receipts: {$slugR} error: " . $e->getMessage() . "\n"; }
        }
        echo "sitemap: " . sitemap_build() . "\n";

        // server-side drama discovery: creator-culture RSS feeds -> keyword filter -> queue
        require_once __DIR__ . '/discover_dramas.php';
        try {
            $dd = discover_dramas_run();
            echo "drama discovery: seen={$dd['seen']} queued={$dd['queued']} dropped={$dd['dropped']}" . ($dd['dead_feeds'] ? " dead=" . implode(',', $dd['dead_feeds']) : '') . "\n";
        } catch (Throwable $e) { echo "drama discovery skipped: " . $e->getMessage() . "\n"; }
        $sel = select_run(15);
        echo "select: +{$sel['selected']} / -{$sel['rejected']} (errors {$sel['errors']})\n";
        build_dramas:
        if (!$buildLocked) goto build_after_dramas;
        // 2026-08-23 RETRY BUDGET (Phase 0 of the learning-machine build): a
        // failing candidate may try 5 times, then retires with its last error
        // written down — the drama_img_retry pattern (attempts + cap) applied
        // to drafting. Fresh candidates draft BEFORE repeat offenders, so one
        // stubborn story can never starve the queue again (#8973 failed 29x).
        foreach (["ADD COLUMN draft_attempts TINYINT NOT NULL DEFAULT 0",
                  "ADD COLUMN last_error VARCHAR(255) NULL"] as $alt) {
            try { $pdo->exec("ALTER TABLE candidates {$alt}"); } catch (Throwable $e) {}
        }
        // r153 (desk switched on 2026-09-10, owner decision "breaking -> publish fast"):
        // a story the desk judged BREAKING is drafted first, ahead of older retries.
        // The gates are untouched: breaking goes first, it does not go through easier.
        $cands = $pdo->query("SELECT id, name, COALESCE(draft_attempts,0) tries, signals FROM candidates
                              WHERE status='selected' AND type='drama' AND COALESCE(draft_attempts,0) < 5
                              ORDER BY (JSON_UNQUOTE(JSON_EXTRACT(signals, '$.urgency')) = 'breaking') DESC,
                                       COALESCE(draft_attempts,0) ASC, heat_score DESC, id DESC LIMIT 1000")->fetchAll();
        // r153 PER-LANE QUOTA (2026-09-11): gaming publications enter this queue at heat 55
        // and a creator story with one keyword at 50, so all top 40 of the 593 waiting were
        // gaming news and the xQc stories the editor picked never got a turn. Breaking stories
        // still go first; after them the drama and gaming lanes take turns, each in the order
        // above. The whole queue is read, not a top slice: a slice held one lane only.
        // The lane test is the one the page is published under (fs_story_lane).
        require_once __DIR__ . '/fetch_sources.php';
        $brk = []; $rest = [];
        foreach ($cands as $cd) {
            $sg = json_decode((string)($cd['signals'] ?? ''), true) ?: [];
            if (($sg['urgency'] ?? '') === 'breaking') $brk[] = $cd; else $rest[] = $cd;
        }
        $cands = array_merge($brk, lane_interleave($rest, fn($cd) => fs_story_lane(json_decode((string)($cd['signals'] ?? ''), true) ?: [])));
        $built = 0; $ready = 0;

        // 2026-08-26 THE STARVATION CAP. Every tick for 12 hours died inside this
        // loop - the host kills the process partway through and NOTHING after it
        // ever runs: not the slang/meme/gaming stage, not the video stage, not even
        // the closing 'tick done'. That is why the term lanes published nothing
        // since 5 August, and why a term-lane fix deployed yesterday never fired
        // once: the code is simply never reached.
        // The old guard counted only SUCCESSES ($built >= $N), so a run of failures
        // was unbounded - 12 candidates each costing a source fetch plus a full AI
        // draft. Attempts are now capped too, so this stage can never eat the whole
        // tick and starve every stage behind it.
        $tried = 0;
        $DRAMA_MAX_TRIES = 4;
        foreach ($cands as $cd) {
            if ($built >= $N) break;
            if ($tried >= $DRAMA_MAX_TRIES) {
                echo "  drama build: stopping after {$tried} attempts so the later stages get a turn\n";
                cnote("drama build: capped at {$tried} attempts");
                break;
            }
            if (time() - $tickT0 > (!empty($GLOBALS['VIDEO_HOUR']) ? 0 : (!empty($GLOBALS['VID_STARVED']) ? 600 : 1200))) { echo '  drama build: stopping at +' . (int)round((time() - $tickT0) / 60) . "m to keep time for video and intelligence\n"; cnote('drama build: stopped by the time budget'); break; }   // r147
            $tried++;
            $src = fetch_sources_for_candidate((int)$cd['id']);
            if (isset($src['error'])) {
                echo "  skip #{$cd['id']}: {$src['error']}\n";
                $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason=? WHERE id=?")->execute(['autopilot: ' . mb_substr($src['error'],0,200), $cd['id']]);
                continue;
            }
            // r151: an exception escaping draft_drama() ended the WHOLE hourly run
            // with no message (this file has no exception handler). Since
            // 2026-08-10 every run that wrote a story died on this line and only
            // runs that wrote nothing reached "tick done". Make it an ordinary,
            // logged draft failure so the rest of the run still happens.
            try { $d = draft_drama($src); }
            catch (Throwable $e) { $d = ['error' => 'draft crashed: ' . get_class($e) . ': ' . $e->getMessage()]; }
            $pdo = db_alive();   // drafting = minutes of AI; refresh before any DB write
            if (!isset($d['error'])) echo "  embeds built: " . (int)($d['embeds'] ?? 0) . " for {$d['slug']}\n";
            if (isset($d['error'])) {
                echo "  draft fail #{$cd['id']}: {$d['error']}\n";
                // 2026-08-22 STOP THE RETRY LOOP. A candidate whose page ALREADY
                // exists fails with 'Duplicate entry ... uniq_path' and was left
                // 'selected', so every tick re-fetched its sources and re-ran the
                // AI on it, forever. Four of those per tick ate the whole 30-minute
                // budget, the run was killed before the SLANG/MEME/GAMING stage
                // below ever executed — which is why those three lanes have
                // published nothing for weeks while 307 approved topics waited.
                if (stripos($d['error'], 'duplicate entry') !== false
                    || stripos($d['error'], 'already exists') !== false) {
                    $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason='dup: page already exists' WHERE id=?")
                        ->execute([$cd['id']]);
                    echo "    -> retired (page already exists)\n";
                    continue;
                }
                // 2026-08-23 THE OTHER TWO FAILURE CLASSES:
                // (a) AI chain dead -> not this candidate's fault, and every later
                //     candidate hits the same dead chain. HALT the drama lane for
                //     this tick so the budget reaches the slang/meme/gaming lanes
                //     below (the ai-health watchdog is what alerts on the chain).
                if (stripos($d['error'], 'all providers failed') !== false) {
                    echo "    -> AI chain down; halting drama drafts this tick (candidates keep their turns)\n";
                    break;
                }
                // (b) anything else (bad JSON, missing fields, thin sources) ->
                //     count the attempt; at 5 the candidate retires with its
                //     last error on record instead of looping forever.
                $tries = (int)($cd['tries'] ?? 0) + 1;
                $pdo->prepare("UPDATE candidates SET draft_attempts=?, last_error=? WHERE id=?")
                    ->execute([$tries, mb_substr((string)$d['error'], 0, 255), $cd['id']]);
                if ($tries >= 5) {
                    $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason=? WHERE id=?")
                        ->execute(['autopilot: gave up after 5 draft attempts: ' . mb_substr((string)$d['error'], 0, 150), $cd['id']]);
                    echo "    -> retired after {$tries} attempts\n";
                }
                continue;
            }
            // 2026-09-24 the legal framing check failed both stories built at 22:30
            // (4 unframed events each), one of which the editor had passed. Drama
            // deepen already chains the framing repair; the build now runs it on the
            // new page before any check, so the checks see the page readers will.
            try {
                require_once __DIR__ . '/framing_repair.php';
                $fr = framing_repair_run($pdo, 16, (int)$d['page_id']);
                if (!empty($fr['repaired'])) echo "    framing: {$fr['repaired']} unconfirmed event(s) given alleged/according-to framing\n";
            } catch (Throwable $e) { echo "    framing repair failed: " . $e->getMessage() . "\n"; }
            $v = verify_drama((int)$d['page_id']);
            $q = quality_check_drama((int)$d['page_id']);
            // the top-3 test (owner rule): what this page has that the top results do not; stored, read by the gate.
            // It needs an Exa key: the keyless tier (shared by 8 features: slang drafts, deepen, clips...) hit
            // its rate limit after ~20 searches in an hour on 2026-09-25, so builds must not spend it.
            if (!empty($CONFIG['exa']['key'])) {
                try {
                    require_once __DIR__ . '/top3.php';
                    $t3 = top3_check($pdo, (int)$d['page_id']);
                    if (isset($t3['error'])) echo "    top 3: not checked ({$t3['error']})\n";
                    // step 3: the original posts the top 3 show and we do not join our timeline, then the test runs again
                    elseif (($t3['missing_posts'] ?? 0) > 0) {
                        $fp = top3_add_missing_posts($pdo, (int)$d['page_id']);
                        echo "    top 3 posts: added {$fp['added']}, attached {$fp['attached']}" . (isset($fp['error']) ? " ({$fp['error']})" : '') . "\n";
                        if ($fp['added'] || $fp['attached']) top3_check($pdo, (int)$d['page_id']);
                    }
                } catch (Throwable $e) { echo "    top 3: failed (" . $e->getMessage() . ")\n"; }
            } else {
                echo "    top 3: skipped (needs an Exa key in config.php)\n";
            }
            $g = gate_check_drama((int)$d['page_id']);
            $ok = ($v['pass'] ?? false) && ($q['pass'] ?? false) && ($g['pass'] ?? false);
            echo "  built {$d['slug']} | v=" . (($v['pass'] ?? 0)?'P':'i') . " q=" . (($q['pass'] ?? 0)?'P':'F') . " g=" . (($g['pass'] ?? 0)?'P':'F') . ($ok ? "  => READY" : "") . "\n";
            if (isset($d['context'])) echo "    context: why=" . ($d['context']['why'] ? 'yes' : 'no') . " next={$d['context']['next']}"
                . ($d['context']['dropped'] ? ' (dropped: ' . implode('; ', array_slice($d['context']['dropped'], 0, 3)) . ')' : '') . "\n";
            // what the page adds that its sources don't (gate_original_value(); report-only for now)
            $ov = $g['original_value'] ?? null;
            if ($ov) echo "    original value: {$ov['label']}\n";
            // 2026-08-23 name the failing check — "g=F" alone hid WHICH gate rule
            // rejected every page for weeks. One line per failed rule, budget 4.
            if (!$ok) {
                $said = 0;
                foreach (($g['checks'] ?? []) as $ck) {
                    if (empty($ck['pass']) && $said < 4) { echo "    gate-fail: {$ck['label']}\n"; $said++; }
                }
                if (!($q['pass'] ?? false)) {
                    foreach (($q['flags'] ?? []) as $fl) { if ($said < 6) { echo "    quality-flag: {$fl}\n"; $said++; } }
                    if (!empty($q['scores'])) echo "    quality-scores: " . json_encode($q['scores']) . "\n";
                }
                if (!($v['pass'] ?? false)) {
                    foreach (array_slice((array)($v['issues'] ?? []), 0, 2) as $iss) {
                        echo "    verify-issue: " . mb_substr(is_string($iss) ? $iss : json_encode($iss), 0, 160) . "\n";
                    }
                }
            }
            // THE RECORD (organ 02): the same outcomes, written to the page's story.
            // Observer only — a record failure must never touch the pipeline.
            try {
                require_once __DIR__ . '/record.php';
                $gateFailed = [];
                foreach (($g['checks'] ?? []) as $ck) if (empty($ck['pass'])) $gateFailed[] = (string)$ck['label'];
                record_touch($pdo, 'drama', (int)$d['page_id'], '', 'build', [
                    'candidate_id' => (int)$cd['id'],
                    'draft'   => ['provider' => (string)($d['provider'] ?? ''), 'events' => (int)($d['events'] ?? 0), 'context' => $d['context'] ?? null],
                    'verify'  => ['pass' => (bool)($v['pass'] ?? false), 'issues' => array_slice((array)($v['issues'] ?? []), 0, 5)],
                    'quality' => ['pass' => (bool)($q['pass'] ?? false), 'scores' => $q['scores'] ?? null, 'flags' => $q['flags'] ?? []],
                    'gate'    => ['pass' => (bool)($g['pass'] ?? false), 'failed' => $gateFailed],
                    'ready'   => $ok,
                    'original_value' => $ov ? ['pass' => $ov['pass'], 'strong' => $ov['strong'], 'weak' => $ov['weak']] : null,
                ]);
            } catch (Throwable $e) { error_log('record build hook: ' . $e->getMessage()); }
            $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason='built' WHERE id=?")->execute([$cd['id']]);
            if ($ok) {
                $ready++;
                if (!empty($CONFIG['auto_publish'])) {
                    $seo = seo_audit_page((int)$d['page_id']);
                    if (!$seo['pass']) {
                        foreach ($seo['fails'] as $sf) echo "  SEO-GATE-FAIL {$d['slug']}: {$sf}\n";
                    } elseif ($slots > 0) {
                        $pp = $pdo->prepare("SELECT path FROM pages WHERE id=?"); $pp->execute([(int)$d['page_id']]);
                        page_publish_live($pdo, (int)$d['page_id']);   // SEO-BATCH-1 choke point
                        echo "  AUTO-PUBLISHED {$d['slug']}\n";
                        try { record_touch($pdo, 'drama', (int)$d['page_id'], '', 'build', ['published' => true, 'published_at' => gmdate('c')]); } catch (Throwable $e) {}
                        indexnow_ping([rtrim($CONFIG['base_url'],'/') . $pp->fetchColumn()]);
                        $slots--;
                    } else {
                        // daily cap reached: hold (built + gate-passed, awaits a later day)
                        $pdo->prepare("UPDATE pages SET status='review', robots='noindex' WHERE id=?")->execute([(int)$d['page_id']]);
                        echo "  HELD {$d['slug']} (daily cap reached)\n";
                    }
                }
            }
            $built++;
        }
        build_after_dramas:
        build_lock_release();
        if ($BUILD_ONLY) {
            echo "build worker done (+" . (int)round((time() - $tickT0) / 60) . "m) | stories built={$built} ready={$ready} | terms built={$tbuilt}\n";
            break;
        }
        // A breadcrumb in cron_events: if this never appears, the tick died before
        // the term lanes again and the cap above needs to be tighter.
        cnote("reached the slang/meme/gaming stage");
        echo "  --- reached the slang/meme/gaming stage ---\n";

        // ---- ORDER (owner decision 2026-08-27): PAGES BEFORE VIDEO ----
        // The video factory used to run BEFORE the drama and slang/meme/gaming
        // stages. Nobody chose that; it was just where the code sat. The effect
        // was that drama drafted first and every other lane queued behind a step
        // that spends minutes in AI calls - and when that step dropped the MySQL
        // connection, the lanes behind it died with it. The website is the
        // product and every lane is equal, so ALL page work now happens first
        // and video generation runs last, on whatever the tick has time for.
        // ---- VIDEO FACTORY: pre-generate faceless-video voiceover scripts for new dramas so the
        // maker (GitHub Actions) can render instantly. CLI = full robust AI chain, no web timeout.
        try {
            require_once __DIR__ . '/video_factory.php';
            // Everything above (framing-repair, redrain, verify) spends real time on
            // AI and network. The handle is often already dead by the time we get
            // here - which is why the video step failed INSTANTLY on every tick,
            // before it ever reached an AI call of its own.
            $pdo = db_alive();
            // r186/r187: the whole tick is SIGKILLed at 1800s by the cron wrapper
            // (timeout -s 9 1800), and one script write measured 525s, one
            // Director pass up to ~540s. So nothing AI-heavy may START after
            // +20m or it dies mid-call. The starvation flag buys the video stage
            // its time by cutting terms (240s) and stories (600s) short instead.
            $vCut = 1200;
            $vn = (time() - $tickT0 < $vCut) ? video_scripts_generate($pdo, 2) : 0;   // r147: not after +25m
            if (time() - $tickT0 >= $vCut) echo '  video: skipped at +' . (int)round((time() - $tickT0) / 60) . "m (time budget)\n";
            if ($vn) echo "  video: pre-generated {$vn} video script(s)\n";
            // r186 SECOND LOOK: nothing new to write and nothing to render ->
            // re-gate one story skipped before its people were resolved
            if (!$vn && !empty($GLOBALS['VID_STARVED']) && time() - $tickT0 < $vCut) {
                $pdo = db_alive();
                $vres = video_rescue_skipped($pdo, 1);
                if ($vres) echo "  video: rescued {$vres} skipped story(ies) that have people now\n";
                $pdo = db_alive();
            }
            $pdo = db_alive();   // 2026-08-23: minutes of AI + people lookups just ran; MySQL may have hung up
            // convert the pre-playbook backlog: rewrite old-style pending scripts (tpl<2)
            // a few per tick so every future render uses the creator template
            $vr = video_scripts_retemplate($pdo, 3);
            if ($vr) { echo "  video: re-templated {$vr} old-style script(s)\n"; cnote("video: re-templated {$vr} scripts to creator playbook"); }
            $pdo = db_alive();
            // 2026-09-24 NOTHING WAITS FOREVER. A pending script that needs a shot
            // list and has had none for 7 days is set aside with a reason instead of
            // sitting in the queue: term pages (114 since 09-05, 1092, 1128) can never
            // get one (the Director below plans drama stories only), and a story
            // that old is no longer news. One cheap UPDATE per tick.
            try {
                $aged = $pdo->exec("UPDATE video_scripts SET video_status='skipped',
                                           skip_reason='set aside: no shot list after 7 days (the Director never planned it)'
                                     WHERE video_status='pending' AND tpl>=2 AND shotlist IS NULL
                                       AND created_at < NOW() - INTERVAL 7 DAY");
                if ($aged) echo "  video: set aside {$aged} script(s) with no shot list after 7 days\n";
            } catch (Throwable $e) { echo "  video age-out failed: " . $e->getMessage() . "\n"; }
            // v4 DIRECTOR backfill: pending creator-era scripts written before the
            // Director existed get their word-anchored shot list (2/tick, ~2min each)
            $need = $pdo->query("SELECT v.page_id, d.people_json FROM video_scripts v
                                 JOIN pages p ON p.id=v.page_id JOIN dramas d ON d.page_id=p.id
                                 WHERE v.video_status='pending' AND v.tpl>=2 AND v.shotlist IS NULL
                                 ORDER BY v.created_at DESC LIMIT 2")->fetchAll();
            $vd = 0;
            require_once __DIR__ . '/video_people.php';
            foreach ($need as $nrow) {
                // r187: a Director pass runs up to ~9 min and the tick is killed at
                // 1800s, so never START one past the same +20m line
                if (time() - $tickT0 >= $vCut) { echo '  video: Director stopped at +' . (int)round((time() - $tickT0) / 60) . "m (tick is killed at +30m)\n"; break; }
                // r158: people_json first, else the names the video gate's AI extraction
                // cached for this page, so a streamer story is not directed as "(none)"
                $ppl = vp_known_names((int)$nrow['page_id'], (string)$nrow['people_json'], 4);
                if (video_write_shotlist($pdo, (int)$nrow['page_id'], $ppl)) $vd++;
            }
            if ($vd) { echo "  video: directed {$vd} shot list(s)\n"; cnote("video: DIRECTOR wrote {$vd} shot list(s)"); }
            $pdo = db_alive();
            // WAF-proof static job feed: pre-write the maker's next jobs as a plain
            // /media/ JSON (the api endpoint gets 403'd for runner IPs; media never is)
            try { require_once __DIR__ . '/video_feed.php'; video_feed_static_write($pdo); }
            catch (Throwable $e) { echo "  video feed static failed: " . $e->getMessage() . "\n"; }
        } catch (Throwable $e) { echo "  video script step failed: " . $e->getMessage() . "\n"; }
        // 2026-08-23 THE SILENT TICK KILLER. The video step above spends minutes
        // in AI calls and people lookups; MySQL's wait_timeout drops the idle
        // connection ("2006 server has gone away" - 51 times since July 12). The
        // very next line used to query the DEAD handle outside any try/catch, so
        // the whole rest of the tick - velocity, discovery, select, drafting,
        // every lane - died silently. The site published nothing for days and
        // the log showed only the tick header. db_alive() already existed for
        // exactly this (SEO-BATCH-1); it just was never called here.
        $pdo = db_alive();
        // ---- CLIP SUPPLY, MOVED HERE (r159, 2026-09-11, owner: the videos must be
        // built out of real CLIPS). It used to sit last, inside the intelligence block
        // behind scout + term discovery + desk + rolodex, and it was reached in 18 of
        // the 97 ticks since 09-08 - while 09-11 alone wrote 16 scripts, 9 of them with
        // no clip at all. Nothing ABOVE this line moves: drama discovery, the drama
        // builds, the slang/meme/gaming lanes and the video factory have all already
        // run, so this stage cannot starve them. It can only take time from the stages
        // BELOW it (governor, scout, desk, rolodex, eyes, brain), every one of which
        // has its own time guard and skips itself cleanly. Three brakes: it does not
        // start after +25m, it carries its own 300s wall clock, and it is wrapped so
        // its failure cannot end the tick.
        $clipsEarly = false;
        if (time() - $tickT0 < 1560) {
            try {
                require_once __DIR__ . '/clip_supply.php';
                $pdo = db_alive();
                [$ck, $pl, $gn] = clip_replan($pdo, 6, 300);
                $clipsEarly = true;
                echo "clips: replanned {$ck} script(s), {$gn} gained footage, {$pl} fetchable clip(s) planned\n";
                if ($gn) cnote("clips: {$gn} story(ies) gained footage the server can fetch");
                $pdo = db_alive();
            } catch (Throwable $e) { echo '  clips skipped: ' . $e->getMessage() . "\n"; try { $pdo = db_alive(); } catch (Throwable $e2) {} }
        } else { echo '  clips: skipped at +' . (int)round((time() - $tickT0) / 60) . "m (time budget)\n"; }
        echo "tick done (+" . (int)round((time() - $tickT0) / 60) . "m) | drama built={$built} ready={$ready} | terms built={$tbuilt} published={$tpub}\n";

        // ---- THE GOVERNOR (organ 13). Rides the tick that already exists rather
        // than adding a cron of its own - the host is shared with other people's
        // sites. A full round is read-only and takes about 25ms. It must never be
        // the reason a tick fails, so it is wrapped and its own failure is spoken.
        try {
            // THE EXPERIMENT DESK (organ 06) guardrail. It may only STOP a test.
            try {
                require_once __DIR__ . '/experiment.php';
                foreach (xp_guard($pdo) as $k)
                    echo "  EXPERIMENT KILLED #{$k['id']} {$k['name']}: {$k['why']}\n";
            } catch (Throwable $e) { echo "  guardrail failed: " . $e->getMessage() . "\n"; }
            require_once __DIR__ . '/governor.php';
            $pdo = db_alive();
            $gr = gov_round($pdo, true);
            $alarms = count(array_filter($gr['findings'], fn($f) => $f['severity'] === 'alarm'));
            if ($gr['findings']) {
                echo 'governor: ' . $alarms . ' alarm(s), '
                   . (count($gr['findings']) - $alarms) . " watch item(s)\n";
                foreach ($gr['findings'] as $f) echo '  ! ' . $f['title'] . "\n";
                cnote('governor: ' . $alarms . ' alarm(s) open');
            } else {
                // Law 5: 'nothing wrong' must never look like 'did not run'.
                echo "governor: all clear\n";
                cnote('governor: all clear');
            }
            foreach ($gr['cleared'] as $c) echo "governor: cleared {$c}\n";
        } catch (Throwable $e) { echo '  governor failed: ' . $e->getMessage() . "\n"; }
        // ---- THE EYES, RECONCILED (r148, 2026-09-09). The owner watched a video
        // the machine had passed and found it was mostly one repeated picture. The
        // eyes had never measured it: the measure hook sat on one of the two
        // delivery doors and the catch-up lived in the block this tick keeps
        // skipping. A RECONCILIATION LOOP cannot miss a door - it asks which
        // finished videos have no measurement and closes the gap, whatever route
        // they arrived by. It runs OUTSIDE the skippable block on its own budget,
        // because a watchman that only watches on quiet days is not a watchman.
        try {
            require_once __DIR__ . '/video_eyes.php';
            $pdo = db_alive();
            $ey = eyes_reconcile($pdo, 120, 5);
            if (!empty($ey['busy'])) echo "eyes: another run holds the lock; skipping\n";
            elseif ($ey['measured'] || $ey['left'] || $ey['missing_file'])
                echo "eyes: measured {$ey['measured']} video(s)" . ($ey['bad'] ? ", {$ey['bad']} BAD" : '') . ($ey['weak'] ? ", {$ey['weak']} weak" : '') . ($ey['missing_file'] ? ", {$ey['missing_file']} file missing" : '') . ", {$ey['left']} still unseen\n";
            $pdo = db_alive();
        } catch (Throwable $e) { echo '  eyes skipped: ' . $e->getMessage() . "\n"; try { $pdo = db_alive(); } catch (Throwable $e2) {} }
        // ---- INTELLIGENCE STAGES, MOVED HERE (r145, 2026-09-06). Measured: since
        // Sep 4 the tick was killed at its 1800s limit during the term builds,
        // because scout + desk + clips + eyes + brain + rolodex ran BEFORE the
        // core (drama discovery, drama builds, video, governor) and ate ~25 min.
        // No new drama story was discovered by the tick for two days and the
        // Governor went silent. Core first; intelligence last, only with time left.
        if (time() - $tickT0 < 1620) {
        echo 'intelligence stages start at +' . (int)round((time() - $tickT0) / 60) . "m\n";
        // THE SCOUT (2026-08-30, owner: "built the scout") — social-native
        // discovery: burst detection over the runner's listening harvest, then
        // an AI screen that infers meaning from the POSTS THEMSELVES. Promoted
        // terms land in candidates as selected; their posts become citations.
        if (time() - $tickT0 < 1380) try {
            require_once __DIR__ . '/scout.php';
            $pdo = db_alive();
            $sc = scout_run($pdo, 10);   // 2026-09-05: was 6. Measured 819 words past the burst bar waiting; 6/h = a 5-day queue, and slang went to zero behind it. Each screen is one cheap AI call.
            echo "scout: {$sc['posts']} posts heard, {$sc['tracked']} terms tracked, {$sc['screened']} screened, {$sc['promoted']} promoted\n";
            $pdo = db_alive();   // screening = AI calls, handle may be stale
        } catch (Throwable $e) { echo "  scout skipped: " . $e->getMessage() . "\n"; try { $pdo = db_alive(); } catch (Throwable $e2) {} }
        require_once __DIR__ . '/discover_terms.php';
        if (time() - $tickT0 < 1380) try {
            $disc = discover_terms_run();
        // ---- HIDDEN-PAGE REPAIR (2026-08-27) ----------------------------
        // 79 finished pages are live but noindex, 45 of them slang. They fail
        // on citations / on-topic sources because they were BUILT BEFORE the
        // retrieval was repaired (the topical screen was reading cookie
        // banners). Re-fetching sources fixes them, but it costs live HTTP
        // plus an AI screen per page - a one-shot run over all 79 wrote
        // nothing in 7 minutes and had to be killed. So it runs HERE, a few
        // per tick, for as long as it takes. Non-fatal by construction.
        try {
            $pdo = db_alive();   // discovery above = minutes of AI; the handle is dead by now (2026-08-29, measured)
            require_once __DIR__ . '/term_resource.php';
            $tr = term_resource_run($pdo, 2);
            if ((int)($tr['checked'] ?? 0) > 0) {
                echo "  hidden-page repair: checked {$tr['checked']}, improved {$tr['improved']}\n";
                cnote("hidden-page repair: {$tr['improved']} improved");
            }
        } catch (Throwable $e) { echo "  hidden-page repair skipped: " . $e->getMessage() . "\n"; }
            echo "term discovery: harvested={$disc['harvested']} new={$disc['new']} selected={$disc['selected']}" . (isset($disc['note']) ? " ({$disc['note']})" : '') . "\n";
        } catch (Throwable $e) { echo "term discovery skipped: " . $e->getMessage() . "\n"; }
        // THE DESK (2026-09-05, owner go): the one general judge over every
        // signal the fetchers above dropped into the intake. SHADOW until
        // app/DESK_LIVE exists: verdicts are logged beside the doors' own,
        // nothing downstream changes. Budget: 30 cards, 240s, ≤3 AI calls.
        if (time() - $tickT0 < 1440) try {
            require_once __DIR__ . '/desk.php';
            $pdo = db_alive();
            $dk = desk_judge_run($pdo, 30, 240);
            echo "desk: {$dk['due']} cards due, {$dk['judged']} judged, {$dk['something']} something, {$dk['routed']} routed" . ($dk['live'] ? ' [LIVE]' : ' [shadow]') . ($dk['failed'] ? " ({$dk['failed']} batch failed)" : '') . "\n";
            $pdo = db_alive();
        } catch (Throwable $e) { echo "  desk skipped: " . $e->getMessage() . "\n"; try { $pdo = db_alive(); } catch (Throwable $e2) {} }
        // THE ROLODEX (2026-09-06, owner go): once a day, the mention alarm
        // (GA4 referrers) and the people-finder (search → read → AI extract).
        // Budget 220s, non-fatal, stamps itself so it never runs twice a day.
        if (time() - $tickT0 < 1560) try {
            require_once __DIR__ . '/rolodex.php';
            $pdo = db_alive();
            $rx = rolodex_daily($pdo, 220);
            if ($rx) echo 'rolodex: mentions ' . json_encode($rx['mentions']) . ' | finder ' . json_encode($rx['discover']) . "\n";
            $pdo = db_alive();
        } catch (Throwable $e) { echo "  rolodex skipped: " . $e->getMessage() . "\n"; try { $pdo = db_alive(); } catch (Throwable $e2) {} }
        // CLIP ROUTES (r140, 2026-09-06, owner: "fix the clip supply first"), and the
        // stages nested in this try ride with it. r159: the re-plan itself moved up to
        // the core, above the governor. What stays here is the daily route doctor plus
        // a TOP-UP re-plan for the ticks where the core one was already out of time.
        // The guard stays at +26m on purpose: this block still owns the eyes, story
        // posts, proofs and brain, and taking the expensive re-plan out of it is what
        // makes those MORE likely to be reached, not a later start. Non-fatal.
        if (time() - $tickT0 < 1560) try {
            require_once __DIR__ . '/clip_supply.php';
            $pdo = db_alive();
            [$ck, $pl] = empty($clipsEarly) ? clip_replan($pdo, 3, 150) : [0, 0];
            $probeStamp = __DIR__ . '/cache/clip_probe_last.txt';
            $probed = '';
            if (time() - (int)@file_get_contents($probeStamp) > 20 * 3600) {
                @file_put_contents($probeStamp, (string)time());
                $pr = clip_probe_routes($pdo);
                $probed = ' | routes: ' . implode(' ', array_map(fn($k, $v) => $k . '=' . ($v ? 'ok' : 'DOWN'), array_keys($pr), $pr));
            }
            if ($ck || $probed !== '') echo "clip routes: top-up replanned $ck script(s), $pl clip(s)" . $probed . "\n";
            $pdo = db_alive();
            // THE EYES (r141, 2026-09-06): measure a few of our own videos per
            // tick, learn once a day from the TikToks on our topics vs ours.
            try {
                require_once __DIR__ . '/video_eyes.php';
                $eb = 0;   // r148: our own videos are reconciled above, outside this block
                $er = eyes_backfill_rivals($pdo, 4);
                $eyesStamp = __DIR__ . '/cache/eyes_learn_last.txt';
                $el = '';
                if (time() - (int)@file_get_contents($eyesStamp) > 20 * 3600) {
                    @file_put_contents($eyesStamp, (string)time());
                    $lr = eyes_learn($pdo);
                    $el = ' | learn: ' . ($lr['written'] ? 'visual_shape written (' . $lr['rivals'] . ' rivals vs ' . $lr['ours'] . ' ours)' : ($lr['note'] ?? 'nothing'));
                }
                echo "eyes: $er rival clip(s) measured" . $el . "\n";
                $pdo = db_alive();
            } catch (Throwable $e) { echo "  eyes skipped: " . $e->getMessage() . "\n"; try { $pdo = db_alive(); } catch (Throwable $e2) {} }
            // POSTS ABOUT THIS STORY (r152, 2026-09-10): once a day, queue the X posts the
            // video pipeline found for the owner's approval and re-check the ones showing on
            // pages, so a post its author deletes on X leaves the page. Bounded to 90 seconds.
            try {
                $spStamp = __DIR__ . '/cache/story_posts_last.txt';
                if (time() - (int)@file_get_contents($spStamp) > 20 * 3600) {
                    @file_put_contents($spStamp, (string)time());
                    require_once __DIR__ . '/story_posts.php';
                    $pdo = db_alive();
                    $spr = sp_refresh_all($pdo, 90);
                    echo "story posts: {$spr['pages']} page(s) checked, {$spr['new_pending']} new waiting for approval, {$spr['approved_gone']} removed (deleted on X)" . ($spr['stopped_early'] ? ' (time budget reached)' : '') . "\n";
                    $pdo = db_alive();
                }
            } catch (Throwable $e) { echo "  story posts skipped: " . $e->getMessage() . "\n"; try { $pdo = db_alive(); } catch (Throwable $e2) {} }
            // PROOF SCREENSHOTS (r155, 2026-09-11, owner: show the posts used as proofs): publish up
            // to 6 screenshots the runner captured, after the same safety check the receipts use.
            // Bounded to 90 seconds; does nothing until app/proofs_engine.php exists.
            if (time() - $tickT0 < 1500 && is_file(__DIR__ . '/proofs_engine.php')) try {
                require_once __DIR__ . '/proofs_engine.php';
                $pdo = db_alive();
                $prf = proofs_promote($pdo, 6, 90);
                if (($prf['promoted'] ?? 0) || ($prf['rejected'] ?? 0)) echo "proofs: " . (int)$prf['promoted'] . " published, " . (int)$prf['rejected'] . " rejected, " . (int)($prf['left'] ?? 0) . " waiting\n";
                $pdo = db_alive();
            } catch (Throwable $e) { echo "  proofs skipped: " . $e->getMessage() . "\n"; try { $pdo = db_alive(); } catch (Throwable $e2) {} }
            // THE BRAIN (organ 14, 2026-09-06): once a day, one bounded action,
            // measured and reversible. Shadow until app/BRAIN_LIVE exists.
            try {
                require_once __DIR__ . '/brain.php';
                $pdo = db_alive();
                $br = brain_run($pdo, false);
                if (isset($br['action'])) echo "brain [{$br['mode']}]: {$br['action']}" . ($br['lever'] ? " {$br['lever']}={$br['value']}" : '') . ($br['acted'] ? '' : ' (recorded only)') . ' | ' . mb_substr((string)$br['why'], 0, 120) . ($br['refused'] ? " | refused: {$br['refused']}" : '') . "\n";
                elseif (isset($br['note']) && $br['note'] !== 'already ran today') echo "brain: {$br['note']}\n";
                $pdo = db_alive();
            } catch (Throwable $e) { echo "  brain skipped: " . $e->getMessage() . "\n"; try { $pdo = db_alive(); } catch (Throwable $e2) {} }
        } catch (Throwable $e) { echo "  clips skipped: " . $e->getMessage() . "\n"; try { $pdo = db_alive(); } catch (Throwable $e2) {} }
        } else { echo 'intelligence stages skipped: tick already at +' . (int)round((time() - $tickT0) / 60) . "m\n"; }
        break;

    case 'watchdog':
        // SELF-AUDIT (manual run): php app/cli.php watchdog [fixLimit]
        set_time_limit(1800);
        $r = watchdog_run(($arg && ctype_digit($arg)) ? (int)$arg : 6);
        require_once __DIR__ . '/sitemap_lib.php';
        echo "sitemap: " . sitemap_build() . "
";
        echo "watchdog: healthy={$r['ok']} fixed={$r['fixed']} restored={$r['restored']} pulled={$r['pulled']} of {$r['scanned']} pages | seo-drift={$r['seo_drift']} baseline-seeded={$r['seo_baseline_seeded']}\n";
        break;

    case 'terms':
        // build N slang terms from the backlog: draft -> gate -> auto-publish
        require_once __DIR__ . '/draft_term.php';
        require_once __DIR__ . '/gate_term.php';
        set_time_limit(1800);
        $N = ($arg && ctype_digit($arg)) ? max(1, min(20, (int)$arg)) : 3;
        $terms = $pdo->query("SELECT id, name, type FROM candidates WHERE status='selected' AND type IN ('term','meme','gaming','music') AND heat_score >= 50 ORDER BY heat_score DESC, id ASC LIMIT 40")->fetchAll();
        if (!$terms) { echo "no term candidates queued (status=selected type=term)\n"; break; }
        $built = 0; $pub = 0;
        foreach ($terms as $tc) {
            if ($built >= $N) break;
            echo "build '{$tc['name']}' ... ";
            $d = draft_term(['term' => $tc['name'], 'lane' => lane_for_cand_type($tc['type']) ?? 'slang']);
            if (isset($d['error'])) {
                echo "FAIL: {$d['error']}\n";
                if (strpos($d['error'], 'already exists') !== false) $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason='dup' WHERE id=?")->execute([$tc['id']]);
                continue;
            }
            $g = gate_check_term((int)$d['page_id']);
            echo "{$d['slug']} | gate=" . ($g['pass'] ? 'PASS' : 'FAIL') . " (via {$d['provider']})\n";
            if (!$g['pass']) foreach (array_filter($g['checks'], fn($c) => !$c['pass']) as $f) echo "    - {$f['label']} ({$f['detail']})\n";
            $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason='built' WHERE id=?")->execute([$tc['id']]);
            if ($g['pass'] && !empty($CONFIG['auto_publish'])) {
                $seo = seo_audit_page((int)$d['page_id']);
                if (!$seo['pass']) {
                    foreach ($seo['fails'] as $sf) echo "  SEO-GATE-FAIL {$d['slug']}: {$sf}\n";
                } else {
                    $pp = $pdo->prepare("SELECT path FROM pages WHERE id=?"); $pp->execute([(int)$d['page_id']]);
                    page_publish_live($pdo, (int)$d['page_id']);   // SEO-BATCH-1 choke point
                    echo "    AUTO-PUBLISHED https://genzhype.com{$pp->fetchColumn()}\n";
                    $pp2 = $pdo->prepare("SELECT path FROM pages WHERE id=?"); $pp2->execute([(int)$d['page_id']]);
                    indexnow_ping([rtrim($CONFIG['base_url'],'/') . $pp2->fetchColumn()]);
                    $pub++;
                }
            }
            $built++;
        }
        echo "sitemap: " . sitemap_build() . "\n";
        echo "terms done | built={$built} published={$pub}\n";
        break;

    case 'meaning-audit':
        // Sweep published terms through the meaning-currency verifier.
        // Usage: php app/cli.php meaning-audit [offset] [limit]   (run in small
        // foreground batches; ~1 AI call per page)
        require_once __DIR__ . '/verify_term.php';
        set_time_limit(1800);
        $off = ($arg !== null && ctype_digit($arg)) ? (int)$arg : 0;
        $lim = (isset($argv[3]) && ctype_digit($argv[3])) ? max(1, min(40, (int)$argv[3])) : 12;
        $rows = $pdo->query("SELECT t.page_id, p.slug, t.term, t.short_def, p.summary FROM terms t
                             JOIN pages p ON p.id=t.page_id WHERE p.status='published'
                             ORDER BY t.page_id ASC LIMIT {$lim} OFFSET {$off}")->fetchAll();
        if (!$rows) { echo "no published terms in range (offset {$off})\n"; break; }
        $flagged = 0;
        foreach ($rows as $r) {
            $mc = meaning_currency_check($r['term'], $r['short_def'], $r['summary'] ?? '');
            ai_log((int)$r['page_id'], 'verify', $mc['res'] ?? [],
                   ['type' => 'meaning', 'verdict' => $mc['verdict'], 'confidence' => $mc['confidence'], 'audit' => true],
                   $mc['pass']);
            if ($mc['pass']) {
                printf("  [PASS] %-28s %s (conf %.2f)\n", $r['slug'], $mc['verdict'], $mc['confidence']);
            } else {
                printf("  [FLAG] %-28s %s (conf %.2f) => dominant: %s\n", $r['slug'], $mc['verdict'], $mc['confidence'], mb_substr($mc['dominant_meaning'], 0, 110));
                $flagged++;
            }
        }
        echo "meaning-audit | checked " . count($rows) . " (offset {$off}) | flagged {$flagged}\n";
        break;

    case 'discover':
        // harvest + AI-filter fresh slang terms into the backlog
        require_once __DIR__ . '/discover_terms.php';
        $r = discover_terms_run();
        echo "discovery | harvested={$r['harvested']} new={$r['new']} selected={$r['selected']} rejected=" . ($r['rejected'] ?? 0) . (isset($r['note']) ? " | {$r['note']}" : '') . (isset($r['provider']) ? " (via {$r['provider']})" : '') . "\n";
        if ($r['selected'] > 0) {
            foreach ($pdo->query("SELECT name, heat_score FROM candidates WHERE type='term' AND status='selected' ORDER BY id DESC LIMIT {$r['selected']}")->fetchAll() as $c) {
                echo "  + {$c['name']} (h{$c['heat_score']})\n";
            }
        }
        break;

    case 'term1':
        // build ONE term by name (manual): php cli.php term1 "rizz"
        if (!$arg) exit("usage: term1 \"<slang term>\"\n");
        require_once __DIR__ . '/draft_term.php';
        require_once __DIR__ . '/gate_term.php';
        $d = draft_term(['term' => $arg]);
        if (isset($d['error'])) { echo "DRAFT FAILED: {$d['error']}\n"; exit(1); }
        $g = gate_check_term((int)$d['page_id']);
        echo "DRAFTED {$d['slug']} | gate=" . ($g['pass'] ? 'PASS' : 'FAIL') . " (via {$d['provider']})\n";
        table($g['checks']);
        echo "preview: https://genzhype.com/slang/{$d['slug']}/?preview=1\n";
        break;

    default:
        echo "commands: list | candidates [status] | select [id|N] | fetch <id> | auto <id> | build = auto | gate <slug> | audit | quality <slug> | publish <slug> | unpublish <slug> | sitemap | draft <json> | verify <slug> | pipeline <json> | cron [N]\n";
}
