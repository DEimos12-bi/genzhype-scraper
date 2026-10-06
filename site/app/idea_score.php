<?php
// GenZHype | ONE TREND SCORE FOR EVERY IDEA (owner 2026-10-01, step 1 of 4): "The trend check moves to the front door:
// every idea the scraper finds (drama, gaming, memes, slang) gets a score 0-100 before the picker decides. Parts:
// rising (use now vs its own normal level), spread (how many different creators and platforms), reach (views/likes on
// the posts), new (started recently), open field (big outlets have not covered it yet). The score and its parts are
// shown per idea in admin."
//
//   is_score()        PURE: the measured facts in, the score and its five parts out. Used live, by the sample and the tests.
//   is_story_facts()  the facts of a story idea: one news search (no AI), the posts our listener heard, the seed's date
//   is_term_facts()   the facts of a meme or term idea: our day-by-day counts (trend.php), the posts its naming page links
//   is_run()          the front door: the ideas with no score yet, a bounded number per run
//
// A part that could not be measured counts as 0 and is shown as "not measured": an idea needs evidence to score high.
// STEP 1 ONLY MEASURES AND SHOWS. Nothing reads the score to decide anything (that is step 2, after the owner sees it).
// On only with the file app/SCORE_ON. [OURS] = our own starting number, to be corrected by step 4 (learning).

require_once __DIR__ . '/db.php';

const IS_WEIGHTS = ['rising' => 30, 'spread' => 25, 'reach' => 15, 'new' => 15, 'open' => 15];   // [OURS] sum 100; step 4 proposes better ones
const IS_LIKE_AS_VIEWS = 25;     // [OURS] one like counts as 25 views when a platform gives likes only
const IS_PER_RUN = 25;           // [OURS] ideas scored per hourly run (one news search each)
// the big outlets: when they already cover an idea, the field is no longer open [OURS list]
const IS_BIG_OUTLETS = ['ign.com', 'kotaku.com', 'polygon.com', 'pcgamer.com', 'gamespot.com', 'eurogamer.net', 'theverge.com', 'dexerto.com',
    'gamesradar.com', 'videogameschronicle.com', 'rockpapershotgun.com', 'engadget.com', 'forbes.com', 'bbc.com', 'bbc.co.uk', 'nytimes.com',
    'cnn.com', 'variety.com', 'hollywoodreporter.com', 'deadline.com', 'tmz.com', 'people.com', 'rollingstone.com', 'billboard.com',
    'buzzfeed.com', 'businessinsider.com', 'dailydot.com', 'knowyourmeme.com', 'nbcnews.com', 'theguardian.com', 'washingtonpost.com',
    'usatoday.com', 'complex.com', 'ew.com', 'pagesix.com', 'dailymail.co.uk', 'independent.co.uk', 'mashable.com', 'vice.com', 'wired.com',
    'techcrunch.com', 'nypost.com', 'foxnews.com', 'yahoo.com', 'msn.com', 'eonline.com', 'usmagazine.com', 'gamerant.com', 'thegamer.com'];

function is_on(): bool { return is_file(__DIR__ . '/SCORE_ON'); }

/** The weights in use: app/score_weights.json when the owner approved a change (step 4, cli.php score weights set), else IS_WEIGHTS. */
function is_weights(): array {
    $j = is_file(__DIR__ . '/score_weights.json') ? json_decode((string)file_get_contents(__DIR__ . '/score_weights.json'), true) : null;
    if (!is_array($j)) return IS_WEIGHTS;
    $w = [];
    foreach (IS_WEIGHTS as $k => $d) $w[$k] = max(0, (int)($j[$k] ?? $d));
    return array_sum($w) > 0 ? $w : IS_WEIGHTS;
}

// STEP 2 (owner 2026-10-01): THE PICKER USES THE SCORE. On only with app/FASTLANE_ON.
//   high score: front of the queue, the posts about it count as proof, no waiting (re-read in 6h, kept a week, never dropped
//               for want of a second outlet), a shorter page may be offered to Google
//   it relaxes PAPERWORK ONLY (number of sources, length, waiting time). It NEVER relaxes the fact check, the framing of
//   claims about real people, the Human check or the 72-hour age rule (a rule test holds the line).
//   low or middle score: today's rules, nothing changes
const IS_HIGH = 50;   // [OURS] today's top band (1 of 47 stories reaches 65 while reach is seldom measured); step 4 proposes the real line
function is_fast_on(): bool { return is_file(__DIR__ . '/FASTLANE_ON'); }
/** Pure: the lane a score puts an idea in. */
function is_band(?int $score): string { return $score !== null && $score >= IS_HIGH ? 'high' : 'normal'; }
/** The lane of a queued idea: 'high' only with the switch on and a stored score at or above the line. */
function is_lane(PDO $pdo, int $candId): string {
    if (!is_fast_on()) return 'normal';
    try { $s = $pdo->query("SELECT score FROM idea_scores WHERE cand_id=" . $candId)->fetchColumn(); } catch (Throwable $e) { $s = false; }
    return is_band($s === false ? null : (int)$s);
}

/** idea_scores, created once and only when missing (DDL commits an open transaction, r151). */
function is_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    if (!$pdo->query("SHOW TABLES LIKE 'idea_scores'")->fetchColumn())
        $pdo->exec("CREATE TABLE IF NOT EXISTS idea_scores (
            cand_id INT UNSIGNED NOT NULL PRIMARY KEY,
            score TINYINT UNSIGNED NOT NULL,
            parts VARCHAR(255) NOT NULL COMMENT 'JSON: rising, spread, reach, new, open (0-100, null = not measured)',
            facts TEXT NULL COMMENT 'JSON: the numbers the parts were made from, and the plain-word reasons',
            scored_at DATETIME NOT NULL,
            KEY idx_scored (scored_at), KEY idx_score (score)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * THE SCORE (pure). $f:
 *   recent, base      uses in the newest window and the average of the same-length windows before it (null = not measured)
 *   creators, platforms   different accounts or outlets, different platforms
 *   views, likes      the best reach among its posts (null = no platform gave a count)
 *   age_hours         hours since the earliest dated item about it (null = no date)
 *   big_outlets       big outlets already covering it (null = not searched)
 * ['score' => 0..100, 'parts' => [name => 0..100|null], 'why' => [name => plain words]]
 */
function is_score(array $f): array {
    $parts = []; $why = [];
    // RISING: use now against its own normal level
    $r = $f['recent'] ?? null; $b = $f['base'] ?? null;
    if ($r === null) { $parts['rising'] = null; $why['rising'] = 'not measured'; }
    else {
        $r = (float)$r; $b = (float)($b ?? 0);
        // 2026-10-04 sample: one lone post with nothing before it scored 40 here and 46 overall, the same as stories 5 outlets covered
        if ($b <= 0) $p = $r >= 3 ? 100 : ($r >= 2 ? 70 : ($r >= 1 ? 10 : 0));
        else { $x = $r / $b; $p = $x >= 3 ? 100 : ($x >= 2 ? 75 : ($x >= 1.5 ? 55 : ($x >= 1 ? 35 : ($x >= 0.5 ? 15 : 0)))); if ($r < 2) $p = min($p, 40); }
        $parts['rising'] = $p;
        $why['rising'] = $b <= 0 ? ($r > 0 ? rtrim(rtrim(number_format($r, 1), '0'), '.') . ' now, nothing before' : 'nothing now')
                                 : rtrim(rtrim(number_format($r, 1), '0'), '.') . ' now against ' . rtrim(rtrim(number_format($b, 1), '0'), '.') . ' normally (' . number_format($r / $b, 1) . 'x)';
    }
    // SPREAD: how many different creators or outlets, on how many platforms
    $c = (int)($f['creators'] ?? 0); $pl = (int)($f['platforms'] ?? 0);
    $p = $c >= 10 ? 80 : ($c >= 6 ? 70 : ($c >= 4 ? 60 : ($c >= 3 ? 50 : ($c >= 2 ? 35 : ($c >= 1 ? 15 : 0)))));
    $p = min(100, $p + ($pl >= 3 ? 20 : ($pl >= 2 ? 15 : 0)));
    $parts['spread'] = $p; $why['spread'] = "{$c} creator(s) or outlet(s) on {$pl} platform(s)";
    // REACH: views (or likes) on its posts
    $v = $f['views'] ?? null; $l = $f['likes'] ?? null;
    if ($v === null && $l === null) { $parts['reach'] = null; $why['reach'] = 'not measured (no platform gave a count)'; }
    else {
        $n = max((int)$v, (int)$l * IS_LIKE_AS_VIEWS);
        $parts['reach'] = $n >= 5000000 ? 100 : ($n >= 1000000 ? 90 : ($n >= 100000 ? 75 : ($n >= 10000 ? 50 : ($n >= 1000 ? 25 : 5))));
        $why['reach'] = $v !== null && (int)$v >= (int)$l * IS_LIKE_AS_VIEWS ? number_format((int)$v) . ' views on its top post' : number_format((int)$l) . ' likes on its top post';
    }
    // NEW: started recently
    $h = $f['age_hours'] ?? null;
    if ($h === null) { $parts['new'] = null; $why['new'] = 'not measured (nothing about it is dated)'; }
    else {
        $h = max(0.0, (float)$h);
        $parts['new'] = $h <= 24 ? 100 : ($h <= 48 ? 85 : ($h <= 72 ? 70 : ($h <= 168 ? 35 : ($h <= 336 ? 15 : 0))));
        $why['new'] = $h < 48 ? 'started ' . (int)round($h) . ' hours ago' : 'started ' . (int)round($h / 24) . ' days ago';
    }
    // OPEN FIELD: the big outlets have not covered it yet
    $big = $f['big_outlets'] ?? null;
    if ($big === null) { $parts['open'] = null; $why['open'] = 'not measured (no search)'; }
    else {
        $big = (int)$big;
        // an open field needs people in it: it counts in full only when 3 or more creators or outlets talk about the idea
        // (2026-10-04 sample: a post nobody else picked up got 100 here for "no big outlet covers it")
        $crowd = min(1.0, $c / 3);
        $parts['open'] = (int)round(($big <= 0 ? 100 : ($big === 1 ? 60 : ($big === 2 ? 30 : 0))) * $crowd);
        $why['open'] = ($big <= 0 ? 'no big outlet covers it yet' : "{$big} big outlet(s) already cover it" . (!empty($f['big_names']) ? ' (' . implode(', ', array_slice((array)$f['big_names'], 0, 3)) . ')' : ''))
                     . ($c < 3 ? "; only {$c} creator(s) or outlet(s) talk about it" : '');
    }
    $sum = 0; $weights = $f['weights'] ?? is_weights();   // a test may pass its own
    foreach ($weights as $k => $w) $sum += $w * (int)($parts[$k] ?? 0);
    return ['score' => (int)round($sum / max(1, array_sum($weights))), 'parts' => $parts, 'why' => $why];
}

/** Likes or views of one post, as far as the platform serves them without an account (X: likes; YouTube: views). */
function is_post_reach(string $url): ?array {
    if (preg_match('#(?:x|twitter)\.com/[^/]+/status/(\d{10,20})#i', $url, $m)) {
        require_once __DIR__ . '/post_cards.php';
        $t = pc_syndication($m[1]);
        return $t ? ['views' => null, 'likes' => (int)($t['likes'] ?? 0)] : null;
    }
    if (preg_match('#(?:youtube\.com/(?:watch\?v=|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m) && ($key = (string)($GLOBALS['CONFIG']['youtube_key'] ?? '')) !== '') {
        $j = json_decode((string)@file_get_contents('https://www.googleapis.com/youtube/v3/videos?part=statistics&id=' . $m[1] . '&key=' . urlencode($key), false,
                          stream_context_create(['http' => ['timeout' => 8]])), true);
        $s = $j['items'][0]['statistics'] ?? null;
        return $s ? ['views' => (int)($s['viewCount'] ?? 0), 'likes' => isset($s['likeCount']) ? (int)$s['likeCount'] : null] : null;
    }
    if (preg_match('#reddit\.com(/r/[^/]+/comments/[a-z0-9]+)#i', $url, $m)) {   // the upvotes our listener read for that post (the latest drop only)
        static $ups = null;
        if ($ups === null) {
            $ups = [];
            $j = json_decode((string)@file_get_contents(__DIR__ . '/reach_cache.json'), true) ?: [];
            foreach ((array)($j['scout_posts'] ?? []) as $p) if (($p['platform'] ?? '') === 'reddit' && preg_match('#^(/r/[^/]+/comments/[a-z0-9]+)#i', (string)($p['permalink'] ?? ''), $x)) $ups[strtolower($x[1])] = (int)($p['ups'] ?? 0);
        }
        return isset($ups[strtolower($m[1])]) ? ['views' => null, 'likes' => $ups[strtolower($m[1])]] : null;
    }
    return null;
}

/**
 * Pure: is a found headline about this idea? Yes when it carries
 *   - one of the idea's RARE words (a name hardly any other idea uses: "Urker", "Soccerina") and one more of its words, or
 *   - 60% of the idea's search words (the editor's query when there is one, else the headline), 2 at least.
 * 2026-10-04 sample: matching on the seed's own headline alone missed all 10 articles about Ken Urker ("Prayers Up!
 * Gypsy Rose Blanchard's Fiancé ...") and half of the stories scored on their seed alone.
 */
function is_about(string $idea, string $headline, string $query = '', array $rare = []): bool {
    require_once __DIR__ . '/story_picker.php';
    $h = array_flip(sp_title_words($headline));
    $q = sp_title_words($query !== '' ? $query : $idea);
    if (!$q) return false;
    $hit = count(array_filter($q, fn($w) => isset($h[$w])));
    if ($hit >= 2 && $hit / count($q) >= 0.6) return true;
    $all = array_unique(array_merge($q, sp_title_words($idea)));
    $hitAll = count(array_filter($all, fn($w) => isset($h[$w])));
    foreach ($rare as $w) if (isset($h[$w]) && $hitAll >= 2) return true;
    return false;
}

/**
 * The idea's rare words: in its name or search words, 4+ letters, and used by at most 3 of the ideas of the last 30 days
 * (its own copies included). "GTA", "streamer", "leak" are in hundreds of ideas; "Urker" is in one story's.
 */
function is_rare_words(PDO $pdo, string $text): array {
    static $df = null;
    require_once __DIR__ . '/story_picker.php';
    if ($df === null) {
        $df = [];
        foreach ($pdo->query("SELECT name FROM candidates WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY")->fetchAll(PDO::FETCH_COLUMN) as $nm)
            foreach (sp_title_words((string)$nm) as $w) $df[$w] = ($df[$w] ?? 0) + 1;
    }
    return array_values(array_filter(sp_title_words($text), fn($w) => mb_strlen($w) >= 4 && !ctype_digit($w) && ($df[$w] ?? 0) <= 3));
}

/** Pure: the facts is_score() needs from a list of dated items about the idea. $items: [['url','date','kind'], ...] */
function is_facts_from_items(array $items, string $now, int $windowHours = 24, int $baseWindows = 3): array {
    require_once __DIR__ . '/story_picker.php';
    $t = strtotime($now . ' UTC'); $win = $windowHours * 3600;
    $recent = 0; $before = 0; $dated = 0; $oldest = null; $creators = []; $platforms = []; $big = [];
    foreach ($items as $it) {
        $url = (string)($it['url'] ?? ''); $kind = (string)($it['kind'] ?? sp_kind($url)); $site = sp_site(sp_host($url));
        if ($kind === 'outlet' || $kind === 'copy') { $creators['site:' . $site] = 1; $platforms['news'] = 1; }
        elseif ($kind === 'post') { $creators['post:' . ($it['author'] ?? $url)] = 1; $platforms[$site] = 1; }
        if (in_array($site, IS_BIG_OUTLETS, true)) $big[$site] = 1;
        $d = (string)($it['date'] ?? '');
        if ($d === '' || ($ts = strtotime($d . (strlen($d) <= 10 ? ' 12:00:00' : '') . ' UTC')) === false || $ts > $t + 86400) continue;
        $dated++; $age = $t - $ts;
        if ($oldest === null || $age > $oldest) $oldest = $age;
        if ($age <= $win) $recent++; elseif ($age <= $win * (1 + $baseWindows)) $before++;
    }
    return ['recent' => $dated ? $recent : null, 'base' => $dated ? $before / $baseWindows : null, 'creators' => count($creators), 'platforms' => count($platforms),
            'age_hours' => $oldest === null ? null : round($oldest / 3600, 1), 'big_outlets' => count($big), 'big_names' => array_keys($big)];
}

/** A story idea: the seed, the posts the desk heard, and one news search; a found headline counts only when it is about the idea. */
function is_story_facts(PDO $pdo, array $cand, string $now = ''): array {
    require_once __DIR__ . '/story_picker.php';
    $now = $now !== '' ? $now : gmdate('Y-m-d H:i:s');
    $items = [];
    $query = sp_query($cand); $rare = is_rare_words($pdo, (string)$cand['name'] . ' ' . $query);
    foreach (sp_gather($pdo, $cand, false) as $it) {
        if (($it['from'] ?? '') === 'search' && !is_about((string)$cand['name'], (string)$it['title'], $query, $rare)) continue;
        $items[] = $it;
    }
    $f = is_facts_from_items($items, $now) + ['views' => null, 'likes' => null, 'items' => count($items)];
    $n = 0;
    foreach ($items as $it) {
        if (($it['kind'] ?? '') !== 'post' || $n >= 3) continue;
        $n++;
        if ($r = is_post_reach((string)$it['url'])) { if ($r['views'] !== null) $f['views'] = max((int)$f['views'], $r['views']); if ($r['likes'] !== null) $f['likes'] = max((int)$f['likes'], $r['likes']); }
    }
    return $f;
}

/** A meme or term idea: our own day-by-day counts, the posts its naming page links, and one news search for the big outlets. */
function is_term_facts(PDO $pdo, array $cand, string $now = ''): array {
    require_once __DIR__ . '/trend.php';
    require_once __DIR__ . '/story_picker.php';
    $now = $now !== '' ? $now : gmdate('Y-m-d H:i:s');
    $name = (string)$cand['name'];
    $sg = json_decode((string)($cand['signals'] ?? ''), true) ?: [];
    $m = tr_measure($pdo, mb_strtolower($name), $now);
    $f = ['recent' => null, 'base' => null, 'creators' => (int)($m['recent_authors'] ?? 0), 'platforms' => count((array)($m['platforms'] ?? [])),
          'views' => ($m['recent_reach'] ?? 0) > 0 ? (int)$m['recent_reach'] : null, 'likes' => null, 'age_hours' => null, 'big_outlets' => null, 'big_names' => []];
    if ((int)($m['history_days'] ?? 0) >= TR_MIN_HISTORY_DAYS) { $f['recent'] = (int)$m['recent_posts']; $f['base'] = (float)$m['baseline_3d']; }
    $heard = (string)($m['first_heard'] ?? ''); $start = (string)($m['listener_start'] ?? '');
    if ($heard !== '' && ($start === '' || strtotime($heard) > strtotime($start) + 2 * 86400)) $f['age_hours'] = round((strtotime($now . ' UTC') - strtotime($heard . ' UTC')) / 3600, 1);
    // a meme a page named (KnowYourMeme, an outlet): the posts that page links, each with its own date
    $url = (string)($sg['url'] ?? '');
    $items = [];
    if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) && sp_kind($url) !== 'post') {
        $items[] = ['url' => $url, 'date' => sp_date((string)($cand['item_date'] ?? '')), 'kind' => sp_kind($url) === 'reference' ? 'outlet' : sp_kind($url)];
        try { $sp = tr_source_posts([$url], 12); } catch (Throwable $e) { $sp = ['posts' => []]; }
        foreach ((array)($sp['posts'] ?? []) as $p) {
            $items[] = ['url' => (string)$p['url'], 'date' => (string)($p['date'] ?? ''), 'kind' => 'post', 'author' => (string)($p['handle'] ?? $p['url'])];
            if (!empty($p['views'])) $f['views'] = max((int)$f['views'], (int)$p['views']);
        }
    }
    foreach (sp_news_items($name . (preg_match('/\b(meme|trend)\b/i', $name) ? '' : ' meme'), 8) as $r)
        if (is_about($name, (string)$r['title'])) $items[] = ['url' => $r['url'], 'date' => $r['date'], 'kind' => sp_kind($r['url'])];
    if ($items) {
        $g = is_facts_from_items($items, $now, 72, 3);   // a meme's posts: the last 3 days against the 9 before
        if ($f['recent'] === null && count($items) > 1) { $f['recent'] = $g['recent']; $f['base'] = $g['base']; }
        $f['creators'] = max($f['creators'], $g['creators']); $f['platforms'] = max($f['platforms'], $g['platforms']);
        if ($f['age_hours'] === null) $f['age_hours'] = $g['age_hours'];
        $f['big_outlets'] = $g['big_outlets']; $f['big_names'] = $g['big_names'];
    } else $f['big_outlets'] = 0;
    return $f + ['items' => count($items)];
}

/** One idea scored and saved. $cand: its candidates row. */
function is_score_candidate(PDO $pdo, array $cand, bool $save = true): array {
    is_install($pdo);
    $story = (string)$cand['type'] === 'drama';
    $f = $story ? is_story_facts($pdo, $cand) : is_term_facts($pdo, $cand);
    $s = is_score($f);
    if ($save) $pdo->prepare("REPLACE INTO idea_scores (cand_id, score, parts, facts, scored_at) VALUES (?,?,?,?,UTC_TIMESTAMP())")
        ->execute([(int)$cand['id'], $s['score'], json_encode($s['parts']), json_encode(['facts' => $f, 'why' => $s['why']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    return $s + ['facts' => $f];
}

/** One line a person can read: "62: rising 100, spread 50, reach not measured, new 100, open field 60". */
function is_line(int $score, array $parts): string {
    $names = ['rising' => 'rising', 'spread' => 'spread', 'reach' => 'reach', 'new' => 'new', 'open' => 'open field'];
    $out = [];
    foreach ($names as $k => $label) $out[] = $label . ' ' . (($parts[$k] ?? null) === null ? 'not measured' : (int)$parts[$k]);
    return $score . ': ' . implode(', ', $out);
}

/** THE FRONT DOOR: the newest ideas with no score yet, at most IS_PER_RUN a run and never past $maxSecs. ['scored' => n, 'line'] */
function is_run(PDO $pdo, int $maxSecs = 120): array {
    if (!is_on()) return ['scored' => 0, 'line' => ''];
    is_install($pdo);
    $t0 = time();
    $rows = $pdo->query("SELECT c.* FROM candidates c LEFT JOIN idea_scores s ON s.cand_id=c.id
                         WHERE s.cand_id IS NULL AND c.status IN ('new','selected','watch') AND c.created_at >= UTC_TIMESTAMP() - INTERVAL 3 DAY
                         ORDER BY (c.status='selected') DESC, c.id DESC LIMIT " . IS_PER_RUN)->fetchAll(PDO::FETCH_ASSOC);
    $n = 0; $top = [];
    foreach ($rows as $c) {
        if (time() - $t0 > $maxSecs) break;
        try { $s = is_score_candidate($pdo, $c); } catch (Throwable $e) { continue; }
        $pdo = db_alive();
        $n++; $top[] = [$s['score'], mb_substr((string)$c['name'], 0, 50)];
    }
    rsort($top);
    return ['scored' => $n, 'line' => $n ? "trend score: {$n} idea(s) scored" . ($top ? '; highest ' . $top[0][0] . ' "' . $top[0][1] . '"' : '') : ''];
}

/**
 * The score block of one idea for the admin lists: the number, its five parts and, in plain words, what each was made
 * from. '' when the idea has no score yet. $rows: idea_scores rows by candidate id (is_admin_rows).
 */
function is_admin_html(array $rows, int $candId): string {
    $r = $rows[$candId] ?? null;
    if (!$r) return '<div class="meta" style="color:#888">Trend score: not scored yet</div>';
    $parts = json_decode((string)$r['parts'], true) ?: []; $why = (json_decode((string)$r['facts'], true) ?: [])['why'] ?? [];
    $names = ['rising' => 'Rising', 'spread' => 'Spread', 'reach' => 'Reach', 'new' => 'New', 'open' => 'Open field'];
    $bits = [];
    foreach ($names as $k => $label)
        $bits[] = '<span title="' . htmlspecialchars((string)($why[$k] ?? ''), ENT_QUOTES) . '">' . $label . ' <b>' . (($parts[$k] ?? null) === null ? '?' : (int)$parts[$k]) . '</b></span>';
    $expl = [];
    foreach ($names as $k => $label) if (!empty($why[$k])) $expl[] = strtolower($label) . ': ' . $why[$k];
    $sc = (int)$r['score']; $col = $sc >= 60 ? '#15803d' : ($sc >= 40 ? '#b45309' : '#6b7280');
    return '<div class="meta" style="margin-top:3px"><b style="color:' . $col . '">Trend score ' . $sc . '</b> &nbsp;' . implode(' · ', $bits)
         . '<br><span style="color:#888">' . htmlspecialchars(implode('; ', $expl), ENT_QUOTES) . '</span></div>';
}

/** The stored scores of a list of candidate ids, by id. */
function is_admin_rows(PDO $pdo, array $candIds): array {
    $ids = array_values(array_filter(array_map('intval', $candIds)));
    if (!$ids) return [];
    try { $rows = $pdo->query("SELECT cand_id, score, parts, facts FROM idea_scores WHERE cand_id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return []; }
    return array_column($rows, null, 'cand_id');
}
