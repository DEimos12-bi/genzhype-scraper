<?php
// GenZHype | embed builder. Converts social-post source URLs into REAL embeds:
//  - YouTube  -> lite-youtube facade (GitHub: paulirish/lite-youtube-embed | fast)
//  - X/Twitter, TikTok, Reddit -> official oEmbed HTML (platform-served, legal)
// Embed HTML is cached in events.embed_html at build time | zero per-visit calls.

const EMBED_UA = 'GenZHypeDesk/1.0 (+https://genzhype.com)';

function embed_http(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => EMBED_UA,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$raw) return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

/** Strip <script> tags from oEmbed HTML | we lazy-load platform scripts ourselves. */
function embed_clean(string $html): string {
    return trim(preg_replace('#<script[^>]*>.*?</script>#is', '', $html));
}

/** Build embed for a URL. Returns ['provider'=>, 'html'=>] or null. */
function embed_for_url(string $url): ?array {
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');

    // YouTube -> lite-youtube facade (no oEmbed needed, instant + fast)
    if (preg_match('#(youtube\.com|youtu\.be)#', $host)) {
        $vid = null;
        if (preg_match('#youtu\.be/([A-Za-z0-9_-]{6,})#', $url, $m)) $vid = $m[1];
        elseif (preg_match('#[?&]v=([A-Za-z0-9_-]{6,})#', $url, $m)) $vid = $m[1];
        elseif (preg_match('#/(shorts|live|embed)/([A-Za-z0-9_-]{6,})#', $url, $m)) $vid = $m[2];
        if (!$vid) return null;
        $v = htmlspecialchars($vid, ENT_QUOTES);
        return ['provider' => 'youtube',
                'html' => '<lite-youtube videoid="' . $v . '" style="background-image:url(\'https://i.ytimg.com/vi/' . $v . '/hqdefault.jpg\')"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '" class="lty-playbtn" title="Play video" target="_blank" rel="noopener nofollow"><span class="lyt-visually-hidden">Play video</span></a></lite-youtube>'];
    }

    // X / Twitter. r25 (owner: "Twitter needs to be a HUGE source"): X killed
    // the public publish.twitter.com/oembed API, so the old path returns null
    // and tweet capture silently died. The SYNDICATION CDN (token=x) still
    // serves canonical tweet JSON from this host (proven in post_cards.php), so
    // build the blockquote from THAT first; oEmbed/library stay as fallbacks in
    // case X ever re-opens it.
    if (preg_match('#(twitter\.com|x\.com)#', $host) && preg_match('#/status/\d+#', $url)) {
        $syn = embed_twitter_syndication($url);
        if ($syn) return $syn;
        $u = preg_replace('#^https?://x\.com#', 'https://twitter.com', $url);
        $j = embed_http('https://publish.twitter.com/oembed?omit_script=1&dnt=1&url=' . urlencode($u));
        if (!empty($j['html'])) return ['provider' => 'twitter', 'html' => embed_clean($j['html'])];
        return embed_via_library($url, 'twitter');
    }

    // TikTok -> official oEmbed
    if (str_contains($host, 'tiktok.com') && preg_match('#/video/\d+|/photo/\d+#', $url)) {
        $j = embed_http('https://www.tiktok.com/oembed?url=' . urlencode($url));
        if (!empty($j['html'])) return ['provider' => 'tiktok', 'html' => embed_clean($j['html'])];
        return embed_via_library($url, 'tiktok');
    }

    // Reddit -> official oEmbed
    if (str_contains($host, 'reddit.com') && str_contains($url, '/comments/')) {
        $j = embed_http('https://www.reddit.com/oembed?url=' . urlencode($url));
        if (!empty($j['html'])) return ['provider' => 'reddit', 'html' => embed_clean($j['html'])];
        return embed_via_library($url, 'reddit');
    }

    // anything else (Instagram, Vimeo, Twitch, Bluesky, Mastodon, 250+ more):
    // let embed/embed try. Returns null for plain articles (stays a cited link).
    return embed_via_library($url, 'embed');
}

/** r25: Build a twitter-tweet blockquote from X's SYNDICATION CDN (the oEmbed
 *  API is dead). Extract the id, pull canonical JSON via pc_syndication(), and
 *  emit the exact blockquote shape post_cards.php parses+enriches. Returns null
 *  on a deleted/tombstoned/unavailable tweet (the harvest simply skips it). */
function embed_twitter_syndication(string $url): ?array {
    if (!preg_match('#(?:twitter\.com|x\.com)/([A-Za-z0-9_]{1,20})/status/(\d+)#', $url, $m)) {
        return null;
    }
    $handle = $m[1]; $id = $m[2];
    require_once __DIR__ . '/post_cards.php';
    $j = pc_syndication($id);
    if (!$j || trim((string)($j['text'] ?? '')) === '') return null;
    $text   = htmlspecialchars((string)$j['text'], ENT_QUOTES, 'UTF-8');
    $author = htmlspecialchars(trim((string)($j['author'] ?: $handle)), ENT_QUOTES, 'UTF-8');
    $h      = htmlspecialchars(trim((string)($j['handle'] ?: $handle)), ENT_QUOTES, 'UTF-8');
    $date   = '';
    if (!empty($j['created_at'])) {
        $ts = strtotime((string)$j['created_at']);
        if ($ts) $date = date('M j, Y', $ts);
    }
    if ($date === '') $date = date('M j, Y');
    // canonical twitter-tweet blockquote (post_cards.php reads text VERBATIM
    // from <p> and author/handle/id from the trailing &mdash; ... <a status>)
    $html = '<blockquote class="twitter-tweet" data-dnt="true"><p lang="en" dir="ltr">'
          . $text . '</p>&mdash; ' . $author . ' (@' . $h . ') '
          . '<a href="https://twitter.com/' . $h . '/status/' . $id . '">' . $date . '</a></blockquote>';
    return ['provider' => 'twitter', 'html' => embed_clean($html)];
}

/** Rich embed via the embed/embed library (250+ providers). Null if none. */
function embed_via_library(string $url, string $provider = 'embed'): ?array {
    if (!defined('GZH_LIBS') || !GZH_LIBS) return null;
    try {
        $info = (new \Embed\Embed())->get($url);
        $code = $info->code;
        $html = $code ? $code->html : null;
        if (!$html) return null;
        // REJECT WordPress internal oEmbeds (`wp-embedded-content`): news sites (Rolling
        // Stone, etc.) serve a sandboxed embed iframe that needs wp-embed.js AND blocks
        // cross-site framing — so it renders as a BROKEN GRAY BOX on our pages. A news
        // article is not a social post; it stays a cited link, never a broken embed.
        if (stripos($html, 'wp-embedded-content') !== false) return null;
        $prov = strtolower($info->providerName ?: $provider) ?: $provider;
        // normalize the provider tag our lazy-loader understands
        if (str_contains($prov, 'tiktok')) $prov = 'tiktok';
        elseif (str_contains($prov, 'x') || str_contains($prov, 'twitter')) $prov = 'twitter';
        elseif (str_contains($prov, 'reddit')) $prov = 'reddit';
        return ['provider' => $prov, 'html' => embed_clean($html)];
    } catch (\Throwable $e) {
        return null;
    }
}

/** Fill embed_html for all events of a drama whose source is a social URL. */
function embeds_build_for_drama(int $drama_id): array {
    $pdo = db();
    $ev = $pdo->prepare("SELECT e.id, s.url FROM events e JOIN sources s ON s.id=e.source_id
                         WHERE e.drama_id=? AND e.embed_html IS NULL AND s.url IS NOT NULL");
    $ev->execute([$drama_id]);
    $made = 0; $skipped = 0;
    foreach ($ev->fetchAll() as $row) {
        $emb = embed_for_url($row['url']);
        if ($emb) {
            $pdo->prepare("UPDATE events SET embed_html=?, embed_provider=? WHERE id=?")
                ->execute([$emb['html'], $emb['provider'], $row['id']]);
            $made++;
        } else {
            $skipped++;
        }
    }
    return ['embeds' => $made, 'not_embeddable' => $skipped];
}

/**
 * 2026-09-24 A story's posts were embedded once, at draft time; a post the platform did not
 * serve that minute stayed unembedded for good (9 live events citing a post had none).
 * Nightly, capped: each one gets ONE second chance. Still not embeddable (deleted, private)
 * = embed_html '' ("tried twice"), which the builder (IS NULL) and the page (!empty) skip,
 * so a dead post is not fetched every night forever.
 */
function embeds_retry_missing(PDO $pdo, int $cap = 20): array {
    $rows = $pdo->query("SELECT e.id, s.url, p.id page_id FROM events e JOIN sources s ON s.id=e.source_id
                           JOIN dramas d ON d.id=e.drama_id JOIN pages p ON p.id=d.page_id
                          WHERE p.status='published' AND e.video_only=0 AND e.embed_html IS NULL
                            AND s.url REGEXP '(x|twitter)\\\\.com/[^/]+/status/|tiktok\\\\.com/@[^/]+/video/|youtube\\\\.com/watch|youtu\\\\.be/|reddit\\\\.com/r/.+/comments/'
                          ORDER BY e.id LIMIT " . max(1, min(100, $cap)))->fetchAll();
    $made = 0;
    foreach ($rows as $r) {
        $emb = embed_for_url((string)$r['url']);
        if (!$emb) {
            $pdo->prepare("UPDATE events SET embed_html='' WHERE id=? AND embed_html IS NULL")->execute([(int)$r['id']]);
            continue;
        }
        $pdo->prepare("UPDATE events SET embed_html=?, embed_provider=? WHERE id=? AND embed_html IS NULL")
            ->execute([$emb['html'], $emb['provider'], (int)$r['id']]);
        // the page gained its post: a real change, and what makes the page cache (repo_data_version) rebuild
        $pdo->prepare("UPDATE pages SET updated_at=NOW() WHERE id=?")->execute([(int)$r['page_id']]);
        $made++;
    }
    return ['tried' => count($rows), 'embedded' => $made];
}

/**
 * r157 REAL POSTS FROM THE CITED ARTICLES (owner 2026-09-11, pointing at the June/July pages: "it wasn't
 * only screenshots"). A story showed a post only when an event's own source WAS the post: 12% of events
 * in June, 3% in September, because new stories cite news articles. Those articles embed the posts
 * themselves (the reporter's receipts): 130 of 189 September stories cite an article whose body embeds an
 * X, TikTok, YouTube or Reddit post. Only embed markup is read (blockquote.twitter-tweet,
 * blockquote.tiktok-embed, youtube iframes, blockquote.reddit-embed), never menu, footer or share links,
 * and never the publisher's own account. Article order is kept. Returns [['platform','url','handle'], ...].
 */
function embed_posts_in_article(string $html, string $articleHost = ''): array {
    $h = str_replace(['\\u003c', '\\u003e', '\\u0026', '\\/', '&amp;', '&#038;'], ['<', '>', '&', '/', '&', '&'], $html);
    $own = explode('.', (string)preg_replace('/^(www|m|amp)\./', '', strtolower($articleHost)))[0];
    $found = [];   // byte offset => [platform, url, handle]
    if (preg_match_all('#<blockquote[^>]*twitter-tweet[^>]*>(.*?)</blockquote>#is', $h, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $in) {
            if (preg_match_all('#(?:twitter|x)\.com/([A-Za-z0-9_]{1,15})/status/(\d+)#', $in[0], $mm, PREG_SET_ORDER)) {
                $last = end($mm);
                $found[$in[1]] = ['twitter', "https://x.com/{$last[1]}/status/{$last[2]}", $last[1]];
            }
        }
    }
    if (preg_match_all('#<blockquote[^>]*tiktok-embed[^>]*>#is', $h, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $tag) {
            if (preg_match('#cite="(https://www\.tiktok\.com/@([\w.\-]+)/video/\d+)#', $tag[0], $mm)) $found[$tag[1]] = ['tiktok', $mm[1], $mm[2]];
        }
    }
    if (preg_match_all('#<iframe[^>]*src="[^"]*youtube(?:-nocookie)?\.com/embed/([\w\-]{11})#is', $h, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $id) $found[$id[1]] = ['youtube', 'https://www.youtube.com/watch?v=' . $id[0], ''];
    }
    if (preg_match_all('#<blockquote[^>]*reddit-embed[^>]*>(.*?)</blockquote>#is', $h, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $in) {
            if (preg_match('#href="(https://www\.reddit\.com/r/\w+/comments/\w+[^"?]*)#', $in[0], $mm)) $found[$in[1]] = ['reddit', $mm[1], ''];
        }
    }
    ksort($found);
    $out = []; $seen = [];
    foreach ($found as [$plat, $url, $handle]) {
        if (isset($seen[$url])) continue;
        if ($handle !== '' && strlen($own) >= 4 && stripos($handle, $own) !== false) continue;   // the publisher's own account
        $seen[$url] = true;
        $out[] = ['platform' => $plat, 'url' => $url, 'handle' => $handle];
    }
    return $out;
}

/** Post identity (platform:id) so the same post never shows twice on one page. Null for non-posts. */
function embed_post_id(string $urlOrHtml): ?string {
    if (preg_match('#(?:twitter|x)\.com/[A-Za-z0-9_]{1,15}/status/(\d+)#', $urlOrHtml, $m)) return 'x:' . $m[1];
    if (preg_match('#tiktok\.com/@[\w.\-]+/video/(\d+)#', $urlOrHtml, $m)) return 'tt:' . $m[1];
    if (preg_match('#(?:videoid="|youtube\.com/watch\?v=|youtu\.be/|youtube(?:-nocookie)?\.com/embed/)([\w\-]{11})#', $urlOrHtml, $m)) return 'yt:' . $m[1];
    if (preg_match('#reddit\.com/r/\w+/comments/(\w+)#', $urlOrHtml, $m)) return 'rd:' . $m[1];
    return null;
}

/**
 * Give each event with no embed the next unused post embedded in the article it cites (see
 * embed_posts_in_article). The article is read from its archived copy (source_archive), so a backfill
 * sends no request to the publisher; $liveFetch allows a live fetch when no copy exists. One post per
 * event, at most $maxPerPage new posts per story, never a post already on the page. Only providers the
 * story page renders live (X, TikTok, YouTube, Reddit) are written.
 */
function embeds_from_cited_articles(int $drama_id, int $maxPerPage = 6, bool $liveFetch = false): array {
    require_once __DIR__ . '/source_archive.php';
    $pdo = db();
    $st = $pdo->prepare("SELECT e.id, e.embed_html, e.source_id, s.url FROM events e LEFT JOIN sources s ON s.id=e.source_id
                         WHERE e.drama_id=? ORDER BY e.event_date, e.sort_order, e.id");
    $st->execute([$drama_id]);
    $events = $st->fetchAll(PDO::FETCH_ASSOC);
    $onPage = [];
    foreach ($events as $ev) {
        if (!empty($ev['embed_html']) && ($pid = embed_post_id((string)$ev['embed_html']))) $onPage[$pid] = true;
    }
    $articlePosts = []; $made = 0; $noPost = 0; $noCopy = 0;
    foreach ($events as $ev) {
        if ($made >= $maxPerPage) break;
        if (!empty($ev['embed_html']) || empty($ev['source_id']) || empty($ev['url'])) continue;
        $url = (string)$ev['url'];
        if (embed_post_id($url)) continue;                    // the source IS a post: embeds_build_for_drama's job
        $sid = (int)$ev['source_id'];
        if (!isset($articlePosts[$sid])) {
            $html = sa_snapshot_html($pdo, $sid);
            if ($html === null && $liveFetch) {
                require_once __DIR__ . '/fetch_sources.php';
                $html = fs_http_get($url) ?: null;
            }
            $articlePosts[$sid] = $html === null ? [] : embed_posts_in_article($html, (string)parse_url($url, PHP_URL_HOST));
            if ($html === null) $noCopy++;
        }
        $emb = null;
        while ($articlePosts[$sid]) {
            $post = array_shift($articlePosts[$sid]);
            $pid = embed_post_id($post['url']);
            if ($pid === null || isset($onPage[$pid])) continue;
            $try = embed_for_url($post['url']);
            if ($try && in_array($try['provider'], ['twitter', 'tiktok', 'youtube', 'reddit'], true)) { $emb = $try; $onPage[$pid] = true; break; }
        }
        if (!$emb) { $noPost++; continue; }
        $pdo->prepare("UPDATE events SET embed_html=?, embed_provider=? WHERE id=? AND (embed_html IS NULL OR embed_html='')")
            ->execute([$emb['html'], $emb['provider'], (int)$ev['id']]);
        $made++;
    }
    return ['embeds' => $made, 'events_without_post' => $noPost, 'articles_without_copy' => $noCopy];
}
