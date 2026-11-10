<?php
/**
 * GenZHype | THE DAILY VIDEO WATCHDOG + REPORT (2026-11-03, owner: "follow what professionals do")
 * =============================================================================
 * Run by the server's 30-minute bridge job (~/genzhype-video-bridge.sh), from the command line only.
 *
 *   php app/video_daily_watch.php poke    -> prints POKE when yesterday's pages still need videos (fewer than 8 made,
 *                                            00:00-14:59 UTC, the last poke over 110 minutes ago); the bridge job then
 *                                            pushes .social/v3_poke and GitHub starts the maker (v3-live.yml). GitHub's
 *                                            own timer is late or dropped under load: this is the reliable clock.
 *   php app/video_daily_watch.php report  -> once a day from 15:00 UTC, e-mails app/NOTIFY_EMAIL the day's count:
 *                                            videos made for yesterday's pages (of 8) and where each was posted.
 *   php app/video_daily_watch.php posters -> prints POKE once an hour from 13:00 to 04:59 UTC: the bridge job then pushes
 *                                            .social/buffer_poke and .social/yt_poke and GitHub starts the posters.
 *   php app/video_daily_watch.php status  -> prints the same report, sends nothing.
 *
 * READ-ONLY on the database. State: app/cache/v3_poke_last, app/cache/v3_report_<day>.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$APP = __DIR__;
$GLOBALS['CONFIG'] = require $APP . '/config.php';
require_once $APP . '/helpers.php';
require_once $APP . '/db.php';

const V3_DAILY = 8;
$mode = (string)($argv[1] ?? 'status');
$day = gmdate('Y-m-d', time() - 86400);
$cache = $APP . '/cache';
if (!is_dir($cache)) { @mkdir($cache, 0755, true); }
$pdo = db();

// the videos made FOR yesterday's pages: delivered after the day ended
$st = $pdo->prepare("SELECT v.page_id, p.h1, v.video_made_at FROM video_scripts v JOIN pages p ON p.id = v.page_id
                      WHERE p.published_at >= ? AND p.published_at < DATE_ADD(?, INTERVAL 1 DAY)
                        AND v.video_status = 'ready' AND v.video_made_at >= DATE_ADD(?, INTERVAL 1 DAY)
                      ORDER BY v.video_made_at");
$st->execute([$day . ' 00:00:00', $day . ' 00:00:00', $day . ' 00:00:00']);
$made = $st->fetchAll(PDO::FETCH_ASSOC);
$list = is_file("$cache/video_daily-$day.json") ? (json_decode((string)file_get_contents("$cache/video_daily-$day.json"), true) ?: []) : [];
$picks = count((array)($list['ranked'] ?? []));

if ($mode === 'posters') {
    // the posters (Buffer: TikTok/Instagram bookings and the Facebook slots; YouTube scheduling) also run on GitHub's
    // timer: from 13:00 to 04:59 UTC the bridge job starts them once an hour (.social/buffer_poke, .social/yt_poke)
    $hour = (int)gmdate('G');
    $last = (int)@file_get_contents("$cache/v3_posters_last");
    if (($hour >= 13 || $hour <= 4) && time() - $last > 55 * 60) {
        file_put_contents("$cache/v3_posters_last", (string)time());
        echo "POKE posters hour=$hour\n";
    } else {
        echo "OK posters hour=$hour\n";
    }
    exit(0);
}
if ($mode === 'poke') {
    $hour = (int)gmdate('G');
    $last = (int)@file_get_contents("$cache/v3_poke_last");
    if (count($made) < V3_DAILY && $picks > 0 && $hour <= 14 && time() - $last > 110 * 60) {
        file_put_contents("$cache/v3_poke_last", (string)time());
        echo "POKE day=$day made=" . count($made) . "/" . V3_DAILY . "\n";
    } else {
        echo "OK day=$day made=" . count($made) . "/" . V3_DAILY . " picks=$picks hour=$hour\n";
    }
    exit(0);
}

// where each video was posted (the posters register every post: platform_videos)
$names = ['tt' => 'TikTok', 'ig' => 'Instagram', 'fb' => 'Facebook', 'yt' => 'YouTube'];
$lines = [];
$perPlatform = array_fill_keys(array_keys($names), 0);
foreach ($made as $m) {
    $q = $pdo->prepare("SELECT DISTINCT platform FROM platform_videos WHERE page_id = ?");
    $q->execute([(int)$m['page_id']]);
    $on = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
    foreach ($on as $pl) { if (isset($perPlatform[$pl])) { $perPlatform[$pl]++; } }
    $lines[] = '- ' . mb_substr((string)$m['h1'], 0, 80) . '  (posted: ' . (implode(', ', array_map(fn($p) => $names[$p] ?? $p, $on)) ?: 'not yet') . ')';
}
$ok = count($made) >= V3_DAILY;
$body = "GenZHype videos for the pages of $day\n\n"
      . 'Made: ' . count($made) . ' of ' . V3_DAILY . ($ok ? '  OK' : '  SHORT') . "  (the day had $picks ranked pages)\n"
      . 'Posted: ' . implode(', ', array_map(fn($k) => $names[$k] . ' ' . $perPlatform[$k], array_keys($names))) . "\n\n"
      . ($lines ? implode("\n", $lines) : '(no video made)') . "\n";
if ($mode !== 'report') { echo $body; exit(0); }

$sent = "$cache/v3_report_$day";
if (is_file($sent) || (int)gmdate('G') < 15) { exit(0); }
$to = is_file("$APP/NOTIFY_EMAIL") ? trim((string)file_get_contents("$APP/NOTIFY_EMAIL")) : '';
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { exit(0); }
require_once $APP . '/mailer.php';
$r = mailer_send($to, 'GenZHype daily videos ' . $day . ': ' . count($made) . '/' . V3_DAILY . ($ok ? '' : ' SHORT'), $body);
if (!empty($r['ok'])) { file_put_contents($sent, gmdate('c')); }
echo (!empty($r['ok']) ? 'report sent' : 'report failed: ' . ($r['error'] ?? '?')) . "\n";
