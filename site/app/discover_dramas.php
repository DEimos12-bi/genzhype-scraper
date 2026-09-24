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
    // GAMING NEWS (2026-08-31, owner: "the community of gamers is real big as
    // hell, specially there's GTA 6 coming - we need to cover most news about
    // gaming"). Drama had four feeds; gaming had NONE, which is why the lane
    // only ever produced vocabulary pages. All seven verified live from this
    // server (item counts measured, not assumed).
    'dexerto-gaming' => 'https://www.dexerto.com/gaming/feed/',      // 50 items
    'rps'            => 'https://www.rockpapershotgun.com/feed',     // 87 items
    'pcgamer'        => 'https://www.pcgamer.com/rss/',              // 50 items
    'gamespot'       => 'https://www.gamespot.com/feeds/news/',      // 30 items
    'kotaku'         => 'https://kotaku.com/feed/rss',               // 20 items
    'vgc'            => 'https://www.videogameschronicle.com/feed/', // 10 items, leak-heavy
    'insidergaming'  => 'https://insider-gaming.com/feed/',          // 10 items, scoops
];

// Feeds that are gaming publications by construction. Items from these are
// on-topic without needing a creator-centric keyword (a GTA 6 delay mentions
// no streamer), and they default to the gaming world rather than to drama.
const DD_GAMING_FEEDS = ['dexerto-gaming', 'rps', 'pcgamer', 'gamespot',
                         'kotaku', 'vgc', 'insidergaming'];

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
    // 2026-08-31 THE ROUTING BUG THAT KILLED GAMING NEWS. 'gaming' means the
    // TERM engine - an encyclopedia entry answering "what does X mean?". A news
    // HEADLINE is not a word, so sending one there produced exactly this, live:
    //   term skip 'xQc furious after being forced to take down GTA 6 VOD by
    //   Twitch': off-lane term rejected by topical-fit gate
    // The word-builder was right to refuse it; the router should never have
    // handed it over. Shape decides: a short phrase can be vocabulary, a
    // sentence-shaped headline is a STORY and belongs to the timeline engine.
    $looksLikeHeadline = mb_strlen(trim($text)) > 46 || str_word_count($text) > 6;
    $gameWords = '/\\b(gta ?6|minecraft|fortnite|valorant|roblox|call of duty|warzone|'
               . 'league of legends|overwatch|speedrun|patch notes|dlc|esports|'
               . 'nintendo|playstation|xbox|steam deck|game ?play)\\b/i';
    if (preg_match($gameWords, $t) && !$looksLikeHeadline) { return 'gaming'; }
    // A gaming publication's headline is a gaming STORY: the timeline engine
    // covers it (that is where /drama/rockstar-faces-gta-6-leaks... came from).
    if (in_array($feed, DD_GAMING_FEEDS, true)) { return 'drama'; }
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
    require_once __DIR__ . '/desk.php';
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
        // 2026-08-31: seven gaming feeds at the default 15 items each would add
        // ~105 candidates an hour while the AI selector judges ~15 a tick - the
        // queue would outgrow the judge. Name-dedupe means steady state is only
        // genuinely NEW stories, but cap the gaming side anyway so one burst of
        // publishing cannot bury the drama queue behind it.
        $perFeed = in_array($label, DD_GAMING_FEEDS, true) ? 6 : 15;
        foreach (dd_feed_items($xml, $perFeed) as $it) {
            $seen++;
            // THE DESK (2026-09-05): every raw item also enters the one intake,
            // BEFORE this door's keyword screen, so the general judge sees
            // what the door drops. Non-fatal; shadow until app/DESK_LIVE exists.
            desk_signal($pdo, 'rss:' . $label, $it['title'], $it['link'], '', 'rss');
            $hits = dd_keyword_hits($it['title'] . ' ' . $it['desc']);
            // 2026-08-31: the keyword list is creator-centric (tiktok, streamer,
            // youtuber...). A real gaming headline - "GTA 6 delayed to November
            // 2027" - names none of them and would be dropped as noise. A gaming
            // PUBLICATION is on-topic by construction, exactly as KnowYourMeme is
            // for memes, so its items skip the noise cut and keep a floor heat
            // that clears the build queue.
            $fromGamingFeed = in_array($label, DD_GAMING_FEEDS, true);
            if ($hits < 1 && !$fromGamingFeed) { $dropped++; continue; }
            $lane = dd_lane_for($label, $it['title'] . ' ' . $it['desc']);
            // dedupe by title across ANY lane so a retitled route cannot restack
            $q = $pdo->prepare("SELECT 1 FROM candidates WHERE LOWER(name)=? LIMIT 1");
            $q->execute([mb_strtolower(mb_substr($it['title'], 0, 240))]);
            if ($q->fetch()) continue;
            $ins->execute([
                $lane,
                mb_substr($it['title'], 0, 240),
                "surfaced via {$label} feed",
                min(90, ($fromGamingFeed ? 55 : 40) + $hits * 10),   // keyword density = heat proxy; AI judges next
                json_encode(['source' => "rss:{$label}", 'url' => $it['link'], 'desc' => $it['desc']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $queued++;
        }
    }
    // THE SCOUT's drama ears (2026-08-30, owner: "grow the circle... the agent
    // working on all 4 and feeding them with data"). The listening harvest now
    // includes r/LivestreamFail, r/youtubedrama, r/GamingLeaksAndRumours —
    // story subs, not vocabulary subs — so their posts enter HERE as drama/
    // gaming candidates through the exact same keyword screen, dedupe and
    // AI-select the RSS feeds go through. Nothing skips the line.
    $cache = json_decode((string)@file_get_contents(__DIR__ . '/reach_cache.json'), true);
    foreach ((array)($cache['scout_posts'] ?? []) as $p) {
        $sub = mb_strtolower((string)($p['sub'] ?? ''));
        if (!in_array($sub, ['livestreamfail', 'youtubedrama', 'gamingleaksandrumours', 'gta6'], true)) continue;
        $title = trim(mb_substr(preg_replace('/\s+/u', ' ', (string)($p['text'] ?? '')), 0, 240));
        if ($title === '') continue;
        $seen++;
        desk_signal($pdo, 'scout:reddit:' . $sub, $title, (string)($p['permalink'] ?? ''), (string)($p['author'] ?? ''), 'reddit',
                    !empty($p['created_utc']) ? date('Y-m-d H:i:s', (int)$p['created_utc']) : null);
        $hits = dd_keyword_hits($title);
        if ($hits < 1) { $dropped++; continue; }
        $lane = dd_lane_for('reddit-' . $sub, $title);
        $q = $pdo->prepare("SELECT 1 FROM candidates WHERE LOWER(name)=? LIMIT 1");
        $q->execute([mb_strtolower($title)]);
        if ($q->fetch()) continue;
        $ins->execute([
            $lane,
            $title,
            "surfaced via r/{$sub} (scout listening)",
            min(90, 40 + $hits * 10),
            json_encode(['source' => "scout:r/{$sub}",
                         'url' => 'https://www.reddit.com' . (string)($p['permalink'] ?? ''),
                         'ups' => (int)($p['ups'] ?? 0)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $queued++;
    }
    return ['seen' => $seen, 'queued' => $queued, 'dropped' => $dropped, 'dead_feeds' => $deadFeeds];
}
