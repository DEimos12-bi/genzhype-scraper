<?php
// GenZHype | TREND INTELLIGENCE ENGINE — our free build of what Exploding Topics /
// Glimpse / Brandwatch sell ($39-249/mo). They charge for (1) live multi-platform
// data and (2) a scoring/forecasting algorithm. We get (1) from free official-ish
// sources that work from our datacenter (YouTube view counts + Wikimedia pageviews)
// and we build (2) ourselves. Output: a 0-100 Trend Score + the raw signals.

require_once __DIR__ . '/fetch_sources.php';
require_once __DIR__ . '/term_data.php';    // term_popularity_data()
require_once __DIR__ . '/trend_stats.php';  // ts_classify() real trend maths

/**
 * YouTube REACH signal: combined view counts of the top results for a term, plus
 * how recent that content is (momentum). Free, datacenter-reachable, current.
 */
function yt_signal(string $term): array {
    $html = fs_http_get('https://www.youtube.com/results?search_query=' . rawurlencode($term)
        . '&hl=en&gl=US', 18);
    if (!$html) return ['views_total' => 0, 'videos' => 0, 'recent_share' => 0.0];
    // view counts ("12,345 views")
    preg_match_all('/"viewCountText":\{"simpleText":"([0-9,]+) views"/', $html, $vc);
    $views = array_map(fn($v) => (int)str_replace(',', '', $v), $vc[1]);
    $views = array_slice($views, 0, 15);
    // recency ("3 years ago" = old, "2 weeks ago" = fresh)
    preg_match_all('/"publishedTimeText":\{"simpleText":"([^"]+)"/', $html, $pt);
    $recent = 0; $seen = 0;
    foreach (array_slice($pt[1], 0, 15) as $p) {
        $seen++;
        // anything measured in hours/days/weeks/months is within the last year
        if (preg_match('/\b(hour|day|week|month)s?\b/i', $p)) $recent++;
        elseif (preg_match('/\b1 year\b/i', $p)) $recent++;   // "1 year ago" still counts as recent-ish
    }
    return [
        'views_total'  => array_sum($views),
        'videos'       => count($views),
        'recent_share' => $seen ? round($recent / $seen, 2) : 0.0,
    ];
}

/** log-scaled 0-100 helper: maps a count to a score via log10. */
function ts_log_score(float $n, float $perDecade, float $cap = 100): float {
    if ($n < 1) return 0;
    return min($cap, log10($n) * $perDecade);
}

/**
 * Composite TREND SCORE for a term (0-100) + the raw signals behind it.
 * Returns null only if we have literally no signal.
 */
function trend_score(string $term, ?array $popData = null): ?array {
    $yt  = yt_signal($term);
    $pop = $popData ?? term_popularity_data($term);   // Wikipedia/Wiktionary interest + series

    $views  = (int)$yt['views_total'];
    $avgDay = $pop['avg_daily'] ?? 0;
    if ($views < 1 && $avgDay < 1) return null;       // no signal at all

    // SIZE axis: how big is its footprint (reach + reading interest), log-scaled
    $reachScore    = ts_log_score($views, 12.5);      // 1M views=75, 100M=100, 10K=50
    $interestScore = ts_log_score($avgDay, 33.0);     // 1000/day~99, 100~66, 10~33
    $size = (int)round(min(100, 0.6 * $reachScore + 0.4 * $interestScore));

    // TREND axis: REAL statistics on the daily series (not a guess). Separate from size.
    $cls = (!empty($pop['series'])) ? ts_classify($pop['series'])
         : ['state' => 'unknown', 'confidence' => 0, 'mk_z' => 0, 'spike_z' => 0, 'forecast_pct' => 0];

    return [
        'size_score'   => $size,            // footprint 0-100 (reach + interest)
        'reach_views'  => $views,           // YouTube combined views
        'yt_videos'    => $yt['videos'],
        'recent_share' => $yt['recent_share'],
        'interest_day' => $avgDay,          // Wikipedia/Wiktionary views/day
        'trend_state'  => $cls['state'],    // surging | rising | steady | fading | dropping (REAL stats)
        'trend_conf'   => $cls['confidence'],
        'mk_z'         => $cls['mk_z'],     // Mann-Kendall significance (|z|>1.96 = 95%)
        'spike_z'      => $cls['spike_z'],  // recent-vs-baseline spike
        'forecast_pct' => $cls['forecast_pct'],
        'as_of'        => date('Ymd'),
    ];
}

/**
 * Build the full trend-intelligence object for a term (pageview series + reach +
 * real trend stats), merged for storage in terms.data_json, AND drop a daily
 * snapshot into term_signals so we accumulate our own history (the moat). Returns
 * the merged data array (with 'series' for the sparkline) or null if no signal.
 */
function term_build_intel(string $term, ?int $pageId = null): ?array {
    $pop = term_popularity_data($term);
    $ts  = trend_score($term, $pop);
    if (!$pop && !$ts) return null;
    $data = $pop ?: [];                      // keep series/avg_daily/growth for the chart
    if ($ts) {
        foreach (['size_score','reach_views','trend_state','trend_conf','mk_z','spike_z','forecast_pct'] as $k) {
            $data[$k] = $ts[$k];
        }
    }
    if ($pageId && $ts) {
        db()->prepare(
            "INSERT INTO term_signals (page_id,d,size_score,reach_views,interest_day,trend_state,mk_z,spike_z,forecast_pct)
             VALUES (?,CURDATE(),?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE size_score=VALUES(size_score),reach_views=VALUES(reach_views),
               interest_day=VALUES(interest_day),trend_state=VALUES(trend_state),mk_z=VALUES(mk_z),
               spike_z=VALUES(spike_z),forecast_pct=VALUES(forecast_pct)"
        )->execute([$pageId, $ts['size_score'], $ts['reach_views'], $ts['interest_day'],
                    $ts['trend_state'], $ts['mk_z'], $ts['spike_z'], $ts['forecast_pct']]);
    }
    return $data;
}
