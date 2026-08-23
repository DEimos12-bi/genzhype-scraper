<?php
// GenZHype | SOURCE-FETCHER. Turns a selected candidate into draftable sources:
//  1) find candidate URLs (the signal URL it arrived with + DuckDuckGo HTML search)
//  2) fetch each page, strip to readable text, keep a dated excerpt
// Pure PHP/curl. Every fetch is best-effort: a failure is skipped, never fatal.

function fs_http_get(string $url, int $timeout = 20): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.9'],
        CURLOPT_ENCODING       => '',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $body) ? $body : null;
}

// SEO-BATCH-1: THE REAL PUBLICATION DATE, read off the page we already fetched.
// Every source row used to be stamped 'date' => date('Y-m-d') — today's date on
// a 2021 article. That is not a missing date, it is a WRONG one, and it is the
// reason published_usage citations validate 10/10 in unit tests and yield zero
// in the pipeline: the drafter had no true date to hand the model, the model
// correctly refused to invent one and left the field blank, and the gate's date
// check killed the citation. Publishers already declare this in three standard
// places, in order of reliability. No extra fetch: same HTML, read properly.
// Returns '' when the page declares nothing — honestly undated beats falsely dated.
function fs_published_date(string $html): string {
    $pats = [
        // 1) JSON-LD (schema.org NewsArticle) — the publisher's own machine statement
        '#"datePublished"\s*:\s*"([0-9]{4}-[0-9]{2}-[0-9]{2})#i',
        // 2) OpenGraph / article meta — what every CMS emits for social cards
        '#<meta[^>]+(?:property|name)=["\'](?:article:published_time|datePublished|pubdate|publish-date|date)["\'][^>]+content=["\']([0-9]{4}-[0-9]{2}-[0-9]{2})#i',
        '#<meta[^>]+content=["\']([0-9]{4}-[0-9]{2}-[0-9]{2})[^"\']*["\'][^>]+(?:property|name)=["\'](?:article:published_time|datePublished|pubdate)["\']#i',
        // 3) <time datetime> — the HTML element built for exactly this
        '#<time[^>]+datetime=["\']([0-9]{4}-[0-9]{2}-[0-9]{2})#i',
    ];
    foreach ($pats as $p) {
        if (preg_match($p, $html, $m)) {
            $d = $m[1];
            // sanity: no pre-web dates, nothing dated in the future
            $y = (int)substr($d, 0, 4);
            if ($y >= 1995 && $d <= date('Y-m-d')) return $d;
        }
    }
    return '';
}

// Bing News RSS = no key, datacenter-friendly, returns REAL publisher URLs
// inside apiclick redirect links. Primary search for news-shaped topics.
function fs_news_search(string $query, int $max = 6): array {
    $xml = fs_http_get('https://www.bing.com/news/search?q=' . urlencode($query) . '&format=rss', 15);
    if (!$xml) return [];
    $urls = [];
    if (preg_match_all('#<link>([^<]+)</link>#i', $xml, $m)) {
        foreach ($m[1] as $raw) {
            $raw = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
            // unwrap bing's apiclick redirect: real target sits in the url= param
            if (preg_match('#[?&]url=([^&]+)#', $raw, $u)) $raw = urldecode($u[1]);
            $host = parse_url($raw, PHP_URL_HOST) ?: '';
            if (!$host || preg_match('#bing\.com|microsoft|msn\.com#i', $host)) continue;
            $urls[$raw] = true;
            if (count($urls) >= $max) break;
        }
    }
    return array_keys($urls);
}

// DuckDuckGo HTML endpoint = no API key, datacenter-friendly.
function fs_search(string $query, int $max = 5): array {
    $html = fs_http_get('https://html.duckduckgo.com/html/?q=' . urlencode($query));
    if (!$html) return [];
    $urls = [];
    if (preg_match_all('#<a[^>]+class="result__a"[^>]+href="([^"]+)"#i', $html, $m)) {
        foreach ($m[1] as $href) {
            // DDG wraps targets in a redirect: extract uddg param if present
            if (preg_match('#[?&]uddg=([^&]+)#', $href, $u)) $href = urldecode($u[1]);
            $host = parse_url($href, PHP_URL_HOST) ?: '';
            if ($host && !preg_match('#duckduckgo|google\.|bing\.#i', $host)) {
                $urls[$href] = true;
            }
            if (count($urls) >= $max) break;
        }
    }
    return array_keys($urls);
}

// Strip an HTML page to a clean text excerpt. Uses fivefilters/readability.php
// (the Firefox Reader algorithm) when available; falls back to regex extraction.
function fs_extract_text(string $html): string {
    if (defined('GZH_LIBS') && GZH_LIBS && mb_strlen($html) > 500) {
        try {
            $cfg = new \fivefilters\Readability\Configuration([
                'fixRelativeURLs' => false,
                'summonCthulhu'   => true,   // strip <script> even in malformed HTML
            ]);
            $rd = new \fivefilters\Readability\Readability($cfg);
            $rd->parse($html);
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($rd->getContent() ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (mb_strlen($text) >= 200) return $text;   // good extraction
        } catch (\Throwable $e) { /* fall through to regex */ }
    }
    return fs_extract_text_regex($html);
}

// Original regex extractor | fallback when the library is absent or bails.
function fs_extract_text_regex(string $html): string {
    $html = preg_replace('#<(script|style|noscript|nav|header|footer|aside|form|svg)\b[^>]*>.*?</\1>#is', ' ', $html);
    if (preg_match('#<article\b[^>]*>(.*?)</article>#is', $html, $a)) $html = $a[1];
    elseif (preg_match('#<main\b[^>]*>(.*?)</main>#is', $html, $a)) $html = $a[1];
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function fs_publisher(string $url): string {
    $h = parse_url($url, PHP_URL_HOST) ?: '';
    $h = preg_replace('/^www\./', '', $h);
    return $h ?: 'source';
}

/** Harvest ORIGINAL social-post URLs (the receipts) out of an article's HTML. */
function fs_harvest_social(string $html, int $max = 3): array {
    $found = [];
    $patterns = [
        'twitter' => '#https?://(?:www\.)?(?:twitter\.com|x\.com)/[A-Za-z0-9_]+/status/\d+#',
        'reddit'  => '#https?://(?:www\.|old\.)?reddit\.com/r/[A-Za-z0-9_]+/comments/[A-Za-z0-9]+#',
        'youtube' => '#https?://(?:www\.)?(?:youtube\.com/watch\?v=[A-Za-z0-9_-]{6,}|youtu\.be/[A-Za-z0-9_-]{6,}|youtube\.com/shorts/[A-Za-z0-9_-]{6,})#',
        'tiktok'  => '#https?://(?:www\.)?tiktok\.com/@[A-Za-z0-9_.]+/video/\d+#',
        // r28: Twitch + Kick clips download from a runner with just curl_cffi
        // TLS impersonation (proven) — streamer drama's ACTUAL moments live
        // here, so harvest them as prime footage.
        'twitch'  => '#https?://(?:www\.)?(?:twitch\.tv/[A-Za-z0-9_]+/clip/[A-Za-z0-9_-]+|clips\.twitch\.tv/[A-Za-z0-9_-]+)#',
        'kick'    => '#https?://(?:www\.)?kick\.com/[A-Za-z0-9_-]+/clips/clip_[A-Za-z0-9]+#',
        // (Instagram intentionally excluded: fights scrapers too hard for the payoff)
    ];
    foreach ($patterns as $prov => $re) {
        if (preg_match_all($re, $html, $m)) {
            foreach (array_unique($m[0]) as $u) {
                $found[] = ['provider' => $prov, 'url' => html_entity_decode($u)];
                if (count($found) >= $max) return $found;
            }
        }
    }
    return $found;
}

/** Light context for a harvested post (oEmbed title/author) so the drafter can cite it. */
function fs_social_excerpt(string $provider, string $url): string {
    // r25: X killed publish.twitter.com/oembed, so twitter excerpts must come
    // from the SYNDICATION CDN (the same source that now builds the embed).
    if ($provider === 'twitter'
            && preg_match('#/([A-Za-z0-9_]{1,20})/status/(\d+)#', $url, $m)) {
        require_once __DIR__ . '/post_cards.php';
        $j = pc_syndication($m[2]);
        if ($j && trim((string)($j['text'] ?? '')) !== '') {
            $by = trim((string)($j['author'] ?: $m[1]));
            return trim("Original tweet by {$by} (@" . trim((string)($j['handle'] ?: $m[1]))
                        . "): " . mb_substr((string)$j['text'], 0, 900));
        }
        return '';   // tombstoned/deleted -> skip
    }
    $oembed = [
        'reddit'  => 'https://www.reddit.com/oembed?url=',
        'youtube' => 'https://www.youtube.com/oembed?format=json&url=',
        'tiktok'  => 'https://www.tiktok.com/oembed?url=',
    ][$provider] ?? null;
    if (!$oembed) return '';
    $raw = fs_http_get($oembed . urlencode($url), 10);
    if (!$raw) return '';
    $j = json_decode($raw, true);
    if (!$j) return '';
    $author = $j['author_name'] ?? '';
    $title  = trim(html_entity_decode(strip_tags($j['html'] ?? ($j['title'] ?? '')), ENT_QUOTES, 'UTF-8'));
    return trim("Original {$provider} post by {$author}: " . mb_substr($title, 0, 900));
}

/** r28 FOOTAGE CLIPS (owner: "wire Twitch/TikTok/Kick/X clips in"). Harvest the
 *  VIDEO clips embedded in a drama's article sources (Twitch/Kick/TikTok/YouTube
 *  — the platforms proven downloadable) and store them on the video_scripts row
 *  as [{platform,url}]. The feed ships them; the maker fetches each with the
 *  right method (impersonate for twitch/tiktok/kick, cookies+WARP for youtube)
 *  and drops them in as REAL MOVING footage matched to the story. Returns the
 *  clip list. Idempotent (dedupes on url). */
/** r45 EMBED HARVEST — THE MONEY CLIP.
 *
 *  The single most valuable asset for a drama video is the footage the reporter
 *  EMBEDDED in the article, and we were blind to it: news sites embed with
 *  <iframe src="https://www.youtube.com/embed/<ID>?start=<N>">, while
 *  fs_harvest_social only ever matched watch?v= / youtu.be / shorts. The
 *  Brady-slaps-Logan-Paul article embedded youtube.com/embed/I07mko_hkmk?start=182
 *  — the reporter's own timestamp for the exact second of the slap — and the
 *  video engine never saw it, so it opened on a photo of a bystander instead.
 *
 *  `start` is the gold here: it points at the money moment, which is what the
 *  hook must show. Returns [['platform','url','start','embed'=>true], ...].
 *  WordPress/JSON payloads escape their markup, so the HTML is normalised first.
 */
function fs_harvest_embeds(string $html, int $max = 6): array
{
    // Un-escape the forms embeds actually arrive in (JSON-in-HTML, entities).
    $h = str_replace(
        ['\\u003c', '\\u003e', '\\u0026', '\\/', '&amp;', '&#038;'],
        ['<', '>', '&', '/', '&', '&'],
        $html);

    $out = []; $seen = [];
    // 1) YouTube iframe embeds (+ start=/t= offset)
    if (preg_match_all('#youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{6,})([^"\'\s>]*)#i',
                       $h, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            if (count($out) >= $max) break;
            $id = $hit[1];
            if (isset($seen[$id])) continue;
            $seen[$id] = 1;
            $start = 0;
            if (preg_match('#[?&](?:start|t)=(\d+)#', (string)($hit[2] ?? ''), $s)) {
                $start = (int)$s[1];
            }
            $out[] = ['platform' => 'youtube', 'embed' => true, 'start' => $start,
                      'url' => 'https://www.youtube.com/watch?v=' . $id];
        }
    }
    // 2) Vimeo player embeds
    if (preg_match_all('#player\.vimeo\.com/video/(\d+)#i', $h, $m2)) {
        foreach ($m2[1] as $vid) {
            if (count($out) >= $max) break;
            $k = 'vimeo' . $vid;
            if (isset($seen[$k])) continue;
            $seen[$k] = 1;
            $out[] = ['platform' => 'vimeo', 'embed' => true, 'start' => 0,
                      'url' => 'https://vimeo.com/' . $vid];
        }
    }
    // 3) og:video / JSON-LD VideoObject contentUrl pointing at a real video file
    if (preg_match_all('#(?:og:video(?::url|:secure_url)?"[^>]*content="|"contentUrl"\s*:\s*")([^"\']+\.(?:mp4|m3u8)[^"\']*)#i',
                       $h, $m3)) {
        foreach ($m3[1] as $vu) {
            if (count($out) >= $max) break;
            if (isset($seen[$vu])) continue;
            $seen[$vu] = 1;
            $out[] = ['platform' => 'file', 'embed' => true, 'start' => 0,
                      'url' => $vu];
        }
    }
    return $out;
}

function footage_clips_gather(int $drama_id, int $max = 8): array {
    $pdo = db();
    $arts = $pdo->prepare(
        "SELECT DISTINCT s.url FROM events e JOIN sources s ON s.id=e.source_id
         WHERE e.drama_id=? AND s.url IS NOT NULL AND s.url NOT LIKE '%/status/%'");
    $arts->execute([$drama_id]);
    $clips = []; $seen = [];
    foreach ($arts->fetchAll(PDO::FETCH_COLUMN) as $artUrl) {
        if (count($clips) >= $max) break;
        $html = fs_http_get($artUrl);
        if (!$html) continue;
        // r45: EMBEDS FIRST — the video the reporter embedded IS the moment, and
        // it carries their own `start` timestamp for it. These outrank any link
        // merely mentioned in the body text, so they lead the list and the maker
        // can open the hook on them.
        foreach (fs_harvest_embeds($html, $max) as $emb) {
            if (count($clips) >= $max) break;
            if (isset($seen[$emb['url']])) continue;
            $seen[$emb['url']] = 1;
            // IDENTITY JOIN (2026-08-06): record WHO posted the clip (the
            // handle lives in the platform URL) and WHICH article embedded
            // it — the timeline generator binds clips by author identity +
            // source affinity, never by order.
            $emb['author'] = fs_clip_author((string)$emb['url']);
            $emb['src'] = $artUrl;
            $clips[] = $emb;
        }
        foreach (fs_harvest_social($html, 20) as $soc) {
            if (count($clips) >= $max) break;
            // video platforms only (tweets/reddit are handled as cards elsewhere)
            if (!in_array($soc['provider'], ['twitch', 'kick', 'tiktok', 'youtube'], true)) continue;
            $u = $soc['url'];
            if (isset($seen[$u])) continue;
            $seen[$u] = 1;
            $clips[] = ['platform' => $soc['provider'], 'url' => $u,
                        'embed' => false, 'start' => 0,
                        'author' => fs_clip_author($u), 'src' => $artUrl];
        }
    }
    // find the page_id for the video_scripts row
    $pid = (int)$pdo->query("SELECT page_id FROM dramas WHERE id=" . (int)$drama_id)->fetchColumn();
    if ($pid) {
        $pdo->prepare("UPDATE video_scripts SET footage_clips=? WHERE page_id=?")
            ->execute([json_encode($clips), $pid]);
    }
    return $clips;
}

/** r26 DEMON EVIDENCE (owner: "we need like a demon scraper... whenever there's
 *  something in the script it gets proof based on what it says — more content
 *  as the video goes, never repeating the same imgs"): aggressively gather MANY
 *  distinct proof pieces for a drama so the video never recycles 5 images.
 *  Pulls distinct-DOMAIN article screenshots (each a unique proof) + every tweet
 *  embedded in them (syndication cards) and stores them as VIDEO-ONLY events
 *  (video_only=1 -> they enrich the video but never touch the article timeline).
 *  Idempotent: dedupes on domain + tweet id. Returns [articles, tweets]. */
function evidence_demon_for_drama(int $drama_id, int $max_articles = 6, int $max_tweets = 8): array {
    require_once __DIR__ . '/embeds.php';
    $pdo = db();
    // topic for search: page title + primary people
    $meta = $pdo->prepare(
        "SELECT d.title, d.people_json FROM dramas d WHERE d.id=?");
    $meta->execute([$drama_id]);
    $m = $meta->fetch();
    if (!$m) return ['articles' => 0, 'tweets' => 0, 'error' => 'drama not found'];
    $ppl = [];
    foreach ((array)json_decode((string)$m['people_json'], true) as $pp) {
        $nm = trim((string)(is_array($pp) ? ($pp['name'] ?? '') : $pp));
        if ($nm !== '') $ppl[] = $nm;
    }
    $topic = trim((string)$m['title'] . ' ' . implode(' ', array_slice($ppl, 0, 3)));

    // domains already sourced (article + tweet) -> never duplicate a proof
    $dq = $pdo->prepare(
        "SELECT DISTINCT s.url FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id=?");
    $dq->execute([$drama_id]);
    $haveDom = []; $haveTweet = [];
    foreach ($dq->fetchAll(PDO::FETCH_COLUMN) as $u) {
        if (preg_match('#/status/(\d+)#', $u, $tm)) { $haveTweet[$tm[1]] = 1; continue; }
        $d = preg_replace('/^www\./', '', strtolower((string)parse_url($u, PHP_URL_HOST)));
        if ($d) $haveDom[$d] = 1;
    }

    // r27 RELEVANCE GATE (owner: an off-topic allhiphop EMINEM article landed in
    // the Soulja video). Every gathered proof MUST be about THIS story: build
    // distinctive name tokens (>=4 chars) from the people; an article/tweet that
    // mentions none of them is rejected. Off-topic proof is worse than a repeat.
    $relTokens = [];
    foreach ($ppl as $name) {
        foreach (preg_split('/\s+/', mb_strtolower($name)) as $w) {
            $w = preg_replace('/[^a-z0-9]/', '', $w);
            if (mb_strlen($w) >= 4) $relTokens[$w] = 1;
        }
    }
    $relTokens = array_keys($relTokens);
    $isRelevant = function (string $blob) use ($relTokens): bool {
        if (!$relTokens) return true;
        $b = mb_strtolower($blob);
        foreach ($relTokens as $t) if (strpos($b, $t) !== false) return true;
        return false;
    };

    // aggressive multi-source search
    $urls = array_merge(fs_news_search($topic, 12), fs_search($topic, 10));
    $addedA = 0; $addedT = 0; $sortA = 8000; $sortT = 9000;
    $insSrc = $pdo->prepare("INSERT INTO sources (url, domain, publisher, title, reliability) VALUES (?,?,?,?,?)");
    $insEv  = $pdo->prepare(
        "INSERT INTO events (drama_id, event_date, title, description, source_id,
            is_confirmed, sort_order, embed_html, embed_provider, video_only)
         VALUES (?,?,?,?,?,1,?,?,?,1)");
    foreach ($urls as $u) {
        if ($addedA >= $max_articles && $addedT >= $max_tweets) break;
        $dom = preg_replace('/^www\./', '', strtolower((string)parse_url($u, PHP_URL_HOST)));
        if ($dom === '' || strpos($dom, 'x.com') !== false || strpos($dom, 'twitter.com') !== false) continue;
        $newArticle = ($addedA < $max_articles) && empty($haveDom[$dom]);
        if (!$newArticle && $addedT >= $max_tweets) continue;   // nothing to gain here
        $html = fs_http_get($u);
        if (!$html) continue;
        $text = fs_extract_text($html);
        $ttl = '';
        if (preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $tt)) {
            $ttl = trim(html_entity_decode(strip_tags($tt[1]), ENT_QUOTES, 'UTF-8'));
        }
        // RELEVANCE GATE: this page must actually be about the story's people.
        if (!$isRelevant($ttl . ' ' . mb_substr($text, 0, 2000))) {
            continue;   // off-topic (homepage / unrelated article) -> reject
        }
        // 1) ARTICLE screenshot proof (one per NEW domain)
        if ($newArticle) {
            if (mb_strlen($text) >= 400) {
                $title = $ttl;
                $insSrc->execute([$u, $dom, fs_publisher($u), mb_substr($title, 0, 200), 'reliable_outlet']);
                $sid = (int)$pdo->lastInsertId();
                // r93 ARCHIVE AT CAPTURE. Keep the page we just read, now,
                // while we still hold it — a citation checked six days later
                // is already too late (30 of 423 were broken when we finally
                // looked). $html is passed so this costs no extra request and
                // archives the exact version the event was written from.
                try {
                    require_once __DIR__ . '/source_archive.php';
                    sa_capture($pdo, $sid, $u, $html);
                } catch (Throwable $e) { /* never block ingest on the archive */ }
                $insEv->execute([$drama_id, date('Y-m-d'),
                    mb_substr($title ?: fs_publisher($u) . ' report', 0, 200),
                    mb_substr($text, 0, 500), $sid, $sortA++, null, null]);
                $haveDom[$dom] = 1; $addedA++;
            }
        }
        // 2) TWEETS embedded in this article (syndication cards)
        if ($addedT < $max_tweets) {
            foreach (fs_harvest_social($html, 12) as $soc) {
                if ($addedT >= $max_tweets) break;
                if ($soc['provider'] !== 'twitter') continue;
                if (!preg_match('#/status/(\d+)#', $soc['url'], $im)) continue;
                $tid = $im[1];
                if (isset($haveTweet[$tid])) continue;
                $haveTweet[$tid] = 1;
                $emb = embed_for_url($soc['url']);   // syndication build; null if deleted
                if (!$emb) continue;
                $exc = fs_social_excerpt('twitter', $soc['url']);
                $insSrc->execute([$soc['url'], 'x.com', 'X (original post)', mb_substr($exc, 0, 200), 'primary']);
                $sid = (int)$pdo->lastInsertId();
                $insEv->execute([$drama_id, date('Y-m-d'),
                    mb_substr($exc ?: 'Tweet', 0, 200), $exc ?: '', $sid,
                    $sortT++, $emb['html'], 'twitter']);
                $addedT++;
            }
        }
    }
    return ['articles' => $addedA, 'tweets' => $addedT];
}

/** r25 (owner: "Twitter needs to be a HUGE source — beef tweets from people
 *  involved + audience reactions, used to the max"): BACKFILL a drama's tweet
 *  receipts by re-harvesting the tweets EMBEDDED in its existing article
 *  sources. X killed oEmbed so early-2026 dramas captured 0 tweets; this pulls
 *  them via the syndication CDN and adds each live tweet as a primary 'post'
 *  event, then embeds it. Returns [added, checked]. Safe to re-run (dedupes on
 *  the tweet URL). */
function tweets_backfill_for_drama(int $drama_id, int $max_tweets = 8): array {
    require_once __DIR__ . '/embeds.php';
    $pdo = db();
    // existing article sources (skip ones already social posts)
    $arts = $pdo->prepare(
        "SELECT DISTINCT s.url FROM events e JOIN sources s ON s.id=e.source_id
         WHERE e.drama_id=? AND s.url IS NOT NULL
           AND s.url NOT LIKE '%/status/%'");
    $arts->execute([$drama_id]);
    $have = $pdo->prepare("SELECT COUNT(*) FROM sources WHERE url=?");
    $added = 0; $checked = 0; $seen = [];
    foreach ($arts->fetchAll(PDO::FETCH_COLUMN) as $artUrl) {
        if ($added >= $max_tweets) break;
        $html = fs_http_get($artUrl);
        if (!$html) continue;
        foreach (fs_harvest_social($html, 12) as $soc) {
            if ($added >= $max_tweets) break;
            if ($soc['provider'] !== 'twitter') continue;   // TWITTER MAX first
            $tu = $soc['url'];
            // dedupe by TWEET ID (x.com and twitter.com are the same tweet)
            if (!preg_match('#/status/(\d+)#', $tu, $idm)) continue;
            $tid = $idm[1];
            if (isset($seen[$tid])) continue;
            $seen[$tid] = 1;
            $checked++;
            // already stored under EITHER host?
            $dupe = $pdo->prepare("SELECT COUNT(*) FROM sources WHERE url LIKE ?");
            $dupe->execute(['%/status/' . $tid]);
            if ((int)$dupe->fetchColumn() > 0) continue;
            $emb = embed_for_url($tu);                       // syndication build
            if (!$emb) continue;                             // deleted/tombstone
            $exc = fs_social_excerpt('twitter', $tu);
            // create source + primary post event + attach the embed
            $pdo->prepare("INSERT INTO sources (url, publisher, reliability) VALUES (?,?,?)")
                ->execute([$tu, 'X (original post)', 'primary']);
            $sid = (int)$pdo->lastInsertId();
            $pdo->prepare(
                "INSERT INTO events (drama_id, event_date, title, description,
                   source_id, is_confirmed, sort_order, embed_html, embed_provider)
                 VALUES (?,?,?,?,?,1,?,?, 'twitter')")
                ->execute([$drama_id, date('Y-m-d'),
                           mb_substr($exc ?: 'Tweet', 0, 200),
                           $exc ?: '', $sid, 900 + $added,
                           $emb['html']]);
            $added++;
        }
    }
    return ['added' => $added, 'checked' => $checked];
}

/**
 * Build a draft-ready input array from a SELECTED candidate.
 * Returns ['topic'=>, 'sources'=>[...]] or ['error'=>..].
 */
function fetch_sources_for_candidate(int $cand_id, int $want = 4): array {
    $pdo = db();
    $c = $pdo->prepare("SELECT * FROM candidates WHERE id=? AND status='selected'");
    $c->execute([$cand_id]);
    $cand = $c->fetch();
    if (!$cand) return ['error' => 'candidate not found or not selected'];

    $verdict = json_decode($cand['ai_verdict'] ?? '{}', true) ?: [];
    $signals = json_decode($cand['signals'] ?? '{}', true) ?: [];
    $query   = $verdict['search_query'] ?: ($cand['name'] . ' explained');

    // seed URLs: whatever the candidate already carried, then news search
    // (real publisher links, never rate-limited), then DDG as last resort
    $urls = [];
    foreach (['url', 'permalink', 'external_url'] as $k) {
        if (!empty($signals[$k]) && filter_var($signals[$k], FILTER_VALIDATE_URL)) $urls[] = $signals[$k];
    }
    foreach (fs_news_search($query, 6) as $u) $urls[] = $u;
    if (count($urls) < 3) foreach (fs_search($query, 6) as $u) $urls[] = $u;
    $urls = array_values(array_unique($urls));

    $sources = [];
    $domTaken = [];  // per-outlet count — AIM for >=3 distinct source domains when the pool has them; never pad
    foreach ($urls as $u) {
        if (count($sources) >= $want && count($domTaken) >= 3) break;   // enough sources AND diverse enough
        if (count($sources) >= $want + 2) break;                        // hard ceiling — never over-fetch
        $host = preg_replace('/^www\./', '', strtolower((string)parse_url($u, PHP_URL_HOST)));
        if ($host !== '' && ($domTaken[$host] ?? 0) >= 2) continue;     // one outlet can't supply the whole citation set
        $html = fs_http_get($u);
        if (!$html) continue;
        $text = fs_extract_text($html);
        if (mb_strlen($text) < 400) continue;            // too thin to be an article
        // title
        $title = '';
        if (preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $t)) {
            $title = trim(html_entity_decode(strip_tags($t[1]), ENT_QUOTES, 'UTF-8'));
        }
        $sources[] = [
            'url'         => $u,
            'publisher'   => fs_publisher($u),
            'date'        => date('Y-m-d'),               // best-effort; primary dating comes from the events
            'reliability' => 'reliable_outlet',
            'excerpt'     => mb_substr($text, 0, 3500),
            'title'       => mb_substr($title, 0, 200),
        ];
        if ($host !== '') $domTaken[$host] = ($domTaken[$host] ?? 0) + 1;

        // harvest the RECEIPTS: original posts linked inside the article
        foreach (fs_harvest_social($html, 2) as $soc) {
            if (count($sources) >= $want + 3) break;       // receipts ride on top of articles
            $dupe = false;
            foreach ($sources as $s2) if ($s2['url'] === $soc['url']) { $dupe = true; break; }
            if ($dupe) continue;
            $exc = fs_social_excerpt($soc['provider'], $soc['url']);
            if ($exc === '') continue;                     // dead/private post: skip
            $sources[] = [
                'url'         => $soc['url'],
                'publisher'   => ucfirst($soc['provider']) . ' (original post)',
                'date'        => date('Y-m-d'),
                'reliability' => 'primary',
                'excerpt'     => $exc,
                'title'       => mb_substr($exc, 0, 200),
            ];
        }
    }

    if (count($sources) < 2) {
        return ['error' => 'could only fetch ' . count($sources) . ' usable source(s); need >= 2', 'tried' => count($urls)];
    }

    return [
        'topic'   => $verdict['angle'] ?: $cand['name'],
        'people'  => $verdict['primary_people'] ?? [],
        'sources' => $sources,
        'cand_id' => $cand_id,
    ];
}

/** IDENTITY JOIN helper (2026-08-06): the clip author's handle, straight from
 *  the platform URL — deterministic identity data, no AI. '' when the URL
 *  shape carries no author (youtube watch ids). */
function fs_clip_author(string $url): string {
    if (preg_match('#tiktok\.com/@([A-Za-z0-9_.]+)/#', $url, $m)) return strtolower($m[1]);
    if (preg_match('#twitch\.tv/([A-Za-z0-9_]+)/clip#', $url, $m)) return strtolower($m[1]);
    if (preg_match('#kick\.com/([A-Za-z0-9_\-]+)#', $url, $m)) return strtolower($m[1]);
    if (preg_match('#youtube\.com/@([A-Za-z0-9_.\-]+)#', $url, $m)) return strtolower($m[1]);
    return '';
}

/**
 * VISUAL DIRECTOR (r70) — the brain in front of the picture helpers.
 *
 * The failure this exists to end: the helpers searched a NAME. For a story
 * about a fictional AI actress that returned robots and circuit boards; for a
 * streamer row it returned gingerbread houses. Both videos were rejected. A
 * name is not a search — the EVENT is.
 *
 * So before anything is fetched, one cheap AI call reads the story and decides
 * what to go and find: phrases describing the MOMENT (for clip hunting) and
 * phrases describing the PEOPLE/SCENE (for stills). Clip queries come first
 * because a single clip yields 12-20 usable frames — measured; every other
 * source yields ~1 (trafilatura found ZERO images inside a real Dexerto
 * article body, so "scrape the article photos" is not a supply route).
 *
 * FAILS CLOSED on purpose: no AI answer means no invented queries, so the
 * helpers fall back to what the story already has rather than guessing. That
 * is the opposite of what happened with the robots.
 *
 * Returns ['clip_queries'=>[], 'image_queries'=>[], 'skip'=>bool] or null.
 */
function visual_director(int $drama_id, bool $persist = true): ?array
{
    require_once __DIR__ . '/ai.php';        // ai_chat / ai_json
    $pdo = db();
    $m = $pdo->prepare(
        "SELECT d.title, d.people_json, v.page_id, v.script
           FROM dramas d JOIN video_scripts v ON v.page_id=d.page_id
          WHERE d.id=?");
    $m->execute([$drama_id]);
    $r = $m->fetch();
    if (!$r) return null;

    $people = [];
    foreach ((array)json_decode((string)$r['people_json'], true) as $p) {
        if (!empty($p['name'])) $people[] = (string)$p['name'];
    }

    // r87 PROMPT REWRITE, measured. The old prompt asked for "the venue, the
    // act, and the month", and never said where the phrases were going. It
    // produced things like "TikTok @ssoftblooms Brooke Sullivan AI actress
    // original video" (0 hits) and "X posts mocking ... compilation" (3 hits,
    // all off-story). Typing the plain subject — "Brooke Sullivan AI actress
    // TikTok" — returned the exact on-story video as the FIRST result. The
    // searchable source is YouTube, so the phrases must be written the way a
    // person types into YouTube.
    $sys = 'You are the VISUAL DIRECTOR for a short news video. You do not write '
         . 'copy — you decide what footage the team must go and find. '
         . 'Your phrases are typed into a YOUTUBE SEARCH BOX, so write them the '
         . 'way a person searches: 3 to 6 plain words, the subject and what '
         . 'happened. '
         . 'FORBIDDEN, because they return nothing or return junk: a platform '
         . 'name at the front ("TikTok ...", "X posts ..."), @handles, and filler '
         . 'words like "original video", "compilation", "follow-up", "explained". '
         . 'Name the person or thing everyone calls it by, plus the event. '
         . 'CLIP queries matter most: one real clip is worth twenty photos. '
         . 'If the subject is fictional, AI-generated, or has no real-world '
         . 'footage/photos, say so with "skip": true instead of inventing queries. '
         . 'Output STRICT JSON: {"clip_queries":["3-5 short phrases"],'
         . '"image_queries":["2-4 short phrases"],"skip":false}';
    $ctx = "TITLE: {$r['title']}\nPEOPLE: " . implode(', ', $people)
         . "\nSCRIPT: " . mb_substr((string)$r['script'], 0, 700);

    $res = ai_chat([['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $ctx]],
                   ['gemini', 'openrouter', 'nvidia'], 0.3);
    if (isset($res['error'])) return null;          // fail closed
    $j = ai_json($res['content']);
    if (!is_array($j)) return null;                 // fail closed

    // r87: strip the shapes that measurably return nothing, rather than
    // trusting the model to have obeyed the prompt. A phrase is a search, not
    // a sentence: no leading platform name, no @handle, no filler tail.
    $clean = static function ($a) {
        $out = [];
        foreach ((array)$a as $q) {
            $q = trim(preg_replace('/\s+/', ' ', (string)$q));
            $q = preg_replace('/^(?:on\s+)?(?:tiktok|x|twitter|instagram|youtube|twitch|reddit)\b[\s:,-]*/i', '', $q);
            $q = preg_replace('/@[A-Za-z0-9._]+/', '', $q);
            $q = preg_replace('/\b(?:original video|full video|compilation|follow-?up(?: video)?|explained|explanation|reaction video)\b/i', '', $q);
            $q = trim(preg_replace('/\s+/', ' ', $q), " \t,-:");
            if (mb_strlen($q) >= 8) $out[] = mb_substr($q, 0, 120);
        }
        return array_values(array_unique($out));
    };
    $clips = $clean($j['clip_queries'] ?? []);

    // r87 PLAIN-SUBJECT FALLBACK. The single best-performing query we measured
    // was not written by the director at all — it was the story's own subject
    // typed plainly. So one is always included, first, for free: title words
    // minus vocabulary, capped at six. It costs no AI call and it is the query
    // most likely to return the actual moment.
    $stopw = ' the a an and of in on at to for from by with over after before '
           . 'about into out up down as is are was were be been has have had how '
           . 'what when where who why new his her their they them its it he she ';
    $subject = [];
    foreach (preg_split('/[^A-Za-z0-9\']+/', (string)$r['title']) as $w) {
        if (mb_strlen($w) < 3 || str_contains($stopw, ' ' . strtolower($w) . ' ')) continue;
        if (count($subject) < 6) $subject[] = $w;
    }
    if (count($subject) >= 2) {
        array_unshift($clips, implode(' ', $subject));
        $clips = array_values(array_unique($clips));
    }

    $plan = [
        'clip_queries'  => array_slice($clips, 0, 5),
        'image_queries' => array_slice($clean($j['image_queries'] ?? []), 0, 4),
        'skip'          => (bool)($j['skip'] ?? false),
    ];
    // $persist=false: the Helpers Lab asks the director what it WOULD plan
    // without overwriting the plan a rendered video is already aligned to.
    if ($persist) {
        $pdo->prepare("UPDATE video_scripts SET visual_plan=? WHERE page_id=?")
            ->execute([json_encode($plan, JSON_UNESCAPED_SLASHES), (int)$r['page_id']]);
    }
    return $plan;
}

/**
 * CLIP HUNTER (r70) — go and find real footage for the director's queries.
 *
 * Why this is the priority: measured, one downloaded clip yields 12-20 usable
 * frames of the ACTUAL event, while a news article yields ~1 photo (trafilatura
 * found zero images inside the article body) and generic image search yields
 * junk. Clips are the only supply source that scales.
 *
 * Searches YouTube via yt-dlp's ytsearch (no API key, no quota) using the
 * EVENT-shaped phrases the visual director wrote. Results are appended to the
 * story's footage_clips, deduped against what the embed harvester already
 * found. yt-dlp is the proven tool here; we do not hand-roll a scraper.
 *
 * NOTE (r70): Hostinger disables shell_exec, so this cannot run on the web
 * host — the SEARCH half lives in video_maker.py on the runner, which has
 * yt-dlp and subprocess. This function is kept for CLI/cron use only.
 *
 * Returns the number of new clips added.
 */
function clip_hunt(int $drama_id, int $max_new = 4): int
{
    $pdo = db();
    $row = $pdo->prepare(
        "SELECT v.page_id, v.footage_clips, v.visual_plan
           FROM video_scripts v JOIN dramas d ON d.page_id=v.page_id WHERE d.id=?");
    $row->execute([$drama_id]);
    $r = $row->fetch();
    if (!$r) return 0;

    $plan = (array)json_decode((string)($r['visual_plan'] ?? ''), true);
    if (!empty($plan['skip'])) return 0;                  // director said: nothing real exists
    $queries = (array)($plan['clip_queries'] ?? []);
    if (!$queries) return 0;                              // fail closed, never guess

    $clips = (array)json_decode((string)($r['footage_clips'] ?? ''), true);
    $have  = [];
    foreach ($clips as $c) if (!empty($c['url'])) $have[$c['url']] = 1;

    $yt = '/home/u219414635/.local/bin/yt-dlp';
    if (!is_executable($yt)) $yt = trim((string)@shell_exec('command -v yt-dlp')) ?: '';
    if ($yt === '') return 0;

    $added = 0;
    foreach ($queries as $q) {
        if ($added >= $max_new) break;
        // ytsearch3 = top 3 matches, metadata only (no download here; the maker
        // downloads with its own cookies/impersonation when it renders).
        $cmd = escapeshellcmd($yt) . ' --no-warnings --flat-playlist --skip-download'
             . ' --print "%(id)s\t%(title)s\t%(duration)s"'
             . ' ' . escapeshellarg('ytsearch3:' . $q) . ' 2>/dev/null';
        $out = (string)@shell_exec($cmd);
        foreach (explode("\n", trim($out)) as $line) {
            if ($added >= $max_new) break;
            $p = explode("\t", $line);
            if (count($p) < 2 || strlen($p[0]) < 6) continue;
            $dur = (int)($p[2] ?? 0);
            if ($dur > 3600) continue;                    // skip full streams
            $url = 'https://www.youtube.com/watch?v=' . $p[0];
            if (isset($have[$url])) continue;
            $have[$url] = 1;
            $clips[] = ['platform' => 'youtube', 'url' => $url, 'start' => 0,
                        'embed' => false, 'found_by' => 'clip_hunt',
                        'query' => mb_substr($q, 0, 90),
                        'title' => mb_substr((string)($p[1] ?? ''), 0, 120)];
            $added++;
        }
    }
    if ($added) {
        $pdo->prepare("UPDATE video_scripts SET footage_clips=? WHERE page_id=?")
            ->execute([json_encode($clips, JSON_UNESCAPED_SLASHES), (int)$r['page_id']]);
    }
    return $added;
}
