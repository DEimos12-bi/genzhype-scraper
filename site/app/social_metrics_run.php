<?php
/* GenZHype | r115 runner — collect our own IG/FB/TikTok numbers from Buffer.
 *
 *   php app/social_metrics_run.php            collect + write the yield rule
 *   php app/social_metrics_run.php --report   show what is stored, change nothing
 *
 * Safe to run on a cron: read-only against Buffer, one reading per post per
 * day, and it can never touch the publishing path. */
declare(strict_types=1);

$CONFIG = require __DIR__ . '/config.php';
$d = $CONFIG['db'];
$pdo = new PDO("mysql:host={$d['host']};dbname={$d['name']};charset=utf8mb4",
               $d['user'], $d['pass'],
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
require __DIR__ . '/social_metrics.php';

$report = in_array('--report', $argv, true);

if (!$report) {
    echo "=== collecting from Buffer ===\n";
    sm_collect($pdo);
    $rule = sm_write_platform_yield($pdo);
    echo $rule ? "platform_yield rule written (best: {$rule['best']})\n"
               : "not enough readings yet for a platform_yield rule\n";
}

echo "\n=== WHERE THE SAME VIDEO ACTUALLY PAYS ===\n";
$q = $pdo->query(
    "SELECT pv.platform,
            COUNT(DISTINCT pv.id) posts, COUNT(m.id) readings,
            ROUND(AVG(m.views)) avg_views, MAX(m.views) best_views,
            ROUND(AVG(m.likes),1) avg_likes, MAX(m.fetched_at) last_read
       FROM platform_videos pv
       LEFT JOIN platform_metrics m ON m.video_id = pv.id
      GROUP BY pv.platform
      ORDER BY avg_views DESC");
printf("  %-4s %6s %9s %10s %10s %10s  %s\n",
       'plat', 'posts', 'readings', 'avg views', 'best', 'avg likes', 'last read');
foreach ($q as $r) {
    printf("  %-4s %6d %9d %10s %10s %10s  %s\n",
           $r['platform'], $r['posts'], $r['readings'],
           (string)($r['avg_views'] ?? '-'), (string)($r['best_views'] ?? '-'),
           (string)($r['avg_likes'] ?? '-'), substr((string)$r['last_read'], 0, 16) ?: 'never');
}
