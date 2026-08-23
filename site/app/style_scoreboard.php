<?php
/**
 * GenZHype | STYLE SCOREBOARD (r66) — which video treatment actually works.
 *
 * The style system (rapid / slowburn / punch) varies cut rhythm, transition
 * hardness, motion intensity and music bed. Each finished video records the
 * style that produced it on video_scripts.style. This joins that against the
 * real platform numbers so the A/B test produces an ANSWER instead of variety.
 *
 * Averages per video (not totals) because styles will not have equal counts,
 * and a style that happens to have more videos would otherwise look better.
 * Shares and likes-per-view matter more than raw views: reach is what the
 * algorithm gives you, engagement is what earns the next reach.
 *
 *   php app/style_scoreboard.php [platform]
 */
declare(strict_types=1);
$APP = __DIR__;
$GLOBALS['CONFIG'] = require $APP . '/config.php';
require $APP . '/helpers.php';
require $APP . '/db.php';

$only = isset($argv[1]) ? preg_replace('/[^a-z]/', '', strtolower($argv[1])) : '';
$pdo  = db();

// Latest metric row per platform video, joined to the style that produced it.
$sql = "
  SELECT vs.style,
         pv.platform,
         COUNT(DISTINCT pv.id)              AS videos,
         AVG(m.views)                       AS avg_views,
         AVG(m.likes)                       AS avg_likes,
         AVG(m.shares)                       AS avg_shares,
         AVG(m.comments)                    AS avg_comments
    FROM video_scripts vs
    JOIN platform_videos pv ON pv.page_id = vs.page_id
    JOIN platform_metrics m ON m.id = (
           SELECT id FROM platform_metrics
            WHERE video_id = pv.id ORDER BY fetched_at DESC LIMIT 1)
   WHERE vs.style <> ''" . ($only ? " AND pv.platform = " . $pdo->quote($only) : "") . "
   GROUP BY vs.style, pv.platform
   ORDER BY pv.platform, avg_views DESC";

$rows = $pdo->query($sql)->fetchAll();
if (!$rows) {
    echo "No style data yet.\n";
    echo "Needs: videos rendered WITH a style (r65+) that have also been posted\n";
    echo "and had their metrics collected at least once.\n";
    $n = (int)$pdo->query("SELECT COUNT(*) FROM video_scripts WHERE style<>''")->fetchColumn();
    echo "videos carrying a style so far: $n\n";
    exit;
}

$plat = '';
printf("%-10s %-9s %6s %10s %9s %9s %9s %9s\n",
       'PLATFORM', 'STYLE', 'VIDS', 'AVG VIEWS', 'AVG LIKE', 'AVG SHR', 'LIKE/VW', 'SHR/VW');
foreach ($rows as $r) {
    if ($r['platform'] !== $plat) { echo str_repeat('-', 78) . "\n"; $plat = $r['platform']; }
    $v = max(1.0, (float)$r['avg_views']);
    printf("%-10s %-9s %6d %10.0f %9.1f %9.1f %8.2f%% %8.2f%%\n",
        $r['platform'], $r['style'], (int)$r['videos'],
        (float)$r['avg_views'], (float)$r['avg_likes'], (float)$r['avg_shares'],
        100 * (float)$r['avg_likes'] / $v, 100 * (float)$r['avg_shares'] / $v);
}
echo str_repeat('-', 78) . "\n";
echo "Read SHR/VW first (shares per view) — it is the strongest growth signal.\n";
echo "Ignore any style with fewer than ~5 videos; the sample is too small.\n";
