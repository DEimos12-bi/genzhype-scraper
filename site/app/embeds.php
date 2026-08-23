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
