<?php
/* GenZHype | THE DESK — one intake for the whole site (owner go 2026-09-05).
 *
 * WHY. Ideas used to walk up to one of four doors (drama, slang, meme, gaming)
 * and each door judged by its own taste. Measured on the live table the day
 * this was written: 2,235 of 7,694 slang-door rejections carried a reason like
 * "not slang: news / court case" — real stories, thrown away at the wrong door
 * — and the story dedupe (discover_dramas.php) checked "does this name exist
 * anywhere in candidates" with no lane filter, so one no became four, forever.
 * Lindsay Clancy arrived 11 times and never met a judge that asked "is this a
 * story our readers want?".
 *
 * THE PATTERN (verified, not invented): Superdesk = one ingest pool → routing
 * scheme → desk/stage; Reuters Tracer = cluster conversations → judge
 * "news-like" → THEN assign topic → score newsworthiness. Rejection belongs
 * to a lane, never to the whole desk.
 *
 * STAGES.  A intake  desk_signal()   every fetcher drops raw items here, no door
 *          B cluster ckey             same thing = one card (name-normalised)
 *          C judge   desk_judge_run() one general judge: what is it, where, how hot
 *          D route   desk_route()     → candidates (the builders are untouched)
 *          E lane assistants          select.php / term screens judge QUALITY only
 *
 * SHADOW MODE. Until the file app/DESK_LIVE exists the judge only LOGS its
 * verdicts next to what the doors did (desk_report). Nothing downstream
 * changes. Flip = touch one file; rollback = delete it.
 *
 * SERVER RULES (Hostinger, tick killed at 1800s, MySQL hangs up during AI):
 * hard per-run deadline, ≤10 cards per AI call, db_alive() after every call,
 * every stage non-fatal, retention on every table it owns.
 */

const DESK_LIVE_FLAG   = __DIR__ . '/DESK_LIVE';
const DESK_BATCH       = 10;      // cards per AI call
const DESK_REJUDGE_GROW = 3;      // re-judge a card when it gained this many signals

/* Which lane a source belongs to. A lane origin is a PRIOR for the judge, never
 * a lock (owner: "Steam is gaming, r/memes is memes — that's correct"; "no
 * discrimination: from the big one, the specific one, or both, we take it"). */
const DESK_ORIGIN_LANE = [
    // general listeners (the "big discovery")
    'google-news' => '', 'google_trends' => '', 'reddit_ootl' => '', 'tiktok_trending' => '',
    'scout:x' => '', 'scout:youtube' => '', 'scout:reddit' => '',
    // lane-specific circles
    'urbandictionary_wotd' => 'slang',
    'rss:kym' => 'meme', 'rss:dailydot' => '', 'scout:reddit:memes' => 'meme', 'scout:reddit:dankmemes' => 'meme',
    'scout:steam' => 'gaming', 'scout:reddit:gta6' => 'gaming', 'scout:reddit:gamingleaksandrumours' => 'gaming',
    'rss:dexerto-gaming' => 'gaming', 'rss:rps' => 'gaming', 'rss:pcgamer' => 'gaming', 'rss:gamespot' => 'gaming',
    'rss:kotaku' => 'gaming', 'rss:vgc' => 'gaming', 'rss:insidergaming' => 'gaming',
    'scout:reddit:livestreamfail' => 'drama', 'scout:reddit:youtubedrama' => 'drama',
    'rss:dexerto' => 'drama', 'rss:tubefilter' => 'drama', 'rss:variety' => 'drama', 'rss:hollywoodreporter' => 'drama',
];

function desk_live(): bool { return is_file(DESK_LIVE_FLAG); }

function desk_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS desk_clusters (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ckey VARCHAR(190) NOT NULL,
        name VARCHAR(255) NOT NULL,
        n_signals INT NOT NULL DEFAULT 0,
        n_general INT NOT NULL DEFAULT 0,
        n_lane INT NOT NULL DEFAULT 0,
        origins_json TEXT NULL,
        platforms_json TEXT NULL,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        judged_at DATETIME NULL,
        judged_signals INT NOT NULL DEFAULT 0,
        is_something DECIMAL(3,2) NULL,
        kind VARCHAR(20) NULL,
        lane VARCHAR(10) NULL,
        urgency VARCHAR(10) NULL,
        confidence DECIMAL(3,2) NULL,
        why VARCHAR(255) NULL,
        verdict_json TEXT NULL,
        routed_to INT UNSIGNED NULL,
        status ENUM('new','judged','routed','dropped') NOT NULL DEFAULT 'new',
        UNIQUE KEY u_ckey (ckey),
        KEY idx_due (status, last_seen),
        KEY idx_judged (judged_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS desk_signals (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cluster_id INT UNSIGNED NOT NULL,
        sig_hash CHAR(32) NOT NULL,
        origin VARCHAR(60) NOT NULL,
        origin_kind ENUM('general','lane') NOT NULL,
        lane_hint VARCHAR(10) NOT NULL DEFAULT '',
        platform VARCHAR(30) NOT NULL DEFAULT '',
        text VARCHAR(500) NOT NULL,
        url VARCHAR(500) NOT NULL DEFAULT '',
        author VARCHAR(120) NOT NULL DEFAULT '',
        seen_at DATETIME NOT NULL,
        UNIQUE KEY u_sig (cluster_id, sig_hash),
        KEY idx_seen (seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS desk_lane_rejections (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cluster_id INT UNSIGNED NOT NULL,
        lane VARCHAR(10) NOT NULL,
        reason VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY u_cl (cluster_id, lane)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* B. the cluster key: "same thing" for v1 = same normalised name. The judge
 * can merge close cards (same_as) in a later version; v1 keeps it cheap. */
function desk_ckey(string $name): string {
    $k = mb_strtolower(trim($name));
    $k = preg_replace('/[\x{2018}\x{2019}\x{201C}\x{201D}"\'`]/u', '', $k);
    $k = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $k);
    $k = trim(preg_replace('/\s+/u', ' ', $k));
    return mb_substr($k, 0, 190);
}

function desk_origin_kind(string $origin): array {
    // longest key wins: 'scout:reddit:gta6' must beat the general 'scout:reddit'
    // (first live pass labelled all four story subs 'general' — measured, fixed)
    static $keys = null;
    if ($keys === null) { $keys = array_keys(DESK_ORIGIN_LANE); usort($keys, fn($a, $b) => strlen($b) <=> strlen($a)); }
    $o = mb_strtolower($origin);
    foreach ($keys as $k) {
        if ($o === $k || str_starts_with($o, $k . ':')) {
            $lane = DESK_ORIGIN_LANE[$k];
            return $lane === '' ? ['general', ''] : ['lane', $lane];
        }
    }
    return ['general', ''];
}

/* A. INTAKE. Called by every fetcher for every raw item, BEFORE its own
 * keyword screens and dedupe, so the desk sees what the doors drop. Non-fatal. */
function desk_signal(PDO $pdo, string $origin, string $text, string $url = '', string $author = '',
                     string $platform = '', ?string $seenAt = null): ?int {
    static $ready = false;
    try {
        if (!$ready) { desk_install($pdo); $ready = true; }
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) < 2) return null;
        $ckey = desk_ckey($text);
        if ($ckey === '') return null;
        [$kind, $laneHint] = desk_origin_kind($origin);
        $now = date('Y-m-d H:i:s');
        $seen = $seenAt ?: $now;
        $pdo->prepare("INSERT INTO desk_clusters (ckey,name,n_signals,first_seen,last_seen,origins_json,platforms_json)
                       VALUES (?,?,0,?,?,'[]','[]')
                       ON DUPLICATE KEY UPDATE last_seen=GREATEST(last_seen, VALUES(last_seen))")
            ->execute([$ckey, mb_substr($text, 0, 255), $seen, $seen]);
        $cid = (int)$pdo->query("SELECT id FROM desk_clusters WHERE ckey=" . $pdo->quote($ckey))->fetchColumn();
        if (!$cid) return null;
        $hash = md5(mb_strtolower($origin) . '|' . $ckey . '|' . $url . '|' . mb_strtolower($author));
        $ins = $pdo->prepare("INSERT IGNORE INTO desk_signals (cluster_id,sig_hash,origin,origin_kind,lane_hint,platform,text,url,author,seen_at)
                              VALUES (?,?,?,?,?,?,?,?,?,?)");
        $ins->execute([$cid, $hash, mb_substr($origin, 0, 60), $kind, $laneHint, mb_substr($platform, 0, 30),
                       mb_substr($text, 0, 500), mb_substr($url, 0, 500), mb_substr($author, 0, 120), $seen]);
        if ($ins->rowCount() > 0) {
            // the card's counters and lists come from its own signals (a few rows)
            $pdo->prepare("UPDATE desk_clusters c SET
                    n_signals = (SELECT COUNT(*) FROM desk_signals s WHERE s.cluster_id=c.id),
                    n_general = (SELECT COUNT(*) FROM desk_signals s WHERE s.cluster_id=c.id AND s.origin_kind='general'),
                    n_lane    = (SELECT COUNT(*) FROM desk_signals s WHERE s.cluster_id=c.id AND s.origin_kind='lane')
                 WHERE c.id=?")->execute([$cid]);
            // r153 (2026-09-10): MariaDB cannot see c.id inside a derived table ("Unknown
            // column 'c.id'"), so the single statement that also built these two lists
            // failed on EVERY card since the desk was built: all 1,609 cards read 0
            // signals, the routing bar treated each one as a lone signal, and this
            // function returned null. The lists are built here instead.
            $o = $pdo->prepare("SELECT DISTINCT origin FROM desk_signals WHERE cluster_id=?");
            $o->execute([$cid]);
            $p = $pdo->prepare("SELECT DISTINCT platform FROM desk_signals WHERE cluster_id=? AND platform<>''");
            $p->execute([$cid]);
            $pdo->prepare("UPDATE desk_clusters SET origins_json=?, platforms_json=? WHERE id=?")
                ->execute([json_encode($o->fetchAll(PDO::FETCH_COLUMN), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                           json_encode($p->fetchAll(PDO::FETCH_COLUMN), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $cid]);
        }
        return $cid;
    } catch (Throwable $e) {
        return null;   // intake must never break a fetcher
    }
}

/* C. THE GENERAL JUDGE. Reads cards in batches, decides what each is and
 * where it goes. Cards due: never judged, or grown by DESK_REJUDGE_GROW
 * signals since the last verdict (a slow burner gets a second look). */
function desk_due(PDO $pdo, int $cap): array {
    return $pdo->query("SELECT * FROM desk_clusters
                         WHERE status IN ('new','judged')
                           AND (judged_at IS NULL OR n_signals >= judged_signals + " . DESK_REJUDGE_GROW . ")
                           AND last_seen >= NOW() - INTERVAL 7 DAY
                         ORDER BY (judged_at IS NULL) DESC,
                                  -- r153: fresh cards first. By signal count alone a one-headline story
                                  -- waited behind 900 older cards: 7 of the 16 stories the drama editor
                                  -- picked on 2026-09-10 were still unjudged by the desk.
                                  (first_seen >= NOW() - INTERVAL 6 HOUR) DESC,
                                  n_signals DESC, last_seen DESC
                         LIMIT " . (int)$cap)->fetchAll(PDO::FETCH_ASSOC);
}

function desk_lane_defs(): string {
    // The lane definitions ARE the taxonomy. Written down once, used by the one
    // judge, so "where does it go" has one vocabulary instead of four prompts.
    return
        "LANES (the four are equal; none is preferred):\n"
      . "- drama: a dated SITUATION people are talking about online, built as a sourced timeline. "
      . "Creator/streamer/influencer disputes and scandals; ALSO court cases, trials and true-crime stories "
      . "WHEN the internet is actively discussing them (owner decision 2026-09-05); ALSO gaming NEWS "
      . "(a delay, leak, trailer, launch, ban wave, studio closing, a community moment) - mark those kind=game_news. "
      . "NOT drama, even when widely discussed: natural disasters, wars, elections, general world/business news, sports results, "
      . "weather, product ads - unless the card is about how the INTERNET reacted (a viral clip, a creator's involvement, a meme wave). "
      . "First shadow pass routed 'floods in nepal' to drama; that is a news site's story, not ours.\n"
      . "- slang: a WORD or short PHRASE people use with a shared meaning in current internet culture "
      . "(an emerging or actively-lived term; decades-old words like bro/dude are not).\n"
      . "- meme: a FORMAT - an image/video/audio/caption template that gets remixed, or a named meme character/moment.\n"
      . "- gaming (vocabulary): a word or phrase from game culture with a shared meaning (kind=word, lane=gaming). "
      // r153 (2026-09-11): after the flip the desk routed game titles, Valorant agents and
      // search phrases as gaming words (zelda, fable, jett, reyna, best fps ... 23 pulled back).
      . "A PROPER NAME is never a gaming word: the title of a game or series, a character, agent or hero, a map, "
      . "a streamer or creator, a team, studio or company. Neither is a phrase that only describes (a 'best X' search). "
      . "Such a card is kind=game_news when its evidence shows a situation, otherwise kind=nothing. "
      . "Game NEWS is kind=game_news, lane=drama (the timeline engine), never lane=gaming.\n"
      . "KINDS: story | game_news | word | meme_format | nothing.\n"
      . "URGENCY: breaking (happening now, hours matter) | rising (growing over days) | evergreen (stable interest).\n"
      . "A bare person/brand/product name with no situation and no shared meaning is kind=nothing.";
}

function desk_judge_run(PDO $pdo, int $cap = 30, int $deadlineSec = 240): array {
    require_once __DIR__ . '/ai.php';
    desk_install($pdo);
    $t0 = time();
    $stats = ['due' => 0, 'judged' => 0, 'something' => 0, 'routed' => 0, 'calls' => 0, 'failed' => 0, 'live' => desk_live()];
    $due = desk_due($pdo, $cap);
    $stats['due'] = count($due);
    if (!$due) return $stats;

    foreach (array_chunk($due, DESK_BATCH) as $batch) {
        if (time() - $t0 > $deadlineSec) { echo "  desk: deadline reached, rest waits for next tick\n"; break; }
        $cards = [];
        foreach ($batch as $c) {
            $sig = $pdo->prepare("SELECT origin, origin_kind, platform, text, author, url, seen_at FROM desk_signals WHERE cluster_id=? ORDER BY seen_at DESC LIMIT 5");
            $sig->execute([(int)$c['id']]);
            $lines = [];
            foreach ($sig as $s) {
                $host = $s['url'] ? (parse_url($s['url'], PHP_URL_HOST) ?: '') : '';
                $lines[] = '    - [' . $s['origin'] . ($s['origin_kind'] === 'lane' ? ' (lane source)' : ' (general source)') . '] '
                         . ($s['author'] !== '' ? '@' . $s['author'] . ': ' : '') . mb_substr($s['text'], 0, 200)
                         . ($host ? " ($host)" : '') . ' ' . substr((string)$s['seen_at'], 0, 10);
            }
            $cards[] = "CARD #{$c['id']}: \"" . $c['name'] . "\"  signals={$c['n_signals']} general={$c['n_general']} lane={$c['n_lane']} first_seen=" . substr($c['first_seen'], 0, 10) . "\n" . implode("\n", $lines);
        }
        $res = ai_chat([
            ['role' => 'system', 'content' =>
                'You are the assignment editor of a US Gen Z internet-culture site with four equal sections. '
              . 'You receive CARDS: each is one thing the site noticed, with the raw signals (posts, headlines, queries) that mention it '
              . 'and where each signal came from. Decide, from the evidence on the card, WHAT each card is and WHICH lane it belongs to. '
              . 'A signal from a lane-specific source is a hint about the lane, not a verdict. Output STRICT JSON only.'],
            ['role' => 'user', 'content' =>
                desk_lane_defs() . "\n\nCARDS:\n" . implode("\n\n", $cards) . "\n\n"
              . 'Return JSON: {"cards":[{"id":123,"is_something":0.0-1.0,"kind":"story|game_news|word|meme_format|nothing",'
              . '"lane":"drama|slang|meme|gaming|none","urgency":"breaking|rising|evergreen","confidence":0.0-1.0,'
              . '"why":"<=120 chars, from the evidence","name":"the thing itself in 1-6 words: the slang word, the meme or trend name, or a short story name; never a headline"}]} - one entry per card id, no extra keys.'],
        ], ['nvidia', 'gemini', 'openrouter'], 0.1, 90);
        $stats['calls']++;
        $pdo = db_alive();
        $j = isset($res['content']) ? ai_json($res['content']) : null;
        if (!$j || !isset($j['cards']) || !is_array($j['cards'])) {
            $stats['failed']++;
            echo "  desk: batch failed (" . ($res['error'] ?? 'bad JSON') . ")\n";
            continue;
        }
        $byId = [];
        foreach ($j['cards'] as $v) if (isset($v['id'])) $byId[(int)$v['id']] = $v;
        foreach ($batch as $c) {
            $v = $byId[(int)$c['id']] ?? null;
            if (!$v) continue;
            $is   = max(0, min(1, (float)($v['is_something'] ?? 0)));
            $kind = in_array($v['kind'] ?? '', ['story', 'game_news', 'word', 'meme_format', 'nothing'], true) ? $v['kind'] : 'nothing';
            $lane = in_array($v['lane'] ?? '', ['drama', 'slang', 'meme', 'gaming'], true) ? $v['lane'] : 'none';
            if ($kind === 'game_news') $lane = 'drama';          // the timeline engine owns game news
            if ($kind === 'nothing' || $is < 0.5) $lane = 'none';
            $urg  = in_array($v['urgency'] ?? '', ['breaking', 'rising', 'evergreen'], true) ? $v['urgency'] : 'rising';
            $conf = max(0, min(1, (float)($v['confidence'] ?? 0)));
            $why  = mb_substr((string)($v['why'] ?? ''), 0, 255);
            $newStatus = $c['status'] === 'routed' ? 'routed' : ($lane === 'none' ? 'dropped' : 'judged');
            $pdo->prepare("UPDATE desk_clusters SET judged_at=NOW(), judged_signals=n_signals, is_something=?, kind=?, lane=?,
                             urgency=?, confidence=?, why=?, verdict_json=?, status=?
                           WHERE id=?")
                ->execute([$is, $kind, $lane, $urg, $conf, $why, json_encode($v, JSON_UNESCAPED_UNICODE), $newStatus, (int)$c['id']]);
            $stats['judged']++;
            if ($lane !== 'none') $stats['something']++;
            // stories go on to the drama assistant (select.php) which judges quality;
            // vocabulary enters 'selected' directly, so a lone signal (one Urban
            // Dictionary entry judged 'coyote ugly' slang at 0.9) needs a higher bar.
            $bar = ($kind === 'story' || $kind === 'game_news') ? 0.6 : ((int)$c['n_signals'] >= 2 ? 0.7 : 0.85);
            if ($lane !== 'none' && desk_live() && $conf >= $bar) {
                if (desk_route($pdo, (int)$c['id'], $kind, $lane, $urg, $is, $why)) $stats['routed']++;
            }
        }
    }
    desk_retention($pdo);
    return $stats;
}

/* D. ROUTE (live mode only). One card → one candidates row for ITS lane, unless
 * that lane already rejected this card (per-lane memory, the fix for the
 * four-doors-one-no bug). The builders downstream are untouched. */
/** r153: the name a builder should use. Word and meme cards were named after the
 *  headline that mentioned them ("The 'Thoughts On Lanterns' Meme Reimagines ...") and
 *  the term builders used that as the term, giving headline URLs. Stories keep their
 *  headline: the drafter writes their title. Null = no clear name, do not route yet. */
function desk_canonical_name(array $c, string $kind): ?string {
    $v = json_decode((string)($c['verdict_json'] ?? ''), true) ?: [];
    $named = trim((string)($v['name'] ?? ''));
    if ($kind === 'story' || $kind === 'game_news') return (string)$c['name'];
    if ($named !== '' && mb_strlen($named) <= 60 && count(preg_split('/\s+/u', $named)) <= 8) return $named;
    $raw = trim((string)$c['name']);
    // 'X' Meme / "X" Trend (up to two words between the quote and the format word)
    // A quote opens only after a space/start and closes only before a space or
    // punctuation; an apostrophe BETWEEN two letters is part of the name, so
    // "The 'Let's Groove' Animation Trend" gives "Let's Groove", not "s Groove".
    $q = '(?:^|[\s(])[\'"\x{2018}\x{201C}]((?:[^\'"\x{2018}\x{2019}\x{201C}\x{201D}]|(?<=\w)[\'\x{2019}](?=\w)){2,60}?)[\'"\x{2019}\x{201D}]';
    if (preg_match('/' . $q . '(?:\s+\w+){0,2}\s+(Meme|Memes|Trend|Copypasta|Challenge|Format|Sound|Filter|Dance|Moment)\b/u', $raw, $m)) return trim($m[1]);
    // otherwise the first properly quoted phrase ("Edge's 'Aw, Dude'" gives "Aw, Dude")
    if (preg_match('/' . $q . '(?=[\s,.:;!?)]|$)/u', $raw, $m)) return trim($m[1]);
    // a short name is already the thing itself ("respawn", "tldu")
    if (count(preg_split('/\s+/u', $raw)) <= 4 && mb_strlen($raw) <= 40) return $raw;
    return null;
}

function desk_route(PDO $pdo, int $cid, string $kind, string $lane, string $urgency, float $is, string $why): bool {
    $c = $pdo->query("SELECT * FROM desk_clusters WHERE id=" . (int)$cid)->fetch(PDO::FETCH_ASSOC);
    if (!$c || $c['status'] === 'routed') return false;
    $rej = $pdo->prepare("SELECT 1 FROM desk_lane_rejections WHERE cluster_id=? AND lane=?");
    $rej->execute([$cid, $lane]);
    if ($rej->fetch()) return false;
    $type = match (true) {
        $kind === 'story' || $kind === 'game_news' => 'drama',
        $kind === 'meme_format' => 'meme',
        $kind === 'word' && $lane === 'gaming' => 'gaming',
        default => 'term',
    };
    $routeName = desk_canonical_name($c, $kind);
    if ($routeName === null) return false;   // headline with no clear name: wait for a named verdict
    // per-lane dedupe against the builders' own table (type-scoped: the bug fix)
    $dup = $pdo->prepare("SELECT id FROM candidates WHERE type=? AND LOWER(name)=? LIMIT 1");
    $dup->execute([$type, mb_strtolower(mb_substr($routeName, 0, 240))]);
    if ($dup->fetch()) { $pdo->prepare("UPDATE desk_clusters SET status='routed' WHERE id=?")->execute([$cid]); return false; }
    $sig = $pdo->prepare("SELECT origin, url, text, author, platform FROM desk_signals WHERE cluster_id=? ORDER BY seen_at DESC LIMIT 6");
    $sig->execute([$cid]);
    $evidence = $sig->fetchAll(PDO::FETCH_ASSOC);
    $first = $evidence[0] ?? [];
    $heat = (int)round(40 + 40 * $is + min(10, (int)$c['n_signals']) + ($urgency === 'breaking' ? 10 : 0));
    $heat = max(45, min(95, $heat));
    $signals = [
        'source'  => 'desk:' . ($first['origin'] ?? 'unknown'),
        'url'     => $first['url'] ?? '',
        'desc'    => $first['text'] ?? '',
        'lane'    => $kind === 'game_news' ? 'gaming' : ($type === 'drama' ? 'drama' : $lane),
        'urgency' => $urgency,
        'desk'    => ['cluster' => $cid, 'kind' => $kind, 'n_signals' => (int)$c['n_signals'],
                      'origins' => json_decode((string)$c['origins_json'], true) ?: [], 'evidence' => $evidence],
    ];
    // stories go to the drama assistant (select.php) as 'new'; vocabulary is
    // already judged for shape and lane, it enters 'selected' like the Scout does
    $status = $type === 'drama' ? 'new' : 'selected';
    $pdo->prepare("INSERT INTO candidates (type,name,angle,heat_score,era,status,signals,ai_verdict)
                   VALUES (?,?,?,?,'present',?,?,?)")
        ->execute([$type, mb_substr($routeName, 0, 240), mb_substr($why, 0, 255), $heat, $status,
                   json_encode($signals, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                   (string)$c['verdict_json']]);
    $newId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE desk_clusters SET status='routed', routed_to=? WHERE id=?")->execute([$newId, $cid]);
    return true;
}

/* A lane assistant said no. Remember it FOR THAT LANE ONLY. */
function desk_lane_reject(PDO $pdo, int $candidateId, string $lane, string $reason): void {
    try {
        $cid = (int)$pdo->query("SELECT id FROM desk_clusters WHERE routed_to=" . (int)$candidateId)->fetchColumn();
        if (!$cid) return;
        $pdo->prepare("INSERT IGNORE INTO desk_lane_rejections (cluster_id,lane,reason,created_at) VALUES (?,?,?,NOW())")
            ->execute([$cid, $lane, mb_substr($reason, 0, 255)]);
        // the card may still be something for another lane: reopen it
        $pdo->prepare("UPDATE desk_clusters SET status='judged', routed_to=NULL WHERE id=?")->execute([$cid]);
    } catch (Throwable $e) {}
}

/* RETENTION — the tables the desk owns never grow without bound. */
function desk_retention(PDO $pdo): void {
    try {
        $pdo->exec("DELETE FROM desk_signals WHERE seen_at < NOW() - INTERVAL 30 DAY LIMIT 5000");
        $pdo->exec("DELETE FROM desk_clusters WHERE status IN ('dropped','judged') AND last_seen < NOW() - INTERVAL 30 DAY LIMIT 5000");
        $pdo->exec("DELETE FROM desk_clusters WHERE status='new' AND last_seen < NOW() - INTERVAL 14 DAY LIMIT 5000");
    } catch (Throwable $e) {}
}

/* SHADOW REPORT — the desk's verdict beside what the doors did with the same
 * name, so the flip is a decision made on evidence. */
function desk_report(PDO $pdo, int $limit = 40): array {
    $out = ['agree' => 0, 'disagree' => 0, 'door_never_saw' => 0, 'desk_dropped' => 0, 'rows' => []];
    $q = $pdo->query("SELECT c.id, c.name, c.kind, c.lane, c.urgency, c.confidence, c.why, c.n_signals, c.n_general, c.n_lane,
                             d.type dtype, d.status dstatus, d.reject_reason
                      FROM desk_clusters c
                      LEFT JOIN candidates d ON LOWER(d.name)=LOWER(c.name)
                      WHERE c.judged_at IS NOT NULL
                      ORDER BY c.judged_at DESC, c.id DESC LIMIT " . (int)$limit);
    foreach ($q as $r) {
        $deskLane = $r['lane'];
        $doorLane = $r['dtype'] === null ? null : ($r['dtype'] === 'term' ? 'slang' : $r['dtype']);
        if ($deskLane === 'none') { $verdict = 'desk: nothing'; $out['desk_dropped']++; }
        elseif ($doorLane === null) { $verdict = 'door never saw it'; $out['door_never_saw']++; }
        elseif ($doorLane === $deskLane && $r['dstatus'] !== 'rejected') { $verdict = 'agree'; $out['agree']++; }
        else { $verdict = 'DISAGREE'; $out['disagree']++; }
        $out['rows'][] = ['id' => $r['id'], 'name' => $r['name'], 'desk' => "{$r['kind']}/{$deskLane}/{$r['urgency']} c={$r['confidence']}",
                          'door' => $doorLane ? "{$doorLane}/{$r['dstatus']}" . ($r['reject_reason'] ? ' (' . mb_substr($r['reject_reason'], 0, 50) . ')' : '') : '-',
                          'verdict' => $verdict, 'why' => $r['why'], 'signals' => "{$r['n_signals']} (g{$r['n_general']}/l{$r['n_lane']})"];
    }
    return $out;
}
