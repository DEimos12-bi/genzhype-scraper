<?php
// GenZHype | DATA LAYER. Real, current, free per-term popularity data from
// Wikipedia pageviews (updated daily, ~2-day lag, datacenter-reachable — unlike
// Reddit/Google-Trends which block our server IP). Powers the visible "By the
// numbers" block + a data-derived status, refreshed by the watchdog (living doc).
// Only ~55-60% of terms have a Wikipedia article; the rest return null and the
// block hides rather than fabricating a trend.

require_once __DIR__ . '/fetch_sources.php';

// CLAIM-4 (2026-08-05): ONE retry for every fetch in this layer. The pipeline
// is a chain of 3-6 API calls per term (opensearch -> redirect -> sense
// extract -> pageviews x1-4) and a single transient miss anywhere nulled the
// whole term: pogchamp measured avg=171/day in one minute and "no data" the
// next, purely on which call the throttle happened to clip. A verdict must
// not depend on luck. Scoped here, not in fs_http_get, so drafting paths keep
// their fast-fail behaviour.
function td_get(string $url, int $timeout = 12): ?string {
    $r = fs_http_get($url, $timeout);
    if (!$r) { sleep(2); $r = fs_http_get($url, $timeout); }
    return $r;
}

/** Best-match Wikipedia article title for a term, or '' if none. */
function term_wiki_title(string $term): string {
    // CLAIM-4 (2026-08-05): limit=3 and PICK, don't just take the first.
    // opensearch("mewing") ranks "Mew" first with the actual "Mewing" article
    // right behind it; first-match therefore resolved to the wrong page and the
    // title guard (correctly) threw it away — the term then read as having no
    // data even though its own article exists.
    $r = td_get("https://en.wikipedia.org/w/api.php?action=opensearch&limit=3&namespace=0&format=json&search=" . rawurlencode($term), 12);
    $j = $r ? json_decode($r, true) : null;
    $cands = array_values(array_filter((array)($j[1] ?? []), 'is_string'));
    if (!$cands) return '';
    // CLAIM-4 FIX (2026-08-05): RESOLVE REDIRECTS, and test candidates on their
    // RESOLVED titles. Two measured traps here:
    //  - "npc" answers the redirect "Npc" (~3 views/day of redirect traffic)
    //    while the target holds the real numbers — so resolution is mandatory;
    //  - "mewing" answers ["Mewing", "Mewing (orthotropics)", ...] where the
    //    exact-looking "Mewing" RESOLVES AWAY to "Mew" (wrong subject) while
    //    "Mewing (orthotropics)" resolves to itself and is the real article.
    //    Selecting before resolving picked the decoy every time.
    foreach ($cands as $c) {
        $r2 = td_get('https://en.wikipedia.org/w/api.php?action=query&redirects=1&format=json&titles=' . rawurlencode($c), 12);
        $j2 = $r2 ? json_decode($r2, true) : null;
        $canon = $c;
        foreach (($j2['query']['pages'] ?? []) as $pg) {
            if (($t2 = (string)($pg['title'] ?? '')) !== '') { $canon = $t2; break; }
        }
        if (term_title_is_term($canon, $term)) return $canon;
    }
    // no candidate survives resolution+equality: return the first resolved
    // candidate and let the caller's guards judge it
    return $cands[0];
}

/**
 * Real popularity data for a term from Wikipedia pageviews.
 * Returns a structured array (total/avg/growth/direction/peak/series) or null.
 */
/** Raw daily pageview series for one article on one Wikimedia project. */
function pv_series(string $project, string $article, int $days): array {
    $end   = date('Ymd', strtotime('-2 days'));
    $start = date('Ymd', strtotime("-$days days"));
    $art   = rawurlencode(str_replace(' ', '_', $article));
    $u = "https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article/$project/all-access/user/$art/daily/$start/$end";
    $r = td_get($u, 12);
    // CLAIM-4 (2026-08-05): one polite retry. The case-variant probing tripled
    // calls per term and Wikimedia throttles bursts — a throttled fetch was
    // indistinguishable from "article has no views" and silently killed cards
    // for terms with perfectly good data (pogchamp, sus).
    $j = $r ? json_decode($r, true) : null;
    $series = [];
    foreach ($j['items'] ?? [] as $it) $series[] = ['d' => substr((string)($it['timestamp'] ?? ''), 0, 8), 'v' => (int)($it['views'] ?? 0)];
    return $series;
}

// CLAIM-4 SENSE GUARD (2026-08-05). Redirect resolution made big numbers easy
// to fetch — and immediately produced FALSE ones: "no cap" resolved to
// ReCAPTCHA (407 reads/day), "mewing" to "Mew". A wrong-sense figure published
// as "Wikipedia reads/day" is a fabricated statistic wearing a real API's
// clothes. Rule: Wikipedia's number is only trusted when the article title IS
// the term — same words, case/punctuation aside, a "(meme)"-style qualifier
// allowed. Everything else falls through to Wiktionary (lookups of the word
// itself are the honest signal for slang) or to no card at all.
function term_title_is_term(string $title, string $term): bool {
    $norm = fn(string $s) => preg_replace('/[^a-z0-9]/', '', mb_strtolower(preg_replace('/\s*\([^)]*\)\s*$/', '', $s)));
    $a = $norm($title); $b = $norm($term);
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    // morphological EXTENSIONS of the term are the same subject ("Rickrolling"
    // for rickroll). Only title-extends-term, never the reverse: the reverse
    // would let "Canon (fiction)" claim "canon event". Term must be >=4 chars
    // so "e" or "gg" cannot prefix-claim unrelated articles.
    return mb_strlen($b) >= 4 && str_starts_with($a, $b);
}

// Second half of the guard: title equality is NOT sense equality. "Sus" passes
// the title test and is the PIG GENUS article — its 36 reads/day are farmers
// and biology students, not slang interest. So the article's own opening
// sentences must place it in internet culture before its number is trusted.
// Deterministic marker test on Wikipedia's extract, no AI.
function term_wiki_sense_ok(string $title): bool {
    $r = td_get('https://en.wikipedia.org/w/api.php?action=query&prop=extracts&exintro=1&explaintext=1&exsentences=3&redirects=1&format=json&titles=' . rawurlencode($title), 12);
    if (!$r) { sleep(2); $r = fs_http_get('https://en.wikipedia.org/w/api.php?action=query&prop=extracts&exintro=1&explaintext=1&exsentences=3&redirects=1&format=json&titles=' . rawurlencode($title), 12); }
    $j = $r ? json_decode($r, true) : null;
    $intro = '';
    foreach (($j['query']['pages'] ?? []) as $pg) { $intro = (string)($pg['extract'] ?? ''); break; }
    if ($intro === '') return false;                 // cannot verify sense -> do not trust
    // markers measured against real intros: NPC says "character in a game",
    // Wojak says "cartoon drawing" — the first marker list missed both and
    // rejected honest articles. The pig-genus counterexample ("Sus is a genus
    // of wild and domestic pigs") still matches nothing here.
    return (bool)preg_match('/\b(slang|meme|internet|emote|twitch|catchphrase|online|streamer|tiktok|video game|gaming|game|character|cartoon|drawing|4chan|reddit|social media|imageboard|expression used|neologism|viral)\b/i', $intro);
}

function term_popularity_data(string $term, int $days = 90, string $titleHint = ''): ?array {
    // 1) Wikipedia first (best for memes / named concepts)
    $title  = $titleHint !== '' ? $titleHint : term_wiki_title($term);
    if ($title !== '' && $titleHint === '' && (!term_title_is_term($title, $term) || !term_wiki_sense_ok($title))) $title = '';
    $series = $title !== '' ? pv_series('en.wikipedia', $title, $days) : [];
    $source = 'Wikipedia'; $srcLabel = 'Wikipedia reading interest';
    // 2) if Wikipedia is weak/absent, try Wiktionary (covers slang WORDS) + keep the bigger
    if (array_sum(array_column($series, 'v')) < $days * 5) {
        // CLAIM-4 FIX (2026-08-05): Wiktionary titles are CASE-SENSITIVE.
        // Lowercasing everything meant "npc" (no such entry) was queried while
        // "NPC" (the real entry, with all the lookups) was never tried. Probe
        // the plausible casings and keep the biggest series.
        $w = [];
        $tried = [];
        foreach ([mb_strtolower($term), $term, mb_strtoupper($term)] as $cand) {
            if (isset($tried[$cand])) continue;
            $tried[$cand] = 1;
            $c = pv_series('en.wiktionary', $cand, $days);
            if (array_sum(array_column($c, 'v')) > array_sum(array_column($w, 'v'))) { $w = $c; $wTitle = $cand; }
        }
        if (array_sum(array_column($w, 'v')) > array_sum(array_column($series, 'v'))) {
            $series = $w; $title = $wTitle; $source = 'Wiktionary'; $srcLabel = 'Wiktionary dictionary lookups';
        }
    }
    if (count($series) < 14) return null;
    $vals  = array_column($series, 'v');
    $n     = count($vals);
    $total = array_sum($vals);
    if ($total / $n < 10) return null;       // < 10 views/day = too weak to feature (box hides)

    // trend: recent half vs older half, with a cap so tiny baselines can't print absurd %
    $half     = intdiv($n, 2);
    $older    = array_sum(array_slice($vals, 0, $half));
    $recent   = array_sum(array_slice($vals, $half));
    $olderAvg = $older / max(1, $half);
    $capped   = false;
    if ($olderAvg < 3) {                      // basically zero baseline -> "new", not a real %
        $growth = $recent > $older ? 300 : 0;
        $capped = $recent > $older;
    } else {
        $growth = (int)round((($recent - $older) / $older) * 100);
        if ($growth > 300) { $growth = 300; $capped = true; }
        $growth = max(-95, $growth);
    }
    $peak   = max($vals);
    $peakIx = array_search($peak, $vals, true);
    $dir    = $growth > 25 ? 'rising' : ($growth < -25 ? 'falling' : 'steady');

    return [
        'title'        => $title,
        'total'        => $total,
        'avg_daily'    => (int)round($total / $n),
        'growth_pct'   => $growth,
        'growth_capped'=> $capped,
        'direction'    => $dir,
        'peak_views'   => $peak,
        'peak_date'    => $series[$peakIx]['d'] ?? '',
        'days'         => $n,
        'series'       => $series,
        'source'       => $source,
        'source_label' => $srcLabel,
        'as_of'        => $series[$n - 1]['d'] ?? '',
    ];
}

/** Inline SVG sparkline (no JS) from a pageview series. */
function term_data_sparkline(array $series, int $w = 240, int $h = 46): string {
    $vals = array_column($series, 'v');
    $n = count($vals);
    if ($n < 2) return '';
    $max = max($vals) ?: 1; $min = min($vals);
    $range = ($max - $min) ?: 1;
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = round($i / ($n - 1) * ($w - 4) + 2, 1);
        $y = round($h - 4 - (($v - $min) / $range) * ($h - 8), 1);
        $pts[] = "$x,$y";
    }
    $poly = implode(' ', $pts);
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h
         . '" preserveAspectRatio="none" role="img" aria-label="popularity trend">'
         . '<polyline fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="' . $poly . '"/>'
         . '</svg>';
}

/** Map real pageview direction -> the dictionary's status ladder. */
function term_status_from_data(array $data): string {
    return [
        'rising'  => 'peaking',
        'falling' => 'fading',
        'steady'  => 'mainstream',
    ][$data['direction']] ?? 'mainstream';
}
