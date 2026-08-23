<?php
// GenZHype | operator CLI. Usage:
//   php app/cli.php list                  - all pages + status
//   php app/cli.php gate <slug>           - run the publish gate on a drama
//   php app/cli.php audit                 - gate every published drama
//   php app/cli.php publish <slug>        - gate, and if pass: robots=index + sitemap + IndexNow
//   php app/cli.php unpublish <slug>      - robots=noindex + sitemap
//   php app/cli.php sitemap               - regenerate sitemap.xml
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }

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
        $twin = dup_redundant_copy($pdo, (int)$h['id']);
        if ($twin) {
            echo "  HELD-DUPLICATE {$h['path']}: {$twin['reason']} as /{$twin['slug']}/ - not re-judged\n";
            if (!$dry) cnote("HELD-DUPLICATE {$h['path']} -> {$twin['slug']}");
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

    case 'imgbackfill':
        require_once __DIR__ . '/drama_image.php';
        $lim = ($arg && ctype_digit($arg)) ? (int)$arg : 5;
        $ib = drama_image_backfill_run($pdo, $lim);
        echo "image backfill: tried={$ib['tried']} fixed={$ib['fixed']} still-card={$ib['still']} dignity-final={$ib['dignity_final']}\n";
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
        // MUTUAL EXCLUSION: one tick at a time. A long tick (PSI/vision/AI) must not
        // overlap the next hourly fire — overlap = double-builds + a race on the
        // in-memory velocity $slots that could exceed the daily publish cap (the
        // anti scaled-content-abuse guard). The lock auto-releases on process exit.
        $GLOBALS['__cron_lock'] = fopen(sys_get_temp_dir() . '/genzhype_cron.lock', 'c');
        if (!$GLOBALS['__cron_lock'] || !flock($GLOBALS['__cron_lock'], LOCK_EX | LOCK_NB)) {
            echo "[" . date('c') . "] a tick is already running; skipping this fire\n";
            break;
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
        echo "[" . date('c') . "] autopilot tick (N={$N}, auto_publish=" . (!empty($CONFIG['auto_publish']) ? 'ON' : 'off') . ")\n";

        // ---- REDDIT RADAR: pre-draft comments for new opportunities so the admin
        // never waits on a slow AI call (that caused a 504). Bounded batch; CLI has no
        // web timeout, so this uses the full robust provider chain (not the fast path).
        try {
            require_once __DIR__ . '/reddit_radar.php';
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

        // ---- VIDEO FACTORY: pre-generate faceless-video voiceover scripts for new dramas so the
        // maker (GitHub Actions) can render instantly. CLI = full robust AI chain, no web timeout.
        try {
            require_once __DIR__ . '/video_factory.php';
            // Everything above (framing-repair, redrain, verify) spends real time on
            // AI and network. The handle is often already dead by the time we get
            // here - which is why the video step failed INSTANTLY on every tick,
            // before it ever reached an AI call of its own.
            $pdo = db_alive();
            $vn = video_scripts_generate($pdo, 2);
            if ($vn) echo "  video: pre-generated {$vn} video script(s)\n";
            $pdo = db_alive();   // 2026-08-23: minutes of AI + people lookups just ran; MySQL may have hung up
            // convert the pre-playbook backlog: rewrite old-style pending scripts (tpl<2)
            // a few per tick so every future render uses the creator template
            $vr = video_scripts_retemplate($pdo, 3);
            if ($vr) { echo "  video: re-templated {$vr} old-style script(s)\n"; cnote("video: re-templated {$vr} scripts to creator playbook"); }
            $pdo = db_alive();
            // v4 DIRECTOR backfill: pending creator-era scripts written before the
            // Director existed get their word-anchored shot list (2/tick, ~2min each)
            $need = $pdo->query("SELECT v.page_id, d.people_json FROM video_scripts v
                                 JOIN pages p ON p.id=v.page_id JOIN dramas d ON d.page_id=p.id
                                 WHERE v.video_status='pending' AND v.tpl>=2 AND v.shotlist IS NULL
                                 ORDER BY v.created_at DESC LIMIT 2")->fetchAll();
            $vd = 0;
            foreach ($need as $nrow) {
                $ppl = [];
                foreach ((array)json_decode((string)$nrow['people_json'], true) as $pp) {
                    $nm = trim((string)(is_array($pp) ? ($pp['name'] ?? '') : $pp));
                    if ($nm !== '') $ppl[] = $nm;
                }
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

        // ---- VELOCITY GUARDRAIL: cap pages going LIVE per day (anti scaled-abuse) ----
        require_once __DIR__ . '/gate_term.php';
        require_once __DIR__ . '/quality.php';
        $dailyCap = (int)($CONFIG['daily_publish_cap'] ?? 12);
        $pubToday = (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE status='published' AND robots='index' AND published_at >= CURDATE()")->fetchColumn();
        $slots = max(0, $dailyCap - $pubToday);
        // drain the HELD queue via the single guarded path (see velocity_drain)
        $dr = velocity_drain($pdo, $slots);
        if (!empty($dr['refused'])) echo "  velocity-drain: REFUSED ({$dr['why']})\n";
        echo "velocity: cap={$dailyCap} published_today={$pubToday} slots_left={$slots}\n";

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
        // SELF-HEALING IMAGES: re-try real photos for dramas stuck on a branded card because
        // vision was rate-limited at draft time. A few per day (quota-safe); dignity stories
        // are finalized as cards and never retried.
        if ($hr === 7) {
            require_once __DIR__ . '/drama_image.php';
            $ib = drama_image_backfill_run($pdo, 3);
            if ($ib['tried'] || $ib['fixed'] || $ib['dignity_final'])
                echo "image backfill: tried={$ib['tried']} fixed={$ib['fixed']} still-card={$ib['still']} dignity-final={$ib['dignity_final']}\n";
        }
        // SOCIAL STUDIO: turn the newest pages into the per-platform post queue (admin Social tab).
        if ($hr === 8) {
            require_once __DIR__ . '/social_studio.php';
            $ss = social_studio_daily($pdo, 3);
            if (!empty($ss['pages_socialized'])) echo "social studio: +{$ss['pages_socialized']} pages socialized\n";
        }

        // server-side drama discovery: creator-culture RSS feeds -> keyword filter -> queue
        require_once __DIR__ . '/discover_dramas.php';
        try {
            $dd = discover_dramas_run();
            echo "drama discovery: seen={$dd['seen']} queued={$dd['queued']} dropped={$dd['dropped']}" . ($dd['dead_feeds'] ? " dead=" . implode(',', $dd['dead_feeds']) : '') . "\n";
        } catch (Throwable $e) { echo "drama discovery skipped: " . $e->getMessage() . "\n"; }
        $sel = select_run(15);
        echo "select: +{$sel['selected']} / -{$sel['rejected']} (errors {$sel['errors']})\n";
        // 2026-08-23 RETRY BUDGET (Phase 0 of the learning-machine build): a
        // failing candidate may try 5 times, then retires with its last error
        // written down — the drama_img_retry pattern (attempts + cap) applied
        // to drafting. Fresh candidates draft BEFORE repeat offenders, so one
        // stubborn story can never starve the queue again (#8973 failed 29x).
        foreach (["ADD COLUMN draft_attempts TINYINT NOT NULL DEFAULT 0",
                  "ADD COLUMN last_error VARCHAR(255) NULL"] as $alt) {
            try { $pdo->exec("ALTER TABLE candidates {$alt}"); } catch (Throwable $e) {}
        }
        $cands = $pdo->query("SELECT id, name, COALESCE(draft_attempts,0) tries FROM candidates
                              WHERE status='selected' AND type='drama' AND COALESCE(draft_attempts,0) < 5
                              ORDER BY COALESCE(draft_attempts,0) ASC, heat_score DESC, id DESC LIMIT 12")->fetchAll();
        $built = 0; $ready = 0;
        foreach ($cands as $cd) {
            if ($built >= $N) break;
            $src = fetch_sources_for_candidate((int)$cd['id']);
            if (isset($src['error'])) {
                echo "  skip #{$cd['id']}: {$src['error']}\n";
                $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason=? WHERE id=?")->execute(['autopilot: ' . mb_substr($src['error'],0,200), $cd['id']]);
                continue;
            }
            $d = draft_drama($src);
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
            $v = verify_drama((int)$d['page_id']);
            $q = quality_check_drama((int)$d['page_id']);
            $g = gate_check_drama((int)$d['page_id']);
            $ok = ($v['pass'] ?? false) && ($q['pass'] ?? false) && ($g['pass'] ?? false);
            echo "  built {$d['slug']} | v=" . (($v['pass'] ?? 0)?'P':'i') . " q=" . (($q['pass'] ?? 0)?'P':'F') . " g=" . (($g['pass'] ?? 0)?'P':'F') . ($ok ? "  => READY" : "") . "\n";
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
                    'draft'   => ['provider' => (string)($d['provider'] ?? ''), 'events' => (int)($d['events'] ?? 0)],
                    'verify'  => ['pass' => (bool)($v['pass'] ?? false), 'issues' => array_slice((array)($v['issues'] ?? []), 0, 5)],
                    'quality' => ['pass' => (bool)($q['pass'] ?? false), 'scores' => $q['scores'] ?? null, 'flags' => $q['flags'] ?? []],
                    'gate'    => ['pass' => (bool)($g['pass'] ?? false), 'failed' => $gateFailed],
                    'ready'   => $ok,
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
        // ---- SLANG lane: discover fresh terms, then build from the backlog ----
        require_once __DIR__ . '/draft_term.php';
        require_once __DIR__ . '/gate_term.php';
        require_once __DIR__ . '/discover_terms.php';
        try {
            $disc = discover_terms_run();
            echo "term discovery: harvested={$disc['harvested']} new={$disc['new']} selected={$disc['selected']}" . (isset($disc['note']) ? " ({$disc['note']})" : '') . "\n";
        } catch (Throwable $e) { echo "term discovery skipped: " . $e->getMessage() . "\n"; }
        $TN = ($argv[3] ?? null) && ctype_digit((string)$argv[3]) ? max(0, min(10, (int)$argv[3])) : 2;
        $terms = $pdo->query("SELECT id, name, type FROM candidates WHERE status='selected' AND type IN ('term','meme','gaming','music') AND heat_score >= 50 ORDER BY heat_score DESC, id ASC LIMIT 12")->fetchAll();
        $tbuilt = 0; $tpub = 0;
        foreach ($terms as $tc) {
            if ($tbuilt >= $TN) break;
            $d = draft_term(['term' => $tc['name'], 'lane' => lane_for_cand_type($tc['type']) ?? 'slang']);
            if (isset($d['error'])) {
                echo "  term skip '{$tc['name']}': {$d['error']}\n";
                // only retire on permanent errors (dup); transient fetch fails stay queued
                if (strpos($d['error'], 'already exists') !== false) {
                    $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason='dup' WHERE id=?")->execute([$tc['id']]);
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
        echo "tick done | drama built={$built} ready={$ready} | terms built={$tbuilt} published={$tpub}\n";

        // ---- THE GOVERNOR (organ 13). Rides the tick that already exists rather
        // than adding a cron of its own - the host is shared with other people's
        // sites. A full round is read-only and takes about 25ms. It must never be
        // the reason a tick fails, so it is wrapped and its own failure is spoken.
        try {
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
