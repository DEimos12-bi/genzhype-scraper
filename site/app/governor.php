<?php
/**
 * GenZHype | THE GOVERNOR — organ 13 of the learning machine (2026-08-23)
 * =============================================================================
 * WHAT THIS IS. A watchman with no hands. Every other organ exists to make a
 * good decision. This one exists to answer a different question, the one nobody
 * was asking: IS ANYTHING STILL WORKING?
 *
 * THE CASE THAT BUILT IT. One step of the autopilot tick failed on EVERY TICK
 * from 2026-07-12 to 2026-08-23 — 51 identical failures over six weeks. It
 * printed one line to a log file nobody reads and the tick carried on. Nothing
 * counted it. Nothing compared today to last week. Nothing told the owner. The
 * machine did not know it was broken, and a machine that cannot notice it is
 * broken cannot be trusted to improve itself.
 *
 * THE LAWS.
 *  (1) IT NEVER FIXES ANYTHING. It raises an alarm; the owner decides. Same
 *      trust model as every other organ. A watchman with hands is a second,
 *      unsupervised operator.
 *  (2) IT DEDUPES BY CODE. A recurring fault updates one row's last_seen. A
 *      Governor that repeats itself becomes noise, noise gets ignored, and an
 *      ignored alarm is the exact failure it exists to prevent.
 *  (3) READ-ONLY AND BOUNDED. This runs on shared hosting beside other people's
 *      sites. It reads what is already recorded, samples the log tail rather
 *      than the whole file, and writes nothing but its own findings.
 *  (4) IT REPORTS ITS OWN LIVENESS. The watchman must not become the next silent
 *      thing. It stamps every run, and a stale stamp is itself visible.
 *  (5) SILENCE IS A FINDING. "Nothing wrong" and "did not run" must never look
 *      the same on screen.
 *
 * REUSE, NOT REINVENTION (owner rule #1). It invents no new instrumentation.
 * cron_events is already the tick's heartbeat, written by cnote() since long
 * before this file existed; ai_reviews already holds every machine judgement;
 * platform_metrics already holds what actually happened. The Governor only
 * compares them to their own past.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** No tick seen in this long means the machine has stopped. Ticks are hourly. */
const GOV_HEARTBEAT_MINS = 150;
/**
 * How often a step must normally run before its absence for a day means
 * anything. Set from a real false alarm: the first live round flagged "archived"
 * as stopped because it ran 7 times in the reference week and none in the last
 * day — but 7 a week is about once a day, and a quiet day for an occasional step
 * is not a fault. Only steps that should have run at least twice today can be
 * said to have "stopped" today.
 */
const GOV_STEP_MIN_PER_DAY = 2.0;
/** The reference window, in days, that "normally" is measured over. */
const GOV_STEP_PRIOR_DAYS  = 7;
/** The same error this many times in a day is a standing fault, not a blip. */
const GOV_ERROR_STREAK    = 5;
/** Read only the tail of the log — the file is ~1MB and the host is shared. */
const GOV_LOG_TAIL_BYTES  = 250000;
/** Machine score up this many points while real results fall = proxy drift. */
const GOV_HACK_GAP_PP     = 5.0;

/* ---------------------------------------------------------------------------
 * STORE
 * ------------------------------------------------------------------------- */

function gov_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS governor_alarm (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(60) NOT NULL,
        severity ENUM('watch','alarm') NOT NULL DEFAULT 'watch',
        title VARCHAR(200) NOT NULL,
        detail TEXT NULL,
        evidence TEXT NULL,
        seen_count INT UNSIGNED NOT NULL DEFAULT 1,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        status ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
        resolved_note VARCHAR(300) NULL,
        UNIQUE KEY uniq_code (code),
        KEY idx_status (status, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS governor_run (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ran_at DATETIME NOT NULL,
        checks INT UNSIGNED NOT NULL DEFAULT 0,
        findings INT UNSIGNED NOT NULL DEFAULT 0,
        took_ms INT UNSIGNED NOT NULL DEFAULT 0,
        KEY idx_ran (ran_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Raise or refresh one alarm. Deduped by code (law 2): the second occurrence of
 * a fault does not create a second row, it makes the first row heavier.
 * A fault the owner had marked resolved re-opens — it came back.
 */
function gov_raise(PDO $pdo, string $code, string $severity, string $title,
                   string $detail = '', string $evidence = ''): void {
    $pdo->prepare(
        "INSERT INTO governor_alarm (code, severity, title, detail, evidence, first_seen, last_seen)
         VALUES (?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            severity   = VALUES(severity),
            title      = VALUES(title),
            detail     = VALUES(detail),
            evidence   = VALUES(evidence),
            seen_count = seen_count + 1,
            last_seen  = UTC_TIMESTAMP(),
            status     = IF(status='resolved','open',status)")
        ->execute([$code, $severity, mb_substr($title, 0, 200),
                   mb_substr($detail, 0, 2000), mb_substr($evidence, 0, 2000)]);
}

/**
 * A fault that has stopped happening clears itself. Without this the board fills
 * with history and the owner learns to ignore it — see law 2.
 */
function gov_clear(PDO $pdo, string $code, string $note = 'stopped happening'): void {
    $pdo->prepare("UPDATE governor_alarm SET status='resolved', resolved_note=?
                   WHERE code=? AND status<>'resolved'")
        ->execute([mb_substr($note, 0, 300), $code]);
}

/* ---------------------------------------------------------------------------
 * HELPERS
 * ------------------------------------------------------------------------- */

/**
 * The step name a tick line belongs to. cron_events messages are written by
 * cnote() as "<step>: <detail>", so the text before the first colon is the step.
 * Numbers are stripped so "select: +3 / -12" and "select: +1 / -14" are one step.
 *
 * Not every message follows that shape: "PROMOTED /drama/some-long-slug/" has no
 * colon, and taking the whole head would make a unique "step" per page. Anything
 * carrying a path is therefore cut back to its leading words, so a thousand
 * PROMOTED lines are one step called "promoted" — which is the thing that can
 * meaningfully stop happening.
 */
function gov_step_key(string $msg): string {
    $head = trim(explode(':', $msg, 2)[0]);
    if (strpos($head, '/') !== false || str_word_count($head) > 4) {
        $head = implode(' ', array_slice(preg_split('/\s+/', trim($head)) ?: [], 0, 1));
    }
    $head = preg_replace('/\d+/', '#', mb_strtolower($head));
    $head = trim((string)preg_replace('/[^a-z0-9#\- ]+/', '', (string)$head));
    return mb_substr($head, 0, 60);
}

/**
 * Strip the variable parts out of an error line so 51 occurrences of one fault
 * group into one finding instead of 51. Page ids, timestamps, byte counts and
 * quoted values all change run to run; the shape does not.
 */
function gov_error_key(string $line): string {
    $s = mb_strtolower($line);
    $s = preg_replace('/\[[^\]]*\]/', '', $s);          // leading timestamps
    $s = preg_replace("/'[^']*'/", "'_'", (string)$s);   // quoted values
    $s = preg_replace('/"[^"]*"/', '"_"', (string)$s);
    $s = preg_replace('/\b\d+\b/', '#', (string)$s);     // ids, counts
    return mb_substr(trim(preg_replace('/\s+/', ' ', (string)$s)), 0, 160);
}

/**
 * Has a step stopped? Pulled out of the check so the rule can be tested without
 * a database — the first live round produced a false alarm here, and a rule that
 * can produce a false alarm is a rule that needs a test, not an argument.
 */
function gov_step_stopped(int $priorCount, int $nowCount): bool {
    if ($nowCount > 0) return false;
    return ($priorCount / GOV_STEP_PRIOR_DAYS) >= GOV_STEP_MIN_PER_DAY;
}

/** Median of a list of numbers. 0 for an empty list. */
function gov_median(array $ns): float {
    $ns = array_values(array_filter(array_map('floatval', $ns), fn($n) => $n >= 0));
    if (!$ns) return 0.0;
    sort($ns);
    $m = intdiv(count($ns), 2);
    return count($ns) % 2 ? $ns[$m] : ($ns[$m - 1] + $ns[$m]) / 2;
}

/* ---------------------------------------------------------------------------
 * THE CHECKS
 * Each returns a list of findings: ['code','severity','title','detail','evidence'].
 * Each is pure and independently testable — no writes here.
 * ------------------------------------------------------------------------- */

/** Did the machine stop entirely? */
function gov_check_heartbeat(PDO $pdo): array {
    $last = $pdo->query("SELECT MAX(at) FROM cron_events")->fetchColumn();
    if (!$last) {
        return [['code' => 'heartbeat', 'severity' => 'alarm',
                 'title' => 'The machine has no heartbeat at all',
                 'detail' => 'cron_events is empty — no tick has ever recorded a step.',
                 'evidence' => '']];
    }
    $mins = (int)$pdo->query("SELECT TIMESTAMPDIFF(MINUTE, MAX(at), UTC_TIMESTAMP()) FROM cron_events")->fetchColumn();
    if ($mins > GOV_HEARTBEAT_MINS) {
        $h = round($mins / 60, 1);
        return [['code' => 'heartbeat', 'severity' => 'alarm',
                 'title' => "The tick has not run for {$h} hours",
                 'detail' => 'Ticks are hourly. Nothing has been published, drafted or '
                           . 'rendered since then, and nothing will be until it runs again.',
                 'evidence' => 'last recorded step: ' . $last . ' UTC']];
    }
    return [];
}

/**
 * THE 12 JULY CHECK. A step that ran every tick for a week and has run zero
 * times in the last day. This is the shape of the fault that hid for six weeks:
 * nothing errored loudly, something simply stopped appearing.
 */
function gov_check_step_vanished(PDO $pdo): array {
    $prior = [];   // days -8 .. -1
    foreach ($pdo->query("SELECT msg FROM cron_events
                          WHERE at < UTC_TIMESTAMP() - INTERVAL 1 DAY
                            AND at >= UTC_TIMESTAMP() - INTERVAL 8 DAY") as $r) {
        $k = gov_step_key((string)$r['msg']);
        if ($k !== '') $prior[$k] = ($prior[$k] ?? 0) + 1;
    }
    $now = [];     // last 24h
    foreach ($pdo->query("SELECT msg FROM cron_events
                          WHERE at >= UTC_TIMESTAMP() - INTERVAL 1 DAY") as $r) {
        $k = gov_step_key((string)$r['msg']);
        if ($k !== '') $now[$k] = ($now[$k] ?? 0) + 1;
    }
    // Not enough history to say anything honest. Saying nothing is correct here.
    if (array_sum($prior) < 20) return [];

    $out = [];
    foreach ($prior as $step => $count) {
        $perDay = $count / GOV_STEP_PRIOR_DAYS;
        if (!gov_step_stopped($count, (int)($now[$step] ?? 0))) continue;
        $out[] = ['code' => 'step_vanished:' . $step, 'severity' => 'alarm',
                  'title' => 'The step "' . $step . '" has stopped running',
                  'detail' => 'It ran about ' . round($perDay) . ' times a day for the previous week '
                            . 'and has not run once in the last 24 hours. Nothing errored loudly — it '
                            . 'simply stopped appearing, which is how the last fault hid for six weeks.',
                  'evidence' => 'previous ' . GOV_STEP_PRIOR_DAYS . ' days: ' . $count . ' runs ('
                              . round($perDay, 1) . '/day) | last 24h: 0'];
    }
    return $out;
}

/**
 * The same failure, over and over, being carried on past.
 *
 * ONLY RECENT FAILURES COUNT. The first live run reported a fault 22 times that
 * had already been fixed an hour earlier — the occurrences were simply still
 * sitting in the log. An alarm board showing faults that are already dead is
 * worse than no board: the owner learns the alarms are stale and stops reading
 * them, which is law 2's failure mode arriving by a different road. Only the
 * step lines have no timestamp of their own, so each one is attributed to the
 * tick header above it, and anything older than $withinHours is ignored.
 * A line with no header above it has unknown age and is not counted.
 */
function gov_check_error_streak(string $logPath, int $withinHours = 24): array {
    if (!is_readable($logPath)) return [];
    $size = (int)filesize($logPath);
    $fh = fopen($logPath, 'rb');
    if (!$fh) return [];
    if ($size > GOV_LOG_TAIL_BYTES) fseek($fh, -GOV_LOG_TAIL_BYTES, SEEK_END);
    $tail = (string)stream_get_contents($fh);
    fclose($fh);

    $cutoff  = time() - $withinHours * 3600;
    $curTs   = null;      // null = we have not seen a header yet; age unknown
    $groups  = [];
    $skipped = 0;
    foreach (explode("\n", $tail) as $line) {
        if (preg_match('/\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2})\]/', $line, $m)) {
            $ts = strtotime($m[1]);
            $curTs = $ts === false ? null : $ts;
        }
        if (!preg_match('/\b(failed|error|exception|could not|unable to)\b/i', $line)) continue;
        if (stripos($line, 'errors 0') !== false) continue;      // "select: +3 / -12 (errors 0)"
        if ($curTs === null || $curTs < $cutoff) { $skipped++; continue; }
        $k = gov_error_key($line);
        if ($k === '') continue;
        $groups[$k] = ($groups[$k] ?? ['n' => 0, 'sample' => trim($line)]);
        $groups[$k]['n']++;
    }
    $out = [];
    foreach ($groups as $k => $g) {
        if ($g['n'] < GOV_ERROR_STREAK) continue;
        $out[] = ['n' => $g['n'],
                  'code' => 'error_streak:' . substr(md5($k), 0, 12), 'severity' => 'alarm',
                  'title' => 'The same failure has repeated ' . $g['n'] . ' times today',
                  'detail' => 'A fault that repeats is a standing fault, not a blip. The tick '
                            . 'has been carrying on past it.',
                  'evidence' => mb_substr(trim($g['sample']), 0, 300)
                              . ' | counted in the last ' . $withinHours . 'h'
                              . ($skipped ? '; ' . $skipped . ' older occurrence(s) ignored' : '')];
    }
    // loudest first, and never flood the board
    usort($out, fn($a, $b) => $b['n'] <=> $a['n']);
    $out = array_slice($out, 0, 5);
    foreach ($out as &$o) unset($o['n']);
    return $out;
}

/** A lane that used to produce and has produced nothing. */
function gov_check_dry_lane(PDO $pdo): array {
    $out = [];

    // pages published per day, last 14 days, excluding today
    $days = $pdo->query("SELECT DATE(published_at) d, COUNT(*) n FROM pages
                         WHERE status='published' AND published_at IS NOT NULL
                           AND published_at >= UTC_TIMESTAMP() - INTERVAL 15 DAY
                           AND published_at <  CURDATE()
                         GROUP BY d")->fetchAll(PDO::FETCH_KEY_PAIR);
    $today = (int)$pdo->query("SELECT COUNT(*) FROM pages
                               WHERE status='published' AND published_at >= CURDATE()")->fetchColumn();
    $med = gov_median(array_values($days));
    if (count($days) >= 5 && $med >= 1 && $today === 0) {
        $out[] = ['code' => 'dry_lane:pages', 'severity' => 'alarm',
                  'title' => 'Nothing has been published today',
                  'detail' => 'The site normally publishes about ' . round($med) . ' pages a day. '
                            . 'Today it has published none.',
                  'evidence' => 'median of the last ' . count($days) . ' days: ' . round($med, 1) . '/day'];
    } else {
        // only clearable by the caller; reported as a non-finding
    }

    // videos posted per day
    $vdays = $pdo->query("SELECT DATE(posted_at) d, COUNT(*) n FROM platform_videos
                          WHERE posted_at >= UTC_TIMESTAMP() - INTERVAL 15 DAY
                            AND posted_at < CURDATE()
                          GROUP BY d")->fetchAll(PDO::FETCH_KEY_PAIR);
    $vtoday = (int)$pdo->query("SELECT COUNT(*) FROM platform_videos WHERE posted_at >= CURDATE()")->fetchColumn();
    $vmed = gov_median(array_values($vdays));
    if (count($vdays) >= 5 && $vmed >= 1 && $vtoday === 0) {
        $out[] = ['code' => 'dry_lane:videos', 'severity' => 'alarm',
                  'title' => 'No video has been posted today',
                  'detail' => 'Normally about ' . round($vmed) . ' go out a day. Today, none.',
                  'evidence' => 'median of the last ' . count($vdays) . ' days: ' . round($vmed, 1) . '/day'];
    }
    return $out;
}

/**
 * THE REWARD-HACK CHECK — the most important one in a machine that changes its
 * own prompts. The literature is blunt: 73.8% of self-improving optimisations
 * improve the PROXY while the real task gets worse. Here the proxy is the
 * machine's own quality judgement; the real task is whether anyone watched.
 * If our scores are climbing while views are not, the machine is learning to
 * please its own judge.
 */
function gov_check_reward_hack(PDO $pdo): array {
    $win = function (string $from, string $to) use ($pdo): array {
        $q = $pdo->prepare("SELECT AVG(passed)*100 pass, COUNT(*) n FROM ai_reviews
                            WHERE stage='quality' AND created_at >= UTC_TIMESTAMP() - INTERVAL ? DAY
                              AND created_at < UTC_TIMESTAMP() - INTERVAL ? DAY");
        $q->execute([(int)$from, (int)$to]);
        $a = $q->fetch(PDO::FETCH_ASSOC) ?: ['pass' => null, 'n' => 0];

        $v = $pdo->prepare("SELECT MAX(m.views) views FROM platform_videos pv
                            JOIN platform_metrics m ON m.video_id = pv.id
                            WHERE pv.posted_at >= UTC_TIMESTAMP() - INTERVAL ? DAY
                              AND pv.posted_at <  UTC_TIMESTAMP() - INTERVAL ? DAY
                            GROUP BY pv.id");
        $v->execute([(int)$from, (int)$to]);
        $views = $v->fetchAll(PDO::FETCH_COLUMN);
        return ['pass' => $a['pass'] === null ? null : (float)$a['pass'], 'n' => (int)$a['n'],
                'views' => gov_median($views), 'vn' => count($views)];
    };
    $recent = $win('14', '0');
    $before = $win('28', '14');

    // Not enough of either signal to compare. Saying nothing is the honest answer.
    if ($recent['n'] < 10 || $before['n'] < 10 || $recent['vn'] < 5 || $before['vn'] < 5
        || $recent['pass'] === null || $before['pass'] === null) return [];
    /* NOTE: this check needs BOTH signals — machine scores and real views. The
     * separate quality-drift check below needs only the scores, and deliberately
     * so: the first live run found the quality pass rate had fallen 76% -> 28%
     * in a fortnight, and this check would have said nothing about it, because
     * it only ever looked for scores going UP. A watchman that only watches one
     * direction is half a watchman. */

    $scoreUp  = $recent['pass'] - $before['pass'];
    $viewsUp  = $before['views'] > 0 ? (($recent['views'] - $before['views']) / $before['views']) * 100 : 0.0;

    if ($scoreUp >= GOV_HACK_GAP_PP && $viewsUp <= 0) {
        return [['code' => 'reward_hack', 'severity' => 'alarm',
                 'title' => 'The machine is scoring itself higher while fewer people watch',
                 'detail' => 'Our own quality judge is passing more work than it did a fortnight '
                           . 'ago, but the median views on what we posted have not risen. That is '
                           . 'the machine learning to please its own judge rather than the '
                           . 'audience — the exact failure this design is meant to catch. The '
                           . 'judge needs recalibrating against real outcomes before any further '
                           . 'rule is approved.',
                 'evidence' => 'quality pass rate ' . round($before['pass']) . '% -> ' . round($recent['pass']) . '% '
                             . '(+' . round($scoreUp, 1) . 'pp over ' . $recent['n'] . ' reviews) | '
                             . 'median views ' . round($before['views']) . ' -> ' . round($recent['views'])
                             . ' (' . round($viewsUp, 1) . '%)']];
    }
    return [];
}

/**
 * THE QUALITY-DRIFT CHECK. How the machine judges its own work, this fortnight
 * against the one before. Unlike the reward-hack check this needs no view data,
 * so it works on any lane and it works immediately.
 *
 * It watches BOTH directions, because both mean something and only one of them
 * is obvious:
 *   falling — the work really did get worse, or something upstream broke and
 *             the judge is the only organ noticing.
 *   rising  — treated as a WATCH, not an alarm, on its own: scores rising is
 *             what improvement is supposed to look like. It only becomes an
 *             alarm when the reward-hack check confirms the audience did not
 *             agree.
 * On the first live run this found the pass rate had fallen from 76% to 28% in
 * a fortnight, unnoticed by anything.
 */
function gov_check_quality_drift(PDO $pdo): array {
    $win = function (int $from, int $to) use ($pdo): array {
        $q = $pdo->prepare("SELECT AVG(passed)*100 pass, COUNT(*) n, COUNT(DISTINCT page_id) pages
                            FROM ai_reviews
                            WHERE stage='quality' AND created_at >= UTC_TIMESTAMP() - INTERVAL ? DAY
                              AND created_at < UTC_TIMESTAMP() - INTERVAL ? DAY");
        $q->execute([$from, $to]);
        $a = $q->fetch(PDO::FETCH_ASSOC) ?: ['pass' => null, 'n' => 0, 'pages' => 0];
        return ['pass' => $a['pass'] === null ? null : (float)$a['pass'],
                'n' => (int)$a['n'], 'pages' => (int)$a['pages']];
    };
    $recent = $win(14, 0);
    $before = $win(28, 14);
    if ($recent['n'] < 10 || $before['n'] < 10 || $recent['pass'] === null || $before['pass'] === null) return [];

    $move = $recent['pass'] - $before['pass'];
    if (abs($move) < 15.0) return [];

    /* IS IT THE WORK, OR IS IT THE SAMPLE? The first live run reported a 48-point
     * collapse (76% -> 28%) that read as the machine falling apart. It was not.
     * The earlier window covered 102 distinct pages; the later one covered 34, and
     * eight stuck pages accounted for half its reviews — the same held drafts
     * being re-judged every hour and failing every time. Same judge, same model,
     * different pool. Acting on the headline would have meant rewriting the
     * writers to fix a queue. So the size of the pool is now stated alongside the
     * number, and a shrunken pool downgrades the finding to a watch: a
     * comparison that is not like-for-like must not be reported as if it were. */
    $poolShrank = $before['pages'] > 0 && $recent['pages'] < $before['pages'] * 0.6;

    $ev = 'quality pass rate ' . round($before['pass']) . '% (' . $before['n'] . ' reviews over '
        . $before['pages'] . ' pages) -> ' . round($recent['pass']) . '% (' . $recent['n']
        . ' reviews over ' . $recent['pages'] . ' pages), ' . ($move > 0 ? '+' : '') . round($move, 1) . 'pp';

    if ($move < 0) {
        if ($poolShrank) {
            return [['code' => 'quality_drift:down', 'severity' => 'watch',
                     'title' => 'The quality score dropped, but it is not comparing like with like',
                     'detail' => 'The pass rate is much lower than a fortnight ago — but it is being '
                               . 'measured over far fewer pages, so the drop may be the sample rather '
                               . 'than the writing. Check whether a small group of stuck pages is '
                               . 'being re-judged over and over before concluding the work got worse.',
                     'evidence' => $ev]];
        }
        return [['code' => 'quality_drift:down', 'severity' => 'alarm',
                 'title' => 'The machine is failing its own quality check far more often',
                 'detail' => 'Work that would have passed a fortnight ago is being rejected now, '
                           . 'across a comparable spread of pages. Either the writing genuinely got '
                           . 'worse, or something upstream broke and the quality judge is the only '
                           . 'part of the machine that has noticed. Worth finding out which before '
                           . 'approving any new rule.',
                 'evidence' => $ev]];
    }
    return [['code' => 'quality_drift:up', 'severity' => 'watch',
             'title' => 'The machine is passing its own quality check much more often',
             'detail' => 'This is what improvement should look like, so it is only worth watching, '
                       . 'not acting on. It becomes a real problem only if the audience does not '
                       . 'agree — which the reward-hack check tests separately.',
             'evidence' => $ev]];
}

/**
 * THE STUCK LOOP. Pages the machine judges again and again and never passes.
 *
 * Found by diagnosing the "quality collapse" above rather than by design: eight
 * pages sat in 'review', were re-judged on almost every tick, and failed every
 * single time — 30 of the last 65 quality reviews spent on work that had already
 * been rejected four times. Nothing was counting the repeats, so the loop was
 * invisible and free-looking. It is neither: every pass costs an AI call, and
 * the stuck pages crowd fresh stories out of the measurement.
 *
 * This is the same failure shape as the draft retry budget (one candidate failed
 * 29 times before a cap was added). Work that cannot succeed must stop being
 * retried, and the machine must be the one to notice.
 */
function gov_check_stuck_rejudging(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT r.page_id, COUNT(*) times
           FROM ai_reviews r
          WHERE r.stage='quality' AND r.page_id IS NOT NULL
            AND r.created_at >= UTC_TIMESTAMP() - INTERVAL 14 DAY
          GROUP BY r.page_id
         HAVING times >= 3 AND SUM(r.passed) = 0
         ORDER BY times DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) < 3) return [];

    $wasted = array_sum(array_column($rows, 'times'));
    $total  = (int)$pdo->query("SELECT COUNT(*) FROM ai_reviews WHERE stage='quality'
                                AND created_at >= UTC_TIMESTAMP() - INTERVAL 14 DAY")->fetchColumn();
    $share  = $total > 0 ? round($wasted / $total * 100) : 0;
    $worst  = array_slice($rows, 0, 4);
    $ids    = implode(', ', array_map(fn($r) => 'page ' . $r['page_id'] . ' (' . $r['times'] . 'x)', $worst));

    return [['code' => 'stuck_rejudging', 'severity' => 'alarm',
             'title' => count($rows) . ' pages are being judged over and over and never pass',
             'detail' => 'These have each been through the quality check at least three times in a '
                       . 'fortnight and failed every time, and they are still in the queue to be '
                       . 'judged again. That is ' . $share . '% of all recent quality checks spent on '
                       . 'work already rejected. It costs a call every time, and it crowds fresh '
                       . 'stories out of the numbers — which is what made the pass rate look like it '
                       . 'had collapsed. They need repairing or retiring, not re-judging.',
             'evidence' => $wasted . ' of ' . $total . ' recent quality checks | worst: ' . $ids]];
}

/** Work arriving faster than it is consumed. A watch, not an alarm. */
function gov_check_backlog(PDO $pdo): array {
    $ready = (int)$pdo->query("SELECT COUNT(*) FROM video_scripts WHERE video_status='ready'")->fetchColumn();
    $posted7 = (int)$pdo->query("SELECT COUNT(DISTINCT page_id) FROM platform_videos
                                 WHERE posted_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY")->fetchColumn();
    if ($ready >= 40 && $posted7 > 0) {
        $weeks = round($ready / max(1, $posted7), 1);
        if ($weeks >= 3) {
            return [['code' => 'backlog:video', 'severity' => 'watch',
                     'title' => $ready . ' videos are written and waiting to go out',
                     'detail' => 'At the current posting rate that is about ' . $weeks . ' weeks of '
                               . 'backlog. Scripts are being written faster than they are posted, so '
                               . 'the newest stories are queuing behind old ones — which is the '
                               . 'wrong order for drama.',
                     'evidence' => 'ready: ' . $ready . ' | distinct stories posted in 7 days: ' . $posted7]];
        }
    }
    return [];
}

/* ---------------------------------------------------------------------------
 * THE ROUND
 * ------------------------------------------------------------------------- */

/**
 * Run every check. $apply=false is a true dry run: it writes nothing at all, not
 * even the liveness stamp, so it is safe to call from anywhere.
 *
 * @return array{findings:array, checks:int, cleared:array, took_ms:int}
 */
function gov_round(PDO $pdo, bool $apply = true, string $logPath = ''): array {
    $t0 = microtime(true);
    if ($apply) gov_install($pdo);
    if ($logPath === '') $logPath = __DIR__ . '/cron.log';

    $checks = [
        'heartbeat'     => fn() => gov_check_heartbeat($pdo),
        'step_vanished' => fn() => gov_check_step_vanished($pdo),
        'error_streak'  => fn() => gov_check_error_streak($logPath),
        'dry_lane'      => fn() => gov_check_dry_lane($pdo),
        'quality_drift' => fn() => gov_check_quality_drift($pdo),
        'stuck_loop'    => fn() => gov_check_stuck_rejudging($pdo),
        'reward_hack'   => fn() => gov_check_reward_hack($pdo),
        'backlog'       => fn() => gov_check_backlog($pdo),
    ];

    $findings = [];
    $ranFamilies = [];
    foreach ($checks as $family => $fn) {
        // One broken check must never take the watchman down with it (law 5:
        // "did not run" and "nothing wrong" must not look the same).
        try {
            $rs = $fn();
            $ranFamilies[] = $family;
            foreach ($rs as $r) $findings[] = $r;
        } catch (Throwable $e) {
            $findings[] = ['code' => 'check_failed:' . $family, 'severity' => 'watch',
                           'title' => 'The "' . $family . '" check could not run',
                           'detail' => 'The Governor cannot currently tell you whether this is healthy.',
                           'evidence' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    $cleared = [];
    if ($apply) {
        foreach ($findings as $f) {
            gov_raise($pdo, $f['code'], $f['severity'], $f['title'], $f['detail'], $f['evidence']);
        }
        // Anything open that this round did NOT re-find has stopped happening —
        // but only inside families that actually ran, or a crashed check would
        // silently mark real faults resolved.
        $live = array_column($findings, 'code');
        foreach ($pdo->query("SELECT code FROM governor_alarm WHERE status='open'") as $row) {
            $code = (string)$row['code'];
            if (in_array($code, $live, true)) continue;
            $fam = explode(':', $code, 2)[0];
            if ($fam === 'check_failed') continue;
            if (!in_array($fam, $ranFamilies, true)) continue;
            gov_clear($pdo, $code);
            $cleared[] = $code;
        }
        $pdo->prepare("INSERT INTO governor_run (ran_at, checks, findings, took_ms)
                       VALUES (UTC_TIMESTAMP(),?,?,?)")
            ->execute([count($checks), count($findings), (int)round((microtime(true) - $t0) * 1000)]);
    }

    return ['findings' => $findings, 'checks' => count($checks),
            'cleared' => $cleared, 'took_ms' => (int)round((microtime(true) - $t0) * 1000)];
}

/**
 * What the room shows. Includes the Governor's OWN liveness (law 4) so a dead
 * watchman is visible instead of looking like a clean board.
 */
function gov_board(PDO $pdo): array {
    $out = ['alarms' => [], 'last_run' => null, 'stale' => true, 'open' => 0];
    try {
        gov_install($pdo);
        foreach ($pdo->query("SELECT code, severity, title, detail, evidence, seen_count,
                                     first_seen, last_seen
                              FROM governor_alarm WHERE status='open'
                              ORDER BY severity='alarm' DESC, last_seen DESC LIMIT 20") as $r) {
            $out['alarms'][] = $r;
        }
        $out['open'] = count($out['alarms']);
        $last = $pdo->query("SELECT ran_at FROM governor_run ORDER BY id DESC LIMIT 1")->fetchColumn();
        if ($last) {
            $out['last_run'] = (string)$last;
            $mins = (int)$pdo->query("SELECT TIMESTAMPDIFF(MINUTE, '" . $last . "', UTC_TIMESTAMP())")->fetchColumn();
            $out['stale'] = $mins > 24 * 60;
            $out['last_run_mins'] = $mins;
        }
    } catch (Throwable $e) { $out['error'] = mb_substr($e->getMessage(), 0, 200); }
    return $out;
}

/**
 * SELF-TEST. The Governor is only worth having if it actually fires, so this
 * proves detection rather than asserting it: it plants the exact conditions and
 * checks the finding comes back. Pure functions only — touches no live data.
 */
function gov_selftest(): array {
    $pass = 0; $fail = 0; $notes = [];
    $t = function (string $name, bool $ok, string $got = '') use (&$pass, &$fail, &$notes) {
        $ok ? $pass++ : $fail++;
        $notes[] = ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $got === '' ? '' : "  (got: {$got})");
    };

    // step keys group by step, ignoring the numbers that change every tick
    $t('two select lines are one step',
       gov_step_key('select: +3 / -12 (errors 0)') === gov_step_key('select: +1 / -14 (errors 0)'),
       gov_step_key('select: +3 / -12 (errors 0)'));
    $t('different steps stay different',
       gov_step_key('draft-redrain: checked 7') !== gov_step_key('framing-repair: 5 before gate'));

    // error keys group 51 occurrences of one fault into one finding
    $a = 'video script step failed: SQLSTATE[HY000]: General error: 2006 MySQL server has gone away';
    $b = 'video script step failed: SQLSTATE[HY000]: General error: 2006 MySQL server has gone away';
    $t('identical faults group', gov_error_key($a) === gov_error_key($b));
    $t('faults differing only by id group',
       gov_error_key('draft fail #9225: db insert failed') === gov_error_key('draft fail #8973: db insert failed'));
    $t('genuinely different faults do not group',
       gov_error_key('video script step failed: gone away') !== gov_error_key('image step failed: no provider'));

    // a path-shaped message is one step, not one step per page
    $t('PROMOTED lines collapse to one step',
       gov_step_key('PROMOTED /drama/twitch-2021-data-leak-source-code/') === gov_step_key('PROMOTED /drama/kai-cenat-la-peace/'),
       gov_step_key('PROMOTED /drama/twitch-2021-data-leak-source-code/'));
    $t('PROMOTED and HELD stay different steps',
       gov_step_key('PROMOTED /drama/a/') !== gov_step_key('HELD /drama/a/ (quality not met)'));

    // the streak check, against a planted log. Fresh header = today.
    $today = date('c');
    $tmp = tempnam(sys_get_temp_dir(), 'gov');
    $lines = ["[{$today}] autopilot tick"];
    for ($i = 0; $i < 51; $i++) $lines[] = '  video script step failed: 2006 MySQL server has gone away';
    $lines[] = '  select: +3 / -12 (errors 0)';
    $lines[] = '  a one-off thing failed once';
    file_put_contents($tmp, implode("\n", $lines));
    $found = gov_check_error_streak($tmp);
    $t('the 51-times fault is caught', count($found) === 1, count($found) . ' finding(s)');
    $t('it is reported as one finding, not 51',
       count($found) === 1 && strpos($found[0]['title'], '51 times') !== false,
       $found[0]['title'] ?? '-');
    $t('a single failure is not an alarm',
       !array_filter($found, fn($f) => strpos((string)$f['evidence'], 'one-off') !== false));

    // THE STALE-ALARM TEST. The same 51 failures, but from six weeks ago and
    // already fixed. A board that still shows them teaches the owner to ignore it.
    $old = date('c', time() - 42 * 86400);
    file_put_contents($tmp, implode("\n", array_merge(["[{$old}] autopilot tick"], array_slice($lines, 1))));
    $t('the same fault, but old, raises nothing', gov_check_error_streak($tmp) === [],
       count(gov_check_error_streak($tmp)) . ' finding(s)');

    // failures above the first header have unknown age and must not be counted
    file_put_contents($tmp, "  orphan step failed\n  orphan step failed\n  orphan step failed\n"
                          . "  orphan step failed\n  orphan step failed\n  orphan step failed\n");
    $t('failures of unknown age are not counted', gov_check_error_streak($tmp) === []);

    $t('a missing log raises nothing', gov_check_error_streak($tmp . '.nope') === []);
    file_put_contents($tmp, "[{$today}] autopilot tick\n  all fine\n  select: +3 / -12 (errors 0)\n");
    $t('a log with no faults raises nothing', gov_check_error_streak($tmp) === []);
    @unlink($tmp);

    // THE FALSE ALARM THAT SET THE THRESHOLD. On the first live round the
    // Governor claimed the "archived" step had stopped: it had run 7 times in the
    // reference week (about once a day) and none in the last day. That is a quiet
    // day for an occasional step, not a fault. It stays here as a test forever.
    $t('an occasional step going quiet for a day is NOT an alarm (the archived case)',
       !gov_step_stopped(7, 0));
    $t('a step that runs every tick going silent IS an alarm',
       gov_step_stopped(48, 0));
    $t('a step still running is never an alarm', !gov_step_stopped(48, 3));
    $t('exactly at the threshold counts', gov_step_stopped((int)(GOV_STEP_MIN_PER_DAY * GOV_STEP_PRIOR_DAYS), 0));
    $t('just under the threshold does not', !gov_step_stopped((int)(GOV_STEP_MIN_PER_DAY * GOV_STEP_PRIOR_DAYS) - 1, 0));

    // median, the basis of every "normally about N" statement
    $t('median of an even list', gov_median([1, 2, 3, 4]) === 2.5, (string)gov_median([1, 2, 3, 4]));
    $t('median of an odd list', gov_median([5, 1, 3]) === 3.0, (string)gov_median([5, 1, 3]));
    $t('median of nothing is zero', gov_median([]) === 0.0);

    return ['pass' => $pass, 'fail' => $fail, 'notes' => $notes];
}
