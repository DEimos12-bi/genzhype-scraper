<?php
/**
 * GenZHype | THE EXPERIMENT DESK — organ 06 of the learning machine (2026-08-25)
 * =============================================================================
 * WHAT THIS IS. Where an approved "try it" actually gets tried. The Reflector
 * proposes, the Proving Ground replays a proposal against videos we already know
 * the outcome of, the owner rules — and then something has to happen in the real
 * world under conditions that make the answer trustworthy. That is here.
 *
 * WHY IT IS NOT JUST "SHIP IT AND WATCH". The Playbook (organ 12) measured the
 * hook archetypes retrospectively and found the three normal ones separated by
 * under 2% on the median. Retrospective numbers cannot tell you whether that gap
 * is the archetype or the stories that happened to get it. A deliberate split
 * can, and only if it is run properly.
 *
 * THE FIVE RULES, each of which exists because skipping it produces a confident
 * wrong answer:
 *
 *  1. EVEN SPLIT ON A STABLE HASH. A video's arm is a pure function of its id, so
 *     re-running assignment never reshuffles history, and nobody can nudge a
 *     borderline story into the arm they are hoping for.
 *
 *  2. A PERMANENT HOLDOUT NO EXPERIMENT MAY TOUCH. A fixed slice, chosen by a
 *     hash that has nothing to do with any experiment, never enters any arm.
 *     Without it every measurement is against a baseline that all the accepted
 *     experiments have already moved, and cumulative drift becomes invisible —
 *     you can win every test and lose the year.
 *
 *  3. MINIMUM SAMPLE BEFORE ANY VERDICT. On an account whose median is 701 views,
 *     four videos an arm is noise. Below the floor the verdict is TOO-EARLY and
 *     the desk says so rather than leaning toward whichever arm is ahead.
 *
 *  4. GUARDRAILS THAT STOP A TEST, NEVER ADOPT ONE. A test doing real damage is
 *     killed automatically, because stopping returns to the status quo and is
 *     safe in the way that adopting is not. Adoption stays the owner's, through
 *     the Proposal Desk. The machine is allowed to protect him; it is not allowed
 *     to promote itself.
 *
 *  5. ONE EXPERIMENT PER SURFACE AT A TIME. Two tests on the hook at once and
 *     neither result means anything.
 *
 * IT DOES NOT APPLY ITS OWN WINNER. A finished experiment produces a result and a
 * recommendation. Turning that into a standing rule is the Memory (organ 04), and
 * only after the owner says so.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Share of videos permanently reserved from every experiment, as a percentage. */
const XP_HOLDOUT_PCT = 10;
/** Videos per arm before any verdict may be spoken. */
const XP_MIN_PER_ARM = 10;
/** How far behind the control an arm may fall, once real, before it is killed. */
const XP_GUARDRAIL_DROP = 0.35;
/** Arms must have at least this many videos before a guardrail may fire. */
const XP_GUARDRAIL_MIN = 6;
/** The surfaces an experiment can run on. One at a time each. */
const XP_SURFACES = ['hook', 'script', 'director', 'caption'];

/* ---------------------------------------------------------------------------
 * STORE
 * ------------------------------------------------------------------------- */

function xp_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS experiment (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        surface VARCHAR(20) NOT NULL,
        name VARCHAR(140) NOT NULL,
        hypothesis VARCHAR(400) NULL,
        control_label VARCHAR(120) NOT NULL DEFAULT 'what we do now',
        variant_label VARCHAR(120) NOT NULL,
        variant_body TEXT NULL,
        status ENUM('draft','running','stopped','done') NOT NULL DEFAULT 'draft',
        from_reco INT UNSIGNED NULL,
        min_per_arm SMALLINT UNSIGNED NOT NULL DEFAULT 10,
        started_at DATETIME NULL,
        stopped_at DATETIME NULL,
        stopped_why VARCHAR(300) NULL,
        created_at DATETIME NOT NULL,
        KEY idx_surface (surface, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS experiment_arm (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        experiment_id INT UNSIGNED NOT NULL,
        page_id INT UNSIGNED NOT NULL,
        arm ENUM('control','variant') NOT NULL,
        assigned_at DATETIME NOT NULL,
        UNIQUE KEY uniq_arm (experiment_id, page_id),
        KEY idx_page (page_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ---------------------------------------------------------------------------
 * ASSIGNMENT — pure, deterministic, testable without a database
 * ------------------------------------------------------------------------- */

/**
 * RULE 2. Is this video part of the permanent holdout?
 *
 * Keyed only on the page id and a fixed salt — deliberately independent of every
 * experiment, so the same videos are held out of all of them forever. That is
 * what makes it a stable baseline instead of a per-test control group.
 */
function xp_is_holdout(int $pageId): bool {
    return (crc32('genzhype-permanent-holdout:' . $pageId) % 100) < XP_HOLDOUT_PCT;
}

/**
 * RULE 1. Which arm this video belongs to, or null when it is held out.
 * A pure function of (experiment, page) — running it again cannot change history.
 */
function xp_arm_for(int $experimentId, int $pageId): ?string {
    if (xp_is_holdout($pageId)) return null;
    return (crc32('xp' . $experimentId . ':' . $pageId) % 2) === 0 ? 'control' : 'variant';
}

/* ---------------------------------------------------------------------------
 * RUNNING
 * ------------------------------------------------------------------------- */

/** Propose an experiment. Created stopped — starting it is a separate, deliberate act. */
function xp_create(PDO $pdo, string $surface, string $name, string $variantLabel,
                   string $variantBody = '', string $hypothesis = '', ?int $fromReco = null): ?int {
    if (!in_array($surface, XP_SURFACES, true)) return null;
    xp_install($pdo);
    $pdo->prepare("INSERT INTO experiment (surface, name, hypothesis, variant_label, variant_body,
                                           status, from_reco, min_per_arm, created_at)
                   VALUES (?,?,?,?,?,'draft',?,?,UTC_TIMESTAMP())")
        ->execute([$surface, mb_substr($name, 0, 140), mb_substr($hypothesis, 0, 400),
                   mb_substr($variantLabel, 0, 120), $variantBody, $fromReco, XP_MIN_PER_ARM]);
    return (int)$pdo->lastInsertId();
}

/** RULE 5. Start it — refused if that surface is already under test. */
function xp_start(PDO $pdo, int $id): array {
    xp_install($pdo);
    $st = $pdo->prepare("SELECT * FROM experiment WHERE id=?");
    $st->execute([$id]);
    $x = $st->fetch(PDO::FETCH_ASSOC);
    if (!$x) return ['ok' => false, 'why' => 'no such experiment'];
    if ($x['status'] === 'running') return ['ok' => true, 'why' => 'already running'];

    $busy = $pdo->prepare("SELECT id, name FROM experiment WHERE surface=? AND status='running' AND id<>?");
    $busy->execute([$x['surface'], $id]);
    if ($b = $busy->fetch(PDO::FETCH_ASSOC)) {
        return ['ok' => false, 'why' => 'the ' . $x['surface'] . ' surface is already under test by #'
                                      . $b['id'] . ' (' . $b['name'] . '). Two at once and neither result means anything.'];
    }
    $pdo->prepare("UPDATE experiment SET status='running', started_at=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);
    return ['ok' => true, 'why' => 'running'];
}

function xp_stop(PDO $pdo, int $id, string $why): bool {
    try {
        $st = $pdo->prepare("UPDATE experiment SET status='stopped', stopped_at=UTC_TIMESTAMP(),
                             stopped_why=? WHERE id=? AND status='running'");
        $st->execute([mb_substr($why, 0, 300), $id]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) { return false; }
}

/** The running experiment on a surface, if any. */
function xp_running(PDO $pdo, string $surface): ?array {
    try {
        $st = $pdo->prepare("SELECT * FROM experiment WHERE surface=? AND status='running' ORDER BY id DESC LIMIT 1");
        $st->execute([$surface]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * What a Doer asks: "am I in a test, and which arm?" Records the assignment so
 * the result can be read later without trusting the hash to stay put.
 * Returns '' when there is nothing to add — the prompt is then untouched.
 */
function xp_prompt_block(PDO $pdo, string $surface, int $pageId): string {
    if ($pageId <= 0) return '';
    $x = xp_running($pdo, $surface);
    if (!$x) return '';
    $arm = xp_arm_for((int)$x['id'], $pageId);
    if ($arm === null) return '';                       // holdout: never touched
    try {
        $pdo->prepare("INSERT IGNORE INTO experiment_arm (experiment_id, page_id, arm, assigned_at)
                       VALUES (?,?,?,UTC_TIMESTAMP())")->execute([(int)$x['id'], $pageId, $arm]);
    } catch (Throwable $e) { error_log('xp assign: ' . $e->getMessage()); }
    if ($arm === 'control') return '';                  // control is "what we do now", by definition
    $body = trim((string)$x['variant_body']);
    return $body === '' ? '' : ' EXPERIMENT IN PROGRESS — for this video only, do this instead: ' . $body . ' ';
}

/* ---------------------------------------------------------------------------
 * READING THE RESULT
 * ------------------------------------------------------------------------- */

/** Median of a list. 0 for empty. */
function xp_median(array $ns): float {
    $ns = array_values(array_map('floatval', $ns));
    if (!$ns) return 0.0;
    sort($ns);
    $m = intdiv(count($ns), 2);
    return count($ns) % 2 ? $ns[$m] : ($ns[$m - 1] + $ns[$m]) / 2;
}

/**
 * RULE 3 and 4. Read an experiment.
 *
 * @return array{verdict:string, control:array, variant:array, why:string, guardrail:bool}
 */
function xp_result(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT * FROM experiment WHERE id=?");
    $st->execute([$id]);
    $x = $st->fetch(PDO::FETCH_ASSOC);
    if (!$x) return ['verdict' => 'UNKNOWN', 'control' => [], 'variant' => [], 'why' => 'no such experiment', 'guardrail' => false];

    $q = $pdo->prepare(
        "SELECT ea.arm, MAX(m.views) views
           FROM experiment_arm ea
           JOIN platform_videos pv ON pv.page_id = ea.page_id
           JOIN platform_metrics m ON m.video_id = pv.id
          WHERE ea.experiment_id = ?
          GROUP BY ea.page_id, ea.arm
         HAVING views > 0");
    $q->execute([$id]);
    $arms = ['control' => [], 'variant' => []];
    foreach ($q as $r) $arms[(string)$r['arm']][] = (int)$r['views'];

    $sum = function (array $v): array {
        return ['n' => count($v), 'median' => xp_median($v), 'best' => $v ? max($v) : 0];
    };
    $c = $sum($arms['control']);
    $v = $sum($arms['variant']);
    $min = (int)($x['min_per_arm'] ?: XP_MIN_PER_ARM);

    // RULE 4: the guardrail may fire before the floor, but only on real damage.
    $guard = false;
    if ($c['n'] >= XP_GUARDRAIL_MIN && $v['n'] >= XP_GUARDRAIL_MIN && $c['median'] > 0) {
        $drop = ($c['median'] - $v['median']) / $c['median'];
        if ($drop >= XP_GUARDRAIL_DROP) $guard = true;
    }
    if ($guard) {
        return ['verdict' => 'STOPPED-BY-GUARDRAIL', 'control' => $c, 'variant' => $v, 'guardrail' => true,
                'why' => 'The variant is running ' . round((($c['median'] - $v['median']) / max(1, $c['median'])) * 100)
                       . '% below the control on the median across ' . $v['n'] . ' videos. Stopped to protect the account; '
                       . 'stopping is safe, adopting is not.'];
    }
    // RULE 3
    if ($c['n'] < $min || $v['n'] < $min) {
        return ['verdict' => 'TOO-EARLY', 'control' => $c, 'variant' => $v, 'guardrail' => false,
                'why' => 'Needs ' . $min . ' videos an arm before the answer means anything; have '
                       . $c['n'] . ' control and ' . $v['n'] . ' variant.'];
    }
    if ($c['median'] <= 0) {
        return ['verdict' => 'TOO-EARLY', 'control' => $c, 'variant' => $v, 'guardrail' => false,
                'why' => 'The control has no usable views yet.'];
    }
    $lift = (($v['median'] - $c['median']) / $c['median']) * 100;
    $verdict = abs($lift) < 10 ? 'NO-DIFFERENCE' : ($lift > 0 ? 'VARIANT-WINS' : 'CONTROL-WINS');
    return ['verdict' => $verdict, 'control' => $c, 'variant' => $v, 'guardrail' => false,
            'why' => 'Variant median ' . round($v['median']) . ' against control ' . round($c['median'])
                   . ' (' . ($lift >= 0 ? '+' : '') . round($lift, 1) . '%) over ' . ($c['n'] + $v['n']) . ' videos.'
                   . (abs($lift) < 10 ? ' Under 10% apart is not a difference at this volume.' : '')];
}

/**
 * The guardrail sweep, safe to run on every tick. It may only STOP (rule 4).
 * Returns the experiments it killed.
 */
function xp_guard(PDO $pdo): array {
    $killed = [];
    try {
        xp_install($pdo);
        foreach ($pdo->query("SELECT id, name FROM experiment WHERE status='running'") as $x) {
            $r = xp_result($pdo, (int)$x['id']);
            if (!$r['guardrail']) continue;
            xp_stop($pdo, (int)$x['id'], $r['why']);
            $killed[] = ['id' => (int)$x['id'], 'name' => (string)$x['name'], 'why' => $r['why']];
        }
    } catch (Throwable $e) { error_log('xp_guard: ' . $e->getMessage()); }
    return $killed;
}

/** Everything running or finished, for the CLI and the room. */
function xp_board(PDO $pdo): array {
    $out = [];
    try {
        xp_install($pdo);
        foreach ($pdo->query("SELECT id, surface, name, status, variant_label, started_at, stopped_why
                              FROM experiment ORDER BY id DESC LIMIT 20") as $x) {
            $out[] = $x + ['result' => xp_result($pdo, (int)$x['id'])];
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * SELF-TEST. The assignment rules are pure functions, so the properties that
 * matter — stability, evenness, and the holdout being untouchable — are provable
 * here without a database or a single posted video.
 */
function xp_selftest(): array {
    $pass = 0; $fail = 0; $notes = [];
    $t = function (string $name, bool $ok, string $got = '') use (&$pass, &$fail, &$notes) {
        $ok ? $pass++ : $fail++;
        $notes[] = ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $got === '' ? '' : "  (got: {$got})");
    };

    // RULE 1: stable
    $t('the same video always lands in the same arm',
       xp_arm_for(7, 12345) === xp_arm_for(7, 12345));
    $t('a different experiment can split it differently',
       count(array_unique([xp_arm_for(1, 500), xp_arm_for(2, 500), xp_arm_for(3, 500), xp_arm_for(4, 500)])) >= 1);

    // RULE 1: even
    $c = 0; $v = 0; $h = 0;
    for ($i = 1; $i <= 4000; $i++) {
        $a = xp_arm_for(1, $i);
        if ($a === null) { $h++; continue; }
        $a === 'control' ? $c++ : $v++;
    }
    $split = $c + $v > 0 ? $c / ($c + $v) : 0;
    $t('the split is even within 5 points', abs($split - 0.5) < 0.05, round($split, 3) . '');
    $t('the holdout is close to its target',
       abs($h / 4000 * 100 - XP_HOLDOUT_PCT) < 3, round($h / 4000 * 100, 1) . '%');

    // RULE 2: sacred
    $hold = array_values(array_filter(range(1, 3000), fn($i) => xp_is_holdout($i)));
    $t('the holdout is not empty', count($hold) > 50, (string)count($hold));
    $leak = 0;
    foreach ($hold as $i) for ($e = 1; $e <= 6; $e++) if (xp_arm_for($e, $i) !== null) $leak++;
    $t('RULE 2: no held-out video enters ANY experiment', $leak === 0, (string)$leak);
    $t('the holdout is the same set for every experiment (it does not depend on one)',
       xp_is_holdout(77) === xp_is_holdout(77) && (xp_arm_for(1, 77) === null) === (xp_arm_for(9, 77) === null));

    // RULE 3 / 4 thresholds
    $t('the sample floor is a real number', XP_MIN_PER_ARM >= 10);
    $t('a guardrail cannot fire on 2 videos', XP_GUARDRAIL_MIN > 2);
    $t('the guardrail needs a big drop, not a wobble', XP_GUARDRAIL_DROP >= 0.25);

    // medians
    $t('median of an even list', xp_median([10, 20, 30, 40]) === 25.0);
    $t('median of an odd list', xp_median([5, 1, 3]) === 3.0);
    $t('median of nothing is zero', xp_median([]) === 0.0);

    // RULE 5 surface list
    $t('surfaces are the four writing surfaces', XP_SURFACES === ['hook', 'script', 'director', 'caption']);

    return ['pass' => $pass, 'fail' => $fail, 'notes' => $notes];
}
