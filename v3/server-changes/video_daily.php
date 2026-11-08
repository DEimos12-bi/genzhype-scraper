<?php
/**
 * GenZHype | THE DAILY VIDEO LIST (2026-11-01, owner's brief)
 * =============================================================================
 * "The videos are made from the pages we publish that day. When the day's pages
 *  are finished, the trend data we built chooses the best 8 (up to 10), no two
 *  on the same topic, and the video maker makes them."
 *
 * This endpoint answers the video maker (v3/live.py on GitHub): the pages
 * published on one UTC day (default: yesterday, the last finished day), ranked
 * by THE PRODUCER's own greenlight equation (app/producer.php: score = DEMAND x
 * QUALITY — trend velocity in our rival feed, rival outliers, arousal, lane
 * yield x visual supply, exclusivity, freshness), same-topic pages dropped
 * (shared person, or two shared topic words), top N returned.
 *
 * READ-ONLY on the database. The ranking of a day is computed ONCE and kept in
 * app/cache/video_daily-<day>.json, so every run of that day sees the same list
 * (and the one AI call the arousal reading makes happens once a day).
 *
 * GET token=<ingest_token> [day=YYYY-MM-DD] [n=10] [fresh=1]
 *   -> {"ok":true,"day":"...","picks":[{page_id,slug,link,title,lane,score,demand,quality,reason}],
 *       "same_topic":[...],"not_videoable":[...],"generated":"..."}
 */
declare(strict_types=1);
$APP = dirname(__DIR__, 2) . '/app';
$GLOBALS['CONFIG'] = require $APP . '/config.php';
require_once $APP . '/helpers.php';
require_once $APP . '/db.php';
if (is_file($APP . '/ai.php')) { require_once $APP . '/ai.php'; }   // the arousal reading; without it producer.php falls back to VADER
require_once $APP . '/producer.php';
if (PHP_SAPI !== 'cli') { header('Content-Type: application/json'); }
// The first call of a day ranks the pages (one AI reading of their feeling, up to a few minutes): it finishes and
// keeps the list even when the caller stops waiting, so the next call answers at once from the kept list.
ignore_user_abort(true);
@set_time_limit(600);

// BUILT ON THE SERVER, READ BY GITHUB (2026-11-01): over the web the site's CDN cuts a call at 60 s and the host then
// stops the script, so the first ranking of a day never finished. The server's own 30-minute bridge job builds it from
// the command line instead:  php public_html/api/video_daily.php --build [YYYY-MM-DD]   (default: yesterday, UTC)
// and every web call afterwards answers in a second from the kept list.
if (PHP_SAPI === 'cli') {
    $_GET = ['day' => (string)($argv[2] ?? ''), 'n' => '10'] + (in_array('--fresh', $argv, true) ? ['fresh' => '1'] : []);
    if (($argv[1] ?? '') !== '--build') { fwrite(STDERR, "usage: php video_daily.php --build [YYYY-MM-DD]
"); exit(2); }
} else {
    $tok = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    if (!hash_equals(($CONFIG['ingest_token'] ?? '') ?: random_bytes(32), $tok)) {
        http_response_code(403); echo json_encode(['error' => 'bad token']); exit;
    }
    // a day that is not kept yet is NOT ranked over the web (the CDN would cut it): the bridge job builds it
    $d0 = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['day'] ?? '')) ? (string)$_GET['day'] : gmdate('Y-m-d', time() - 86400);
    if (!is_file($APP . '/cache/video_daily-' . $d0 . '.json') && $d0 < gmdate('Y-m-d') && empty($_GET['fresh'])) {
        http_response_code(503); echo json_encode(['ok' => false, 'error' => 'the list of ' . $d0 . ' is being built on the server; ask again in a few minutes']); exit;
    }
}
$day = (string)($_GET['day'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { $day = gmdate('Y-m-d', time() - 86400); }
$n = max(1, min(20, (int)($_GET['n'] ?? 10)));

$cacheDir = getenv('VD_CACHE_DIR') ?: $APP . '/cache';          // a test run writes its list elsewhere, never over the live one
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }
$cache = $cacheDir . '/video_daily-' . $day . '.json';
if (empty($_GET['fresh']) && is_file($cache)) {
    $j = json_decode((string)file_get_contents($cache), true);
    if (is_array($j)) {
        $j['picks'] = array_slice((array)($j['ranked'] ?? []), 0, $n);
        unset($j['ranked']);
        echo json_encode($j + ['cached' => true], JSON_UNESCAPED_SLASHES); exit;
    }
}

$pdo = db();
$st = $pdo->prepare("SELECT id, slug, path, h1, type, published_at FROM pages
                      WHERE status='published' AND robots='index' AND cover<>''
                        AND published_at >= ? AND published_at < DATE_ADD(?, INTERVAL 1 DAY)");
$st->execute([$day . ' 00:00:00', $day . ' 00:00:00']);
$pages = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) { $pages[(int)$p['id']] = $p; }

// EVERY indexed page of the day competes. producer_intake() keeps the OLD maker's entry rules (a drama timeline
// needed >= 4 dated events, a term video >= 3): on 2026-10-31 that let 2 of 10 pages in. The new maker (v3) builds
// from the page, its posts and the clips it finds, so a page with few events is not excluded: it simply scores
// lower on QUALITY. The candidate fields are the ones producer_intake() builds, without its thresholds.
$cands = []; $notVideoable = [];
foreach ($pages as $id => $p) {
    $c = ['page_id' => $id, 'title' => (string)$p['h1'], 'slug' => (string)$p['slug'], 'follow_up' => false,
          'dated' => 0, 'sourced' => 0, 'newest' => '', 'clips' => 0, 'people' => [], 'lane' => 'drama'];
    $d = $pdo->prepare("SELECT d.id did, d.people_json, d.lane dlane,
                (SELECT COUNT(*) FROM events e WHERE e.drama_id=d.id AND e.event_date IS NOT NULL AND e.video_only=0) dated,
                (SELECT COUNT(*) FROM events e WHERE e.drama_id=d.id AND (e.source_id IS NOT NULL OR e.embed_html<>'')) sourced,
                (SELECT MAX(e.event_date) FROM events e WHERE e.drama_id=d.id) newest
              FROM dramas d WHERE d.page_id=? LIMIT 1");
    $d->execute([$id]);
    $t = $pdo->prepare("SELECT term, lane, citations, origin_date, short_def, why_trending, status_label FROM terms WHERE page_id=? LIMIT 1");
    $t->execute([$id]);
    if ($tr = $t->fetch(PDO::FETCH_ASSOC)) {                       // slang / meme / gaming term page
        $dated = 0;
        foreach ((array)json_decode((string)$tr['citations'], true) as $ct) { if (!empty($ct['date']) && strtotime((string)$ct['date'])) { $dated++; } }
        $od = trim((string)($tr['origin_date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $od)) { $dated++; } else { $od = ''; }
        $c = array_merge($c, ['lane' => (string)($tr['lane'] ?: 'term'), 'dated' => $dated, 'sourced' => $dated, 'newest' => $od,
              'term' => (string)$tr['term'], 'match' => (string)$tr['term'], 'status' => (string)($tr['status_label'] ?? ''),
              'brief' => trim((string)$tr['term'] . ' — ' . mb_substr(trim((string)$tr['short_def']), 0, 220) . ' ' . mb_substr(producer_first_str($tr['why_trending']), 0, 280))]);
    } elseif ($dr = $d->fetch(PDO::FETCH_ASSOC)) {                 // drama / news page
        // 'bucket' = the site section (drama or gaming news) for the daily mix; 'lane' stays "drama" for the scoring
        $c = array_merge($c, ['bucket' => ((string)($dr['dlane'] ?? '') === 'gaming') ? 'gaming' : 'drama',
              'dated' => (int)$dr['dated'], 'sourced' => (int)$dr['sourced'], 'newest' => (string)($dr['newest'] ?? ''),
              'people' => (array)(json_decode((string)($dr['people_json'] ?? '[]'), true) ?: [])]);
    } else {
        $notVideoable[] = ['page_id' => $id, 'title' => (string)$p['h1'], 'why' => 'neither a story nor a term page'];
        continue;
    }
    $cands[] = $c;
}

$ranked = []; $same = []; $skipped = [];   // skipped: a page whose scoring broke is left out and named, the list still builds (2026-11-06)
if ($cands) {
    $w = producer_weights($pdo);
    $yield = vd_lane_yield($pdo);
    $arousal = producer_arousal($pdo, array_slice($cands, 0, 30));
    // STRICTER DEMAND (owner, 2026-11-01, "deploy then improve", fix 1). The producer counts demand word by word, so one
    // generic word was enough: "a rival video on 'home' pulled 3.2M views". Here a rival video or a feed item counts for
    // a page only when it shares TWO of the page's topic words (a slang/meme page: its term itself). The producer's own
    // score is then computed on those items alone, so its demand, its reasons and its numbers mean the same story.
    $cut = time() - 48 * 3600;
    $feed = [];
    foreach ($pdo->query("SELECT name, created_at FROM candidates WHERE created_at > DATE_SUB(NOW(), INTERVAL 14 DAY)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $feed[] = ['kw' => producer_keywords((string)$r['name'], 8), 'recent' => strtotime((string)$r['created_at']) >= $cut];
    }
    $rival = [];
    try {
        foreach ($pdo->query("SELECT title, views FROM vid_rival_post WHERE published_at > DATE_SUB(NOW(), INTERVAL 60 DAY) ORDER BY views DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rival[] = ['kw' => producer_keywords((string)$r['title'], 6), 'views' => (int)$r['views']];
        }
    } catch (Throwable $e) { /* engine table absent: no appetite signal */ }
    $scored = [];
    foreach ($cands as $c) { try {
        $kw = producer_keywords((string)($c['match'] ?? $c['title']), 6);
        $termKw = ($c['lane'] !== 'drama' && !empty($c['term'])) ? producer_keywords((string)$c['term'], 4) : [];
        $sameTopic = function (array $itemKw) use ($kw, $termKw): bool {
            if ($termKw) { return count(array_intersect($termKw, $itemKw)) === count($termKw); }
            return count(array_intersect($kw, $itemKw)) >= 2;
        };
        $trend = ['recent' => [], 'base' => [], 'sample' => count($feed)];
        foreach ($feed as $f) {
            if (!$sameTopic($f['kw'])) { continue; }
            foreach (array_intersect($kw, $f['kw']) as $k) {
                if ($f['recent']) { $trend['recent'][$k] = ($trend['recent'][$k] ?? 0) + 1; } else { $trend['base'][$k] = ($trend['base'][$k] ?? 0) + 1; }
            }
        }
        $outliers = [];
        foreach ($rival as $rv) {
            if (!$sameTopic($rv['kw'])) { continue; }
            foreach (array_intersect($kw, $rv['kw']) as $k) { $outliers[$k] = max($outliers[$k] ?? 0, $rv['views']); }
        }
        $s = producer_score($c, $w, $trend, $outliers, $yield, $arousal);
        // THE PAGE'S OWN TREND STATUS (2026-11-05): our best video ever (27k views on TikTok) was a meme page marked
        // "peaking", posted while people were searching for it. A meme or slang page at its peak goes up the list; one
        // that is fading, or already cringe, goes down. Evergreen ("mainstream") pages are unchanged.
        $boost = ['peaking' => 1.25, 'emerging' => 1.10, 'fading' => 0.85, 'cringe' => 0.70][strtolower((string)($c['status'] ?? ''))] ?? 1.0;
        if ($boost != 1.0) { $s['score'] = round($s['score'] * $boost, 4); $s['status_boost'] = $boost; }
        $scored[] = ['c' => $c, 's' => $s];
    } catch (Throwable $e) { $skipped[] = ['page_id' => (int)($c['page_id'] ?? 0), 'error' => substr($e->getMessage(), 0, 200) . ' @' . $e->getLine()]; } }
    usort($scored, fn($a, $b) => $b['s']['score'] <=> $a['s']['score']);
    // ONE TOPIC, ONE VIDEO: a shared person, or two shared topic words, is the same story
    $taken = [];
    foreach ($scored as $row) { try {
        $c = $row['c']; $s = $row['s'];
        $kw = producer_keywords((string)($c['match'] ?? $c['title']), 6);
        $people = array_map(fn($x) => mb_strtolower(trim(is_array($x) ? (string)($x['name'] ?? '') : (string)$x)), (array)($c['people'] ?? []));
        $people = array_values(array_filter($people, fn($x) => mb_strlen($x) >= 3));
        $tw = vd_title_words((string)$c['title']);
        $twin = null;
        foreach ($taken as $t) {
            // + the TITLES (2026-11-08): "Sony Confirms PS6 Development on Schedule" and "PS6 Development Progresses as
            // Scheduled" both went out the same night: the topic words missed "ps6" and "schedule(d)"
            if (array_intersect($people, $t['people']) || count(array_intersect($kw, $t['kw'])) >= 2
                || vd_same_title($tw, $t['tw'])) { $twin = $t; break; }
        }
        $p = $pages[(int)$c['page_id']];
        $item = ['page_id' => (int)$c['page_id'], 'slug' => (string)$p['slug'],
                 'link' => 'https://genzhype.com' . (string)$p['path'], 'title' => (string)$p['h1'],
                 'lane' => (string)$c['lane'], 'bucket' => (string)($c['bucket'] ?? $c['lane']),
                 'score' => $s['score'], 'demand' => $s['demand'], 'quality' => $s['quality'],
                 'reason' => producer_reason($c, $s) . (!empty($s['status_boost']) ? sprintf(' Trend status "%s": x%.2f.', $c['status'], $s['status_boost']) : '')];
        if ($twin) { $same[] = $item + ['same_as' => $twin['page_id']]; continue; }
        $ranked[] = $item;
        $taken[] = ['page_id' => (int)$c['page_id'], 'kw' => $kw, 'people' => $people, 'tw' => $tw];
    } catch (Throwable $e) { $skipped[] = ['page_id' => (int)($row['c']['page_id'] ?? 0), 'error' => substr($e->getMessage(), 0, 200) . ' @' . $e->getLine()]; } }
}
$mix = null;
try { [$ranked, $mix] = vd_mix($pdo, $APP, $day, $ranked, $taken ?? []); }
catch (Throwable $e) { $skipped[] = ['page_id' => 0, 'error' => 'mix: ' . substr($e->getMessage(), 0, 200) . ' @' . $e->getLine()]; }
$out = ['ok' => true, 'day' => $day, 'pages_that_day' => count($pages), 'candidates' => count($cands),
        'ranked' => $ranked, 'same_topic' => $same, 'not_videoable' => $notVideoable, 'skipped' => $skipped, 'mix' => $mix, 'generated' => gmdate('c')];
// a day still being published is not cached: its list may still grow
if ($day < gmdate('Y-m-d')) { @file_put_contents($cache, json_encode($out, JSON_UNESCAPED_SLASHES)); }
$out['picks'] = array_slice($ranked, 0, $n);
unset($out['ranked']);
echo json_encode($out, JSON_UNESCAPED_SLASHES);

/**
 * HOW EACH LANE ACTUALLY DOES, from OUR posted videos (2026-11-05). The producer's own producer_lane_yield() joined the
 * views to social_posts (the site's queued text posts) instead of platform_videos (the videos we posted), and grouped by
 * pages.type ("drama"/"term") while the ranking looks a lane up as "meme", "slang" or "gaming": every meme page got the
 * drama number. Here: each video counted once (its latest, highest view count), the last 45 days, per platform; a lane's
 * yield = its views over the platform's average, the platforms weighted by their reach. 1.0 = par. A lane measured on
 * fewer than 3 pages borrows the meme+slang+gaming ("term") figure; with nothing measured it stays neutral.
 */
function vd_lane_yield(PDO $pdo): array
{
    $out = [];
    try {
        $rows = $pdo->query(
            "SELECT v.platform, v.page_id, x.mv views, p.type, t.lane tlane
               FROM (SELECT video_id, MAX(views) mv FROM platform_metrics GROUP BY video_id) x
               JOIN platform_videos v ON v.id = x.video_id
               JOIN pages p ON p.id = v.page_id
               LEFT JOIN terms t ON t.page_id = p.id
              WHERE v.posted_at > DATE_SUB(NOW(), INTERVAL 45 DAY) AND x.mv > 0")->fetchAll(PDO::FETCH_ASSOC);
        $plat = []; $lane = []; $pages = [];
        foreach ($rows as $r) {
            $pl = (string)$r['platform']; $v = (float)$r['views'];
            $plat[$pl][] = $v;
            $ln = ((string)$r['type'] === 'term') ? (((string)$r['tlane']) ?: 'term') : 'drama';
            foreach (array_unique([$ln, $ln === 'drama' ? 'drama' : 'term']) as $k) {
                $lane[$k][$pl][] = $v;
                $pages[$k][(int)$r['page_id']] = true;
            }
        }
        $avg = [];
        foreach ($plat as $pl => $vals) { $avg[$pl] = max(1.0, array_sum($vals) / count($vals)); }
        foreach ($lane as $k => $byPl) {
            if (count($pages[$k]) < 3) { continue; }
            $num = 0.0; $den = 0.0;
            foreach ($byPl as $pl => $vals) { $num += array_sum($vals) / count($vals); $den += $avg[$pl]; }
            $out[$k] = $den > 0 ? round($num / $den, 3) : 1.0;
        }
        foreach (['meme', 'slang', 'gaming', 'term'] as $k) {
            if (!isset($out[$k]) && isset($out['term'])) { $out[$k] = $out['term']; }
        }
    } catch (Throwable $e) { /* no metrics yet -> neutral */ }
    return $out;
}

/** Title words for the same-topic check: lower case, suffix-trimmed, no filler. "PS6" stays; plain numbers go. */
function vd_title_words(string $title): array
{
    static $stop = ['the', 'and', 'for', 'with', 'after', 'over', 'from', 'into', 'that', 'this', 'its', 'are', 'was', 'were',
        'has', 'have', 'new', 'says', 'said', 'how', 'why', 'what', 'who', 'when', 'explained', 'meme', 'memes', 'trend', 'complete',
        'timeline', 'release', 'date', 'update', 'patch', 'game', 'games', 'gaming', 'season', 'details', 'player', 'players',
        'fans', 'now', 'out', 'more', 'than', 'about', 'his', 'her', 'their', 'they', 'not', 'all', 'get', 'gets', 'will', 'can',
        'does', 'mean', 'means', 'meaning', 'blowing', 'way', 'viral', 'goes', 'going'];
    $out = [];
    foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($title)) as $w) {
        if ($w === '' || ctype_digit($w) || in_array($w, $stop, true)) { continue; }
        if (strlen($w) < 3 && !preg_match('/\d/', $w)) { continue; }
        if (strlen($w) > 4) { $w = preg_replace('/(ing|ed|es|e|s)$/', '', $w); }
        $out[$w] = true;
    }
    return array_keys($out);
}

/** Same story by title: 3 shared title words, or a short title (1-2 words left) whose words all sit in the other one. */
function vd_same_title(array $a, array $b): bool
{
    $both = count(array_intersect($a, $b));
    $small = min(count($a), count($b));
    return $both >= 3 || ($small > 0 && $small <= 2 && $both === $small);
}

/**
 * THE TEMPORARY DAILY MIX (owner, 2026-11-08): "push the memes a little more, even the older ones, alongside the slang,
 * for these days ... only the best 2 of drama and 1 gaming, then the rest between memes and slang."
 * Our memes are the hits (Frutiger Aero Yams 57,542 TikTok views, the Wassup meme 7,014) while gaming news sits at ~240.
 * Switched on by app/video_mix.json while today <= its "until" day; without the file the list is the plain ranking.
 *   {"until":"2026-11-14","total":8,"drama":2,"gaming":1,"slang_share":0.4,"backlog_days":30}
 * The first `total` places: memes, then slang (the day's own first, then older meme/slang pages that never got a video,
 * peaking ones first), then the best `drama` drama stories and the best `gaming` gaming one. Everything left follows in
 * the same order, so when the maker fails on a page the next one is a meme or slang page again.
 */
function vd_mix(PDO $pdo, string $APP, string $day, array $ranked, array $taken): array
{
    $f = $APP . '/video_mix.json';
    $cfg = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!is_array($cfg) || empty($cfg['until']) || gmdate('Y-m-d') > (string)$cfg['until']) { return [$ranked, null]; }
    $total = max(1, (int)($cfg['total'] ?? 8));
    $nDrama = (int)($cfg['drama'] ?? 2); $nGaming = (int)($cfg['gaming'] ?? 1);
    $days = max(1, min(120, (int)($cfg['backlog_days'] ?? 30)));

    $by = ['meme' => [], 'slang' => [], 'drama' => [], 'gaming' => []];
    foreach ($ranked as $r) { $b = (string)($r['bucket'] ?? $r['lane']); $by[isset($by[$b]) ? $b : 'drama'][] = $r; }

    // older meme / slang pages with no video and never posted, peaking first, newest first within a status
    $have = array_map('intval', array_column($ranked, 'page_id'));
    $st = $pdo->prepare("SELECT p.id, p.slug, p.path, p.h1, t.lane, t.status_label FROM pages p JOIN terms t ON t.page_id = p.id
        WHERE p.status = 'published' AND p.robots = 'index' AND p.cover <> '' AND t.lane IN ('meme', 'slang')
          AND p.published_at >= DATE_SUB(?, INTERVAL $days DAY) AND p.published_at < ?
          AND NOT EXISTS (SELECT 1 FROM video_scripts v WHERE v.page_id = p.id AND v.video_status = 'ready' AND v.video_path <> '')
          AND NOT EXISTS (SELECT 1 FROM platform_videos pv WHERE pv.page_id = p.id)
        ORDER BY p.published_at DESC LIMIT 300");
    $st->execute([$day . ' 00:00:00', $day . ' 00:00:00']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $order = ['peaking' => 0, 'emerging' => 1, 'mainstream' => 2, '' => 3, 'fading' => 4, 'cringe' => 5];
    usort($rows, fn($a, $b) => ($order[strtolower((string)$a['status_label'])] ?? 3) <=> ($order[strtolower((string)$b['status_label'])] ?? 3));
    $back = ['meme' => [], 'slang' => []];
    foreach ($rows as $r) {
        if (in_array((int)$r['id'], $have, true)) { continue; }
        $tw = vd_title_words((string)$r['h1']);
        foreach ($taken as $t) { if (vd_same_title($tw, (array)($t['tw'] ?? []))) { continue 2; } }
        $taken[] = ['tw' => $tw];
        $st2 = trim((string)$r['status_label']);
        $back[$r['lane']][] = ['page_id' => (int)$r['id'], 'slug' => (string)$r['slug'], 'link' => 'https://genzhype.com' . (string)$r['path'],
            'title' => (string)$r['h1'], 'lane' => (string)$r['lane'], 'bucket' => (string)$r['lane'],
            'score' => 0.0, 'demand' => 0.0, 'quality' => 0.0, 'backlog' => true,
            'reason' => 'Older ' . $r['lane'] . ' page with no video yet (owner mix: memes and slang first).' . ($st2 !== '' ? ' Trend status "' . $st2 . '".' : '')];
    }
    $pool = ['meme' => array_merge($by['meme'], $back['meme']), 'slang' => array_merge($by['slang'], $back['slang']),
             'drama' => $by['drama'], 'gaming' => $by['gaming']];

    $rest = max(0, $total - $nDrama - $nGaming);
    $nSlang = (int)floor($rest * (float)($cfg['slang_share'] ?? 0.4));
    $want = ['meme' => $rest - $nSlang, 'slang' => $nSlang, 'drama' => $nDrama, 'gaming' => $nGaming];
    $take = [];
    foreach ($want as $b => $k) { $take[$b] = array_splice($pool[$b], 0, $k); }
    // a bucket that ran short is filled from the others, in the owner's order
    $short = $total - array_sum(array_map('count', $take));
    foreach (['meme', 'slang', 'drama', 'gaming'] as $b) {
        while ($short > 0 && $pool[$b]) { $take[$b][] = array_shift($pool[$b]); $short--; }
    }
    $final = array_merge($take['meme'], $take['slang'], $take['drama'], $take['gaming'],
                         $pool['meme'], $pool['slang'], $pool['drama'], $pool['gaming']);
    $info = ['until' => (string)$cfg['until'], 'first' => array_map('count', $take),
             'older_meme_slang_in_first' => count(array_filter(array_slice($final, 0, $total), fn($x) => !empty($x['backlog'])))];
    return [$final, $info];
}
