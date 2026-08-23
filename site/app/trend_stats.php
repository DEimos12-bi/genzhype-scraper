<?php
// GenZHype | TREND STATISTICS — the real maths the paid tools use, built ourselves.
// Operates on a daily time-series [{d,v}, ...]. No external calls; pure algorithm.
//  - velocity:      least-squares slope of the recent window (rate of change/day)
//  - spike z-score: recent window vs its own prior baseline (detects "surging NOW")
//  - Mann-Kendall:  non-parametric trend-significance test (is the rise/fall real?)
//  - forecast:      linear projection N days out
// This is what separates "trending now" from "was famous in 2023" — the thing my
// first 100-line version got conceptually wrong.

/** Least-squares slope (per-day change) and intercept over the last $win points. */
function ts_slope(array $vals, int $win = 0): array {
    $n = count($vals);
    if ($win > 0 && $n > $win) $vals = array_slice($vals, -$win);
    $m = count($vals);
    if ($m < 3) return ['slope' => 0.0, 'intercept' => $vals ? end($vals) : 0.0];
    $sx = $sy = $sxx = $sxy = 0.0;
    foreach ($vals as $i => $y) { $sx += $i; $sy += $y; $sxx += $i * $i; $sxy += $i * $y; }
    $denom = ($m * $sxx - $sx * $sx) ?: 1;
    $slope = ($m * $sxy - $sx * $sy) / $denom;
    $intercept = ($sy - $slope * $sx) / $m;
    return ['slope' => $slope, 'intercept' => $intercept];
}

/** Spike z-score: how many SDs the recent window sits above its prior baseline. */
function ts_spike_z(array $vals, int $recent = 7): float {
    $n = count($vals);
    if ($n < $recent + 10) return 0.0;
    $base = array_slice($vals, 0, $n - $recent);
    $rec  = array_slice($vals, -$recent);
    $bm = array_sum($base) / count($base);
    $var = 0.0; foreach ($base as $v) $var += ($v - $bm) ** 2;
    $sd = sqrt($var / max(1, count($base) - 1));
    if ($sd < 0.0001) return 0.0;
    $rm = array_sum($rec) / count($rec);
    return ($rm - $bm) / $sd;
}

/**
 * Mann-Kendall trend test. Returns S, tau, and z (significance). |z|>1.96 = 95%.
 * Positive z = significant upward trend, negative = downward.
 */
function ts_mann_kendall(array $vals): array {
    $n = count($vals);
    if ($n < 8) return ['tau' => 0.0, 'z' => 0.0, 's' => 0];
    $S = 0;
    for ($i = 0; $i < $n - 1; $i++)
        for ($j = $i + 1; $j < $n; $j++)
            $S += ($vals[$j] <=> $vals[$i]);
    // variance with a simple tie correction
    $counts = array_count_values(array_map('strval', $vals));
    $tie = 0; foreach ($counts as $t) { $t = (int)$t; if ($t > 1) $tie += $t * ($t - 1) * (2 * $t + 5); }
    $var = ($n * ($n - 1) * (2 * $n + 5) - $tie) / 18;
    $z = 0.0;
    if ($var > 0) $z = $S > 0 ? ($S - 1) / sqrt($var) : ($S < 0 ? ($S + 1) / sqrt($var) : 0.0);
    $tau = $S / (0.5 * $n * ($n - 1));
    return ['tau' => round($tau, 3), 'z' => round($z, 2), 's' => $S];
}

/** Forecast: project the recent slope $days into the future, as a % of current level. */
function ts_forecast(array $vals, int $days = 14, int $win = 21): array {
    $fit = ts_slope($vals, $win);
    $cur = end($vals) ?: 0;
    $proj = max(0, $cur + $fit['slope'] * $days);
    $pct = $cur > 0 ? (int)round((($proj - $cur) / $cur) * 100) : 0;
    return ['projected' => (int)round($proj), 'pct_change' => $pct, 'slope_per_day' => round($fit['slope'], 2)];
}

/**
 * Full real-trend classification from a daily series. This is the honest momentum
 * signal — driven by velocity + statistical significance, not a hardcoded guess.
 */
function ts_classify(array $series): array {
    $vals = array_column($series, 'v');
    $n = count($vals);
    if ($n < 14) return ['state' => 'unknown', 'confidence' => 0, 'mk_z' => 0, 'spike_z' => 0, 'forecast_pct' => 0];
    $mk    = ts_mann_kendall($vals);
    $spike = round(ts_spike_z($vals), 2);
    $fc    = ts_forecast($vals);

    // classify: a spike outranks a slow trend
    if ($spike >= 2.5)                 $state = 'surging';
    elseif ($mk['z'] >= 1.96)          $state = 'rising';
    elseif ($mk['z'] <= -1.96)         $state = 'fading';
    elseif ($spike <= -1.5)            $state = 'dropping';
    else                               $state = 'steady';

    // confidence 0-100 from how decisive the signals are
    $conf = (int)round(min(100, (abs($mk['z']) * 22) + (abs($spike) * 10)));

    return [
        'state'        => $state,
        'confidence'   => $conf,
        'mk_z'         => $mk['z'],
        'mk_tau'       => $mk['tau'],
        'spike_z'      => $spike,
        'forecast_pct' => $fc['pct_change'],
        'forecast'     => $fc['projected'],
    ];
}
