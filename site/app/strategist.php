<?php
/* GenZHype | r123 — THE STRATEGIST.
 *
 * WHAT THIS IS. The intelligence engine had eyes (rival watchers), memory
 * (comp_rule) and hands (bounded auto-applies) — but no brain that sits down
 * once a week, reads EVERYTHING together, and thinks like the media buyer the
 * owner is trying to employ: "where are we against the goal, what moved,
 * what should change next". Rules existed as separate facts; nobody
 * connected them into strategy. This does.
 *
 * THE GOAL it works toward is explicit: first 1,000 followers on each
 * platform. Every weekly report opens with distance-to-goal.
 *
 * TRUST MODEL (the owner's, verbatim in spirit): the Strategist RECOMMENDS,
 * in writing, with evidence — and nothing changes until the owner approves.
 * It earns autonomy by being right week after week, like a hire would. So
 * v1 applies nothing by itself: recommendations land in strategist_reco with
 * approve/dismiss buttons in the admin, and the report is stored where every
 * other piece of engine memory lives (comp_rule, scope=video).
 *
 * ITS EDUCATION. The knowledge base below is the distilled, RESEARCHED core
 * of the four platform rulebooks (each line traceable to the 2026 studies) —
 * the "course books". More lessons can be fed to it by INSERTing into
 * strategist_knowledge (source, lesson); the owner wants to keep schooling
 * it with marketing books, and that table is the shelf.
 *
 * SAFETY. Read-only against every production table except its own three
 * (knowledge, reco, and its comp_rule report row). It cannot touch pages,
 * videos, posters or money paths. Worst case is a wrong sentence in a
 * report. */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';

const STRAT_GOAL_FOLLOWERS = 1000;

/** Where the weekly report is mailed. The owner asked for updates by email
 *  (2026-08-14), so the report is not only an admin page you must remember
 *  to open — it arrives. */
const STRAT_REPORT_EMAIL = 'mehdi.vsfagency@gmail.com';

function strategist_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS strategist_knowledge (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source VARCHAR(120) NOT NULL,
        lesson TEXT NOT NULL,
        added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS strategist_reco (
        id INT AUTO_INCREMENT PRIMARY KEY,
        made_on DATE NOT NULL,
        title VARCHAR(200) NOT NULL,
        why TEXT NOT NULL,
        evidence TEXT NULL,
        risk VARCHAR(300) NULL,
        status ENUM('proposed','approved','dismissed','done') NOT NULL DEFAULT 'proposed',
        decided_at DATETIME NULL,
        UNIQUE KEY uq_reco (made_on, title(120))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 2026-08-23 organ 05: a recommendation now says WHAT KIND it is, so a
    // rule change, a work order for the builder, and a question are not the
    // same row shape wearing one label.
    foreach (["ADD COLUMN type ENUM('rule_change','upgrade','question','other') NOT NULL DEFAULT 'other'",
              "ADD COLUMN record_ids VARCHAR(255) NULL"] as $alt) {
        try { $pdo->exec("ALTER TABLE strategist_reco {$alt}"); } catch (Throwable $e) {}
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS follower_snapshot (
        id INT AUTO_INCREMENT PRIMARY KEY,
        platform VARCHAR(8) NOT NULL,
        followers INT NOT NULL,
        taken_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY k_plat (platform, taken_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** The distilled rulebook core. Each line survived the 2026 platform studies;
 *  nothing here is invented for this file. */
function strategist_base_knowledge(): array {
    return [
        'tiktok-rulebook'   => 'TikTok: name-first captions (search is how drama is found), no #fyp, ~30s daily publish rhythm; the unoriginal-content filter is the #1 format threat; 1,000 followers unlocks the clickable link.',
        'instagram-rulebook'=> 'Instagram: max 5 hashtags (Dec 2025 cap), caption truncates ~125 chars so the hook must lead, no URLs in captions, sends-per-reach is the growth signal.',
        'facebook-rulebook' => 'Facebook: Meta 2026 originality policy deprioritizes narrated-clip formats — original caption text is one of the few originality signals a Page can add; no links in captions, <=5 hashtags, minimal caps.',
        'youtube-rulebook'  => 'YouTube Shorts: swipe-vs-view is THE metric; subject-first short titles; hashtags in description never the title; 2026 watch-history clusters reward a channel the algorithm can classify into ONE niche.',
        'measured-own'      => 'Our own measurements: same video averages TikTok ~200 views, FB ~150, IG ~100, YouTube ~7. IG winners write ~100 chars MORE caption (story, not teaser). YT winners open with the person name (100% vs 87%) and use numbers 2x more. Video length is NOT a signal (36s median both groups).',
        'strategy-frame'    => 'Small account playbook: consistency in one niche beats volume; every post competes with the previous one for the test audience; freshness matters for news content; measure per-post, compare each account only against its own median.',
    ];
}

function strategist_knowledge(PDO $pdo): array {
    $out = strategist_base_knowledge();
    try {
        foreach ($pdo->query("SELECT source, lesson FROM strategist_knowledge ORDER BY id") as $r) {
            $out[(string)$r['source']] = (string)$r['lesson'];
        }
    } catch (Throwable $e) { /* the base set still applies */ }
    return $out;
}

/** Everything the Strategist can see, as one compact array. Read-only. */
function strategist_gather(PDO $pdo): array {
    $d = ['goal_followers' => STRAT_GOAL_FOLLOWERS, 'date' => gmdate('Y-m-d')];

    // Followers vs the goal — newest snapshot per platform. Buffer's API does
    // not expose follower counts (checked 2026-08-14: no such field on
    // Channel), so snapshots come from the admin form / future collectors.
    $d['followers'] = [];
    try {
        foreach ($pdo->query(
            "SELECT platform, followers, taken_at FROM follower_snapshot f
              WHERE taken_at = (SELECT MAX(taken_at) FROM follower_snapshot
                                 WHERE platform = f.platform)") as $r) {
            $d['followers'][(string)$r['platform']] = [
                'now' => (int)$r['followers'],
                'as_of' => substr((string)$r['taken_at'], 0, 10),
            ];
        }
    } catch (Throwable $e) {}

    // Where the same video pays, per platform (last 30 days of readings).
    try {
        $d['platform_month'] = $pdo->query(
            "SELECT pv.platform, COUNT(DISTINCT pv.id) posts, COUNT(m.id) readings,
                    ROUND(AVG(m.views)) avg_views, MAX(m.views) best_views,
                    ROUND(AVG(m.likes),1) avg_likes
               FROM platform_videos pv JOIN platform_metrics m ON m.video_id=pv.id
              WHERE m.fetched_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
              GROUP BY pv.platform")->fetchAll();
    } catch (Throwable $e) { $d['platform_month'] = []; }

    // Our best and worst recent stories (what SUBJECTS work, not just where).
    try {
        $d['best_stories'] = $pdo->query(
            "SELECT p.h1 title, pv.platform, MAX(m.views) views
               FROM platform_videos pv
               JOIN platform_metrics m ON m.video_id = pv.id
               JOIN pages p ON p.id = pv.page_id
              GROUP BY pv.id ORDER BY views DESC LIMIT 6")->fetchAll();
    } catch (Throwable $e) { $d['best_stories'] = []; }

    // Every active rule the engine has learned, compressed.
    try {
        foreach ($pdo->query("SELECT rule_key, confidence, rule_value
                                FROM comp_rule WHERE scope='video' AND active=1
                                 AND rule_key <> 'weekly_strategy'") as $r) {
            $d['rules'][(string)$r['rule_key']] = [
                'confidence' => (int)$r['confidence'],
                'value' => json_decode((string)$r['rule_value'], true)
                           ?: mb_substr((string)$r['rule_value'], 0, 300),
            ];
        }
    } catch (Throwable $e) {}

    // What last week's strategist said (so it builds, not repeats).
    try {
        $prev = $pdo->query("SELECT rule_value FROM comp_rule
                              WHERE scope='video' AND rule_key='weekly_strategy'")->fetchColumn();
        if ($prev) {
            $pj = json_decode((string)$prev, true);
            $d['last_week'] = [
                'summary' => (string)($pj['state_summary'] ?? ''),
                'recommendations' => array_column((array)($pj['recommendations'] ?? []), 'title'),
            ];
        }
    } catch (Throwable $e) {}

    // What the owner decided about past recommendations — the trust loop.
    try {
        $d['reco_history'] = $pdo->query(
            "SELECT title, status FROM strategist_reco
              WHERE status <> 'proposed' ORDER BY decided_at DESC LIMIT 10")->fetchAll();
    } catch (Throwable $e) { $d['reco_history'] = []; }

    // r124 SELF-CHECK: approved advice at least 7 days old comes back with
    // the numbers frozen at approval time next to the numbers now, and the
    // prompt orders a verdict. A buyer who never grades his own calls is a
    // salesman; this is the difference.
    try {
        $checks = [];
        foreach ($pdo->query(
            "SELECT title, decided_at, before_json FROM strategist_reco
              WHERE status='approved' AND before_json IS NOT NULL
                AND decided_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
              ORDER BY decided_at DESC LIMIT 5") as $r) {
            $now = $pdo->query(
                "SELECT pv.platform, ROUND(AVG(m.views)) avg_views, ROUND(AVG(m.likes),1) avg_likes
                   FROM platform_videos pv JOIN platform_metrics m ON m.video_id=pv.id
                  WHERE m.fetched_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)
                  GROUP BY pv.platform")->fetchAll();
            $checks[] = [
                'advice' => (string)$r['title'],
                'approved_on' => substr((string)$r['decided_at'], 0, 10),
                'numbers_then' => json_decode((string)$r['before_json'], true),
                'numbers_now' => $now,
            ];
        }
        if ($checks) { $d['self_check'] = $checks; }
    } catch (Throwable $e) {}

    // YouTube retention, once the Analytics API starts answering: the metric
    // that actually rules short-form, per video.
    try {
        $ret = $pdo->query(
            "SELECT pv.platform, p.h1 title, m.views, m.retention_pct, m.avg_view_s
               FROM platform_metrics m
               JOIN platform_videos pv ON pv.id=m.video_id
               JOIN pages p ON p.id=pv.page_id
              WHERE m.retention_pct IS NOT NULL
              ORDER BY m.fetched_at DESC LIMIT 10")->fetchAll();
        if ($ret) { $d['yt_retention'] = $ret; }
    } catch (Throwable $e) {}

    // 2026-08-23 THE TRACES (organ 02 -> 05). Averages hide the thing that
    // matters: video 700 scored 8/10 from the judge and "disaster" from the
    // owner. Reading whole stories is what makes learning possible at a few
    // videos a day instead of thousands (GEPA, ICLR 2026). Non-fatal: if the
    // Record is not deployed yet, the pass still runs on aggregates alone.
    try {
        require_once __DIR__ . '/record.php';
        $d['video_traces']   = record_traces($pdo, 25);
        $d['open_questions'] = question_open($pdo, 15);
    } catch (Throwable $e) { $d['video_traces'] = []; $d['open_questions'] = []; }

    return $d;
}

/** One weekly think. Returns the stored report, or null on AI failure. */
function strategist_run(PDO $pdo): ?array {
    strategist_install($pdo);
    $data = strategist_gather($pdo);
    $knowledge = strategist_knowledge($pdo);

    $sys = 'You are the social media STRATEGIST for GenZHype, a Gen-Z internet-drama '
         . 'timeline site with a faceless video pipeline posting to TikTok, Instagram, '
         . 'Facebook and YouTube. Your standing goal: first '
         . STRAT_GOAL_FOLLOWERS . ' followers on EACH platform. '
         . 'You reason ONLY from the measured data and the knowledge base given — '
         . 'never invent numbers, and when data is missing say so plainly. '
         . 'Write for a non-technical owner: short sentences, no jargon. '
         . 'Recommend at most 3 changes, each concrete enough to act on this week, '
         . 'each grounded in a specific number or lesson. Do not re-recommend what '
         . 'was dismissed. '
         // 2026-08-23 organ 05: read the STORIES, not just the averages.
         . 'When video_traces are present, reason from the individual videos first: '
         . 'compare what was planned (hook, shots, style) against what the judge '
         . 'scored, what the OWNER said, and what the platforms returned. '
         . 'OWNER_VERDICT IS GROUND TRUTH AND OUTRANKS EVERY MACHINE SCORE - if the '
         . 'judge scored a video well and the owner called it a disaster, the judge '
         . 'is wrong and that gap is itself a finding worth reporting. '
         . 'Cite the page_id of every video you reason from. '
         . 'Each recommendation carries a TYPE: "rule_change" (change how we make or '
         . 'post things), "upgrade" (a part of the machine is broken or missing and '
         . 'the builder must fix it - say which part and what evidence proves it), or '
         . '"question" (something we should test but cannot yet). '
         . 'Finally, ALWAYS end by listing what you could NOT answer with the data you '
         . 'were given, in open_questions - each with what would be needed to answer '
         . 'it. Saying "I do not know, and here is what I would need" is required, not '
         . 'a failure. '
         . 'When self_check data is present, OPEN state_summary by '
         . 'grading your own past advice honestly: say worked, failed, or too early '
         . 'to tell, with the numbers. When yt_retention is present, treat swipe/'
         . 'retention as the primary YouTube signal, above views. Output STRICT JSON: '
         . '{"state_summary":"3-5 plain sentences: where we stand vs the goal",'
         . '"wins":["..."],"concerns":["..."],'
         . '"recommendations":[{"type":"rule_change|upgrade|question",'
         . '"title":"imperative, <=90 chars",'
         . '"why":"2-3 plain sentences","evidence":"the specific numbers/lessons, with page_ids",'
         . '"record_ids":"comma-separated page_ids you reasoned from, or empty",'
         . '"risk":"one honest sentence on what could go wrong"}],'
         . '"open_questions":[{"question":"what you could not answer","why":"why it matters",'
         . '"needs":"what data or tool would answer it"}]}';

    $user = "KNOWLEDGE BASE:\n" . json_encode($knowledge, JSON_UNESCAPED_UNICODE)
          . "\n\nMEASURED DATA:\n" . json_encode($data, JSON_UNESCAPED_UNICODE);

    $res = ai_chat([['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $user]],
                   ['gemini', 'openrouter', 'nvidia'], 0.4);
    if (isset($res['error'])) { return null; }
    $j = ai_json($res['content']);
    if (!$j || empty($j['state_summary'])) { return null; }

    $report = [
        'date' => gmdate('Y-m-d'),
        'state_summary' => mb_substr((string)$j['state_summary'], 0, 1200),
        'wins' => array_slice(array_map('strval', (array)($j['wins'] ?? [])), 0, 5),
        'concerns' => array_slice(array_map('strval', (array)($j['concerns'] ?? [])), 0, 5),
        'recommendations' => [],
    ];
    foreach (array_slice((array)($j['recommendations'] ?? []), 0, 3) as $rec) {
        if (!is_array($rec) || trim((string)($rec['title'] ?? '')) === '') { continue; }
        $type = (string)($rec['type'] ?? 'other');
        if (!in_array($type, ['rule_change', 'upgrade', 'question'], true)) $type = 'other';
        $report['recommendations'][] = [
            'type' => $type,
            'title' => mb_substr(trim((string)$rec['title']), 0, 200),
            'why' => mb_substr((string)($rec['why'] ?? ''), 0, 800),
            'evidence' => mb_substr((string)($rec['evidence'] ?? ''), 0, 600),
            'record_ids' => mb_substr((string)($rec['record_ids'] ?? ''), 0, 255),
            'risk' => mb_substr((string)($rec['risk'] ?? ''), 0, 300),
        ];
    }

    // Store the report where all engine memory lives...
    $pdo->prepare(
        "INSERT INTO comp_rule (scope, rule_key, rule_value, confidence, evidence, version, active, updated_at)
         VALUES ('video','weekly_strategy',?,70,?,1,1,UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE rule_value=VALUES(rule_value), evidence=VALUES(evidence),
                                 active=1, updated_at=UTC_TIMESTAMP()")
        ->execute([json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                   'weekly strategist pass ' . $report['date']]);

    // ...and each recommendation becomes a decision waiting for the owner.
    $ins = $pdo->prepare(
        "INSERT IGNORE INTO strategist_reco (made_on, title, why, evidence, risk, type, record_ids)
         VALUES (?,?,?,?,?,?,?)");
    foreach ($report['recommendations'] as $rec) {
        $ins->execute([$report['date'], $rec['title'], $rec['why'],
                       $rec['evidence'], $rec['risk'], $rec['type'], $rec['record_ids']]);
    }

    // 2026-08-23 THE QUESTION LEDGER (organ 08). What it could not answer is
    // the next thing worth measuring - the list of questions must grow by
    // itself or the machine only ever learns what it already tracks.
    $report['open_questions'] = [];
    try {
        require_once __DIR__ . '/record.php';
        foreach (array_slice((array)($j['open_questions'] ?? []), 0, 5) as $q) {
            if (!is_array($q) || trim((string)($q['question'] ?? '')) === '') continue;
            question_add($pdo, (string)$q['question'], (string)($q['why'] ?? ''), (string)($q['needs'] ?? ''));
            $report['open_questions'][] = ['question' => mb_substr((string)$q['question'], 0, 300),
                                           'needs' => mb_substr((string)($q['needs'] ?? ''), 0, 300)];
        }
    } catch (Throwable $e) { error_log('question ledger: ' . $e->getMessage()); }

    strategist_email($report);
    return $report;
}

/** SMTP credentials, read AT SEND TIME from the one place they already live
 *  (the agency site's mailer). Deliberately not copied into this project's
 *  config: a secret that exists in one file can be rotated in one file, and
 *  the first version of this feature proved plain mail() on this host is a
 *  black hole — "accepted" and never delivered. */
function strategist_smtp(): ?array {
    $src = '/home/u219414635/domains/vsfagency.tech/public_html/send_mail.php';
    $s = @file_get_contents($src);
    if (!$s) { return null; }
    if (!preg_match("/Username\s*=\s*'([^']+)'/", $s, $u)) { return null; }
    if (!preg_match("/Password\s*=\s*'([^']+)'/", $s, $p)) { return null; }
    return ['host' => 'smtp.hostinger.com', 'port' => 465,
            'user' => $u[1], 'pass' => $p[1]];
}

/** r124 — one generic "tell the owner now" mail, reusing the exact SMTP path
 *  the weekly report already delivers through. Exists so the spike watcher
 *  (and anything else time-critical) does not wait for Monday. */
function strategist_notify(string $subject, string $body): bool {
    try {
        $smtp = strategist_smtp();
        if (!$smtp) { return false; }
        require_once __DIR__ . '/lib/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/lib/phpmailer/SMTP.php';
        require_once __DIR__ . '/lib/phpmailer/Exception.php';
        $m = new \PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host = $smtp['host']; $m->SMTPAuth = true;
        $m->Username = $smtp['user']; $m->Password = $smtp['pass'];
        $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $m->Port = (int)$smtp['port']; $m->CharSet = 'UTF-8';
        $m->setFrom($smtp['user'], 'GenZHype Strategist');
        $m->addAddress(STRAT_REPORT_EMAIL);
        $m->Subject = $subject;
        $m->Body = $body;
        return $m->send();
    } catch (Throwable $e) { return false; }
}

/** Mail the report to the owner. Plain text on purpose: it must read fine on
 *  a phone, and nothing in it needs styling. Never fatal — the report is
 *  already stored and shown in the admin whether or not the mail leaves. */
function strategist_email(array $report): bool {
    try {
        $lines = [];
        $lines[] = 'GENZHYPE — WEEKLY STRATEGY (' . ($report['date'] ?? '') . ')';
        $lines[] = str_repeat('=', 46);
        $lines[] = '';
        $lines[] = $report['state_summary'] ?? '';
        if (!empty($report['wins'])) {
            $lines[] = '';
            $lines[] = 'WORKING:';
            foreach ($report['wins'] as $w) { $lines[] = '  + ' . $w; }
        }
        if (!empty($report['concerns'])) {
            $lines[] = '';
            $lines[] = 'WORRYING:';
            foreach ($report['concerns'] as $w) { $lines[] = '  ! ' . $w; }
        }
        foreach (($report['recommendations'] ?? []) as $i => $r) {
            $lines[] = '';
            $lines[] = 'RECOMMENDATION ' . ($i + 1) . ': ' . ($r['title'] ?? '');
            $lines[] = '  Why: ' . ($r['why'] ?? '');
            $lines[] = '  Evidence: ' . ($r['evidence'] ?? '');
            $lines[] = '  Risk: ' . ($r['risk'] ?? '');
        }
        $lines[] = '';
        $lines[] = 'Approve or dismiss: https://genzhype.com/admin/?tab=strategist';
        $body = implode("\r\n", $lines);

        $smtp = strategist_smtp();
        if (!$smtp) { return false; }
        require_once __DIR__ . '/lib/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/lib/phpmailer/SMTP.php';
        require_once __DIR__ . '/lib/phpmailer/Exception.php';
        $m = new \PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host = $smtp['host'];
        $m->SMTPAuth = true;
        $m->Username = $smtp['user'];
        $m->Password = $smtp['pass'];
        $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $m->Port = (int)$smtp['port'];
        $m->CharSet = 'UTF-8';
        // From must be the authenticated mailbox or Hostinger refuses it.
        $m->setFrom($smtp['user'], 'GenZHype Strategist');
        $m->addAddress(STRAT_REPORT_EMAIL);
        $m->Subject = 'GenZHype weekly strategy — ' . ($report['date'] ?? '');
        $m->Body = $body;
        return $m->send();
    } catch (Throwable $e) {
        return false;
    }
}
