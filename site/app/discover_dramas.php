<?php
// GenZHype | DRAMA discovery (server-side). Complements the GitHub scraper with
// creator-culture RSS feeds pulled straight from this server every cron tick.
//  1) harvest items from creator-specific feeds
//  2) keyword pre-filter kills mainstream-celeb noise BEFORE it costs AI calls
//  3) survivors land in candidates (type=drama, status=new); the existing
//     AI selector (select_run) remains the judge of what gets built.
// Every step best-effort; a dead feed is skipped, never fatal.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fetch_sources.php';   // fs_http_get

const DD_FEEDS = [
    'dexerto'    => 'https://www.dexerto.com/feed/',
    'tubefilter' => 'https://www.tubefilter.com/feed/',
    'dailydot'   => 'https://www.dailydot.com/feed/',
    'kym'        => 'https://knowyourmeme.com/newsfeed.rss',
];

// a title must hit >= 1 of these to even become a candidate (cheap noise cut)
const DD_KEYWORDS = [
    'tiktok', 'tiktoker', 'youtube', 'youtuber', 'twitch', 'streamer', 'stream',
    'kick', 'influencer', 'creator', 'content creator', 'vtuber', 'discord',
    'drama', 'feud', 'beef', 'exposed', 'allegation', 'apolog', 'banned',
    'deplatform', 'cancel', 'controversy', 'lawsuit', 'callout', 'leak',
    'mrbeast', 'kai cenat', 'ishowspeed', 'adin ross', 'xqc', 'pokimane',
    'logan paul', 'jake paul', 'ksi', 'sneako', 'fanum', 'duke dennis',
];

/**
 * Which lane does a freshly harvested feed item belong in?
 * Feed identity first (KnowYourMeme publishes memes), then an explicit
 * gaming test, then drama as the default. Deliberately conservative: a
 * wrong lane is worse than the default, because each lane has its own gate.
 */
function dd_lane_for(string $feed, string $text): string
{
    $t = ' ' . mb_strtolower($text) . ' ';
    // a person-centred scandal is drama even on a meme feed
    $dramaWords = '/\\b(lawsuit|allegation|arrest|charged|apolog|accus|assault|abuse|'
                . 'banned|deplatform|controvers|feud|exposed|scandal|fired|sued)\\b/i';
    if (preg_match($dramaWords, $t)) { return 'drama'; }
    $gameWords = '/\\b(gta ?6|minecraft|fortnite|valorant|roblox|call of duty|warzone|'
               . 'league of legends|overwatch|speedrun|patch notes|dlc|esports|'
               . 'nintendo|playstation|xbox|steam deck|game ?play)\\b/i';
    if (preg_match($gameWords, $t)) { return 'gaming'; }
    // feed identity is the fallback, not the first word: a GTA 6 story on a
    // meme feed is still a gaming story.
    if ($feed === 'kym') { return 'meme'; }
    return 'drama';
}

/** Parse RSS/Atom items via SimplePie when available; regex fallback otherwise. */
function dd_feed_items(string $xml, int $max = 15): array {
    if (defined('GZH_LIBS') && GZH_LIBS) {
        try {
            $fp = new \SimplePie\SimplePie();
            $fp->set_raw_data($xml);
            $fp->enable_cache(false);
            @$fp->init();
            $items = [];
            foreach ($fp->get_items(0, $max) as $it) {
                $t = trim((string)$it->get_title());
                $l = trim((string)$it->get_permalink());
                if ($t === '' || $l === '') continue;
                $desc = trim(html_entity_decode(strip_tags((string)$it->get_description()), ENT_QUOTES, 'UTF-8'));
                $items[] = ['title' => $t, 'link' => $l, 'desc' => mb_substr($desc, 0, 400)];
            }
            if ($items) return $items;
        } catch (\Throwable $e) { /* fall through to regex */ }
    }
    return dd_feed_items_regex($xml, $max);
}

/** Original tolerant regex feed parser | fallback. */
function dd_feed_items_regex(string $xml, int $max = 15): array {
    $items = [];
    if (preg_match_all('#<item\b[^>]*>(.*?)</item>#is', $xml, $m)) {
        foreach (array_slice($m[1], 0, $max) as $chunk) {
            $get = function ($tag) use ($chunk) {
                if (!preg_match("#<{$tag}[^>]*>(.*?)</{$tag}>#is", $chunk, $x)) return '';
                $v = trim($x[1]);
                $v = preg_replace('/^<!\[CDATA\[(.*)\]\]>$/s', '$1', $v);
                return trim(html_entity_decode(strip_tags($v), ENT_QUOTES, 'UTF-8'));
            };
            $t = $get('title');
            $l = $get('link');
            if ($t !== '' && $l !== '') $items[] = ['title' => $t, 'link' => $l, 'desc' => mb_substr($get('description'), 0, 400)];
        }
    }
    return $items;
}

function dd_keyword_hits(string $text): int {
    $t = mb_strtolower($text);
    $hits = 0;
    foreach (DD_KEYWORDS as $kw) if (str_contains($t, $kw)) $hits++;
    return $hits;
}

/** Harvest all feeds -> keyword filter -> queue as candidates (status=new). */
function discover_dramas_run(): array {
    $pdo = db();
    $seen = 0; $queued = 0; $dropped = 0; $deadFeeds = [];
    // LANE ROUTING (2026-08-22). Every feed item was inserted as 'drama',
    // including all 137 KnowYourMeme items — memes were being judged as news
    // stories and 133 of them were rejected on those grounds. That, not a
    // lack of supply, is why the meme lane has published nothing since June:
    // the topics arrive daily, in the wrong queue. KnowYourMeme is a meme
    // feed by definition, so its items start in the meme lane; anything
    // clearly about a game routes to gaming. Everything else is drama, as
    // before.
    $ins = $pdo->prepare("INSERT INTO candidates (type,name,angle,heat_score,era,status,signals)
                          VALUES (?,?,?,?,'present','new',?)");
    foreach (DD_FEEDS as $label => $url) {
        $xml = fs_http_get($url, 15);
        if (!$xml) { $deadFeeds[] = $label; continue; }
        foreach (dd_feed_items($xml) as $it) {
            $seen++;
            $hits = dd_keyword_hits($it['title'] . ' ' . $it['desc']);
            if ($hits < 1) { $dropped++; continue; }
            $lane = dd_lane_for($label, $it['title'] . ' ' . $it['desc']);
            // dedupe by title across ANY lane so a retitled route cannot restack
            $q = $pdo->prepare("SELECT 1 FROM candidates WHERE LOWER(name)=? LIMIT 1");
            $q->execute([mb_strtolower(mb_substr($it['title'], 0, 240))]);
            if ($q->fetch()) continue;
            $ins->execute([
                $lane,
                mb_substr($it['title'], 0, 240),
                "surfaced via {$label} feed",
                min(90, 40 + $hits * 10),       // keyword density = heat proxy; AI judges next
                json_encode(['source' => "rss:{$label}", 'url' => $it['link'], 'desc' => $it['desc']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $queued++;
        }
    }
    return ['seen' => $seen, 'queued' => $queued, 'dropped' => $dropped, 'dead_feeds' => $deadFeeds];
}
