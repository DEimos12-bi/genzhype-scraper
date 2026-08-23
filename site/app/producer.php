<?php
/**
 * GenZHype | THE PRODUCER — the daily greenlight (2026-08-20)
 * =============================================================================
 * Owner brief: "pick the best 5 topics daily, from ALL pages not just drama,
 * be trustworthy, and don't overwork the factory" + "the marketing side: does
 * it match the trend, is it current or outdated, and are there signs it will
 * actually succeed".
 *
 * Study first (videorepos/EDITOR-STUDY.md). What real humans do:
 *  [RULE] Newsrooms run ONE daily budget meeting: candidates compete, limited
 *         slots force kills, chosen stories get assigned to producers.
 *         (fiveable.me newsroom unit-14; journalism.university)
 *  [RULE] They score against a published checklist — Harcup & O'Neill 2016's
 *         15 news values. Two matter most to us: "Audio-visuals" (stories with
 *         arresting footage are MORE newsworthy — supply IS a news value) and
 *         "Follow-up" (subjects already in the news = our trend match).
 *  [RULE] Berger & Milkman 2012 (JMR, ~7,000 NYT articles): HIGH-AROUSAL
 *         emotion drives sharing — anger, awe, anxiety, amusement. SADNESS
 *         SUPPRESSES it. Arousal was the single biggest virality factor. This
 *         is the owner's "attack the emotional side" instinct, measured.
 *  [STRUCK] "95% of decisions are subconscious" — a 2003 Zaltman estimate about
 *         ALL THOUGHTS, no study behind it. Direction kept, number never cited.
 *  [RULE] Data informs, editors decide (Salt Lake Tribune moved 1.5 -> 3
 *         reporters onto religion after analytics showed it outperforming).
 *  [RULE] Outlier method (MrBeast team): study videos 3-50x a channel average.
 *         [OURS] our video-intel engine already writes those rules daily.
 *
 * THE GREENLIGHT EQUATION:  score = DEMAND x QUALITY
 *   DEMAND  = do people crave it   (arousal, trend velocity, proven appetite,
 *                                   lane yield, fame)
 *   QUALITY = can we make it well  (visual supply, exclusivity, freshness)
 * Multiplied, not added: a zero on either side kills the pick, exactly like a
 * studio greenlight. A story nobody wants made perfectly = 0. A story everyone
 * wants that we cannot show = 0.
 *
 * GUARDRAIL the owner asked me to judge: raw trends do NOT pick. Google Trends
 * today serves "iran war trump / caitlin clark stats" — off-lane. Trend enters
 * only as VELOCITY measured inside OUR OWN rival feed (dailydot/kym/dexerto/
 * tubefilter RSS = our lane by construction), so a rising lane story outranks a
 * peaked global one we would lose anyway at our size.
 *
 * FAILS CLOSED everywhere: no AI -> deterministic signals still rank (VADER,
 * MIT-licensed, vendored in app/vendor_vader) and the run is logged as
 * degraded. Never picks a page twice. Never exceeds the daily slot count.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const PRODUCER_SLOTS      = 5;     // the budget meeting's chairs
const PRODUCER_MAX_DRAMA  = 3;     // front-page balance: never an all-drama day
const PRODUCER_MIN_TERM   = 1;     // the slang/meme/gaming lane always eats
const PRODUCER_FLOOR      = 0.12;  // below this a pick is not worth a render

/** Tables. Idempotent; safe to call on every run. */
function producer_install(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS producer_pick (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_date DATE NOT NULL,
        page_id INT UNSIGNED NOT NULL,
        lane VARCHAR(16) NOT NULL,
        rank_i TINYINT UNSIGNED NOT NULL,
        score FLOAT NOT NULL,
        demand FLOAT NOT NULL,
        quality FLOAT NOT NULL,
        signals_json MEDIUMTEXT NULL,
        reason VARCHAR(600) NOT NULL DEFAULT '',
        assigned TINYINT(1) NOT NULL DEFAULT 0,
        outcome_json TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_day_page (run_date, page_id),
        KEY (run_date), KEY (page_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // The owner must be able to argue with him, so every candidate he
    // CONSIDERED is stored too — not just the winners. verdict='passed' rows
    // carry the plain-language reason he said no.
    foreach (["verdict VARCHAR(16) NOT NULL DEFAULT 'picked'",
              "pass_reason VARCHAR(300) NOT NULL DEFAULT ''"] as $col) {
        try { $pdo->exec("ALTER TABLE producer_pick ADD COLUMN IF NOT EXISTS $col"); }
        catch (Throwable $e) { /* older MariaDB: column already there */ }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS producer_weight (
        `signal` VARCHAR(40) NOT NULL PRIMARY KEY,
        weight FLOAT NOT NULL,
        note VARCHAR(300) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                   ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed weights ONCE. Sourced, not invented:
    //  arousal highest  -> Berger & Milkman's single biggest virality factor
    //  supply high      -> H&O "Audio-visuals" + our own page-116 evidence
    //  trend real but capped -> the off-lane drift guardrail above
    $seed = [
        ['arousal',    0.34, 'Berger-Milkman 2012: high-arousal emotion is the top virality factor'],
        ['trend',      0.22, 'H&O 2016 "Follow-up" news value; measured as velocity inside our own lane feed'],
        ['appetite',   0.20, 'audience already voted: rival video outliers + artifact engagement'],
        ['lane_yield', 0.14, 'our own per-lane results, platform-normalized (TikTok divided by TikTok)'],
        ['fame',       0.10, 'H&O 2016 "Celebrity" / "Power elite"'],
        ['supply',     0.45, 'H&O 2016 "Audio-visuals"; page 116 proved thin supply renders weak'],
        ['exclusive',  0.30, 'H&O 2016 "Exclusivity": our dated receipt depth'],
        ['fresh',      0.25, 'H&O timeliness; our staleness watcher found 12 of 20 stories behind the news'],
    ];
    $st = $pdo->prepare("INSERT IGNORE INTO producer_weight (`signal`, weight, note) VALUES (?,?,?)");
    foreach ($seed as $s) { $st->execute($s); }
}

/** Current weights (learned values win; seeds are the floor). */
function producer_weights(PDO $pdo): array
{
    $w = [];
    foreach ($pdo->query("SELECT `signal`, weight FROM producer_weight") as $r) {
        $w[$r['signal']] = (float)$r['weight'];
    }
    return $w;
}

/** Words worth matching on: drop stopwords, keep names/rare terms. */
function producer_keywords(string $text, int $max = 6): array
{
    static $stop = null;
    if ($stop === null) {
        $stop = array_flip(explode(' ',
            'the a an and or but of to in on at for with from by as is are was were be been '
          . 'being it its this that these those his her their our your my he she they we you i '
          . 'not no yes new after before over under about into how why what when who which '
          . 'says said say video says viral fans internet online sparks amid explained '
          // FIX: every term page is titled "What Does 'X' Mean?", so these
          // format words became the topic keywords and matched a random rival
          // video ("a rival video on \"does\" pulled 362k views" in the first run).
          . 'does mean meaning means meant guide really actually definition term '
          . 'slang word words full story timeline everything know need'));
    }
    $t = mb_strtolower(strip_tags($text));
    $t = preg_replace('/[^a-z0-9\s\'-]/u', ' ', $t) ?? $t;
    $words = preg_split('/\s+/', trim((string)$t), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($words as $w) {
        if (mb_strlen($w) < 4 || isset($stop[$w])) { continue; }
        $out[$w] = ($out[$w] ?? 0) + 1;
    }
    // rarest-looking first = longest; good enough proxy without a corpus IDF.
    // (PHP casts numeric-looking keys like "2026" to int, so stringify both in
    // the comparator AND on the way out — strict_types would fatal otherwise.)
    uksort($out, fn($a, $b) => mb_strlen((string)$b) <=> mb_strlen((string)$a));
    return array_map('strval', array_slice(array_keys($out), 0, $max));
}

/** First usable string from a column that may be JSON array, JSON string or text. */
function producer_first_str($v): string
{
    $raw = trim((string)$v);
    if ($raw === '') { return ''; }
    $j = json_decode($raw, true);
    if (is_array($j)) {
        foreach ($j as $item) {
            if (is_string($item) && trim($item) !== '') { return trim($item); }
        }
        return '';
    }
    return is_string($j) ? trim($j) : $raw;
}

/**
 * INTAKE — every lane competes (the owner's core complaint: drama-only).
 * A page is a candidate when it is published, indexed, and has no finished
 * video. Dramas additionally re-enter as FOLLOW-UPS when their timeline gained
 * a new dated event after the last video was made (H&O "Follow-up").
 */
function producer_intake(PDO $pdo): array
{
    $c = [];

    // --- drama lane -----------------------------------------------------
    $sql = "SELECT p.id page_id, p.slug, p.h1 title, p.published_at, d.id did,
                   d.people_json, d.mood,
                   (SELECT COUNT(*) FROM events e
                     WHERE e.drama_id=d.id AND e.event_date IS NOT NULL
                       AND e.video_only=0) dated_events,
                   (SELECT COUNT(*) FROM events e
                     WHERE e.drama_id=d.id AND (e.source_id IS NOT NULL
                            OR e.embed_html<>'')) sourced_events,
                   (SELECT MAX(e.event_date) FROM events e
                     WHERE e.drama_id=d.id) newest_event,
                   v.video_status, v.video_made_at, v.footage_clips
              FROM pages p
              JOIN dramas d ON d.page_id = p.id
              LEFT JOIN video_scripts v ON v.page_id = p.id
             WHERE p.status='published' AND p.robots='index' AND p.cover<>''
               AND p.type='drama'";   // term_video materializes TERM pages into
        // dramas rows to reuse the timeline engine, so without this filter a
        // slang page enters BOTH lanes and can win two of the five chairs
        // (rickroll did exactly that on the first live run).
    foreach ($pdo->query($sql) as $r) {
        $hasVideo = ((string)($r['video_status'] ?? '')) === 'ready';
        $followUp = false;
        if ($hasVideo) {
            // FOLLOW-UP (H&O news value): re-enter ONLY when the timeline has
            // gained an event DATED AFTER the last render — the events table
            // has no created_at, and a newer dated fact is the honest test of
            // "the story moved" anyway.
            $made = strtotime((string)($r['video_made_at'] ?? '')) ?: 0;
            $newE = strtotime((string)($r['newest_event'] ?? '')) ?: 0;
            $followUp = ($made > 0 && $newE > $made);
            if (!$followUp) { continue; }
        }
        if ((int)$r['dated_events'] < 4) { continue; }   // timeline needs >= 4
        $clips = json_decode((string)($r['footage_clips'] ?? '[]'), true);
        $c[] = [
            'page_id'   => (int)$r['page_id'],
            'lane'      => 'drama',
            'title'     => (string)$r['title'],
            'slug'      => (string)$r['slug'],
            'follow_up' => $followUp,
            'dated'     => (int)$r['dated_events'],
            'sourced'   => (int)$r['sourced_events'],
            'newest'    => (string)($r['newest_event'] ?? ''),
            'clips'     => is_array($clips) ? count($clips) : 0,
            'people'    => (array)(json_decode((string)($r['people_json'] ?? '[]'), true) ?: []),
        ];
    }

    // --- term lane (slang / meme / gaming) -------------------------------
    // 82 published term pages have no video; the format is proven (PogChamp).
    $sql = "SELECT p.id page_id, p.slug, p.h1 title, p.published_at,
                   t.term, t.lane, t.citations, t.origin_date,
                   t.short_def, t.why_trending
              FROM pages p JOIN terms t ON t.page_id = p.id
              LEFT JOIN video_scripts v ON v.page_id = p.id AND v.video_status='ready'
             WHERE p.status='published' AND p.robots='index' AND p.cover<>''
               AND v.page_id IS NULL";
    foreach ($pdo->query($sql) as $r) {
        $dated = 0;
        foreach ((array)json_decode((string)$r['citations'], true) as $ct) {
            if (!empty($ct['date']) && strtotime((string)$ct['date'])) { $dated++; }
        }
        if (!empty($r['origin_date']) && strtotime((string)$r['origin_date'])) { $dated++; }
        if ($dated < 3) { continue; }        // term_video needs >= 3 dated items
        // DATE TRAP: origin_date is often a BARE YEAR ("2013"), and
        // strtotime("2013") returns 20:13 TODAY — delulu scored "0 days old".
        // Only a full date counts as dated evidence; a year means evergreen.
        $od = trim((string)($r['origin_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $od)) { $od = ''; }
        $c[] = [
            'page_id'   => (int)$r['page_id'],
            'lane'      => (string)($r['lane'] ?: 'term'),
            'title'     => (string)$r['title'],
            'slug'      => (string)$r['slug'],
            'follow_up' => false,
            'dated'     => $dated,
            'sourced'   => $dated,
            'newest'    => $od,
            'clips'     => 0,
            'people'    => [],
            'term'      => (string)$r['term'],
            // the slang word IS the topic; the H1 is a template around it
            'match'     => (string)$r['term'],
            // WHAT HE JUDGES THE FEELING ON. Every term page's H1 is the
            // dry SEO template "What Does 'X' Mean?", so scoring emotion on
            // the title called the ENTIRE slang lane "flat" — while the
            // pages underneath held things like mewing being a classroom
            // prank that drives teachers up the wall. Judge the substance.
            'brief'     => trim((string)$r['term'] . ' — '
                . mb_substr(trim((string)$r['short_def']), 0, 220) . ' '
                . mb_substr(producer_first_str($r['why_trending']), 0, 280)),
        ];
    }
    return $c;
}

/**
 * TREND VELOCITY — is this topic RISING in our own lane right now?
 * Measured inside our rival RSS intake (candidates table: dailydot, kym,
 * dexerto, tubefilter...). Rising beats peaked: at our size a peaked global
 * trend belongs to accounts with millions of followers, while a rising lane
 * story is where our timeline-and-receipts format can actually win.
 */
function producer_trend_index(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT name, created_at FROM candidates
          WHERE created_at > DATE_SUB(NOW(), INTERVAL 14 DAY)")->fetchAll(PDO::FETCH_ASSOC);
    $recent = [];   // word -> hits in last 48h
    $base   = [];   // word -> hits in the 12 days before that
    $cut = time() - 48 * 3600;
    foreach ($rows as $r) {
        $isRecent = strtotime((string)$r['created_at']) >= $cut;
        foreach (producer_keywords((string)$r['name'], 8) as $w) {
            if ($isRecent) { $recent[$w] = ($recent[$w] ?? 0) + 1; }
            else           { $base[$w]   = ($base[$w]   ?? 0) + 1; }
        }
    }
    return ['recent' => $recent, 'base' => $base, 'sample' => count($rows)];
}

/** Rival VIDEO outliers (video-intel engine) — proof that video demand exists. */
function producer_outlier_index(PDO $pdo): array
{
    $idx = [];
    try {
        $rows = $pdo->query(
            "SELECT title, views FROM vid_rival_post
              WHERE published_at > DATE_SUB(NOW(), INTERVAL 60 DAY)
              ORDER BY views DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            foreach (producer_keywords((string)$r['title'], 6) as $w) {
                $idx[$w] = max($idx[$w] ?? 0, (int)$r['views']);
            }
        }
    } catch (Throwable $e) { /* engine table absent -> signal simply 0 */ }
    return $idx;
}

/** Per-lane historical yield, platform-normalized (owner's TikTok rule). */
function producer_lane_yield(PDO $pdo): array
{
    $out = [];
    try {
        // platform average first, so TikTok's free boost cannot flatter a lane
        $avg = [];
        foreach ($pdo->query(
            "SELECT s.platform, AVG(m.views) a
               FROM platform_metrics m
               JOIN social_posts s ON s.id = m.video_id
              WHERE m.views > 0 GROUP BY s.platform") as $r) {
            $avg[(string)$r['platform']] = max(1.0, (float)$r['a']);
        }
        $rows = $pdo->query(
            "SELECT p.type lane, s.platform, AVG(m.views) v,
                    COUNT(DISTINCT s.page_id) pages
               FROM platform_metrics m
               JOIN social_posts s ON s.id = m.video_id
               JOIN pages p ON p.id = s.page_id
              WHERE m.views > 0 GROUP BY p.type, s.platform")->fetchAll(PDO::FETCH_ASSOC);
        $acc = [];  $seen = [];
        foreach ($rows as $r) {
            $pl = (string)$r['platform'];
            if (!isset($avg[$pl])) { continue; }
            $lane = (string)$r['lane'];
            $acc[$lane][] = (float)$r['v'] / $avg[$pl];   // 1.0 = platform par
            $seen[$lane] = max($seen[$lane] ?? 0, (int)$r['pages']);
        }
        foreach ($acc as $lane => $vals) {
            // MINIMUM SAMPLE: a lane measured on fewer than 3 distinct pages is
            // an anecdote, not a yield. Neutral until it earns an opinion —
            // same discipline the video-intel engine uses (12-outlier floor).
            $out[$lane] = ($seen[$lane] ?? 0) < 3
                ? 1.0
                : array_sum($vals) / max(1, count($vals));
        }
    } catch (Throwable $e) { /* no metrics yet -> neutral */ }
    return $out;
}

/** VADER fallback intensity (MIT, vendored). Never fatal. */
function producer_vader(string $text): ?float
{
    static $an = null;
    if ($an === false) { return null; }
    if ($an === null) {
        try {
            require_once __DIR__ . '/vendor_vader/SentiText.php';
            require_once __DIR__ . '/vendor_vader/SentimentIntensityAnalyzer.php';
            $an = new \VaderSentiment\SentimentIntensityAnalyzer();
        } catch (Throwable $e) { $an = false; return null; }
    }
    try {
        $s = $an->getSentiment($text);
        return abs((float)($s['compound'] ?? 0));
    } catch (Throwable $e) { return null; }
}

/**
 * AROUSAL — the Berger-Milkman signal, batched into ONE AI call for the whole
 * shortlist (quota discipline). Returns page_id => ['emotion'=>..,'score'=>..].
 * AI down -> VADER intensity, logged as degraded. Sadness is deliberately
 * scored LOW: the study found sad content suppresses sharing.
 */
function producer_arousal(PDO $pdo, array $cands): array
{
    $out = [];
    $list = '';
    foreach ($cands as $c) {
        $list .= "[{$c['page_id']}] "
              . mb_substr((string)($c['brief'] ?? $c['title']), 0, 320) . "\n";
    }
    $ok = false;
    if ($list !== '' && function_exists('ai_chat')) {
        $sys = 'You rate how strongly a story would make a scrolling viewer FEEL, using the '
             . 'arousal model from Berger & Milkman (2012): high-arousal emotions (anger, awe, '
             . 'anxiety, amusement) drive sharing; sadness is low-arousal and suppresses it. '
             . 'Return ONE json object covering ALL stories, nothing else: {"r":[{"id":123,"emotion":"anger|awe|'
             . 'amusement|anxiety|sadness|flat","intensity":0-100}]}. Judge the FEELING the '
             . 'headline provokes, not whether it is important. No prose.';
        try {
            $res = ai_chat([
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $list],
            ], ['gemini', 'openrouter', 'nvidia'], 0.2, 90);
            // SHAPE-PROOF PARSE. Free providers answer in whatever shape they
            // like — nvidia returned THREE separate numbered {"r":[...]}
            // objects and ai_json() returned null, so every term fell back to
            // VADER and the whole slang lane read "flat". Pull every item
            // object out of the text instead of trusting one envelope.
            $content = (string)($res['content'] ?? '');
            $items = [];
            $j = ai_json($content);
            if (is_array($j) && !empty($j['r']) && is_array($j['r'])) {
                $items = $j['r'];
            } elseif (preg_match_all('/\{[^{}]*"id"\s*:\s*\d+[^{}]*\}/', $content, $mm)) {
                foreach ($mm[0] as $blob) {
                    $one = json_decode($blob, true);
                    if (is_array($one) && isset($one['id'])) { $items[] = $one; }
                }
            }
            foreach ($items as $row) {
                $id = (int)($row['id'] ?? 0);
                if (!$id) { continue; }
                $emo = strtolower((string)($row['emotion'] ?? 'flat'));
                $int = max(0, min(100, (int)($row['intensity'] ?? 0))) / 100.0;
                // Berger-Milkman weighting: activation, not positivity
                $mult = ['anger' => 1.0, 'awe' => 0.95, 'amusement' => 0.9,
                         'anxiety' => 0.85, 'sadness' => 0.30, 'flat' => 0.25][$emo] ?? 0.4;
                $out[$id] = ['emotion' => $emo, 'score' => round($int * $mult, 3),
                             'via' => 'ai'];
                $ok = true;
            }
        } catch (Throwable $e) { /* fall through to VADER */ }
    }
    foreach ($cands as $c) {                       // fill every gap, fail closed
        if (isset($out[$c['page_id']])) { continue; }
        $v = producer_vader((string)($c['brief'] ?? $c['title']));
        $out[$c['page_id']] = $v === null
            ? ['emotion' => 'unknown', 'score' => 0.35, 'via' => 'default']
            : ['emotion' => 'intensity', 'score' => round(min(1.0, $v * 0.9), 3),
               'via' => 'vader'];
    }
    $GLOBALS['__producer_ai_ok'] = $ok;
    return $out;
}

/** Score one candidate. Returns demand/quality/score + per-signal evidence. */
function producer_score(array $c, array $w, array $trend, array $outliers,
                        array $laneYield, array $arousal): array
{
    $sig = [];

    // ---------------- DEMAND ----------------
    $ar = $arousal[$c['page_id']] ?? ['emotion' => 'unknown', 'score' => 0.35, 'via' => 'default'];
    $sig['arousal'] = ['v' => (float)$ar['score'],
                       'why' => "feeling: {$ar['emotion']} (via {$ar['via']})"];

    $kw = producer_keywords((string)($c['match'] ?? $c['title']), 6);
    $rHits = 0; $bHits = 0; $hot = [];
    foreach ($kw as $k) {
        $r = $trend['recent'][$k] ?? 0;
        $b = $trend['base'][$k] ?? 0;
        $rHits += $r; $bHits += $b;
        if ($r >= 2) { $hot[] = "{$k} x{$r}"; }
    }
    // velocity: last-48h rate vs the prior 12-day daily rate (rising > peaked)
    $rate48 = $rHits / 2.0;                       // hits per day, last 2 days
    $rateBase = $bHits / 12.0;                    // hits per day, prior 12
    $vel = $rateBase > 0.05 ? $rate48 / $rateBase : ($rHits > 0 ? 2.0 : 0.0);
    $sig['trend'] = ['v' => max(0.0, min(1.0, $vel / 3.0)),
                     'why' => $hot ? ('rising in our feed: ' . implode(', ', $hot))
                                   : 'no rival chatter in the last 48h'];

    $best = 0; $bw = '';
    foreach ($kw as $k) {
        if (($outliers[$k] ?? 0) > $best) { $best = (int)$outliers[$k]; $bw = $k; }
    }
    $sig['appetite'] = ['v' => $best > 0 ? min(1.0, log10(max(10, $best)) / 6.0) : 0.0,
                        'why' => $best > 0
                            ? "a rival video on \"{$bw}\" pulled " . number_format($best) . " views"
                            : 'no rival video outlier on this topic'];

    $ly = $laneYield[$c['lane']] ?? ($laneYield['drama'] ?? 1.0);
    $sig['lane_yield'] = ['v' => max(0.0, min(1.0, $ly / 2.0)),
                          'why' => sprintf('%s lane runs %.2fx platform par', $c['lane'], $ly)];

    $isNews = ($c['lane'] === 'drama');

    $np = count($c['people']);
    $sig['fame'] = ['v' => min(1.0, $np / 3.0),
                    'why' => $np ? "$np named person(s) on the page" : 'no named people'];

    // ---------------- QUALITY ----------------
    // LANE-AWARE SUPPLY: a drama video is carried by clips and receipts; a term
    // video is built by term_video from its DATED CITATIONS (that is literally
    // what it renders). Judging an explainer by clip count scored the whole
    // slang lane to zero on the first live run — a features desk does not grade
    // an explainer on breaking-news criteria.
    if ($isNews) {
        $supply = $c['clips'] * 1.5 + min(6, $c['sourced']) * 0.5;
        $why = "{$c['clips']} clip(s) + {$c['sourced']} sourced receipt(s)";
    } else {
        $supply = min(8, $c['sourced']) * 0.75;
        $why = "{$c['sourced']} dated citation(s) to build from";
    }
    $sig['supply'] = ['v' => min(1.0, $supply / 6.0), 'why' => $why];

    $sig['exclusive'] = ['v' => min(1.0, $c['dated'] / 8.0),
                         'why' => "{$c['dated']} dated timeline item(s)"];

    $days = 999;
    if ($c['newest'] && strtotime($c['newest'])) {
        $days = max(0, (int)floor((time() - strtotime($c['newest'])) / 86400));
    }
    if ($isNews) {
        // news decays fast — our staleness watcher caught 12 of 20 stories
        // published behind the news
        $fresh = max(0.0, 1.0 - $days / 45.0);
        $fwhy  = "newest evidence {$days}d old";
    } else {
        // EVERGREEN CURRENCY (the owner's "is it updated or outdated" question,
        // answered the right way for slang): a 2013 word is not stale — a word
        // nobody is saying right now is. So currency = present-day chatter,
        // never the coinage date.
        $fresh = 0.70 + 0.30 * (float)$sig['trend']['v'];
        $fwhy  = $sig['trend']['v'] > 0.05
            ? 'evergreen, and currently being talked about'
            : 'evergreen explainer, quiet right now';
    }
    $sig['fresh'] = ['v' => round($fresh, 3), 'why' => $fwhy];

    // Signals that cannot apply to a lane must not COUNT AGAINST it: an
    // explainer has no "named people", so scoring it on fame is a structural
    // penalty, not a judgement. Weights renormalize over what applies.
    $dW = $isNews
        ? ['arousal', 'trend', 'appetite', 'lane_yield', 'fame']
        : ['arousal', 'trend', 'appetite', 'lane_yield'];
    $qW = ['supply', 'exclusive', 'fresh'];
    if (!$isNews) {
        // drop it entirely so neither the score NOR the printed reason blames
        // an explainer for having no celebrities in it
        unset($sig['fame']);
    }
    $sum = function (array $keys) use ($sig, $w) {
        $num = 0.0; $den = 0.0;
        foreach ($keys as $k) {
            $wt = $w[$k] ?? 0.2;
            $num += $wt * ($sig[$k]['v'] ?? 0.0);
            $den += $wt;
        }
        return $den > 0 ? $num / $den : 0.0;
    };
    $demand  = $sum($dW);
    $quality = $sum($qW);
    if (!empty($c['follow_up'])) { $demand = min(1.0, $demand * 1.15); }  // H&O follow-up

    return ['demand' => round($demand, 4), 'quality' => round($quality, 4),
            'score' => round($demand * $quality, 4), 'signals' => $sig];
}

/** Plain-language reason (the trust contract: never vibes, always numbers). */
function producer_reason(array $c, array $s): string
{
    $sig = $s['signals'];
    $rank = $sig;
    uasort($rank, fn($a, $b) => $b['v'] <=> $a['v']);
    $top = array_slice(array_keys($rank), 0, 3);
    $bits = [];
    foreach ($top as $k) { $bits[] = $sig[$k]['why']; }
    $weak = array_key_last($rank);
    return sprintf('Chosen because %s. Weakest point: %s. (demand %d%% x quality %d%%)',
        implode('; ', $bits), $sig[$weak]['why'],
        (int)round($s['demand'] * 100), (int)round($s['quality'] * 100));
}

/**
 * THE BUDGET MEETING — score everything, pick exactly N under lane quotas,
 * assign, log. $dry=true scores and logs WITHOUT queueing renders.
 */
function producer_run(PDO $pdo, int $slots = PRODUCER_SLOTS, bool $dry = false): array
{
    producer_install($pdo);
    $w        = producer_weights($pdo);
    $cands    = producer_intake($pdo);
    if (!$cands) { return ['ok' => true, 'candidates' => 0, 'picked' => 0,
                           'note' => 'no eligible pages in any lane']; }

    $trend    = producer_trend_index($pdo);
    $outliers = producer_outlier_index($pdo);
    $yield    = producer_lane_yield($pdo);

    // Cheap pre-rank on deterministic signals, so the ONE AI call only rates a
    // shortlist (quota discipline; the full field still gets scored after).
    $pre = [];
    foreach ($cands as $c) {
        $pre[] = ['c' => $c,
                  's' => producer_score($c, $w, $trend, $outliers, $yield, [])];
    }
    usort($pre, fn($a, $b) => $b['s']['score'] <=> $a['s']['score']);
    $short = array_slice(array_column($pre, 'c'), 0, min(24, count($pre)));
    $arousal = producer_arousal($pdo, $short);

    $scored = [];
    foreach ($short as $c) {
        $s = producer_score($c, $w, $trend, $outliers, $yield, $arousal);
        $scored[] = ['c' => $c, 's' => $s];
    }
    usort($scored, fn($a, $b) => $b['s']['score'] <=> $a['s']['score']);

    // ---- the kills: quotas + floor (a real front page, not a top-5 list) ----
    $picks = []; $nDrama = 0; $nTerm = 0; $skipped = []; $takenPages = []; $passed = [];
    foreach ($scored as $row) {
        if (count($picks) >= $slots) {
            $passed[] = [$row['c'], $row['s'], 'all ' . $slots
                . ' chairs were already taken by stronger topics — this one is '
                . 'first in line tomorrow.'];
            continue;
        }
        $c = $row['c']; $s = $row['s'];
        if ($s['score'] < PRODUCER_FLOOR) {
            $passed[] = [$c, $s, 'nothing here would stop a thumb: '
                . strtolower(producer_weak_bit($s)) . '.'];
            $skipped[] = "{$c['page_id']}: below floor"; continue;
        }
        $isDrama = $c['lane'] === 'drama';
        if ($isDrama && $nDrama >= PRODUCER_MAX_DRAMA) {
            $passed[] = [$c, $s, 'good enough to make, but I had already taken '
                . PRODUCER_MAX_DRAMA . ' drama stories and I do not run drama-only days.'];
            $skipped[] = "{$c['page_id']}: drama quota full"; continue;
        }
        // hold the last chair for the term lane if it has not eaten yet
        if ($isDrama && $nTerm < PRODUCER_MIN_TERM
            && count($picks) === $slots - 1) {
            $hasTerm = false;
            foreach ($scored as $r2) {
                if ($r2['c']['lane'] !== 'drama' && $r2['s']['score'] >= PRODUCER_FLOOR) {
                    $hasTerm = true; break;
                }
            }
            if ($hasTerm) {
                $passed[] = [$c, $s, 'it scored well, but I held the last chair for the '
                    . 'slang/meme/gaming side so that lane eats every day.'];
                $skipped[] = "{$c['page_id']}: last chair reserved for the term lane";
                continue;
            }
        }
        if (isset($takenPages[$c['page_id']])) {   // belt: one chair per page
            $passed[] = [$c, $s, 'the same page had already won a chair in another lane.'];
            $skipped[] = "{$c['page_id']}: already picked in another lane"; continue;
        }
        $takenPages[$c['page_id']] = true;
        $picks[] = $row;
        if ($isDrama) { $nDrama++; } else { $nTerm++; }
    }

    // ---- assignment ----
    $today = date('Y-m-d');
    $ins = $pdo->prepare(
        "INSERT INTO producer_pick
            (run_date, page_id, lane, rank_i, score, demand, quality, signals_json, reason, assigned)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE score=VALUES(score), demand=VALUES(demand),
            quality=VALUES(quality), signals_json=VALUES(signals_json),
            reason=VALUES(reason), assigned=VALUES(assigned), rank_i=VALUES(rank_i)");
    $assigned = 0; $out = [];
    foreach ($picks as $i => $row) {
        $c = $row['c']; $s = $row['s'];
        // ORDER MATTERS: the verdict is stored BEFORE the script is written,
        // because the script writer asks producer_emotion_for_page() what
        // feeling this story carries so the closing line can speak to it.
        // Written after, the writer would read yesterday's verdict or none.
        $ins->execute([$today, $c['page_id'], $c['lane'], $i + 1,
                       $s['score'], $s['demand'], $s['quality'],
                       json_encode($s['signals'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                       mb_substr(producer_reason($c, $s), 0, 600), 0]);
        $did = false;
        if (!$dry) {
            try { $did = producer_assign($pdo, $c); }
            catch (Throwable $e) {
                error_log("producer: assign failed for {$c['page_id']}: " . $e->getMessage());
            }
        }
        if ($did) {
            $assigned++;
            $pdo->prepare("UPDATE producer_pick SET assigned=1
                            WHERE run_date=? AND page_id=?")
                ->execute([$today, $c['page_id']]);
        }
        $out[] = ['page_id' => $c['page_id'], 'lane' => $c['lane'],
                  'title' => $c['title'], 'score' => $s['score'],
                  'demand' => $s['demand'], 'quality' => $s['quality'],
                  'assigned' => $did, 'reason' => producer_reason($c, $s)];
    }

    // the ones he said no to — stored so the owner can argue with him
    $insP = $pdo->prepare(
        "INSERT INTO producer_pick
            (run_date, page_id, lane, rank_i, score, demand, quality,
             signals_json, reason, assigned, verdict, pass_reason)
         VALUES (?,?,?,0,?,?,?,?,'',0,'passed',?)
         ON DUPLICATE KEY UPDATE score=VALUES(score), pass_reason=VALUES(pass_reason)");
    foreach (array_slice($passed, 0, 12) as $p) {
        if (isset($takenPages[$p[0]['page_id']])) { continue; }   // it won elsewhere
        try {
            $insP->execute([$today, $p[0]['page_id'], $p[0]['lane'], $p[1]['score'],
                            $p[1]['demand'], $p[1]['quality'],
                            json_encode($p[1]['signals'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                            mb_substr($p[2], 0, 300)]);
        } catch (Throwable $e) { /* logging a rejection must never break a run */ }
    }

    return ['ok' => true, 'run_date' => $today, 'candidates' => count($cands),
            'scored' => count($scored), 'picked' => count($picks),
            'assigned' => $assigned, 'lanes' => ['drama' => $nDrama, 'other' => $nTerm],
            'arousal_via' => !empty($GLOBALS['__producer_ai_ok']) ? 'ai' : 'vader/degraded',
            'trend_sample' => $trend['sample'], 'skipped' => array_slice($skipped, 0, 8),
            'picks' => $out];
}

/** Turn a pick into a queued render (lane-appropriate generator). */
function producer_assign(PDO $pdo, array $c): bool
{
    $pageId = (int)$c['page_id'];
    // NOTE the two generators use OPPOSITE conventions — this bit me on the
    // first live run (a successful rickroll build was logged as "not queued"):
    //   video_write_timeline_script() -> array on success, null on failure
    //   term_video_materialize()      -> NULL on success, error STRING on failure
    if ($c['lane'] === 'drama') {
        require_once __DIR__ . '/video_factory.php';
        if (!video_write_timeline_script($pdo, $pageId)) { return false; }
    } else {
        require_once __DIR__ . '/term_video.php';
        $err = term_video_materialize($pdo, $pageId);
        if ($err !== null) {
            error_log("producer: term {$pageId} declined — {$err}");
            return false;
        }
    }
    $pdo->prepare("UPDATE video_scripts
                      SET force_render=1, video_status='pending',
                          video_path=NULL, video_made_at=NULL
                    WHERE page_id=?")->execute([$pageId]);
    return true;
}

/**
 * THE TRIBUNE MOVE — read our own yesterday and nudge the weights.
 * Bounded steps (max +-8% per run, hard clamped) so one lucky video can never
 * flip the machine's taste; performance is platform-normalized first.
 */
function producer_review(PDO $pdo, int $lookbackDays = 3): array
{
    producer_install($pdo);
    $rows = $pdo->query(
        "SELECT id, page_id, lane, score, demand, quality, signals_json, run_date
           FROM producer_pick
          WHERE assigned=1 AND outcome_json IS NULL
            AND run_date <= DATE_SUB(CURDATE(), INTERVAL {$lookbackDays} DAY)
          ORDER BY run_date ASC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { return ['ok' => true, 'reviewed' => 0, 'note' => 'nothing mature enough yet']; }

    $avg = [];
    foreach ($pdo->query(
        "SELECT s.platform, AVG(m.views) a FROM platform_metrics m
           JOIN social_posts s ON s.id=m.video_id
          WHERE m.views>0 GROUP BY s.platform") as $r) {
        $avg[(string)$r['platform']] = max(1.0, (float)$r['a']);
    }

    $hits = 0; $done = 0; $perSignal = [];
    foreach ($rows as $r) {
        $st = $pdo->prepare(
            "SELECT s.platform, MAX(m.views) v FROM platform_metrics m
               JOIN social_posts s ON s.id=m.video_id
              WHERE s.page_id=? GROUP BY s.platform");
        $st->execute([(int)$r['page_id']]);
        $ratios = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $pl = (string)$m['platform'];
            if (!isset($avg[$pl])) { continue; }
            $ratios[] = (float)$m['v'] / $avg[$pl];   // 1.0 = that platform's par
        }
        if (!$ratios) { continue; }                    // not measured yet, leave open
        $perf = array_sum($ratios) / count($ratios);
        $hit = $perf >= 1.0;
        if ($hit) { $hits++; }
        $done++;
        foreach ((array)json_decode((string)$r['signals_json'], true) as $k => $s) {
            $v = (float)($s['v'] ?? 0);
            if ($v <= 0.001) { continue; }             // signal said nothing here
            $perSignal[$k][] = $hit ? $v : -$v;        // credit/blame by strength
        }
        $pdo->prepare("UPDATE producer_pick SET outcome_json=? WHERE id=?")
            ->execute([json_encode(['perf' => round($perf, 3), 'hit' => $hit,
                                    'platforms' => count($ratios)]), (int)$r['id']]);
    }

    $moved = [];
    if ($done >= 3) {                                   // never learn from noise
        $w = producer_weights($pdo);
        foreach ($perSignal as $k => $vals) {
            if (count($vals) < 3 || !isset($w[$k])) { continue; }
            $bias = array_sum($vals) / count($vals);    // -1..1
            $new  = max(0.05, min(0.6, $w[$k] * (1.0 + max(-0.08, min(0.08, $bias * 0.16)))));
            if (abs($new - $w[$k]) > 0.0005) {
                $pdo->prepare("UPDATE producer_weight SET weight=?, note=? WHERE `signal`=?")
                    ->execute([$new,
                        sprintf('auto: %d picks, bias %+.2f (%s)', count($vals), $bias, date('Y-m-d')),
                        $k]);
                $moved[$k] = ['from' => round($w[$k], 3), 'to' => round($new, 3)];
            }
        }
    }
    return ['ok' => true, 'reviewed' => $done, 'hits' => $hits,
            'hit_rate' => $done ? round($hits / $done, 2) : null,
            'weights_moved' => $moved,
            'note' => $done < 3 ? 'measured too few to move weights safely' : ''];
}

/* ===========================================================================
 * HIS VOICE — the owner's rule: "I need him to talk to me, why he chose
 * anything". Tables do not argue back; a person does. Everything below is
 * built from the stored numbers only (no AI, so it never goes silent and
 * never invents a reason he did not actually use).
 * ======================================================================== */

/** Plain-English name for each signal, used when he speaks. */
function producer_signal_words(string $k): string
{
    return [
        'arousal'    => 'how strongly it makes people feel something',
        'trend'      => 'whether it is rising right now',
        'appetite'   => 'proof that videos on this topic get watched',
        'lane_yield' => 'how this lane usually performs for us',
        'fame'       => 'how well known the people are',
        'supply'     => 'how much real material I have to show',
        'exclusive'  => 'how deep our receipts go',
        'fresh'      => 'whether it is current or outdated',
    ][$k] ?? $k;
}

/** The weakest thing about a scored candidate, in his words. */
function producer_weak_bit(array $s): string
{
    $sig = $s['signals'] ?? [];
    if (!$sig) { return 'I could not measure much about it'; }
    uasort($sig, fn($a, $b) => ($a['v'] ?? 0) <=> ($b['v'] ?? 0));
    $k = array_key_first($sig);
    return (string)($sig[$k]['why'] ?? producer_signal_words((string)$k));
}

/**
 * The daily briefing: what he looked at, why each winner won, who he turned
 * down and why, and what he is unsure about. Returns paragraphs of plain text.
 */
function producer_briefing(PDO $pdo, ?string $date = null): array
{
    $date = $date ?: date('Y-m-d');
    $rows = $pdo->prepare(
        "SELECT pp.*, p.h1 FROM producer_pick pp
           LEFT JOIN pages p ON p.id = pp.page_id
          WHERE pp.run_date = ? ORDER BY pp.verdict DESC, pp.rank_i ASC, pp.score DESC");
    $rows->execute([$date]);
    $all = $rows->fetchAll(PDO::FETCH_ASSOC);
    if (!$all) {
        return ['intro' => "I have not held a meeting today yet.",
                'picks' => [], 'passed' => [], 'worries' => []];
    }

    $picked = array_values(array_filter($all, fn($r) => ($r['verdict'] ?? 'picked') !== 'passed'));
    $passed = array_values(array_filter($all, fn($r) => ($r['verdict'] ?? '') === 'passed'));

    $lanes = [];
    foreach ($picked as $r) { $lanes[$r['lane']] = ($lanes[$r['lane']] ?? 0) + 1; }
    $laneTalk = [];
    foreach ($lanes as $l => $n) { $laneTalk[] = "$n from {$l}"; }

    $intro = sprintf(
        "Today I looked at %d topic%s and gave the green light to %d of them — %s. "
      . "I judge every candidate on two questions at once: do people actually want "
      . "to watch this, and can we make it well with what we have. I multiply the two, "
      . "so being easy to make never rescues a boring story, and being popular never "
      . "rescues a story I have nothing to show for.",
        count($all), count($all) === 1 ? '' : 's', count($picked),
        $laneTalk ? implode(', ', $laneTalk) : 'nothing');

    $picksTalk = [];
    foreach ($picked as $r) {
        $sig = json_decode((string)$r['signals_json'], true) ?: [];
        uasort($sig, fn($a, $b) => ($b['v'] ?? 0) <=> ($a['v'] ?? 0));
        $strong = array_slice($sig, 0, 2, true);
        $lines = [];
        foreach ($strong as $k => $sv) {
            $lines[] = producer_signal_words((string)$k) . ' — ' . ($sv['why'] ?? '');
        }
        $weakK = array_key_last($sig);
        $d = (int)round(((float)$r['demand']) * 100);
        $q = (int)round(((float)$r['quality']) * 100);
        $verdict = $d >= $q
            ? "People should want this more than I can currently show it"
            : "I can show this well; the pull is the weaker half";
        $picksTalk[] = [
            'title' => (string)($r['h1'] ?: ('page ' . $r['page_id'])),
            'lane'  => (string)$r['lane'],
            'rank'  => (int)$r['rank_i'],
            'say'   => sprintf(
                "I picked this because %s. %s (want it: %d%%, can make it: %d%%). "
              . "The weakest part is %s — if that stays weak, tell me and I will stop "
              . "trusting that signal so much.",
                implode('; and ', $lines), $verdict, $d, $q,
                (string)($sig[$weakK]['why'] ?? 'unclear')),
            'assigned' => (int)$r['assigned'] === 1,
        ];
    }

    $passTalk = [];
    foreach (array_slice($passed, 0, 8) as $r) {
        $passTalk[] = [
            'title' => (string)($r['h1'] ?: ('page ' . $r['page_id'])),
            'lane'  => (string)$r['lane'],
            'say'   => (string)($r['pass_reason'] ?: 'it simply scored lower than the winners.'),
        ];
    }

    // What he is honestly unsure about — never hide the thin parts.
    $worries = [];
    if (count($all) < 12) {
        $worries[] = sprintf(
            "I only had %d topics to choose between. That is a thin menu — my picks are "
          . "as good as the pages the site gives me, so more published pages means "
          . "sharper choices.", count($all));
    }
    if (count($picked) < PRODUCER_SLOTS) {
        $worries[] = sprintf(
            "I left %d of my %d chairs empty on purpose. Everything else I looked at was "
          . "too weak to be worth a render, and I would rather make fewer good videos "
          . "than fill a quota with filler.",
            PRODUCER_SLOTS - count($picked), PRODUCER_SLOTS);
    }
    $measured = 0; $hits = 0;
    foreach ($pdo->query("SELECT outcome_json FROM producer_pick
                           WHERE outcome_json IS NOT NULL ORDER BY id DESC LIMIT 40") as $o) {
        $j = json_decode((string)$o['outcome_json'], true);
        if (!is_array($j)) { continue; }
        $measured++; if (!empty($j['hit'])) { $hits++; }
    }
    $worries[] = $measured < 3
        ? "You should not trust me yet. Only {$measured} of my picks have real view "
        . "numbers back, so I have not proven anything — judge me in a week, on whether "
        . "my picks beat our own averages."
        : sprintf("So far %d of my last %d measured picks beat our own average on the "
        . "platform they ran on. That is my real record — everything else I say is "
        . "just reasoning.", $hits, $measured);

    return ['intro' => $intro, 'picks' => $picksTalk,
            'passed' => $passTalk, 'worries' => $worries];
}

/**
 * The emotion he judged a page to carry, for anyone downstream who wants to
 * speak to it (captions, closing lines). Latest verdict wins; [] when he has
 * never weighed this page.
 */
function producer_emotion_for_page(PDO $pdo, int $pageId): array
{
    try {
        $st = $pdo->prepare("SELECT signals_json FROM producer_pick
                              WHERE page_id=? ORDER BY run_date DESC, id DESC LIMIT 1");
        $st->execute([$pageId]);
        $sig = json_decode((string)$st->fetchColumn(), true);
        $a = $sig['arousal'] ?? null;
        if (!is_array($a)) { return []; }
        // "feeling: anger (via ai)" -> anger
        if (preg_match('/feeling:\s*([a-z]+)/i', (string)($a['why'] ?? ''), $m)) {
            return ['emotion' => strtolower($m[1]), 'score' => (float)($a['v'] ?? 0)];
        }
        return [];
    } catch (Throwable $e) { return []; }
}
