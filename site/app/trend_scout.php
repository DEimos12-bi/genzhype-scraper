<?php
/**
 * TREND SCOUT (r79) — the "apply before anyone else" arm of the intelligence
 * engine.
 *
 * The other arms look backward (what won for rivals) and inward (did our
 * stories move on). This one looks FORWARD: what is rising RIGHT NOW, before
 * it saturates. Two verified keyless sources:
 *   - Google Trends daily RSS (US) — what America is searching today.
 *     TikTok- and IG-born moments surface here fast, which is how we read
 *     those platforms without scraping them (their rival APIs are closed;
 *     probed live 2026-08-09, Creative Center returns 40101 without an ads
 *     session, and IG has no rival API at all).
 *   - YouTube mostPopular charts (gaming + entertainment, US) via our own
 *     token — 1 quota unit per chart out of 10,000/day.
 *
 * WHAT "APPLY" MEANS HERE: matches land in the EXISTING discovery queue
 * (candidates, status=new) tagged "via trend-scout", where the same gate and
 * judge that vet every other story decide their fate. The scout never writes
 * pages; it hands leads to the pipeline that already knows how to say no.
 */
declare(strict_types=1);

const TS_LANE_WORDS = [
    // the lanes we actually cover — a trend must touch one to be a lead
    'streamer', 'stream', 'twitch', 'kick', 'youtuber', 'youtube', 'tiktok',
    'tiktoker', 'influencer', 'creator', 'drama', 'exposed', 'banned',
    'cancelled', 'canceled', 'apology', 'lawsuit', 'sued', 'feud', 'beef',
    'meme', 'viral', 'trend', 'gamer', 'gaming', 'esports', 'minecraft',
    'fortnite', 'roblox', 'speedrun', 'vtuber', 'podcast', 'rapper',
    'celebrity', 'scandal', 'controversy', 'leak', 'leaked', 'diss',
];

function ts_lane_hit(string $text): bool {
    $t = mb_strtolower($text);
    foreach (TS_LANE_WORDS as $w) {
        if (str_contains($t, $w)) return true;
    }
    return false;
}

/** Already covered or already queued? Cheap word-overlap test. */
function ts_known(PDO $pdo, string $name): bool {
    $words = array_values(array_filter(
        preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name)) ?: [],
        fn($w) => mb_strlen($w) >= 4));
    if (!$words) return true;                    // nothing to match on: skip
    $needle = '%' . implode('%', array_slice($words, 0, 3)) . '%';
    $q = $pdo->prepare(
        "SELECT (SELECT COUNT(*) FROM candidates WHERE name LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 14 DAY))
              + (SELECT COUNT(*) FROM pages WHERE h1 LIKE ?)");
    $q->execute([$needle, $needle]);
    return (int)$q->fetchColumn() > 0;
}

function ts_add_candidate(PDO $pdo, string $type, string $name, string $angle, int $heat): bool {
    if (ts_known($pdo, $name)) return false;
    $pdo->prepare("INSERT INTO candidates (type, name, angle, heat_score, era, signals, status, created_at)
                   VALUES (?,?,?,?, 'present', NULL, 'new', NOW())")
        ->execute([$type, mb_substr($name, 0, 250), mb_substr($angle, 0, 250), $heat]);
    return true;
}

/** Google Trends daily RSS: title + approx traffic + supporting headline. */
function ts_google_trends(PDO $pdo): array {
    $out = ['seen' => 0, 'added' => 0];
    $xml = @file_get_contents('https://trends.google.com/trending/rss?geo=US');
    if (!$xml) return $out + ['error' => 'rss unreachable'];
    if (!preg_match_all('#<item>(.*?)</item>#s', $xml, $items)) return $out;
    foreach ($items[1] as $item) {
        $out['seen']++;
        $title = preg_match('#<title>(.*?)</title>#s', $item, $m) ? html_entity_decode(trim($m[1])) : '';
        $traffic = preg_match('#<ht:approx_traffic>(.*?)</ht:approx_traffic>#s', $item, $m) ? trim($m[1]) : '';
        $news = preg_match('#<ht:news_item_title>(.*?)</ht:news_item_title>#s', $item, $m)
              ? html_entity_decode(trim($m[1])) : '';
        if ($title === '') continue;
        // the supporting headline usually carries the lane words the bare
        // search term lacks ("john doe" vs "streamer john doe banned")
        if (!ts_lane_hit($title . ' ' . $news)) continue;
        $heat = str_contains($traffic, 'M') ? 75 : (str_contains($traffic, '00K') ? 65 : 55);
        $name = $news !== '' ? $news : $title;
        if (ts_add_candidate($pdo, 'drama', $name,
                'via trend-scout: google-trends US (' . $traffic . ') term "' . $title . '"', $heat)) {
            $out['added']++;
        }
    }
    return $out;
}

/** YouTube trending charts, gaming + entertainment. */
function ts_youtube_trending(PDO $pdo, string $accessToken): array {
    $out = ['seen' => 0, 'added' => 0];
    foreach (['20' => 'gaming', '24' => 'entertainment'] as $cat => $lane) {
        $ch = curl_init('https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
            'part' => 'snippet', 'chart' => 'mostPopular', 'regionCode' => 'US',
            'videoCategoryId' => $cat, 'maxResults' => 15]));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken]]);
        $d = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        foreach (($d['items'] ?? []) as $v) {
            $out['seen']++;
            $t = (string)($v['snippet']['title'] ?? '');
            $chan = (string)($v['snippet']['channelTitle'] ?? '');
            if (!ts_lane_hit($t . ' ' . $chan)) continue;
            if (ts_add_candidate($pdo, $lane === 'gaming' ? 'gaming' : 'drama', $t,
                    "via trend-scout: youtube trending {$lane} US by {$chan}", 60)) {
                $out['added']++;
            }
        }
    }
    return $out;
}

function ts_run(PDO $pdo, string $accessToken): array {
    $g = ts_google_trends($pdo);
    $y = $accessToken !== '' ? ts_youtube_trending($pdo, $accessToken) : ['seen' => 0, 'added' => 0, 'error' => 'no yt token'];
    return ['google' => $g, 'youtube' => $y,
            'added_total' => (int)$g['added'] + (int)$y['added']];
}
