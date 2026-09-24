<?php
/**
 * GenZHype | THE EXECUTIVE — organ 14 of the learning machine (2026-09-06)
 * =============================================================================
 * WHAT WAS MISSING. Thirteen organs could see (Record, Eyes, Outside Intake,
 * the video eyes, the clip meter), remember (Memory, Playbook), test
 * (Experiment Desk, Proving Ground), advise (Strategist) and watch (Governor).
 * None had authority. Every organ proposed, the owner ruled, and execution
 * waited for a human: three approved fixes sat for two weeks. Owner, 2026-09-06:
 * "the machine learning is where all the systems drop... this one is the brain
 * and the executor, to keep upgrading on itself."
 *
 * WHAT THIS IS. One desk, once a day, with bounded authority:
 *   GATHER  every organ's numbers into one compact sheet (no raw text dumps).
 *   DECIDE  one action from a FIXED MENU, through the rotating AI chain
 *           (ai_chat: 4 providers, 11 models, falls through on every limit).
 *   ACT     text levers via the Memory (prompt directives, as today);
 *           numeric levers via brain_levers (new) that the writers read here
 *           and the video maker receives inside the feed it already reads;
 *           experiments via the Experiment Desk; adoption of a WIN by itself.
 *   MEASURE every action has a window and a yardstick; better = keep, worse =
 *           revert, by itself. Every step is a row the owner can read.
 *   ASK     a lever it does not have becomes a request with evidence (the
 *           self-upgrade path: the builder adds the lever, the brain pulls it).
 *
 * THE LIMITS (set here, not by the model; the model cannot widen them):
 *   - at most BRAIN_MAX_ACTIONS_PER_DAY action(s) per day; one numeric lever
 *     under measurement at a time; one experiment per surface (Desk rule 5).
 *   - every lever has a floor and a ceiling; unknown keys are refused; values
 *     are clamped here AND again on the runner (defense in depth).
 *   - no verdict below BRAIN_MIN_VIDEOS videos in the window (Desk rule 3).
 *   - kill switch: app/BRAIN_OFF. Mode: app/BRAIN_LIVE present = live, absent =
 *     SHADOW (decides and logs, touches nothing). Both are files, no deploy.
 *   - never touches page content, credentials, money, posting schedules, or
 *     grave stories (those are exempt in the writers already).
 *   - the Governor watches the brain: too many actions, stale windows, or a
 *     revert streak raise an alarm (brain_governor_check).
 *
 * SERVER RULES. Runs inside the hourly tick, once a day, budget BRAIN_BUDGET_S,
 * ONE AI call, db_alive() after it. Hot paths (writers, bridge) read levers from
 * a file cache (cache/levers.json, TTL BRAIN_CACHE_TTL) with the registry
 * default as fallback, so a DB hiccup can never change a video. Tables are
 * indexed and bounded; brain_runs is pruned at 90 days; actions are never
 * deleted (audit trail).
 */
declare(strict_types=1);

const BRAIN_OFF_FLAG   = __DIR__ . '/BRAIN_OFF';
const BRAIN_LIVE_FLAG  = __DIR__ . '/BRAIN_LIVE';
const BRAIN_CACHE_FILE = __DIR__ . '/cache/levers.json';
const BRAIN_CACHE_TTL  = 300;
const BRAIN_BUDGET_S   = 150;
const BRAIN_MAX_ACTIONS_PER_DAY = 1;
const BRAIN_WINDOW_DAYS = 7;
const BRAIN_MIN_VIDEOS  = 6;
const BRAIN_KEEP_GAIN   = 0.05;    // primary yardstick must improve by 5% (relative) to keep
const BRAIN_MAX_HARM    = 0.10;    // secondary yardstick may not fall more than 10%

/* THE LEVER REGISTRY. The only numbers the brain may move, with their walls.
 * 'env' = the maker reads it from the workflow environment (delivered through
 * feed/levers.env); 'yard' = the primary yardstick that decides keep/revert. */
function brain_registry(): array {
    return [
        'footage_frac'         => ['default' => 0.85, 'min' => 0.50, 'max' => 1.00, 'step' => 0.05, 'env' => 'VIDEO_FOOTAGE_FRAC',       'yard' => 'live_ratio', 'what' => 'share of the video that may be real clips'],
        'footage_max_scenes'   => ['default' => 16,   'min' => 8,    'max' => 16,   'step' => 1,    'env' => 'VIDEO_FOOTAGE_MAX_SCENES', 'yard' => 'live_ratio', 'what' => 'how many scenes may carry a clip'],
        'footage_consec'       => ['default' => 6,    'min' => 3,    'max' => 8,    'step' => 1,    'env' => 'VIDEO_FOOTAGE_CONSEC',     'yard' => 'live_ratio', 'what' => 'clips allowed back to back'],
        'clip_hunt_min'        => ['default' => 5,    'min' => 3,    'max' => 8,    'step' => 1,    'env' => 'VIDEO_CLIP_HUNT_MIN',      'yard' => 'live_ratio', 'what' => 'clips the maker hunts for per story'],
        'clips_per_story'      => ['default' => 6,    'min' => 3,    'max' => 8,    'step' => 1,    'env' => null,                       'yard' => 'live_ratio', 'what' => 'clips the server stages per story'],
        'hunt_relevance_words' => ['default' => 1,    'min' => 1,    'max' => 2,    'step' => 1,    'env' => null,                       'yard' => 'judge_pass', 'what' => 'shared words a hunted TikTok must have with the story (1 loose, 2 strict)'],
        'title_target_chars'   => ['default' => 0,    'min' => 24,   'max' => 70,   'step' => 2,    'env' => null,                       'yard' => 'views',      'what' => 'title length target (0 = use the learned value)'],
        'script_words_scale'   => ['default' => 1.00, 'min' => 0.80, 'max' => 1.20, 'step' => 0.05, 'env' => null,                       'yard' => 'views',      'what' => 'multiplies the script word budget'],
    ];
}

function brain_live(): bool { return !is_file(BRAIN_OFF_FLAG) && is_file(BRAIN_LIVE_FLAG); }
function brain_off(): bool  { return is_file(BRAIN_OFF_FLAG); }

function brain_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS brain_levers (
        lever_key VARCHAR(40) PRIMARY KEY,
        value DECIMAL(8,3) NOT NULL,
        updated_by ENUM('default','owner','brain') NOT NULL DEFAULT 'default',
        why VARCHAR(255) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS brain_actions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        at DATETIME NOT NULL,
        mode ENUM('shadow','live') NOT NULL,
        kind ENUM('noop','lever','experiment','adopt','revert','request') NOT NULL,
        lever_key VARCHAR(40) NULL,
        before_val DECIMAL(8,3) NULL,
        after_val DECIMAL(8,3) NULL,
        ref_id INT UNSIGNED NULL,
        why VARCHAR(255) NOT NULL DEFAULT '',
        evidence VARCHAR(400) NOT NULL DEFAULT '',
        yardstick VARCHAR(20) NOT NULL DEFAULT '',
        baseline_json TEXT NULL,
        window_ends DATETIME NULL,
        outcome ENUM('shadow','pending','kept','reverted','stopped','done') NOT NULL,
        outcome_note VARCHAR(255) NOT NULL DEFAULT '',
        outcome_at DATETIME NULL,
        KEY idx_outcome (outcome, window_ends),
        KEY idx_at (at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS brain_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        at DATETIME NOT NULL,
        title VARCHAR(140) NOT NULL,
        why VARCHAR(400) NOT NULL DEFAULT '',
        evidence VARCHAR(400) NOT NULL DEFAULT '',
        status ENUM('open','building','done','dismissed') NOT NULL DEFAULT 'open',
        UNIQUE KEY u_title (title)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS brain_runs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        at DATETIME NOT NULL,
        mode ENUM('shadow','live','off') NOT NULL,
        provider VARCHAR(60) NOT NULL DEFAULT '',
        ms INT NOT NULL DEFAULT 0,
        gathered_json MEDIUMTEXT NULL,
        decision_json TEXT NULL,
        action_id INT UNSIGNED NULL,
        note VARCHAR(255) NOT NULL DEFAULT '',
        KEY idx_at (at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ins = $pdo->prepare("INSERT IGNORE INTO brain_levers (lever_key, value, updated_by, why, updated_at) VALUES (?,?,'default','registry default',NOW())");
    foreach (brain_registry() as $k => $r) $ins->execute([$k, $r['default']]);
}

/* ---- LEVER READS: file cache first, registry default last, never a throw --- */
function brain_levers_all(?PDO $pdo = null): array {
    // r164: this was a function static with no way to clear it, so a lever the
    // brain had just written read back STALE two lines later, failed
    // brain_verify_lever and rolled itself back as 'stopped'. Every live lever
    // move would have undone itself. Backed by a global the writer can clear.
    if (isset($GLOBALS['__brain_levers_mem'])) return $GLOBALS['__brain_levers_mem'];
    $reg = brain_registry();
    $out = [];
    foreach ($reg as $k => $r) $out[$k] = (float)$r['default'];
    $fresh = is_file(BRAIN_CACHE_FILE) && filemtime(BRAIN_CACHE_FILE) > time() - BRAIN_CACHE_TTL;
    if ($fresh) {
        $j = json_decode((string)@file_get_contents(BRAIN_CACHE_FILE), true);
        if (is_array($j)) { foreach ($j as $k => $v) if (isset($reg[$k]) && is_numeric($v)) $out[$k] = brain_clamp($k, (float)$v); $GLOBALS['__brain_levers_mem'] = $out; return $out; }
    }
    try {
        if ($pdo === null) { require_once __DIR__ . '/db.php'; $pdo = db(); }
        brain_install($pdo);
        foreach ($pdo->query("SELECT lever_key, value FROM brain_levers") as $r) if (isset($reg[$r['lever_key']])) $out[$r['lever_key']] = brain_clamp($r['lever_key'], (float)$r['value']);
        @file_put_contents(BRAIN_CACHE_FILE, json_encode($out), LOCK_EX);
    } catch (Throwable $e) { /* defaults or stale cache stand; a DB hiccup never changes a video */
        $j = json_decode((string)@file_get_contents(BRAIN_CACHE_FILE), true);
        if (is_array($j)) foreach ($j as $k => $v) if (isset($reg[$k]) && is_numeric($v)) $out[$k] = brain_clamp($k, (float)$v);
    }
    $GLOBALS['__brain_levers_mem'] = $out;
    return $out;
}

function brain_lever(string $key, ?float $fallback = null): float {
    try { $all = brain_levers_all(); if (isset($all[$key])) return $all[$key]; } catch (Throwable $e) {}
    $reg = brain_registry();
    return $fallback ?? (float)($reg[$key]['default'] ?? 0);
}

function brain_clamp(string $key, float $v): float {
    $r = brain_registry()[$key] ?? null;
    if (!$r) return $v;
    if ($v == 0.0 && (float)$r['default'] == 0.0) return 0.0;      // 0 = "use the learned value" for title/caption levers
    $v = max((float)$r['min'], min((float)$r['max'], $v));
    $step = (float)$r['step'];
    return $step > 0 ? round(round($v / $step) * $step, 3) : round($v, 3);
}

/* ---- LEVER WRITES: clamp, persist, refresh the cache, leave a trace ------ */
function brain_set_lever(PDO $pdo, string $key, float $value, string $by, string $why, int $pageId = 0): ?float {
    $reg = brain_registry();
    if (!isset($reg[$key]) || !in_array($by, ['owner', 'brain', 'default'], true)) return null;
    brain_install($pdo);
    $v = brain_clamp($key, $value);
    $before = (float)($pdo->query("SELECT value FROM brain_levers WHERE lever_key=" . $pdo->quote($key))->fetchColumn() ?: $reg[$key]['default']);
    $pdo->prepare("INSERT INTO brain_levers (lever_key, value, updated_by, why, updated_at) VALUES (?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE value=VALUES(value), updated_by=VALUES(updated_by), why=VALUES(why), updated_at=NOW()")
        ->execute([$key, $v, $by, mb_substr($why, 0, 255)]);
    unset($GLOBALS['__brain_levers_mem']);   // r164: or the read below returns the value we just replaced
    @unlink(BRAIN_CACHE_FILE);
    brain_levers_all($pdo);
    try {
        $pdo->prepare("INSERT INTO rule_apply_log (rule_key, page_id, what, before_val, after_val, at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())")
            ->execute(['brain:' . $key, $pageId, mb_substr("$by set $key: $why", 0, 255), (string)$before, (string)$v]);
    } catch (Throwable $e) {}
    return $v;
}

/* Lines for feed/levers.env: only maker-env levers, numeric, clamped. The
 * workflow loads them with a whitelist regex (defense in depth). */
function brain_levers_env(): string {
    $all = brain_levers_all();
    $lines = [];
    foreach (brain_registry() as $k => $r) {
        if (empty($r['env'])) continue;
        $v = $all[$k];
        $lines[] = $r['env'] . '=' . ($r['step'] >= 1 ? (string)(int)round($v) : number_format($v, 2, '.', ''));
    }
    return implode("\n", $lines) . "\n";
}

/* ---- YARDSTICKS (measured, bounded queries) ------------------------------- */
function brain_median(array $xs): float { if (!$xs) return 0.0; sort($xs); $n = count($xs); return $n % 2 ? (float)$xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2; }

/* Videos made in [from, to): median of best views (any platform), live ratio, judge pass. */
function brain_yardsticks(PDO $pdo, string $from, string $to): array {
    $out = ['n' => 0, 'views' => 0.0, 'live_ratio' => 0.0, 'judge_pass' => 0.0];
    try {
        $views = $pdo->prepare("SELECT MAX(m.views) v FROM video_scripts s
                                JOIN platform_videos pv ON pv.page_id=s.page_id
                                JOIN platform_metrics m ON m.video_id=pv.id
                                WHERE s.video_made_at >= ? AND s.video_made_at < ? GROUP BY s.page_id HAVING v > 0");
        $views->execute([$from, $to]);
        $vs = array_map('intval', $views->fetchAll(PDO::FETCH_COLUMN));
        $out['n'] = count($vs);
        $out['views'] = brain_median($vs);
        $lr = $pdo->prepare("SELECT AVG(live_ratio) FROM vid_shape WHERE kind='ours' AND frames >= 20 AND measured_at >= ? AND measured_at < ?");
        $lr->execute([$from, $to]);
        $out['live_ratio'] = round((float)$lr->fetchColumn(), 3);
        $out['judge_pass'] = brain_judge_pass_rate($from, $to);
    } catch (Throwable $e) {}
    return $out;
}

/* Judge pass rate from the heartbeat log tail (bounded read: last 1.5 MB). */
function brain_judge_pass_rate(string $from, string $to): float {
    $f = dirname(__DIR__) . '/public_html/media/heartbeat.log';
    if (!is_file($f)) return 0.0;
    $size = filesize($f);
    $fh = fopen($f, 'rb');
    if (!$fh) return 0.0;
    if ($size > 1500000) fseek($fh, $size - 1500000);
    $txt = (string)stream_get_contents($fh);
    fclose($fh);
    $fromT = strtotime($from); $toT = strtotime($to);
    $pass = 0; $fail = 0;
    foreach (explode("\n", $txt) as $line) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})Z .* stage=(JUDGE_FAIL|post)\b/', $line, $m)) continue;
        $t = strtotime($m[1] . 'Z');
        if ($t < $fromT || $t >= $toT) continue;
        if ($m[2] === 'post') $pass++; else $fail++;
    }
    return ($pass + $fail) ? round($pass / ($pass + $fail), 3) : 0.0;
}

/* ---- GATHER: one compact sheet, numbers only ------------------------------ */
function brain_gather(PDO $pdo): array {
    $g = ['date' => gmdate('Y-m-d'), 'mode' => brain_off() ? 'off' : (brain_live() ? 'live' : 'shadow')];
    $now = gmdate('Y-m-d H:i:s');
    $g['last7']  = brain_yardsticks($pdo, gmdate('Y-m-d H:i:s', time() - 7 * 86400), $now);
    $g['prior7'] = brain_yardsticks($pdo, gmdate('Y-m-d H:i:s', time() - 14 * 86400), gmdate('Y-m-d H:i:s', time() - 7 * 86400));
    $g['levers'] = brain_levers_all($pdo);
    $g['registry'] = array_map(fn($r) => ['min' => $r['min'], 'max' => $r['max'], 'what' => $r['what'], 'yard' => $r['yard']], brain_registry());
    try {   // platform yield per platform, 7 days (the maker's own postings)
        $g['platform_7d'] = [];
        foreach ($pdo->query("SELECT pv.platform, COUNT(DISTINCT pv.id) n, ROUND(AVG(mx.v)) avg_views FROM platform_videos pv
                              JOIN (SELECT video_id, MAX(views) v FROM platform_metrics GROUP BY video_id) mx ON mx.video_id=pv.id
                              WHERE pv.posted_at >= NOW() - INTERVAL 7 DAY GROUP BY pv.platform") as $r) $g['platform_7d'][$r['platform']] = ['videos' => (int)$r['n'], 'avg_views' => (int)$r['avg_views']];
    } catch (Throwable $e) {}
    try { require_once __DIR__ . '/clip_supply.php'; $cs = clip_supply_stats($pdo, 7);
        $g['clip_supply_7d'] = ['stage' => array_map(fn($r) => [$r['platform'], (int)$r['planned'], (int)$r['staged']], $cs['stage']), 'routes' => array_map(fn($r) => [$r['route'], (int)$r['ok']], $cs['probe'])];
    } catch (Throwable $e) {}
    try { require_once __DIR__ . '/video_eyes.php'; $ey = eyes_summary($pdo);
        $g['eyes'] = ['by_kind' => $ey['by_kind'], 'rule' => $ey['rule']['value']['read'] ?? null];
    } catch (Throwable $e) {}
    try {
        $g['rules'] = [];
        foreach ($pdo->query("SELECT rule_key, confidence, LEFT(rule_value, 220) v FROM comp_rule WHERE scope='video' AND active=1 AND rule_key NOT IN ('daily_brief','weekly_strategy','top_outliers','ig_top_outliers') ORDER BY confidence DESC LIMIT 12") as $r)
            $g['rules'][] = [$r['rule_key'], (int)$r['confidence'], $r['v']];
    } catch (Throwable $e) {}
    try {
        $g['approved_not_done'] = [];
        foreach ($pdo->query("SELECT id, made_on, type, title, LEFT(why,200) why, LEFT(evidence,200) evidence FROM strategist_reco WHERE status='approved' AND proved_at IS NULL ORDER BY made_on DESC LIMIT 8") as $r)
            $g['approved_not_done'][] = $r;
        $g['proposed'] = [];
        foreach ($pdo->query("SELECT id, made_on, type, title, LEFT(why,160) why FROM strategist_reco WHERE status='proposed' ORDER BY made_on DESC LIMIT 4") as $r) $g['proposed'][] = $r;
    } catch (Throwable $e) {}
    try { require_once __DIR__ . '/experiment.php'; $g['experiments'] = array_map(fn($x) => ['id' => $x['id'], 'status' => $x['status'], 'surface' => $x['surface'], 'name' => $x['name'], 'verdict' => $x['result']['verdict'] ?? '', 'why' => mb_substr((string)($x['result']['why'] ?? ''), 0, 160)], array_slice(xp_board($pdo), 0, 6)); } catch (Throwable $e) {}
    try { $g['alarms'] = []; foreach ($pdo->query("SELECT code, severity, title FROM governor_alarm WHERE status='open' ORDER BY last_seen DESC LIMIT 6") as $r) $g['alarms'][] = $r; } catch (Throwable $e) {}
    try { $g['pending_actions'] = []; foreach ($pdo->query("SELECT id, kind, lever_key, before_val, after_val, window_ends, yardstick FROM brain_actions WHERE outcome='pending' ORDER BY id DESC LIMIT 5") as $r) $g['pending_actions'][] = $r; } catch (Throwable $e) {}
    // structured outcomes fed back (r144): the model sees what its last moves did, with the numbers, not a summary
    try { $g['recent_actions'] = []; foreach ($pdo->query("SELECT at, mode, kind, lever_key, before_val, after_val, yardstick, outcome, LEFT(outcome_note,160) outcome_note, LEFT(why,120) why FROM brain_actions ORDER BY id DESC LIMIT 8") as $r) $g['recent_actions'][] = $r; } catch (Throwable $e) {}
    try { $g['open_requests'] = $pdo->query("SELECT title FROM brain_requests WHERE status IN ('open','building') ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    try { $g['lessons'] = $pdo->query("SELECT lesson FROM strategist_knowledge WHERE source='brain' ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    return $g;
}

/* ---- DECIDE: one action from the menu, strictly validated ---------------- */
function brain_decide(PDO $pdo, array $g): array {
    require_once __DIR__ . '/ai.php';
    $menu = "MENU (exactly one):\n"
          . "- noop: nothing is worth changing today, or evidence is thin.\n"
          . "- lever: move ONE numeric lever to a value inside its walls (registry min/max). Say which yardstick should move.\n"
          . "- experiment: start an A/B on one writing surface (hook|script|director|caption) with a concrete variant instruction (variant_body <= 240 chars).\n"
          . "- adopt: an experiment whose verdict is VARIANT-WINS becomes a standing directive (experiment_id). Never for NO-DIFFERENCE or CONTROL-WINS.\n"
          . "- revert: a pending lever action is visibly harming the yardsticks already (action_id).\n"
          . "- request: you need a lever or a code change that does not exist (title <= 120, why, evidence). Use this for 'approved_not_done' items that are code changes.\n";
    $rules = "HARD RULES: one action. Only registry keys. Values inside walls. Never touch page text, posting hours, credentials, money. "
           . "Rows in recent_actions with mode=shadow were NEVER applied: the lever still sits at the value in 'levers'; do not credit them with any change in the numbers. "
           . "Do not move a lever that already has a pending action. Prefer the smallest step (one step of the registry step size) unless evidence is strong. "
           . "Reason only from the numbers given; never invent numbers. If last7 has fewer than " . BRAIN_MIN_VIDEOS . " videos with views, prefer levers whose yardstick is live_ratio or judge_pass, or noop. "
           . "Output STRICT JSON only: {\"action\":\"noop|lever|experiment|adopt|revert|request\",\"lever_key\":\"\",\"value\":0,\"surface\":\"\",\"name\":\"\",\"variant_label\":\"\",\"variant_body\":\"\",\"hypothesis\":\"\",\"experiment_id\":0,\"action_id\":0,\"title\":\"\",\"why\":\"<=200 chars, plain English for the owner\",\"evidence\":\"<=300 chars, the numbers you used\"}";
    $res = ai_chat([
        ['role' => 'system', 'content' => 'You are the EXECUTIVE of a faceless short-video pipeline for an internet-culture site. Once a day you take ONE bounded action that should raise real views, keep the judge pass rate, and push the share of real footage up. You act like a careful operator: small steps, measured, reversible. ' . $rules],
        ['role' => 'user', 'content' => "SHEET (all numbers measured today):\n" . json_encode($g, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n" . $menu],
    ], ['nvidia', 'gemini', 'openrouter'], 0.1, 90);
    $j = isset($res['content']) ? ai_json($res['content']) : null;
    $d = brain_validate($j, $g);
    $d['provider'] = (string)($res['provider'] ?? ($res['error'] ?? 'none'));
    if (!$j) { $d['action'] = 'noop'; $d['why'] = 'AI answered no JSON (' . ($res['error'] ?? 'unknown') . ')'; }
    return $d;
}

function brain_validate(?array $j, array $g): array {
    $reg = brain_registry();
    $d = ['action' => 'noop', 'why' => '', 'evidence' => '', 'lever_key' => null, 'value' => null, 'surface' => null, 'name' => '', 'variant_label' => '', 'variant_body' => '', 'hypothesis' => '', 'experiment_id' => 0, 'action_id' => 0, 'title' => '', 'refused' => ''];
    if (!$j) return $d;
    $d['why'] = mb_substr(trim((string)($j['why'] ?? '')), 0, 200);
    $d['evidence'] = mb_substr(trim((string)($j['evidence'] ?? '')), 0, 300);
    $a = (string)($j['action'] ?? 'noop');
    if (!in_array($a, ['noop', 'lever', 'experiment', 'adopt', 'revert', 'request'], true)) { $d['refused'] = "unknown action '$a'"; return $d; }
    $pendingKeys = array_column((array)($g['pending_actions'] ?? []), 'lever_key');
    switch ($a) {
        case 'lever':
            $k = (string)($j['lever_key'] ?? '');
            if (!isset($reg[$k])) { $d['refused'] = "unknown lever '$k'"; return $d; }
            if (in_array($k, $pendingKeys, true)) { $d['refused'] = "lever '$k' already under measurement"; return $d; }
            if (!is_numeric($j['value'] ?? null)) { $d['refused'] = 'no numeric value'; return $d; }
            $v = brain_clamp($k, (float)$j['value']);
            $cur = (float)($g['levers'][$k] ?? $reg[$k]['default']);
            if (abs($v - $cur) < 1e-9) { $d['refused'] = "lever '$k' already at $v"; return $d; }
            // ONE STEP AT A TIME, in code (r144): the second shadow run asked for two
            // steps at once (0.85 → 0.95). Small, reversible moves are the whole point.
            $step = (float)$reg[$k]['step'];
            if ($cur > 0 && $step > 0 && abs($v - $cur) > $step + 1e-9) {
                $v = brain_clamp($k, $cur + ($v > $cur ? $step : -$step));
                $d['why'] = mb_substr('(clamped to one step) ' . $d['why'], 0, 200);
            }
            $d['action'] = 'lever'; $d['lever_key'] = $k; $d['value'] = $v; return $d;
        case 'experiment':
            $s = (string)($j['surface'] ?? '');
            if (!in_array($s, ['hook', 'script', 'director', 'caption'], true)) { $d['refused'] = "bad surface '$s'"; return $d; }
            $body = mb_substr(trim((string)($j['variant_body'] ?? '')), 0, 240);
            if (mb_strlen($body) < 20) { $d['refused'] = 'variant_body too short'; return $d; }
            $d['action'] = 'experiment'; $d['surface'] = $s; $d['name'] = mb_substr(trim((string)($j['name'] ?? 'brain experiment')), 0, 140);
            $d['variant_label'] = mb_substr(trim((string)($j['variant_label'] ?? 'variant')), 0, 120); $d['variant_body'] = $body; $d['hypothesis'] = mb_substr(trim((string)($j['hypothesis'] ?? '')), 0, 400);
            return $d;
        case 'adopt':
            $id = (int)($j['experiment_id'] ?? 0);
            if ($id <= 0) { $d['refused'] = 'no experiment_id'; return $d; }
            $d['action'] = 'adopt'; $d['experiment_id'] = $id; return $d;
        case 'revert':
            $id = (int)($j['action_id'] ?? 0);
            if ($id <= 0) { $d['refused'] = 'no action_id'; return $d; }
            $d['action'] = 'revert'; $d['action_id'] = $id; return $d;
        case 'request':
            $t = mb_substr(trim((string)($j['title'] ?? '')), 0, 120);
            if (mb_strlen($t) < 8) { $d['refused'] = 'request title too short'; return $d; }
            $d['action'] = 'request'; $d['title'] = $t; return $d;
    }
    return $d;
}

/* ---- PRE-ACTION HOOK (r144): a check in CODE, outside the model, that runs
 * right before any live action and can refuse it. Re-checks the kill switch,
 * the daily cap, the walls, the pending-lever rule, and open Governor alarms
 * of severity 'alarm'. Returns '' to allow, else the reason. ---------------- */
function brain_precheck(PDO $pdo, array $d): string {
    if (brain_off()) return 'kill switch present';
    if (!brain_live()) return 'not in live mode';
    if ($d['action'] === 'noop') return '';
    $n = (int)$pdo->query("SELECT COUNT(*) FROM brain_actions WHERE mode='live' AND kind<>'noop' AND at >= CURDATE()")->fetchColumn();
    if ($n >= BRAIN_MAX_ACTIONS_PER_DAY) return 'daily action cap reached';
    try {
        $alarms = (int)$pdo->query("SELECT COUNT(*) FROM governor_alarm WHERE status='open' AND severity='alarm'")->fetchColumn();
        if ($alarms > 0) return "$alarms open Governor alarm(s): the machine is not healthy enough to be changed";
    } catch (Throwable $e) {}
    if ($d['action'] === 'lever') {
        $reg = brain_registry();
        $k = (string)$d['lever_key'];
        if (!isset($reg[$k])) return "unknown lever '$k'";
        $v = (float)$d['value'];
        if ($v != 0.0 && ($v < $reg[$k]['min'] || $v > $reg[$k]['max'])) return "value $v outside the walls of $k";
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM brain_actions WHERE outcome='pending' AND kind='lever'")->fetchColumn();
        if ($pending > 0) return 'a lever is already under measurement (one at a time)';
    }
    return '';
}

/* ---- POST-ACTION VERIFICATION (r144): prove the change landed before
 * calling it done — read the lever back through the same cache the writers
 * use, and through the env lines the maker receives. ----------------------- */
function brain_verify_lever(string $key, float $expected): string {
    @unlink(BRAIN_CACHE_FILE);
    $seen = brain_lever($key, -1.0);
    if (abs($seen - $expected) > 1e-6) return "NOT VERIFIED: cache reads $seen, expected $expected";
    $reg = brain_registry()[$key] ?? [];
    if (!empty($reg['env'])) {
        $env = brain_levers_env();
        if (!preg_match('/^' . preg_quote($reg['env'], '/') . '=([0-9.]+)$/m', $env, $m) || abs((float)$m[1] - $expected) > 0.011) return 'NOT VERIFIED: env line missing or wrong for ' . $reg['env'];
    }
    return 'verified: cache and env agree';
}

/* ---- ACT: perform (live) or record (shadow) ------------------------------ */
function brain_act(PDO $pdo, array $d, bool $live): ?int {
    brain_install($pdo);
    if ($live) {
        $block = brain_precheck($pdo, $d);
        if ($block !== '') { $d['refused'] = 'pre-action hook refused: ' . $block; $d['why'] = ($d['why'] ? $d['why'] . ' | ' : '') . 'refused: ' . $block; $live = false; }
    }
    $mode = $live ? 'live' : 'shadow';
    $ins = function (array $row) use ($pdo, $mode): int {
        $pdo->prepare("INSERT INTO brain_actions (at, mode, kind, lever_key, before_val, after_val, ref_id, why, evidence, yardstick, baseline_json, window_ends, outcome, outcome_note)
                       VALUES (NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$mode, $row['kind'], $row['lever_key'] ?? null, $row['before'] ?? null, $row['after'] ?? null, $row['ref_id'] ?? null,
                       mb_substr((string)($row['why'] ?? ''), 0, 255), mb_substr((string)($row['evidence'] ?? ''), 0, 400), (string)($row['yardstick'] ?? ''),
                       $row['baseline'] ?? null, $row['window_ends'] ?? null, $row['outcome'], mb_substr((string)($row['note'] ?? ''), 0, 255)]);
        return (int)$pdo->lastInsertId();
    };
    $why = $d['why']; $ev = $d['evidence'];
    if ($d['action'] === 'noop') {
        return $ins(['kind' => 'noop', 'why' => $why ?: ($d['refused'] ?: 'nothing worth changing'), 'evidence' => $ev, 'outcome' => $live ? 'done' : 'shadow', 'note' => $d['refused']]);
    }
    if ($d['action'] === 'lever') {
        $k = $d['lever_key']; $reg = brain_registry()[$k];
        $before = brain_levers_all($pdo)[$k];
        $base = json_encode(brain_yardsticks($pdo, gmdate('Y-m-d H:i:s', time() - 14 * 86400), gmdate('Y-m-d H:i:s')));
        $verify = '';
        if ($live) { brain_set_lever($pdo, $k, (float)$d['value'], 'brain', $why); $verify = brain_verify_lever($k, brain_clamp($k, (float)$d['value'])); }
        if ($live && str_starts_with($verify, 'NOT VERIFIED')) {   // the change did not land: undo, record, never pretend
            brain_set_lever($pdo, $k, $before, 'brain', 'rolled back: ' . $verify);
            return $ins(['kind' => 'lever', 'lever_key' => $k, 'before' => $before, 'after' => $d['value'], 'why' => $why, 'evidence' => $ev,
                         'yardstick' => $reg['yard'], 'outcome' => 'stopped', 'note' => $verify]);
        }
        return $ins(['kind' => 'lever', 'lever_key' => $k, 'before' => $before, 'after' => $d['value'], 'why' => $why, 'evidence' => $ev,
                     'yardstick' => $reg['yard'], 'baseline' => $base, 'window_ends' => gmdate('Y-m-d H:i:s', time() + BRAIN_WINDOW_DAYS * 86400),
                     'outcome' => $live ? 'pending' : 'shadow', 'note' => $verify]);
    }
    if ($d['action'] === 'experiment') {
        require_once __DIR__ . '/experiment.php';
        $xid = null; $note = '';
        if ($live) {
            $xid = xp_create($pdo, $d['surface'], $d['name'], $d['variant_label'], $d['variant_body'], $d['hypothesis'], null);
            if ($xid) { $r = xp_start($pdo, $xid); $note = ($r['ok'] ? 'started: ' : 'refused: ') . $r['why']; if (!$r['ok']) { $pdo->prepare("UPDATE experiment SET status='draft' WHERE id=?")->execute([$xid]); } }
        }
        return $ins(['kind' => 'experiment', 'ref_id' => $xid, 'why' => $why, 'evidence' => $ev . ' | ' . $d['surface'] . ': ' . $d['variant_body'], 'yardstick' => 'views',
                     'window_ends' => gmdate('Y-m-d H:i:s', time() + 21 * 86400), 'outcome' => $live ? 'pending' : 'shadow', 'note' => $note]);
    }
    if ($d['action'] === 'adopt') {
        require_once __DIR__ . '/experiment.php';
        require_once __DIR__ . '/memory.php';
        $x = $pdo->query("SELECT * FROM experiment WHERE id=" . (int)$d['experiment_id'])->fetch(PDO::FETCH_ASSOC);
        $res = $x ? xp_result($pdo, (int)$x['id']) : ['verdict' => 'UNKNOWN', 'why' => 'no such experiment'];
        $win = (string)$res['verdict'] === 'VARIANT-WINS';   // the Desk's exact label; NO-DIFFERENCE and CONTROL-WINS are refusals
        $note = $res['verdict'] . ': ' . mb_substr((string)$res['why'], 0, 150);
        if ($live && $win && $x) {
            memory_adopt($pdo, 100000 + (int)$x['id'], (string)$x['variant_body'], (string)$x['hypothesis'], '');
            // honest provenance: this directive was adopted by the brain from a measured win, not approved in the room
            try { $pdo->prepare("UPDATE comp_rule SET evidence=? WHERE scope='video' AND rule_key=?")->execute(['adopted by the brain from experiment #' . (int)$x['id'] . ' (' . mb_substr((string)$res['verdict'], 0, 40) . '), ' . gmdate('Y-m-d'), 'owner_directive_' . (100000 + (int)$x['id'])]); } catch (Throwable $e) {}
            $pdo->prepare("UPDATE experiment SET status='adopted' WHERE id=?")->execute([(int)$x['id']]);
        }
        return $ins(['kind' => 'adopt', 'ref_id' => (int)$d['experiment_id'], 'why' => $why, 'evidence' => $ev, 'outcome' => $live ? ($win ? 'done' : 'stopped') : 'shadow', 'note' => $win ? $note : 'refused, not a win: ' . $note]);
    }
    if ($d['action'] === 'revert') {
        $a = $pdo->query("SELECT * FROM brain_actions WHERE id=" . (int)$d['action_id'] . " AND kind='lever' AND outcome='pending'")->fetch(PDO::FETCH_ASSOC);
        if (!$a) return $ins(['kind' => 'noop', 'why' => 'revert refused: no such pending lever action', 'evidence' => $ev, 'outcome' => $live ? 'done' : 'shadow']);
        if ($live) {
            brain_set_lever($pdo, $a['lever_key'], (float)$a['before_val'], 'brain', 'reverted early: ' . $why);
            $pdo->prepare("UPDATE brain_actions SET outcome='reverted', outcome_note=?, outcome_at=NOW() WHERE id=?")->execute([mb_substr('reverted early by the brain: ' . $why, 0, 255), (int)$a['id']]);
        }
        return $ins(['kind' => 'revert', 'lever_key' => $a['lever_key'], 'before' => $a['after_val'], 'after' => $a['before_val'], 'ref_id' => (int)$a['id'], 'why' => $why, 'evidence' => $ev, 'outcome' => $live ? 'done' : 'shadow']);
    }
    if ($d['action'] === 'request') {
        if ($live) {
            $pdo->prepare("INSERT INTO brain_requests (at, title, why, evidence, status) VALUES (NOW(),?,?,?,'open')
                           ON DUPLICATE KEY UPDATE why=VALUES(why), evidence=VALUES(evidence)")->execute([$d['title'], mb_substr($why, 0, 400), mb_substr($ev, 0, 400)]);
        }
        return $ins(['kind' => 'request', 'why' => $d['title'] . ' | ' . $why, 'evidence' => $ev, 'outcome' => $live ? 'done' : 'shadow']);
    }
    return null;
}

/* ---- MEASURE: close windows, keep or revert, by the yardstick ------------ */
function brain_measure(PDO $pdo): array {
    brain_install($pdo);
    $out = ['closed' => 0, 'kept' => 0, 'reverted' => 0, 'extended' => 0];
    foreach ($pdo->query("SELECT * FROM brain_actions WHERE outcome='pending' AND kind='lever' AND window_ends <= NOW()")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $base = json_decode((string)$a['baseline_json'], true) ?: [];
        $after = brain_yardsticks($pdo, (string)$a['at'], gmdate('Y-m-d H:i:s'));
        $yard = (string)$a['yardstick'];
        $enough = $yard === 'views' ? $after['n'] >= BRAIN_MIN_VIDEOS : ($yard === 'live_ratio' ? $after['live_ratio'] > 0 && brain_ours_measured_since($pdo, (string)$a['at']) >= BRAIN_MIN_VIDEOS : $after['judge_pass'] > 0);
        if (!$enough) {
            $ext = (int)$pdo->query("SELECT TIMESTAMPDIFF(DAY, at, window_ends) FROM brain_actions WHERE id=" . (int)$a['id'])->fetchColumn();
            if ($ext < 2 * BRAIN_WINDOW_DAYS) { $pdo->prepare("UPDATE brain_actions SET window_ends=DATE_ADD(window_ends, INTERVAL ? DAY) WHERE id=?")->execute([BRAIN_WINDOW_DAYS, (int)$a['id']]); $out['extended']++; continue; }
            // still not enough after two windows: inconclusive → back to before (stopping is safe, adopting is not)
            brain_set_lever($pdo, $a['lever_key'], (float)$a['before_val'], 'brain', 'window closed without enough videos; reverted as inconclusive');
            $pdo->prepare("UPDATE brain_actions SET outcome='stopped', outcome_note=?, outcome_at=NOW() WHERE id=?")->execute(['inconclusive: not enough videos in two windows, lever returned to ' . $a['before_val'], (int)$a['id']]);
            $out['closed']++; $out['reverted']++; continue;
        }
        $b = (float)($base[$yard] ?? 0); $x = (float)($after[$yard] ?? 0);
        $gain = $b > 0 ? ($x - $b) / $b : ($x > 0 ? 1.0 : 0.0);
        $sec = $yard === 'views' ? 'judge_pass' : 'views';
        $sb = (float)($base[$sec] ?? 0); $sx = (float)($after[$sec] ?? 0);
        $harm = ($sb > 0 && $sx > 0) ? ($sb - $sx) / $sb : 0.0;
        $keep = $gain >= BRAIN_KEEP_GAIN && $harm <= BRAIN_MAX_HARM;
        $note = sprintf('%s %s→%s (%+.0f%%), %s %s→%s; %s', $yard, $b, $x, 100 * $gain, $sec, $sb, $sx, $keep ? 'kept' : 'reverted');
        if (!$keep) brain_set_lever($pdo, $a['lever_key'], (float)$a['before_val'], 'brain', 'window closed: ' . $note);
        $pdo->prepare("UPDATE brain_actions SET outcome=?, outcome_note=?, outcome_at=NOW() WHERE id=?")->execute([$keep ? 'kept' : 'reverted', mb_substr($note, 0, 255), (int)$a['id']]);
        brain_learn_lesson($pdo, gmdate('Y-m-d') . ': ' . $a['lever_key'] . ' ' . $a['before_val'] . '→' . $a['after_val'] . ' was ' . ($keep ? 'KEPT' : 'REVERTED') . ' (' . $note . ')');
        $out['closed']++; $keep ? $out['kept']++ : $out['reverted']++;
    }
    // experiments the brain started: reflect their verdicts
    try {
        require_once __DIR__ . '/experiment.php';
        foreach ($pdo->query("SELECT id, ref_id FROM brain_actions WHERE outcome='pending' AND kind='experiment' AND ref_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $r = xp_result($pdo, (int)$a['ref_id']);
            $v = (string)$r['verdict'];
            if ($v === 'TOO-EARLY' || $v === '') continue;
            $o = str_starts_with($v, 'STOPPED') ? 'stopped' : 'done';
            $pdo->prepare("UPDATE brain_actions SET outcome=?, outcome_note=?, outcome_at=NOW() WHERE id=?")->execute([$o, mb_substr($v . ': ' . $r['why'], 0, 255), (int)$a['id']]);
            $out['closed']++;
        }
    } catch (Throwable $e) {}
    return $out;
}

function brain_ours_measured_since(PDO $pdo, string $since): int {
    try { return (int)$pdo->query("SELECT COUNT(*) FROM vid_shape WHERE kind='ours' AND frames>=20 AND measured_at >= " . $pdo->quote($since))->fetchColumn(); } catch (Throwable $e) { return 0; }
}

/* ---- RUN: once a day inside the tick, one AI call, budgeted --------------- */
function brain_run(PDO $pdo, bool $force = false): array {
    brain_install($pdo);
    $t0 = microtime(true);
    if (brain_off()) return ['mode' => 'off', 'note' => 'BRAIN_OFF present'];
    $live = brain_live();
    $mode = $live ? 'live' : 'shadow';
    if (!$force) {
        $today = (int)$pdo->query("SELECT COUNT(*) FROM brain_runs WHERE at >= CURDATE()")->fetchColumn();
        if ($today > 0) return ['mode' => $mode, 'note' => 'already ran today'];
        // CIRCUIT BREAKER (r144, the pattern public analyses of the Claude Code
        // source describe: repeated denials trip a fallback). Three refused or
        // failed decisions in a row = the sheet or the model is off; stop
        // spending calls, let the Governor say so, resume after 24h of quiet.
        if (brain_breaker_tripped($pdo)) {
            $pdo->prepare("INSERT INTO brain_runs (at, mode, provider, ms, note) VALUES (NOW(),?,?,0,?)")->execute([$mode, 'breaker', 'circuit breaker: 3 refused/failed decisions in a row, no AI call today']);
            return ['mode' => $mode, 'note' => 'circuit breaker tripped (3 refused/failed decisions in a row); resumes after 24h of quiet'];
        }
    }
    $measured = brain_measure($pdo);
    $g = brain_gather($pdo);
    $g['measured_today'] = $measured;
    $decision = brain_decide($pdo, $g);
    $pdo = db_alive();
    $actionsToday = (int)$pdo->query("SELECT COUNT(*) FROM brain_actions WHERE mode='live' AND kind<>'noop' AND at >= CURDATE()")->fetchColumn();
    $canAct = $live && $actionsToday < BRAIN_MAX_ACTIONS_PER_DAY;
    $aid = null;
    try { $aid = brain_act($pdo, $decision, $canAct); } catch (Throwable $e) { $decision['refused'] = 'act failed: ' . $e->getMessage(); }
    $ms = (int)((microtime(true) - $t0) * 1000);
    $pdo->prepare("INSERT INTO brain_runs (at, mode, provider, ms, gathered_json, decision_json, action_id, note) VALUES (NOW(),?,?,?,?,?,?,?)")
        ->execute([$mode, mb_substr((string)$decision['provider'], 0, 60), $ms, json_encode($g, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode($decision, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $aid,
                   mb_substr(($live && !$canAct ? 'daily action already taken; ' : '') . (string)($decision['refused'] ?? ''), 0, 255)]);
    try { $pdo->exec("DELETE FROM brain_runs WHERE at < NOW() - INTERVAL 90 DAY LIMIT 500"); } catch (Throwable $e) {}
    return ['mode' => $mode, 'action' => $decision['action'], 'lever' => $decision['lever_key'], 'value' => $decision['value'], 'why' => $decision['why'],
            'refused' => $decision['refused'], 'provider' => $decision['provider'], 'measured' => $measured, 'ms' => $ms, 'acted' => $canAct];
}

/* True when the last three real runs were all refused or answered by no provider. */
function brain_breaker_tripped(PDO $pdo): bool {
    try {
        $rows = $pdo->query("SELECT provider, note, decision_json FROM brain_runs WHERE provider <> 'breaker' ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) < 3) return false;
        foreach ($rows as $r) {
            $d = json_decode((string)$r['decision_json'], true) ?: [];
            $bad = ($r['provider'] === '' || $r['provider'] === 'none' || str_contains((string)$r['provider'], 'error')) || !empty($d['refused']);
            if (!$bad) return false;
        }
        return true;
    } catch (Throwable $e) { return false; }
}

/* DISTILLATION (r144): every closed window becomes one dated lesson on the
 * Strategist's shelf (strategist_knowledge), so the weekly brain and the
 * daily brain both learn from outcomes, not only from rival numbers. */
function brain_learn_lesson(PDO $pdo, string $lesson): void {
    try {
        $pdo->prepare("INSERT INTO strategist_knowledge (source, lesson, added_at) VALUES ('brain', ?, NOW())")->execute([mb_substr($lesson, 0, 400)]);
    } catch (Throwable $e) {}
}

/* ---- GOVERNOR CHECK: the watchman watches the brain too ------------------- */
function brain_governor_check(PDO $pdo): array {
    brain_install($pdo);
    if (brain_breaker_tripped($pdo)) $found_breaker = ['code' => 'brain_breaker', 'severity' => 'watch', 'title' => 'The brain tripped its circuit breaker: 3 refused or failed decisions in a row',
        'detail' => 'It stops spending AI calls until a day passes quietly. Read its last three decisions in the Brain tab; the sheet or the model is off.', 'evidence' => 'last 3 brain_runs refused/failed'];
    // Governor finding shape: code / severity ('alarm'|'watch') / title / detail / evidence
    $found = [];
    if (isset($found_breaker)) $found[] = $found_breaker;
    $n = (int)$pdo->query("SELECT COUNT(*) FROM brain_actions WHERE mode='live' AND kind<>'noop' AND at >= NOW() - INTERVAL 1 DAY")->fetchColumn();
    if ($n > BRAIN_MAX_ACTIONS_PER_DAY) $found[] = ['code' => 'brain_overactive', 'severity' => 'alarm', 'title' => "The brain took $n actions in 24h (limit " . BRAIN_MAX_ACTIONS_PER_DAY . ')',
        'detail' => 'The daily cap exists so one bad day cannot move several levers at once. Check brain_actions and switch the brain to shadow if unsure.', 'evidence' => "$n live actions since " . gmdate('Y-m-d H:i', time() - 86400)];
    $stale = (int)$pdo->query("SELECT COUNT(*) FROM brain_actions WHERE outcome='pending' AND window_ends < NOW() - INTERVAL 2 DAY")->fetchColumn();
    if ($stale > 0) $found[] = ['code' => 'brain_stale_window', 'severity' => 'watch', 'title' => "$stale brain action(s) past their window without a verdict",
        'detail' => 'The measuring step did not close these. The brain may not be running, or the yardstick data is missing.', 'evidence' => "$stale pending rows with window_ends older than 2 days"];
    $rev = (int)$pdo->query("SELECT COUNT(*) FROM brain_actions WHERE outcome='reverted' AND outcome_at >= NOW() - INTERVAL 14 DAY")->fetchColumn();
    if ($rev >= 3) $found[] = ['code' => 'brain_revert_streak', 'severity' => 'watch', 'title' => "The brain reverted $rev of its own changes in 14 days: its choices are not landing",
        'detail' => 'Reverting is safe, but a streak means the sheet it reasons from is missing something. Consider shadow mode and a look at its evidence column.', 'evidence' => "$rev reverted in 14 days"];
    $last = $pdo->query("SELECT MAX(at) FROM brain_runs")->fetchColumn();
    if (!$last || strtotime((string)$last) < time() - 2 * 86400) $found[] = ['code' => 'brain_silent', 'severity' => 'watch', 'title' => 'The brain has not run for 2 days',
        'detail' => 'It runs once a day inside the hourly tick. Silence means the tick died before its stage or BRAIN_OFF is set.', 'evidence' => 'last run: ' . ($last ?: 'never')];
    return $found;
}

/* ---- REPORT: for the admin and the CLI, plain words ------------------------ */
function brain_report(PDO $pdo): array {
    brain_install($pdo);
    return [
        'mode'     => brain_off() ? 'off' : (brain_live() ? 'live' : 'shadow'),
        'levers'   => $pdo->query("SELECT * FROM brain_levers ORDER BY lever_key")->fetchAll(PDO::FETCH_ASSOC),
        'actions'  => $pdo->query("SELECT * FROM brain_actions ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC),
        'requests' => $pdo->query("SELECT * FROM brain_requests ORDER BY FIELD(status,'open','building','done','dismissed'), id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC),
        'runs'     => $pdo->query("SELECT id, at, mode, provider, ms, note, decision_json FROM brain_runs ORDER BY id DESC LIMIT 7")->fetchAll(PDO::FETCH_ASSOC),
    ];
}
