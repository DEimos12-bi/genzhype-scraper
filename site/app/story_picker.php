<?php
/**
 * THE STORY PICKER RULES (owner 2026-09-30).
 *
 * Three problems, confirmed on live pages:
 *   - old stories: a fresh r/GTA6 post about a "crop duster" screenshot became a page on the August leak (41 days old),
 *     because the search found only August articles; a Times of India article from April, resurfaced by Google News,
 *     became a RaKai page 6 months late. The queue kept only the day we FOUND an item, never its own date.
 *   - repeats: 4 pages on the GTA 6 leak saga; the copy check shortlisted pages by title words and exact event days,
 *     so pages about the same people and the same events with other words were never compared.
 *   - single-source builds: "need 2 sources" counted any readable page (the same outlet twice, Wikipedia, posts).
 *
 * Before a queued story is written, it must pass, in this order:
 *   1. QUEUE AGE: an entry more than 14 days in the queue expires (rule 6).
 *   2. NEW: an item about it dated in the last 72 hours. The date is the evidence's own (the article's publish date,
 *      the post's time), never the day we found it (rule 1).
 *   3. TOPIC: only articles and posts about the candidate's own topic count, not ones about the same game or person
 *      (the crop duster screenshot, not the August leak) (rule 4).
 *   4. SOURCES: 2+ independent outlets, or 1 outlet + the original post. Different sites only; Wikipedia and other
 *      reference sites are not outlets; a syndicated copy counts once; a post on X/TikTok/Reddit/YouTube is original
 *      evidence, not an outlet (rule 7). Short of that the story waits on the WATCH LIST: re-checked after 24h, dropped
 *      after 48h if nobody else covers it (rule 3).
 *   5. SAGA: a new chapter of a story we already have (the same people or company AND the same chain of events) is
 *      added to that page as a new event, never made a new page (rule 2).
 * After it is written, the page must still be about that topic and carry an event dated in the last 72 hours.
 * Every decision is logged in story_decisions and counted weekly (built / merged / watched / dropped / expired).
 *
 * Switched on by the file app/PICKER_ON (cli.php picker on|off). Off, the old picker runs untouched; the article's own
 * date is saved on every new queue entry either way (candidates.item_date).
 */

const SP_NEW_HOURS          = 72;   // [owner] a story is new only with a dated item in the last 72 hours
const SP_WATCH_RECHECK_H    = 24;   // [owner] a story short of sources is re-checked after 24 hours
const SP_WATCH_DROP_H       = 48;   // [owner] and dropped after 48 hours if nobody else covers it
const SP_QUEUE_MAX_DAYS     = 14;   // [owner] a queue entry older than this expires
const SP_MIN_OUTLETS        = 2;    // [owner] 2+ independent outlets, or 1 outlet + the original post
const SP_OUTLET_DAYS        = 7;    // [ours] an outlet counts when it wrote about it in the last 7 days (a Sep 4 article is not coverage of a Sep 30 story)
const SP_WATCH_DROP_HIGH_H  = 168;  // [owner, step 2] a high-score story on the watch list is kept a week, never dropped for want of a second outlet in 48h
const SP_WATCH_RECHECK_HIGH_H = 6; // [owner, step 2] and read again after 6 hours, not 24
const SP_SYNDICATED_SIMILAR = 0.75; // [ours] two headlines sharing this share of their words are one syndicated story
/** The picker's AI readings: the reader chain, then Gemma (14,400 a day on our Gemini key) and OpenRouter's free models
    (2026-09-30: the reader chain alone answered nothing while NVIDIA returned 503s). */
const SP_AI_ORDER = ['groq', 'nvidia', 'nvidia_director', 'gemini', 'openrouter'];   // Groq first: 0.5 s an answer vs NVIDIA's 20-30 s (2026-09-30)
function sp_ai_skip(): array { require_once __DIR__ . '/ai.php'; return array_values(array_diff(AI_READER_SKIP, ['gemini/gemma-4-31b-it'])); }

/** Hosts whose links are original evidence (a post by a person or an account), never an outlet. */
const SP_POST_HOSTS = ['x.com', 'twitter.com', 'tiktok.com', 'instagram.com', 'youtube.com', 'youtu.be', 'reddit.com',
    'redd.it', 'twitch.tv', 'kick.com', 'bsky.app', 'threads.net', 'threads.com', 'facebook.com', 'fb.watch',
    'snapchat.com', 'discord.com', 't.me', 'patreon.com', 'streamable.com', 'tumblr.com'];
/** Reference sites: not coverage of a news event. */
const SP_REFERENCE_HOSTS = ['wikipedia.org', 'wikimedia.org', 'wiktionary.org', 'wikiwand.com', 'fandom.com',
    'knowyourmeme.com', 'imdb.com', 'urbandictionary.com', 'britannica.com', 'genius.com', 'steamdb.info'];
/** Aggregators and archives: they carry other outlets' articles, so they are copies, never an outlet of their own. */
const SP_COPY_HOSTS = ['news.google.com', 'msn.com', 'yahoo.com', 'aol.com', 'inkl.com', 'newsbreak.com', 'ground.news',
    'flipboard.com', 'smartnews.com', 'headtopics.com', 'newsnow.co.uk', 'newsnow.com', 'apple.news', 'pressreader.com',
    'muckrack.com', 'archive.org', 'archive.ph', 'archive.today', 'feedly.com', 'bing.com', 'newsbeezer.com'];

function sp_on(): bool { return is_file(__DIR__ . '/PICKER_ON'); }

/** Columns and the decision log (never inside a transaction: an ALTER commits it). */
function sp_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $cols = array_column($pdo->query("SHOW COLUMNS FROM candidates")->fetchAll(PDO::FETCH_ASSOC), 'Type', 'Field');
    if (!isset($cols['item_date']))        $pdo->exec("ALTER TABLE candidates ADD COLUMN item_date DATETIME NULL COMMENT 'the article or post own date (UTC), not the day we found it'");
    if (!isset($cols['watch_since']))      $pdo->exec("ALTER TABLE candidates ADD COLUMN watch_since DATETIME NULL");
    if (!isset($cols['picker_checked_at'])) $pdo->exec("ALTER TABLE candidates ADD COLUMN picker_checked_at DATETIME NULL");
    if (isset($cols['status']) && !str_contains((string)$cols['status'], "'watch'"))
        $pdo->exec("ALTER TABLE candidates MODIFY status ENUM('new','selected','rejected','fetched','drafted','failed','watch') NOT NULL DEFAULT 'new'");
    $dcols = array_column($pdo->query("SHOW COLUMNS FROM desk_signals")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('item_date', $dcols, true)) $pdo->exec("ALTER TABLE desk_signals ADD COLUMN item_date DATETIME NULL");
    $pdo->exec("CREATE TABLE IF NOT EXISTS story_decisions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cand_id INT UNSIGNED NOT NULL,
        decision ENUM('build','merge','watch','drop','expire','hold') NOT NULL,
        rule VARCHAR(40) NOT NULL,
        detail VARCHAR(500) NULL,
        page_id INT UNSIGNED NULL,
        decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_when (decided_at), KEY idx_cand (cand_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function sp_log(PDO $pdo, int $candId, string $decision, string $rule, string $detail, ?int $pageId = null): void {
    try {
        $pdo->prepare("INSERT INTO story_decisions (cand_id, decision, rule, detail, page_id) VALUES (?,?,?,?,?)")
            ->execute([$candId, $decision, $rule, mb_substr($detail, 0, 500), $pageId]);
    } catch (Throwable $e) { error_log('sp_log: ' . $e->getMessage()); }
}

/* ---------------------------------------------------------------------------------------------------------------
 * SITES AND DATES
 * ------------------------------------------------------------------------------------------------------------- */

function sp_host(string $url): string {
    if (str_starts_with($url, '/r/')) return 'reddit.com';   // desk keeps Reddit permalinks without the host
    return preg_replace('/^(www\.|m\.|mobile\.|old\.|amp\.)/', '', strtolower((string)parse_url($url, PHP_URL_HOST)));
}

/** The site a host belongs to (me.mashable.com and mashable.com are one outlet; bbc.co.uk keeps its 3 parts). */
function sp_site(string $host): string {
    $p = explode('.', $host);
    $n = count($p);
    if ($n <= 2) return $host;
    $two = $p[$n - 2] . '.' . $p[$n - 1];
    $multi = ['co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'com.au', 'net.au', 'co.nz', 'co.in', 'co.jp', 'co.kr', 'com.br',
              'com.mx', 'co.za', 'com.sg', 'com.tr', 'com.ar', 'com.ph', 'com.my', 'co.id'];
    return in_array($two, $multi, true) ? $p[$n - 3] . '.' . $two : $two;
}

/** outlet | post | reference | copy */
function sp_kind(string $url): string {
    $site = sp_site(sp_host($url));
    if ($site === '') return 'copy';
    if (in_array($site, SP_POST_HOSTS, true)) return 'post';
    if (in_array($site, SP_REFERENCE_HOSTS, true)) return 'reference';
    if (in_array($site, SP_COPY_HOSTS, true) || in_array(sp_host($url), SP_COPY_HOSTS, true)) return 'copy';
    return 'outlet';
}

/** A post's own time from its id, when the platform puts it there (X: snowflake; TikTok: first 32 bits). '' otherwise. */
function sp_post_time(string $url): string {
    if (preg_match('#(?:x|twitter)\.com/[^/]+/status(?:es)?/(\d{15,20})#i', $url, $m)) {
        $ms = (intdiv((int)$m[1], 1 << 22)) + 1288834974657;
        return gmdate('Y-m-d H:i:s', intdiv($ms, 1000));
    }
    if (preg_match('#tiktok\.com/@[^/]+/(?:video|photo)/(\d{18,20})#i', $url, $m)) {
        return gmdate('Y-m-d H:i:s', intdiv((int)$m[1], 1 << 32));
    }
    return '';
}

/** Any date string to 'Y-m-d H:i:s' UTC; a bare day is noon of that day; '' when unreadable or before 2005. */
function sp_date(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) $s .= ' 12:00:00';
    $t = strtotime($s . (preg_match('/[a-z]{3}|[+-]\d{2}:?\d{2}$|Z$/i', $s) ? '' : ' UTC'));
    return ($t && $t > 1104537600) ? gmdate('Y-m-d H:i:s', $t) : '';
}

/** Headline words for the syndication test. */
function sp_title_words(string $t): array {
    $t = mb_strtolower(preg_replace('/\s+[-|–—]\s+[^-|–—]{2,40}$/u', '', $t));   // " - Site Name" suffix
    $w = preg_split('/[^\p{L}\p{N}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique(array_filter($w, fn($x) => mb_strlen($x) > 2)));
}

/**
 * Independent outlets among items (rule 7). $items: [['url', 'title', 'kind'?], ...]. Different sites only, one per
 * site; a headline another counted site already ran (syndication) counts once; posts, reference sites and aggregator
 * copies are not outlets. ['outlets' => n, 'sites' => [...], 'posts' => n]
 */
function sp_count_outlets(array $items): array {
    $sites = []; $heads = []; $posts = 0;
    foreach ($items as $it) {
        $kind = $it['kind'] ?? sp_kind((string)$it['url']);
        if ($kind === 'post') { $posts++; continue; }
        if ($kind !== 'outlet') continue;
        $site = sp_site(sp_host((string)$it['url']));
        if (isset($sites[$site])) continue;
        $w = sp_title_words((string)($it['title'] ?? ''));
        $copy = false;
        foreach ($heads as $hw) {
            $inter = count(array_intersect($w, $hw)); $union = count(array_unique(array_merge($w, $hw)));
            if ($w && $union && $inter / $union >= SP_SYNDICATED_SIMILAR) { $copy = true; break; }
        }
        if ($copy) continue;
        $sites[$site] = true; $heads[] = $w;
    }
    return ['outlets' => count($sites), 'sites' => array_keys($sites), 'posts' => $posts];
}

/* ---------------------------------------------------------------------------------------------------------------
 * THE DECISION (pure: facts in, decision out; the live picker, the backtest and the tests all use it)
 * ------------------------------------------------------------------------------------------------------------- */

/**
 * $f: now, found_at (the day we found it), watch_since (null|datetime), queue_expiry (bool: apply rule 6),
 *     items: [['url', 'title', 'date', 'kind', 'on_topic' => bool, 'seed' => bool]], saga (page id or 0), saga_title.
 * Only items dated at or before 'now' are counted (the backtest replays a past moment).
 * ['decision' => build|merge|watch|drop|expire, 'rule' => ..., 'why' => ..., 'outlets' => n, 'posts' => n, 'age_h' => n]
 */
function sp_decide(array $f): array {
    $now = strtotime((string)$f['now']);
    $lane = (string)($f['lane'] ?? 'normal');   // step 2 (idea_score.php): 'high' relaxes the paperwork below, never the age rule
    $found = strtotime((string)$f['found_at']);
    if (!empty($f['queue_expiry']) && $found && $now - $found > SP_QUEUE_MAX_DAYS * 86400)
        return ['decision' => 'expire', 'rule' => 'queue_age', 'why' => 'over ' . SP_QUEUE_MAX_DAYS . ' days in the queue (found ' . gmdate('M j', $found) . ')', 'outlets' => 0, 'posts' => 0, 'age_h' => null];
    $known = array_values(array_filter((array)$f['items'], fn($i) => ($i['date'] ?? '') === '' || strtotime($i['date']) <= $now + 3600));
    $topic = array_values(array_filter($known, fn($i) => !empty($i['on_topic']) || !empty($i['seed'])));
    // 1. NEW: the age is the evidence's own date. With original posts about it (X, TikTok, Reddit...), the posts date the
    //    story (a site writing up a June TikTok in September is a June story); without any, the articles do.
    $dated = fn(array $list) => array_values(array_filter(array_map(fn($i) => ($i['date'] ?? '') !== '' ? strtotime($i['date']) : 0, $list)));
    $postDates = $dated(array_filter($topic, fn($i) => ($i['kind'] ?? sp_kind((string)$i['url'])) === 'post'));
    $dates = $postDates ?: $dated($topic);
    $what = $postDates ? 'original post' : 'article';
    // the AI's reading, when there is one: when the development it reports HAPPENED (a September article on a June TikTok
    // -> June), never later than the newest dated item found; and the earliest evidence, for the story's age
    $ev = !empty($f['event_date']) ? strtotime((string)$f['event_date']) : 0;
    // the newer of the AI's date and the story's own newest post (Pikachu: the AI gave the June launch, its own posts were 2 days old)
    if ($ev && $dated($topic)) { $dates = [max(min($ev, max($dated($topic))), $postDates ? max($postDates) : 0)]; $what = 'development'; }
    if (!empty($f['first_evidence']) && ($fe = strtotime((string)$f['first_evidence'])) && $dates) $dates[] = min($fe, max($dates));
    $ageH = $dates ? (int)round(($now - min($dates)) / 3600) : null;
    $waited = !empty($f['watch_since']) ? $now - strtotime((string)$f['watch_since']) : 0;
    if (!$dates) {
        // nothing dated found (a search that came back empty looks the same): it waits and is read again, then dropped
        if ($waited < SP_WATCH_RECHECK_H * 3600)
            return ['decision' => 'watch', 'rule' => 'age_unknown', 'why' => 'no dated article or post about it yet: read again in ' . SP_WATCH_RECHECK_H . 'h', 'outlets' => 0, 'posts' => 0, 'age_h' => null];
        return ['decision' => 'drop', 'rule' => 'age', 'why' => 'no dated article or post about it', 'outlets' => 0, 'posts' => 0, 'age_h' => null];
    }
    $newest = max($dates);
    // "old" on one reading, while 2+ outlets wrote about it in the last 72 hours: one more reading in 24h before it is dropped
    if ($now - $newest > SP_NEW_HOURS * 3600 && !$waited) {
        $fresh = sp_count_outlets(array_filter($topic, fn($i) => ($i['kind'] ?? sp_kind((string)$i['url'])) !== 'post' && ($i['date'] ?? '') !== '' && strtotime($i['date']) >= $now - SP_NEW_HOURS * 3600));
        if ($fresh['outlets'] >= SP_MIN_OUTLETS)
            return ['decision' => 'watch', 'rule' => 'age_recheck', 'why' => 'reads as old (' . gmdate('M j, Y', $newest) . ") but {$fresh['outlets']} outlets wrote about it in the last " . SP_NEW_HOURS . 'h: read again in ' . SP_WATCH_RECHECK_H . 'h before dropping', 'outlets' => $fresh['outlets'], 'posts' => 0, 'age_h' => $ageH];
    }
    if ($now - $newest > SP_NEW_HOURS * 3600)
        return ['decision' => 'drop', 'rule' => 'age', 'why' => ($what === 'development' ? 'old story: what it reports happened on ' : "old story: its newest {$what} about it is from ") . gmdate('M j, Y', $newest) . ($postDates && count($dated($topic)) > count($postDates) ? ' (articles about it are newer)' : ''), 'outlets' => 0, 'posts' => 0, 'age_h' => $ageH];
    // 2+3. SOURCES about this topic: 2+ independent outlets that wrote about it lately, or 1 outlet + the original post.
    //      The original post is the story's own (the candidate itself, the posts it came with or its article embeds),
    //      never a discussion thread the search turned up.
    //      Owner 2026-10-01: that post must also be dated in the last 72 hours (Xbox passed on Kotaku plus an undated
    //      Reddit thread the article linked); an undated post proves nothing about when the story happened.
    $c = sp_count_outlets(array_filter($topic, function ($i) use ($now, $lane) {
        $t = ($i['date'] ?? '') !== '' ? strtotime($i['date']) : 0;
        // step 2: in the high lane every post about it counts, found or not (owner: "the posts count as proof")
        if (($i['kind'] ?? sp_kind((string)$i['url'])) === 'post') return ($lane === 'high' || ($i['from'] ?? '') !== 'search') && $t && $t >= $now - SP_NEW_HOURS * 3600;
        return !$t || $t >= $now - SP_OUTLET_DAYS * 86400;
    }));
    $sourced = $c['outlets'] >= SP_MIN_OUTLETS || ($c['outlets'] >= 1 && $c['posts'] >= 1) || ($lane === 'high' && $c['posts'] >= 2);
    if (!$sourced) {
        $since = !empty($f['watch_since']) ? strtotime((string)$f['watch_since']) : $now;
        $offTopic = count($known) - count($topic);
        $what = "{$c['outlets']} outlet(s)" . ($c['posts'] ? " + {$c['posts']} post(s)" : '') . ' about it'
              . ($offTopic > 0 ? " ({$offTopic} other result(s) were about something else)" : '');
        $dropH = $lane === 'high' ? SP_WATCH_DROP_HIGH_H : SP_WATCH_DROP_H;
        if ($now - $since >= $dropH * 3600)
            return ['decision' => 'drop', 'rule' => 'watch_expired', 'why' => "nobody else covered it in {$dropH}h: {$what}", 'outlets' => $c['outlets'], 'posts' => $c['posts'], 'age_h' => $ageH];
        if ($lane === 'high')
            return ['decision' => 'watch', 'rule' => 'sources_high', 'why' => "fast lane: {$what}; needs 2 outlets, 1 outlet + a post, or 2 posts; read again in " . SP_WATCH_RECHECK_HIGH_H . 'h, kept ' . (int)(SP_WATCH_DROP_HIGH_H / 24) . ' days', 'outlets' => $c['outlets'], 'posts' => $c['posts'], 'age_h' => $ageH];
        return ['decision' => 'watch', 'rule' => 'sources', 'why' => "{$what}; needs 2 outlets, or 1 outlet + the original post", 'outlets' => $c['outlets'], 'posts' => $c['posts'], 'age_h' => $ageH];
    }
    // 4. SAGA: a new chapter goes on the page we already have
    if (!empty($f['saga']))
        return ['decision' => 'merge', 'rule' => 'saga', 'why' => 'new chapter of "' . mb_substr((string)($f['saga_title'] ?? ''), 0, 90) . '": add the event there', 'outlets' => $c['outlets'], 'posts' => $c['posts'], 'age_h' => $ageH, 'page_id' => (int)$f['saga']];
    return ['decision' => 'build', 'rule' => $lane === 'high' ? 'ok_high' : 'ok', 'why' => ($lane === 'high' ? 'fast lane: ' : '') . "{$c['outlets']} outlet(s) (" . implode(', ', $c['sites']) . ')' . ($c['posts'] ? " + {$c['posts']} post(s)" : ''), 'outlets' => $c['outlets'], 'posts' => $c['posts'], 'age_h' => $ageH];
}

/** After writing: an event dated in the last 72 hours (the page tells something new). */
function sp_events_fresh(array $eventDates, string $now): bool {
    $t = strtotime($now);
    foreach ($eventDates as $d) {
        $d = (string)$d;
        if (!preg_match('/^\d{4}-\d{2}-(\d{2})/', $d, $m) || $m[1] === '00') continue;   // a month-only date is not a dated event
        $e = strtotime(substr($d, 0, 10) . ' 12:00:00 UTC');
        if ($e <= $t + 86400 && $t - $e <= SP_NEW_HOURS * 3600 + 43200) return true;   // a bare day: +-12h
    }
    return false;
}

/* ---------------------------------------------------------------------------------------------------------------
 * GATHERING THE FACTS (network + database)
 * ------------------------------------------------------------------------------------------------------------- */

/** Bing News results with their own dates and outlets: [['url', 'title', 'desc', 'date', 'source']]. */
function sp_news_items(string $query, int $max = 10): array {
    require_once __DIR__ . '/fetch_sources.php';
    $xml = fs_http_get('https://www.bing.com/news/search?q=' . urlencode($query) . '&format=rss&setlang=en-us&cc=us', 15);
    if (!$xml || !preg_match_all('#<item>(.*?)</item>#is', $xml, $m)) return [];
    $out = [];
    foreach ($m[1] as $chunk) {
        $g = function (string $tag) use ($chunk): string {
            if (!preg_match('#<' . $tag . '[^>]*>(.*?)</' . $tag . '>#is', $chunk, $x)) return '';
            return trim(html_entity_decode(strip_tags(preg_replace('/^<!\[CDATA\[(.*)\]\]>$/s', '$1', trim($x[1]))), ENT_QUOTES, 'UTF-8'));
        };
        $link = $g('link');
        if (preg_match('#[?&]url=([^&]+)#', $link, $u)) $link = urldecode($u[1]);
        if (!filter_var($link, FILTER_VALIDATE_URL)) continue;
        $out[] = ['url' => $link, 'title' => $g('title'), 'desc' => mb_substr($g('description'), 0, 240),
                  'date' => sp_date($g('pubDate')), 'source' => $g('News:Source')];
        if (count($out) >= $max) break;
    }
    return $out;
}

/** The candidate's own headline as search words: no site suffix, links, quotes or markup, its first 10 words. */
function sp_headline_query(array $cand): string {
    $h = html_entity_decode((string)$cand['name'], ENT_QUOTES, 'UTF-8');
    $h = preg_replace('/\s+[-|–—]\s+[^-|–—]{2,30}$/u', '', $h);
    $h = preg_replace('/https?:\/\/\S+|\[[^\]]*\]\([^)]*\)|[“”"‘’\'`*]/u', ' ', $h);
    return implode(' ', array_slice(preg_split('/\s+/', trim($h)), 0, 10));
}

/** The search words for a candidate, without the outlet names that pulled back the same outlet ("... Kotaku"). */
function sp_query(array $cand): string {
    $v = json_decode((string)($cand['ai_verdict'] ?? ''), true) ?: [];
    $q = trim((string)($v['search_query'] ?? '')) ?: (string)$cand['name'];
    $outlets = ['kotaku', 'dexerto', 'reddit', 'daily dot', 'dailydot', 'insider gaming', 'pc gamer', 'pcgamer', 'ign',
                'polygon', 'eurogamer', 'gamerant', 'game rant', 'the verge', 'tmz', 'sportskeeda', 'times of india', 'vgc',
                'livestreamfail', 'r/', 'twitter', 'x.com', 'youtube', 'tiktok', 'notebookcheck', 'insider-gaming'];
    foreach ($outlets as $o) $q = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($o, '/') . '(?![\p{L}\p{N}])/iu', ' ', $q);
    $q = trim(preg_replace('/\s+/', ' ', $q));
    return $q !== '' ? mb_substr($q, 0, 120) : mb_substr((string)$cand['name'], 0, 120);
}

/**
 * Everything known about a candidate before any AI reading: its own item (with its own date), the posts it came with
 * (desk evidence, dated by the post), the posts its article embeds (dated by their ids) and the news search results.
 * $fetchSeed: read the candidate's own article (1 request) for its date and embedded posts.
 */
function sp_gather(PDO $pdo, array $cand, bool $fetchSeed = true): array {
    require_once __DIR__ . '/fetch_sources.php';
    $sg = json_decode((string)($cand['signals'] ?? ''), true) ?: [];
    $items = [];
    $seedUrl = (string)($sg['url'] ?? '');
    if (str_starts_with($seedUrl, '/r/')) $seedUrl = 'https://www.reddit.com' . $seedUrl;
    $seedDate = sp_date((string)($cand['item_date'] ?? ''));
    $embedded = [];
    if ($seedUrl !== '' && filter_var($seedUrl, FILTER_VALIDATE_URL)) {
        $kind = sp_kind($seedUrl);
        if ($seedDate === '') $seedDate = sp_post_time($seedUrl);
        if ($seedDate === '' && $kind === 'post') {   // a Reddit post: the listener kept its created time (desk_signals.seen_at)
            $dq = $pdo->prepare("SELECT COALESCE(item_date, seen_at) FROM desk_signals WHERE url IN (?, ?) AND origin LIKE 'scout:reddit:%' ORDER BY seen_at LIMIT 1");
            $dq->execute([(string)parse_url($seedUrl, PHP_URL_PATH), $seedUrl]);
            $seedDate = sp_date((string)$dq->fetchColumn());
        }
        if ($fetchSeed && $kind === 'outlet') {
            $html = fs_http_get($seedUrl, 12);
            if ($html) {
                if ($seedDate === '') $seedDate = sp_date(fs_published_date($html));
                $seedDesc = mb_substr(trim(preg_replace('/\s+/u', ' ', fs_extract_text($html))), 0, 400);
                foreach (fs_harvest_social($html, 4) as $soc) {
                    // only a post we can read: its words are what the AI judges and what the writer cites (2026-10-01, first
                    // live run: a deleted X post counted as "the story's own post" and the writer then had one source)
                    $ptxt = fs_social_excerpt((string)$soc['provider'], (string)$soc['url']);
                    if ($ptxt === '') continue;
                    $embedded[] = ['url' => $soc['url'], 'title' => mb_substr($ptxt, 0, 220), 'date' => sp_post_time($soc['url']),
                                   'kind' => 'post', 'on_topic' => true, 'seed' => false, 'from' => 'embed'];
                }
            }
        }
        if ($seedDate !== '' && !empty($cand['created_at']) && strtotime($seedDate) > strtotime((string)$cand['created_at'])) $seedDate = sp_date((string)$cand['created_at']);   // updated later: not after the day we found it
        $items[] = ['url' => $seedUrl, 'title' => (string)$cand['name'], 'desc' => $seedDesc ?? '', 'date' => $seedDate, 'kind' => $kind === 'copy' ? 'copy' : $kind,
                    'on_topic' => true, 'seed' => true, 'from' => 'seed'];
    }
    // the posts the desk heard about it, dated by the post (Reddit created_utc) where the fetcher knew it
    if (!empty($sg['desk']['cluster'])) {
        $ds = $pdo->prepare("SELECT url, text, platform, seen_at, item_date FROM desk_signals WHERE cluster_id=? ORDER BY seen_at LIMIT 8");
        try { $ds->execute([(int)$sg['desk']['cluster']]); } catch (Throwable $e) { $ds = null; }
        foreach ($ds ? $ds->fetchAll(PDO::FETCH_ASSOC) : [] as $s) {
            $u = (string)$s['url'];
            if (str_starts_with($u, '/r/')) $u = 'https://www.reddit.com' . $u;
            if ($u === '' || $u === $seedUrl) {
                if ($u === $seedUrl && $seedDate === '' && $items) $items[0]['date'] = sp_date((string)($s['item_date'] ?: $s['seen_at']));
                continue;
            }
            $items[] = ['url' => $u, 'title' => (string)$s['text'], 'date' => sp_date((string)($s['item_date'] ?: $s['seen_at'])),
                        'kind' => sp_kind($u), 'on_topic' => true, 'seed' => false, 'from' => 'desk'];
        }
    }
    foreach ($embedded as $e) $items[] = $e;
    $have = array_flip(array_column($items, 'url'));
    // the editor's search words sometimes find nothing ("Microsoft Flight Simulator expensive plane waste simulation":
    // 0 results, 2026-09-30 backtest): with fewer than 3 results the story's own headline is searched too
    $found = sp_news_items($q1 = sp_query($cand), 10);
    if (count($found) < 3 && ($q2 = sp_headline_query($cand)) !== '' && $q2 !== $q1) foreach (sp_news_items($q2, 10) as $r) $found[] = $r;
    foreach ($found as $r) {
        if (isset($have[$r['url']])) { continue; }
        $items[] = ['url' => $r['url'], 'title' => $r['title'], 'desc' => $r['desc'], 'date' => $r['date'], 'kind' => sp_kind($r['url']),
                    'on_topic' => false, 'seed' => false, 'from' => 'search', 'source' => $r['source']];
        $have[$r['url']] = 1;
    }
    return $items;
}

/** Names of the people, companies and games in a candidate: the editor's list, then capitalised runs of its headline. */
function sp_names(array $cand): array {
    $v = json_decode((string)($cand['ai_verdict'] ?? ''), true) ?: [];
    $names = array_map('strval', (array)($v['primary_people'] ?? []));
    $text = (string)$cand['name'] . ' ' . (string)($v['angle'] ?? '');
    if (preg_match_all('/\b(?:[A-Z][\p{L}\'’.&-]*|[A-Z0-9]{2,})(?:\s+(?:[A-Z][\p{L}\'’.&-]*|[A-Z0-9]{1,}|of|the|&))*\b/u', $text, $m))
        foreach ($m[0] as $x) $names[] = $x;
    $stop = ['The', 'A', 'An', 'This', 'New', 'After', 'Before', 'Why', 'How', 'What', 'TikToker', 'Streamer', 'YouTuber',
             'Creator', 'Fans', 'Players', 'Reddit', 'Twitter', 'TikTok', 'YouTube', 'Twitch', 'Viewers', 'Says', 'Is', 'In', 'On'];
    $out = [];
    foreach ($names as $n) {
        $n = trim(preg_replace('/^(The|A|An)\s+/u', '', trim($n)), " .'’-");
        if (mb_strlen($n) < 3 || in_array($n, $stop, true) || is_numeric($n)) continue;
        $out[mb_strtolower($n)] = $n;
    }
    return array_values($out);
}

/**
 * Stories on the site that share a person, company or game with the candidate, best first (at most $limit):
 * the pages an AI reads to decide whether the candidate is a new chapter of one of them (rule 2).
 * $before: only pages that existed then (the backtest replays a past moment).
 */
function sp_saga_suspects(PDO $pdo, array $cand, string $before = '', int $limit = 4): array {
    require_once __DIR__ . '/dedupe.php';
    $names = sp_names($cand);
    if (!$names) return [];
    $words = dup_story_words((string)$cand['name']);
    $w = []; $args = [];
    foreach (array_slice($names, 0, 6) as $n) { $w[] = "(p.h1 LIKE ? OR d.title LIKE ? OR p.summary LIKE ?)"; array_push($args, "%{$n}%", "%{$n}%", "%{$n}%"); }
    $sql = "SELECT p.id, p.h1, p.summary, p.created_at, d.id did FROM pages p JOIN dramas d ON d.page_id=p.id
            WHERE p.type='drama' AND p.status IN ('published','review','draft') AND (" . implode(' OR ', $w) . ")"
         . ($before !== '' ? " AND p.created_at < ?" : '') . " ORDER BY p.created_at DESC LIMIT 60";
    if ($before !== '') $args[] = $before;
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $hay = mb_strtolower($r['h1'] . ' ' . $r['summary']);
        $shared = 0;
        foreach ($names as $n) if (str_contains($hay, mb_strtolower($n))) $shared++;
        $contain = $words ? dup_containment($words, dup_story_words((string)$r['h1'] . ' ' . mb_substr((string)$r['summary'], 0, 300))) : 0;
        $r['score'] = $shared * 2 + $contain * 3;
        $out[] = $r;
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    $out = array_slice($out, 0, $limit);
    $ev = $pdo->prepare("SELECT event_date, title FROM events WHERE drama_id=? AND video_only=0 ORDER BY event_date");
    foreach ($out as &$r) {
        $ev->execute([(int)$r['did']]);
        $evs = $ev->fetchAll(PDO::FETCH_ASSOC);
        $r['first'] = $evs ? $evs[0]['event_date'] : '';
        $r['last'] = $evs ? end($evs)['event_date'] : '';
        $r['latest'] = array_map(fn($e) => "[{$e['event_date']}] " . mb_substr((string)$e['title'], 0, 100), array_slice($evs, -3));
    }
    unset($r);
    return $out;
}

/**
 * One AI reading per candidate: which found items are about its own topic (rule 4), and whether it is a new chapter
 * of one of the suspect pages (rule 2). ['topic', 'on_topic' => [item index], 'saga' => page id|0, 'saga_title', 'why']
 * or ['error' => ...] (no answer: the candidate keeps its turn; nothing is decided on a guess).
 */
function sp_ai_read(array $cand, array $items, array $suspects): array {
    require_once __DIR__ . '/ai.php';
    $v = json_decode((string)($cand['ai_verdict'] ?? ''), true) ?: [];
    $list = '';
    foreach (array_slice($items, 0, 18) as $i => $it) {
        $list .= ($i + 1) . '. ' . ($it['seed'] ? '[THE CANDIDATE ITSELF] ' : '') . $it['kind'] . ' ' . sp_host((string)$it['url'])
               . ' ' . ($it['date'] !== '' ? substr($it['date'], 0, 10) : 'undated') . ' "' . mb_substr((string)$it['title'], 0, 140) . '"'
               . (!empty($it['desc']) ? ' - ' . mb_substr((string)$it['desc'], 0, !empty($it['seed']) ? 350 : 110) : '') . "\n";
    }
    $pages = '';
    $letters = range('A', 'Z');
    foreach ($suspects as $k => $s) {
        $pages .= $letters[$k] . '. "' . $s['h1'] . '" (events ' . ($s['first'] ?: '?') . ' to ' . ($s['last'] ?: '?') . '; latest: '
                . implode(' | ', $s['latest']) . ")\n";
    }
    $prompt = "A news site checks a story candidate before writing it.\n"
        . "CANDIDATE: \"" . $cand['name'] . "\"\nAngle: " . ((string)($v['angle'] ?? $cand['angle'] ?? '')) . "\n\n"
        . "ITEMS FOUND (number, kind, site, date, headline):\n{$list}\n"
        . ($pages !== '' ? "PAGES ALREADY ON THE SITE that share a person, company or game with it:\n{$pages}\n" : '')
        . "Answer in STRICT JSON:\n"
        . "{\"topic\": \"<the candidate's specific topic, max 12 words>\",\n"
        . " \"on_topic\": [<numbers of the items about THIS specific topic: the same event or development. An item about the same game, person or company but a different event is NOT on topic (e.g. a new screenshot vs last month's leak). A post (X, TikTok, Reddit, YouTube) is on topic only if it IS this story's own original post (what the story reports happened), not an older post an article cites as background>],\n"
        . " \"latest_event\": \"<YYYY-MM-DD: when the newest development this candidate reports HAPPENED, from the items (not when an article was published about it: an article written in September about a TikTok posted in June -> the June date)>\",\n"
        . " \"first_evidence\": \"<YYYY-MM-DD: the date of the earliest original post or evidence of this story>\",\n"
        . " \"saga\": \"<" . ($pages !== '' ? "letter of the page this candidate most likely CONTINUES (the same story, saga or dispute: a reply, a new filing, a ban or a result in it); empty if none is clearly the same story. A second, stricter check decides" : 'empty') . ">\",\n"
        . " \"why\": \"<max 20 words>\"}";
    $res = ai_chat([['role' => 'user', 'content' => $prompt]], $GLOBALS['SP_AI_ORDER'] ?? SP_AI_ORDER, 0.0, 60, sp_ai_skip());
    if (isset($res['error'])) return ['error' => (string)$res['error']];
    $j = ai_json((string)$res['content']);
    if (!is_array($j) || !isset($j['on_topic'])) return ['error' => 'unreadable answer'];
    $on = [];
    foreach ((array)$j['on_topic'] as $n) if (is_numeric($n) && (int)$n >= 1 && (int)$n <= count($items)) $on[] = (int)$n - 1;
    $saga = 0; $sagaTitle = '';
    $L = strtoupper(trim((string)($j['saga'] ?? '')));
    if ($L !== '' && ($k = array_search($L[0], $letters, true)) !== false && isset($suspects[$k])) { $saga = (int)$suspects[$k]['id']; $sagaTitle = (string)$suspects[$k]['h1']; }
    return ['topic' => mb_substr((string)($j['topic'] ?? ''), 0, 120), 'on_topic' => $on,
            'latest_event' => sp_date((string)($j['latest_event'] ?? '')), 'first_evidence' => sp_date((string)($j['first_evidence'] ?? '')), 'saga' => $saga, 'saga_title' => $sagaTitle,
            'why' => mb_substr((string)($j['why'] ?? ''), 0, 200), 'provider' => (string)($res['provider'] ?? '')];
}

/**
 * The strict second reading of a proposed merge: is the candidate the next development of the SAME EVENT the page is
 * about? ['same' => bool, 'event' => the event both are about, 'why'], or null when no model answered.
 */
function sp_saga_confirm(PDO $pdo, array $cand, array $items, array $read): ?array {
    require_once __DIR__ . '/ai.php';
    require_once __DIR__ . '/dedupe.php';
    $heads = [];
    foreach ($items as $k => $it) if (!empty($it['seed']) || in_array($k, (array)$read['on_topic'], true)) $heads[] = '- ' . mb_substr((string)$it['title'], 0, 140);
    $res = ai_chat([['role' => 'user', 'content' =>
        "NEW STORY: \"" . $cand['name'] . "\"\nIts topic: " . (string)($read['topic'] ?? '') . "\nHeadlines about it:\n" . implode("\n", array_slice($heads, 0, 6))
        . "\n\nPAGE ALREADY ON OUR SITE:\n" . dup_story_side_of($pdo, (int)$read['saga'])
        . "\nIs the new story the NEXT DEVELOPMENT OF THE SAME EVENT that page is about (the same leak, the same feud, the same lawsuit, the same ban, the same accusation, the same tournament)? "
        . "A follow-up INSIDE the same dispute or saga (a reply to it, a lawsuit threat over it, a ban or apology because of it, a new filing in the same case, the result of the same tournament) IS the same event. The same game, person, company or franchise alone is NOT enough: another patch, another feature, a review score, release times, system specs, a different leak or a different dispute are DIFFERENT events and get their own page. "
        . "STRICT JSON {\"same_event\": true|false, \"event\": \"<the one event both are about, max 10 words, empty if none>\", \"why\": \"<max 15 words>\"}"]],
        $GLOBALS['SP_AI_ORDER'] ?? SP_AI_ORDER, 0.0, 60, sp_ai_skip());
    $j = isset($res['error']) ? null : ai_json((string)$res['content']);
    if (!is_array($j) || !isset($j['same_event'])) return null;
    return ['same' => (bool)$j['same_event'] && trim((string)($j['event'] ?? '')) !== '', 'event' => mb_substr((string)($j['event'] ?? ''), 0, 120), 'why' => mb_substr((string)($j['why'] ?? ''), 0, 160)];
}

/**
 * The full check of one candidate at moment $now (live: now; backtest: a past moment). Reads what it needs (a search,
 * the candidate's own article, at most one AI reading) and returns sp_decide()'s answer plus what it read.
 * $gathered: items already read (the backtest reads once and replays three moments).
 */
function sp_evaluate(PDO $pdo, array $cand, string $now, ?array $gathered = null, ?array $read = null, string $before = '', string $sourcesBy = ''): array {
    $items = $gathered ?? sp_gather($pdo, $cand);
    $foundAt = (string)$cand['created_at'];
    $lane = (string)($cand['lane'] ?? 'normal');   // step 2 (sp_pick sets it from the idea's trend score)
    $f = ['now' => $now, 'found_at' => $foundAt, 'watch_since' => $cand['watch_since'] ?? null, 'queue_expiry' => true,
          'items' => $items, 'saga' => 0, 'lane' => $lane];
    // before any AI: an entry past the queue limit, or with nothing dated in the last 72 hours at all, is decided on dates
    $pre = sp_decide(array_merge($f, ['items' => []]));
    if ($pre['decision'] === 'expire') return $pre + ['items' => $items, 'read' => null];
    $all = array_values(array_filter(array_map(fn($i) => ($i['date'] ?? '') !== '' ? strtotime($i['date']) : 0, $items), fn($t) => $t && $t <= strtotime($now) + 3600));
    if (!$all) return sp_decide($f) + ['items' => $items, 'read' => null];   // nothing dated: waits 24h, then dropped (sp_decide)
    if (strtotime($now) - max($all) > SP_NEW_HOURS * 3600)
        return ['decision' => 'drop', 'rule' => 'age', 'why' => 'old story: nothing about it is dated after ' . gmdate('M j, Y', max($all)),
                'outlets' => 0, 'posts' => 0, 'age_h' => (int)round((strtotime($now) - min($all)) / 3600), 'items' => $items, 'read' => null];
    $others = array_filter($items, fn($i) => empty($i['seed']));
    // the AI reading only where it can change the answer: when even counting every result as on its topic the story
    // would not reach 2 outlets (or 1 + its own post) by $sourcesBy (the backtest: 48h later), it waits and is dropped
    // whatever the reading says (the topic test can only take sources away)
    $by = strtotime($sourcesBy !== '' ? $sourcesBy : $now);
    $nowT = strtotime($now);
    $couldRecent = array_filter($items, function ($i) use ($by, $nowT, $lane) {
        $t = ($i['date'] ?? '') !== '' ? strtotime($i['date']) : 0;
        if (($i['kind'] ?? '') === 'post') return ($lane === 'high' || ($i['from'] ?? '') !== 'search') && $t && $t <= $by + 3600 && $t >= $nowT - SP_NEW_HOURS * 3600;
        return !$t || ($t <= $by + 3600 && $t >= $by - SP_OUTLET_DAYS * 86400);
    });
    $cc = sp_count_outlets($couldRecent);
    $could = $cc['outlets'] >= SP_MIN_OUTLETS || ($cc['outlets'] >= 1 && $cc['posts'] >= 1) || ($lane === 'high' && $cc['posts'] >= 2);
    $suspects = ($read === null && $could) ? sp_saga_suspects($pdo, $cand, $before) : [];
    if ($read === null) {
        $read = ($could && ($others || $suspects)) ? sp_ai_read($cand, $items, $suspects)
              : ['topic' => (string)$cand['name'], 'on_topic' => [], 'saga' => 0, 'saga_title' => '',
                 'why' => $could ? 'nothing else found' : 'not enough outlets even counting every result: no AI reading needed', 'skipped' => !$could];
    }
    if (!empty($read['skipped'])) $others = [];   // no reading: the candidate's own posts keep counting, search results do not
    if (isset($read['error'])) return ['decision' => 'hold', 'rule' => 'ai', 'why' => 'no AI answer: ' . $read['error'], 'items' => $items, 'read' => $read];
    // a proposed merge is read a second time, strictly: only the same event merges (owner 2026-10-01; the first backtest
    // sent "Witcher 3 release times" onto a page about Roach's movement). No answer = it keeps its turn, nothing is guessed.
    if (!empty($read['saga']) && !isset($read['saga_checked'])) {
        $ok = sp_saga_confirm($pdo, $cand, $items, $read);
        if ($ok === null) return ['decision' => 'hold', 'rule' => 'ai', 'why' => 'no AI answer on the merge check', 'items' => $items, 'read' => ['error' => 'no answer on the merge check']];
        $read['saga_checked'] = true;
        if (!$ok['same']) { $read['saga_refused'] = $read['saga_title'] . ' (' . $ok['why'] . ')'; $read['saga'] = 0; $read['saga_title'] = ''; }
        else $read['saga_event'] = $ok['event'];
    }
    foreach ($items as $k => &$it) if (empty($it['seed']) && ($others || ($it['from'] ?? '') === 'search')) $it['on_topic'] = in_array($k, $read['on_topic'], true);
    unset($it);
    $f['items'] = $items;
    $f['saga'] = $read['saga']; $f['saga_title'] = $read['saga_title'];
    $f['event_date'] = (string)($read['latest_event'] ?? ''); $f['first_evidence'] = (string)($read['first_evidence'] ?? '');
    return sp_decide($f) + ['items' => $items, 'read' => $read];
}

/* ---------------------------------------------------------------------------------------------------------------
 * LIVE (only with app/PICKER_ON; called from the story build in cli.php)
 * ------------------------------------------------------------------------------------------------------------- */

const SP_CHECKS_PER_RUN = 6;   // [ours] candidates the picker reads per build run (a search + at most one AI reading each)
const SP_WATCH_PER_RUN  = 3;   // [ours] watch-list re-checks per build run

/** The candidate row with everything the picker reads. */
function sp_cand(PDO $pdo, int $id): ?array {
    $c = $pdo->prepare("SELECT * FROM candidates WHERE id=?");
    $c->execute([$id]);
    return $c->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Decide one queued story now, act on it and log it. build: ['decision' => 'build', 'urls' => the articles and posts
 * about its topic, to write from]; merge: the event goes on the existing page (drama_deepen_page); watch: status
 * 'watch' (re-checked after 24h); drop/expire: retired with the reason; hold: no AI answer, it keeps its turn.
 */
function sp_pick(PDO $pdo, array $cd): array {
    sp_install($pdo);
    $cand = sp_cand($pdo, (int)$cd['id']);
    if (!$cand) return ['decision' => 'hold', 'why' => 'candidate not found', 'urls' => []];
    $sg = json_decode((string)($cand['signals'] ?? ''), true) ?: [];
    // cleared in the last 3 hours (a watch-list re-check): write it from what that check found
    if (($sg['picker']['decision'] ?? '') === 'build' && strtotime((string)($sg['picker']['at'] ?? '')) > time() - 10800)
        return ['decision' => 'build', 'why' => (string)$sg['picker']['why'], 'urls' => (array)$sg['picker']['urls']];
    $now = gmdate('Y-m-d H:i:s');
    require_once __DIR__ . '/idea_score.php';
    $cand['lane'] = is_lane($pdo, (int)$cand['id']);   // step 2: 'high' from the idea's trend score, else 'normal'
    $r = sp_evaluate($pdo, $cand, $now);
    // owner (step 2): a high-score idea short of sources gets more work, not a drop: one more search, by the names in it
    if ($cand['lane'] === 'high' && $r['decision'] === 'watch' && ($r['rule'] ?? '') === 'sources_high') {
        $more = []; $have = array_flip(array_column($r['items'], 'url'));
        foreach (sp_news_items(mb_substr(implode(' ', array_slice(sp_names($cand), 0, 3)), 0, 120), 10) as $x)
            if (!isset($have[$x['url']])) { $more[] = ['url' => $x['url'], 'title' => $x['title'], 'desc' => $x['desc'], 'date' => $x['date'], 'kind' => sp_kind($x['url']), 'on_topic' => false, 'seed' => false, 'from' => 'search', 'source' => $x['source']]; $have[$x['url']] = 1; }
        if ($more) { $r2 = sp_evaluate($pdo, $cand, $now, array_merge($r['items'], $more)); if ($r2['decision'] !== 'hold') { $r = $r2; $r['why'] .= ' (after a second search)'; } }
    }
    $seed = array_values(array_filter($r['items'], fn($i) => !empty($i['seed'])))[0] ?? null;
    $urls = [];
    foreach ($r['items'] as $it) if ((!empty($it['on_topic']) || !empty($it['seed'])) && in_array($it['kind'], ['outlet', 'post'], true)) $urls[] = $it['url'];
    $sg['picker'] = ['decision' => $r['decision'], 'why' => $r['why'], 'at' => $now, 'urls' => array_slice($urls, 0, 8),
                     'topic' => (string)($r['read']['topic'] ?? ''), 'lane' => $cand['lane']];
    $pdo->prepare("UPDATE candidates SET picker_checked_at=?, signals=?, item_date=COALESCE(item_date, ?) WHERE id=?")
        ->execute([$now, json_encode($sg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ($seed && $seed['date'] !== '') ? $seed['date'] : null, $cand['id']]);
    $id = (int)$cand['id'];
    switch ($r['decision']) {
        case 'build':
            if ($cand['status'] === 'watch') $pdo->prepare("UPDATE candidates SET status='selected' WHERE id=?")->execute([$id]);
            sp_log($pdo, $id, 'build', $r['rule'], $r['why']);
            return ['decision' => 'build', 'why' => $r['why'], 'urls' => $sg['picker']['urls'], 'lane' => $cand['lane']];
        case 'merge':
            $page = (int)$r['page_id'];
            $added = '';
            try { require_once __DIR__ . '/drama_deepen.php'; $dd = drama_deepen_page($pdo, $page, true); $added = !empty($dd['ok']) ? 'event(s) added' : (string)($dd['why'] ?? 'nothing added'); }
            catch (Throwable $e) { $added = 'deepen failed: ' . $e->getMessage(); }
            $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason=? WHERE id=?")->execute([mb_substr("merged into page #{$page}: {$added}", 0, 255), $id]);
            sp_log($pdo, $id, 'merge', 'saga', $r['why'] . " ({$added})", $page);
            return ['decision' => 'merge', 'why' => $r['why'] . " ({$added})", 'urls' => []];
        case 'watch':
            $pdo->prepare("UPDATE candidates SET status='watch', watch_since=COALESCE(watch_since, ?) WHERE id=?")->execute([$now, $id]);
            // step 2: a high-lane story is read again after 6 hours, not 24 (the watch list re-check counts from picker_checked_at)
            if ($cand['lane'] === 'high') $pdo->prepare("UPDATE candidates SET picker_checked_at=DATE_SUB(?, INTERVAL ? HOUR) WHERE id=?")->execute([$now, SP_WATCH_RECHECK_H - SP_WATCH_RECHECK_HIGH_H, $id]);
            if ($cand['status'] !== 'watch') sp_log($pdo, $id, 'watch', $r['rule'], $r['why']);
            return ['decision' => 'watch', 'why' => $r['why'], 'urls' => []];
        case 'drop':
        case 'expire':
            $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason=? WHERE id=?")
                ->execute([mb_substr(($r['decision'] === 'expire' ? 'expired: ' : 'picker: ') . $r['why'], 0, 255), $id]);
            sp_log($pdo, $id, $r['decision'], $r['rule'], $r['why']);
            return ['decision' => $r['decision'], 'why' => $r['why'], 'urls' => []];
    }
    return ['decision' => 'hold', 'why' => $r['why'], 'urls' => []];   // no AI answer: it keeps its turn
}

/**
 * After writing: the page must still be about the candidate's topic and carry an event dated in the last 72 hours.
 * A page that fails is archived (off the site, nothing deleted) and never published. ['ok' => bool, 'why' => ...]
 */
function sp_page_check(PDO $pdo, int $pageId, int $candId): array {
    $cand = sp_cand($pdo, $candId);
    $topic = (string)((json_decode((string)($cand['signals'] ?? ''), true) ?: [])['picker']['topic'] ?? '') ?: (string)($cand['name'] ?? '');
    $p = $pdo->prepare("SELECT p.h1, p.summary, d.id did FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=?");
    $p->execute([$pageId]);
    $pg = $p->fetch(PDO::FETCH_ASSOC);
    if (!$pg) return ['ok' => true, 'why' => 'page not found'];
    $ev = $pdo->prepare("SELECT event_date, title FROM events WHERE drama_id=? AND video_only=0 ORDER BY sort_order");
    $ev->execute([(int)$pg['did']]);
    $events = $ev->fetchAll(PDO::FETCH_ASSOC);
    $why = '';
    if (!sp_events_fresh(array_column($events, 'event_date'), gmdate('Y-m-d H:i:s'))) {
        $why = 'no event dated in the last ' . SP_NEW_HOURS . ' hours (' . implode(', ', array_slice(array_unique(array_column($events, 'event_date')), 0, 4)) . ')';
        $rule = 'page_new';
    } else {
        require_once __DIR__ . '/ai.php';
        $list = implode("\n", array_map(fn($e) => "- [{$e['event_date']}] " . mb_substr((string)$e['title'], 0, 120), array_slice($events, 0, 10)));
        $res = ai_chat([['role' => 'user', 'content' => "TOPIC: {$topic}\n\nPAGE TITLE: {$pg['h1']}\nSUMMARY: " . mb_substr((string)$pg['summary'], 0, 500)
            . "\nEVENTS:\n{$list}\n\nIs this page about that specific topic (the same event or development), not only the same game, person or company? "
            . "STRICT JSON {\"about\": true|false, \"why\": \"<max 15 words>\"}"]], SP_AI_ORDER, 0.0, 60, sp_ai_skip());
        $j = isset($res['error']) ? null : ai_json((string)$res['content']);
        if (is_array($j) && isset($j['about'])) {
            if (!$j['about']) { $why = 'not about "' . $topic . '": ' . mb_substr((string)($j['why'] ?? ''), 0, 120); $rule = 'page_topic'; }
        } else {
            // no AI answer: the topic's own words must be in the page's title or summary (half of them at least)
            $tw = sp_title_words($topic); $pw = sp_title_words($pg['h1'] . ' ' . $pg['summary']);
            if ($tw && count(array_intersect($tw, $pw)) / count($tw) < 0.5) { $why = 'not about "' . $topic . '" (its words are not on the page)'; $rule = 'page_topic'; }
        }
    }
    if ($why === '') return ['ok' => true, 'why' => ''];
    $pdo->prepare("UPDATE pages SET status='archived', robots='noindex', updated_at=NOW() WHERE id=? AND status<>'published'")->execute([$pageId]);
    sp_log($pdo, $candId, 'drop', $rule, 'written page not published: ' . $why, $pageId);
    return ['ok' => false, 'why' => $why];
}

/**
 * A story the picker approved, but the writer could not fetch two readable sources for it (an outlet that blocks our
 * reader): it waits on the watch list and is tried again, and is dropped 48 hours after it first waited.
 */
function sp_fetch_failed(PDO $pdo, int $candId, string $error): string {
    $c = sp_cand($pdo, $candId);
    $since = !empty($c['watch_since']) ? strtotime((string)$c['watch_since']) : 0;
    if ($since && time() - $since >= SP_WATCH_DROP_H * 3600) {
        $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason=? WHERE id=?")->execute([mb_substr('picker: its sources could not be read in ' . SP_WATCH_DROP_H . 'h (' . $error . ')', 0, 255), $candId]);
        sp_log($pdo, $candId, 'drop', 'fetch', 'its sources could not be read in ' . SP_WATCH_DROP_H . 'h: ' . $error);
        return 'dropped, its sources could not be read in ' . SP_WATCH_DROP_H . 'h';
    }
    $sg = json_decode((string)($c['signals'] ?? ''), true) ?: [];
    unset($sg['picker']['decision']);   // the next check reads it afresh
    $pdo->prepare("UPDATE candidates SET status='watch', watch_since=COALESCE(watch_since, UTC_TIMESTAMP()), picker_checked_at=UTC_TIMESTAMP(), signals=? WHERE id=?")
        ->execute([json_encode($sg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $candId]);
    sp_log($pdo, $candId, 'watch', 'fetch', 'approved, but its sources could not be read: ' . $error);
    return 'watch list, tried again in ' . SP_WATCH_RECHECK_H . 'h';
}
/** Queue entries over 14 days (the stories waiting to be written), oldest first. */
function sp_expire_list(PDO $pdo, int $limit = 0): array {
    $sql = "SELECT id, name, created_at, item_date, status FROM candidates
            WHERE type='drama' AND status IN ('selected','watch') AND created_at < NOW() - INTERVAL " . SP_QUEUE_MAX_DAYS . " DAY ORDER BY created_at";
    return $pdo->query($sql . ($limit ? " LIMIT {$limit}" : ''))->fetchAll(PDO::FETCH_ASSOC);
}

/** Before a build run: expire the old queue entries, re-check the watch list. ['line' => what it did] */
function sp_housekeeping(PDO $pdo): array {
    sp_install($pdo);
    $exp = 0;
    foreach (sp_expire_list($pdo, 200) as $e) {
        $pdo->prepare("UPDATE candidates SET status='rejected', reject_reason=? WHERE id=?")
            ->execute(['expired: over ' . SP_QUEUE_MAX_DAYS . ' days in the queue', (int)$e['id']]);
        sp_log($pdo, (int)$e['id'], 'expire', 'queue_age', 'over ' . SP_QUEUE_MAX_DAYS . ' days in the queue (found ' . substr($e['created_at'], 0, 10) . ')');
        $exp++;
    }
    $due = $pdo->query("SELECT id FROM candidates WHERE status='watch' AND type='drama'
                        AND (picker_checked_at IS NULL OR picker_checked_at <= NOW() - INTERVAL " . SP_WATCH_RECHECK_H . " HOUR
                             OR watch_since <= NOW() - INTERVAL " . SP_WATCH_DROP_H . " HOUR)
                        ORDER BY watch_since LIMIT " . SP_WATCH_PER_RUN)->fetchAll(PDO::FETCH_COLUMN);
    $w = [];
    foreach ($due as $id) { $r = sp_pick($pdo, ['id' => (int)$id]); $w[] = "#{$id} {$r['decision']}"; }
    $line = ($exp ? "{$exp} queue entr" . ($exp === 1 ? 'y' : 'ies') . ' expired' : '') . ($w ? ($exp ? '; ' : '') . 'watch list re-checked: ' . implode(', ', $w) : '');
    return ['line' => $line, 'expired' => $exp, 'rechecked' => count($w)];
}

/** Built / merged / watched / dropped / expired over the last $days (a candidate counts once per decision). */
function sp_report(PDO $pdo, int $days = 7): array {
    sp_install($pdo);
    $out = ['days' => $days, 'counts' => [], 'rules' => [], 'examples' => []];
    foreach ($pdo->query("SELECT decision, COUNT(DISTINCT cand_id) n FROM story_decisions WHERE decided_at >= NOW() - INTERVAL {$days} DAY GROUP BY decision") as $r)
        $out['counts'][$r['decision']] = (int)$r['n'];
    foreach ($pdo->query("SELECT decision, rule, COUNT(DISTINCT cand_id) n FROM story_decisions WHERE decided_at >= NOW() - INTERVAL {$days} DAY GROUP BY decision, rule ORDER BY n DESC") as $r)
        $out['rules'][] = "{$r['decision']} / {$r['rule']}: {$r['n']}";
    foreach ($pdo->query("SELECT s.decision, s.detail, c.name FROM story_decisions s JOIN candidates c ON c.id=s.cand_id
                          WHERE s.decided_at >= NOW() - INTERVAL {$days} DAY AND s.decision IN ('drop','merge') ORDER BY s.id DESC LIMIT 10") as $r)
        $out['examples'][] = "{$r['decision']}: " . mb_substr($r['name'], 0, 90) . " | " . mb_substr($r['detail'], 0, 140);
    return $out;
}
