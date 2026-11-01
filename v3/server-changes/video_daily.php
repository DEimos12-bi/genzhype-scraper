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
header('Content-Type: application/json');
// The first call of a day ranks the pages (one AI reading of their feeling, up to a few minutes): it finishes and
// keeps the list even when the caller stops waiting, so the next call answers at once from the kept list.
ignore_user_abort(true);
@set_time_limit(600);

$tok = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if (!hash_equals(($CONFIG['ingest_token'] ?? '') ?: random_bytes(32), $tok)) {
    http_response_code(403); echo json_encode(['error' => 'bad token']); exit;
}
$day = (string)($_GET['day'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { $day = gmdate('Y-m-d', time() - 86400); }
$n = max(1, min(20, (int)($_GET['n'] ?? 10)));

$cacheDir = $APP . '/cache';
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
    $d = $pdo->prepare("SELECT d.id did, d.people_json,
                (SELECT COUNT(*) FROM events e WHERE e.drama_id=d.id AND e.event_date IS NOT NULL AND e.video_only=0) dated,
                (SELECT COUNT(*) FROM events e WHERE e.drama_id=d.id AND (e.source_id IS NOT NULL OR e.embed_html<>'')) sourced,
                (SELECT MAX(e.event_date) FROM events e WHERE e.drama_id=d.id) newest
              FROM dramas d WHERE d.page_id=? LIMIT 1");
    $d->execute([$id]);
    $t = $pdo->prepare("SELECT term, lane, citations, origin_date, short_def, why_trending FROM terms WHERE page_id=? LIMIT 1");
    $t->execute([$id]);
    if ($tr = $t->fetch(PDO::FETCH_ASSOC)) {                       // slang / meme / gaming term page
        $dated = 0;
        foreach ((array)json_decode((string)$tr['citations'], true) as $ct) { if (!empty($ct['date']) && strtotime((string)$ct['date'])) { $dated++; } }
        $od = trim((string)($tr['origin_date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $od)) { $dated++; } else { $od = ''; }
        $c = array_merge($c, ['lane' => (string)($tr['lane'] ?: 'term'), 'dated' => $dated, 'sourced' => $dated, 'newest' => $od,
              'term' => (string)$tr['term'], 'match' => (string)$tr['term'],
              'brief' => trim((string)$tr['term'] . ' — ' . mb_substr(trim((string)$tr['short_def']), 0, 220) . ' ' . mb_substr(producer_first_str($tr['why_trending']), 0, 280))]);
    } elseif ($dr = $d->fetch(PDO::FETCH_ASSOC)) {                 // drama / news page
        $c = array_merge($c, ['dated' => (int)$dr['dated'], 'sourced' => (int)$dr['sourced'], 'newest' => (string)($dr['newest'] ?? ''),
              'people' => (array)(json_decode((string)($dr['people_json'] ?? '[]'), true) ?: [])]);
    } else {
        $notVideoable[] = ['page_id' => $id, 'title' => (string)$p['h1'], 'why' => 'neither a story nor a term page'];
        continue;
    }
    $cands[] = $c;
}

$ranked = []; $same = [];
if ($cands) {
    $w = producer_weights($pdo);
    $trend = producer_trend_index($pdo);
    $outliers = producer_outlier_index($pdo);
    $yield = producer_lane_yield($pdo);
    $arousal = producer_arousal($pdo, array_slice($cands, 0, 30));
    $scored = [];
    foreach ($cands as $c) { $scored[] = ['c' => $c, 's' => producer_score($c, $w, $trend, $outliers, $yield, $arousal)]; }
    usort($scored, fn($a, $b) => $b['s']['score'] <=> $a['s']['score']);
    // ONE TOPIC, ONE VIDEO: a shared person, or two shared topic words, is the same story
    $taken = [];
    foreach ($scored as $row) {
        $c = $row['c']; $s = $row['s'];
        $kw = producer_keywords((string)($c['match'] ?? $c['title']), 6);
        $people = array_map(fn($x) => mb_strtolower(trim(is_array($x) ? (string)($x['name'] ?? '') : (string)$x)), (array)($c['people'] ?? []));
        $people = array_values(array_filter($people, fn($x) => mb_strlen($x) >= 3));
        $twin = null;
        foreach ($taken as $t) {
            if (array_intersect($people, $t['people']) || count(array_intersect($kw, $t['kw'])) >= 2) { $twin = $t; break; }
        }
        $p = $pages[(int)$c['page_id']];
        $item = ['page_id' => (int)$c['page_id'], 'slug' => (string)$p['slug'],
                 'link' => 'https://genzhype.com' . (string)$p['path'], 'title' => (string)$p['h1'],
                 'lane' => (string)$c['lane'], 'score' => $s['score'], 'demand' => $s['demand'], 'quality' => $s['quality'],
                 'reason' => producer_reason($c, $s)];
        if ($twin) { $same[] = $item + ['same_as' => $twin['page_id']]; continue; }
        $ranked[] = $item;
        $taken[] = ['page_id' => (int)$c['page_id'], 'kw' => $kw, 'people' => $people];
    }
}
$out = ['ok' => true, 'day' => $day, 'pages_that_day' => count($pages), 'candidates' => count($cands),
        'ranked' => $ranked, 'same_topic' => $same, 'not_videoable' => $notVideoable, 'generated' => gmdate('c')];
// a day still being published is not cached: its list may still grow
if ($day < gmdate('Y-m-d')) { @file_put_contents($cache, json_encode($out, JSON_UNESCAPED_SLASHES)); }
$out['picks'] = array_slice($ranked, 0, $n);
unset($out['ranked']);
echo json_encode($out, JSON_UNESCAPED_SLASHES);
