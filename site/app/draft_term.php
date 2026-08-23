<?php
// GenZHype | SLANG drafter. Structures PROVIDED sources into an encyclopedic
// term page that answers the 5 questions (what / when / who / why / what's next).
// Hard rule: the model may ONLY use the supplied excerpts. Never invent.
// Output lands in MySQL as type='term', status='draft', robots='noindex'.

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fetch_sources.php';
require_once __DIR__ . '/gate_term.php';   // SEO-BATCH-1: commodity-source test + truth gate
require_once __DIR__ . '/lanes.php';

function term_slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim(preg_replace('/-+/', '-', $s), '-');
}

/** Strip the classic AI tells (em/en dashes, odd unicode hyphens) so copy reads human. */
function term_dehum(string $s): string {
    $s = str_replace(["\xE2\x80\x94", "\xE2\x80\x95"], ', ', $s);          // em dash, horizontal bar -> comma
    $s = str_replace("\xE2\x80\x93", '-', $s);                              // en dash -> hyphen
    $s = str_replace(["\xE2\x80\x91", "\xC2\xAD", "\xE2\x81\x84"], '-', $s); // non-breaking / soft hyphen
    $s = preg_replace('/ ?, ?, ?/', ', ', $s);                              // collapse doubled commas
    $s = preg_replace('/\s+,/', ',', $s);
    return trim(preg_replace('/[ \t]{2,}/', ' ', $s));
}

/** Recursively de-AI every string in a draft structure. */
function term_clean($v) {
    if (is_string($v)) return term_dehum($v);
    if (is_array($v))  { foreach ($v as $k => $x) $v[$k] = term_clean($x); }
    return $v;
}

/** Branded SVG cover for an entry (dictionary-card look, on-brand). */
function term_make_cover(string $slug, string $term, string $pos, string $kicker = 'GEN Z SLANG / DEFINED', string $sub = 'meaning, origin &amp; how to use it'): string {
    $t   = htmlspecialchars(strtolower($term), ENT_XML1);
    $pe  = htmlspecialchars(strtoupper($pos ?: 'slang'), ENT_XML1);
    $size = mb_strlen($term) > 14 ? 92 : 132;
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630" role="img" aria-label="{$t}">
  <rect width="1200" height="630" fill="#FBFAF8"/>
  <rect x="0" y="0" width="1200" height="12" fill="#C71F12"/>
  <text x="64" y="92" font-family="Georgia, 'Times New Roman', serif" font-size="30" font-weight="800" fill="#1A1814">GENZ<tspan fill="#C71F12">HYPE</tspan></text>
  <text x="64" y="122" font-family="ui-monospace, monospace" font-size="16" letter-spacing="3" fill="#6B6559">{$kicker}</text>
  <text x="600" y="320" text-anchor="middle" font-family="Georgia, 'Times New Roman', serif" font-style="italic" font-size="{$size}" font-weight="900" fill="#1A1814">{$t}</text>
  <text x="600" y="372" text-anchor="middle" font-family="ui-monospace, monospace" font-size="22" letter-spacing="4" fill="#C71F12">{$pe}</text>
  <line x1="450" y1="408" x2="750" y2="408" stroke="#1A1814" stroke-width="2"/>
  <text x="600" y="452" text-anchor="middle" font-family="system-ui, Arial, sans-serif" font-size="22" fill="#4A4538">{$sub}</text>
  <text x="600" y="580" text-anchor="middle" font-family="ui-monospace, monospace" font-size="18" fill="#6B6559">genzhype.com</text>
</svg>
SVG;
    $path = dirname(__DIR__) . '/public_html/assets/covers/' . $slug . '.svg';
    file_put_contents($path, $svg);
    return cover_rasterize($path) ?? ('/assets/covers/' . $slug . '.svg');
}

/** Rasterize a cover SVG to PNG (social crawlers cannot render SVG og:images).
 *  Web font stacks are swapped for fonts INSTALLED ON THIS SERVER (Fraunces in
 *  ~/.fonts + URW Nimbus set) | without this, glyphs render as empty boxes. */
function cover_rasterize(string $svgPath): ?string {
    if (!class_exists('Imagick')) return null;
    try {
        $svg = file_get_contents($svgPath);
        $svg = str_replace("Georgia, 'Times New Roman', serif", 'Fraunces', $svg);
        $svg = str_replace('ui-monospace, monospace', 'Nimbus Mono PS', $svg);
        $svg = str_replace('system-ui, Arial, sans-serif', 'Nimbus Sans', $svg);
        $png = preg_replace('/\.svg$/', '.png', $svgPath);
        $im = new Imagick();
        $im->setBackgroundColor(new ImagickPixel('#FBFAF8'));
        $im->readImageBlob($svg);
        $im->setImageFormat('png24');
        $im->resizeImage(1200, 630, Imagick::FILTER_LANCZOS, 1);
        $im->writeImage($png);
        $im->destroy();
        return '/assets/covers/' . basename($png);
    } catch (Throwable $e) { return null; }
}

/**
 * Find a real example video for an entry via YouTube search (no API key:
 * the results page carries videoIds in its inline JSON). Returns
 * ['html','provider','url','title'] or null. Embeds are platform-sanctioned,
 * so this is the legal way to put real faces/footage on a page.
 */
function term_find_video(string $term, string $lane = 'slang'): ?array {
    require_once __DIR__ . '/embeds.php';
    $q = [
        'slang'  => "$term slang meaning",
        'meme'   => "$term meme",
        'gaming' => "$term gaming meaning",
        'music'  => "$term explained",
    ][$lane] ?? "$term explained";
    $html = fs_http_get('https://www.youtube.com/results?search_query=' . urlencode($q), 20);
    if (!$html) return null;
    if (!preg_match_all('/"videoId":"([A-Za-z0-9_-]{11})"/', $html, $m)) return null;
    $seen = [];
    foreach ($m[1] as $vid) {
        if (isset($seen[$vid])) continue;
        $seen[$vid] = true;
        if (count($seen) > 4) break;                       // top few results only
        $url = "https://www.youtube.com/watch?v={$vid}";
        // oEmbed gives the real title + confirms the video is public/embeddable
        $j = embed_http('https://www.youtube.com/oembed?format=json&url=' . urlencode($url));
        $title = trim($j['title'] ?? '');
        if ($title === '') continue;
        // relevance check: the title must mention the term's first word
        if (stripos($title, explode(' ', $term)[0]) === false) continue;
        $emb = embed_for_url($url);
        if (!$emb) continue;
        return ['html' => $emb['html'], 'provider' => 'youtube', 'url' => $url, 'title' => mb_substr($title, 0, 250)];
    }
    return null;
}

/**
 * Find a real CC-licensed "scene" photo for an entry via Openverse (free, no
 * key, commercial-safe licenses, credit stored). $query is a CONCRETE visual
 * concept (e.g. "couple holding hands sunset"), not the slang word itself.
 */
function term_find_scene(string $query, string $slug, string $lane = 'slang'): ?array {
    require_once __DIR__ . '/images.php';
    require_once __DIR__ . '/vision.php';
    $local_of = fn($cover) => dirname(__DIR__) . '/public_html' . $cover;

    // content words from the motif (used to confirm a candidate is on-topic)
    $stop = ['the','and','with','over','atop','on','in','of','a','an','vintage','illustration','classic','floating','displaying'];
    $words = array_values(array_filter(preg_split('/\W+/', strtolower($query)), fn($w) => mb_strlen($w) > 3 && !in_array($w, $stop, true)));

    // 1) STOCK PHOTO | modern concept shot (only if a Pexels/Pixabay key is set)
    $stock = stock_photo($query);
    if ($stock) {
        $hero = openverse_fetch_hero($stock, $slug . '-scene');
        if ($hero) {
            $v = vision_check_image($local_of($hero['cover']), $query);
            if ($v !== false) return ['img' => $hero['cover'], 'credit' => $hero['credit'], 'credit_url' => $hero['credit_url'], 'matched' => $stock['title']];
            @unlink($local_of($hero['cover']));
        }
    }

    // 2) THE MET | public-domain fine art for the motif (keyless, reliable)
    $met = met_pd_art($query);
    if ($met) {
        $hero = openverse_fetch_hero($met, $slug . '-scene');
        if ($hero) {
            $v = vision_check_image($local_of($hero['cover']), $query);
            if ($v !== false) return ['img' => $hero['cover'], 'credit' => $hero['credit'], 'credit_url' => $hero['credit_url'], 'matched' => $met['title']];
            @unlink($local_of($hero['cover']));
        }
    }

    // 2) OPENVERSE | CC photos/art, title-matched + vision-checked
    foreach ([$query . ' illustration', $query] as $q) {
        foreach (openverse_find_all($q) as $cand) {
            $title = strtolower($cand['title'] ?? '');
            if (preg_match('/findid|find id|hoard|artefact|artifact|excavat|object number|museum number|specimen|\\bcast\\b/', $title)) continue;
            $relevant = false;
            foreach ($words as $w) if (str_contains($title, $w)) { $relevant = true; break; }
            if (!$relevant) continue;
            $hero = openverse_fetch_hero($cand, $slug . '-scene');
            if (!$hero) continue;
            $v = vision_check_image($local_of($hero['cover']), $query);
            if ($v === false) { @unlink($local_of($hero['cover'])); continue; }
            return ['img' => $hero['cover'], 'credit' => $hero['credit'], 'credit_url' => $hero['credit_url'], 'matched' => $cand['title']];
        }
    }
    return null;
}

/**
 * RECEIPT FALLBACK via Reddit. Most slang/gaming terms have no article-embedded
 * tweet/tiktok, so they shipped with a generic GIF. Reddit DOES discuss every
 * term, PullPush is datacenter-safe, and Reddit's client-side embed renders in
 * the visitor's browser (their oEmbed 403s our server IP). The catch: Reddit's
 * top posts for a term are frequently political / offensive / off-topic, so an
 * AI relevance+safety judge is mandatory — a clean GIF beats a toxic receipt.
 */
function term_reddit_candidates(string $term): array {
    $raw = fs_http_get('https://api.pullpush.io/reddit/search/submission/?q='
         . rawurlencode($term) . '&sort_type=score&sort=desc&size=25', 15);
    $j = $raw ? json_decode($raw, true) : null;
    // hard ad-safety prefilter BEFORE the AI even sees them (English + common foreign slurs)
    $bad = '/\b(nigg|fagg|rape|terror|jihad|nazi|kkk|porn|onlyfans|nsfw|suicide|kys|incel|groom)\w*|\b(paki|kanker|tranny|kike|chink|spic|wetback|coon)\b|\bretard/i';
    $out = [];
    foreach ($j['data'] ?? [] as $p) {
        if (!empty($p['over_18'])) continue;
        $title = trim((string)($p['title'] ?? ''));
        $self  = (string)($p['selftext'] ?? '');
        if ($title === '' || in_array($self, ['[removed]', '[deleted]'], true)) continue;
        $hay = $title . ' ' . $self;
        if (!preg_match('/(^|\W)' . preg_quote($term, '/') . '(\W|$)/i', $hay)) continue; // really uses it
        if (preg_match($bad, $hay)) continue;                                              // ad-safety
        if ((int)($p['score'] ?? 0) < 15) continue;                                        // real engagement
        $out[] = ['title' => $title, 'sub' => $p['subreddit'] ?? '', 'user' => $p['author'] ?? '[deleted]',
                  'score' => (int)($p['score'] ?? 0), 'permalink' => 'https://www.reddit.com' . ($p['permalink'] ?? '')];
        if (count($out) >= 8) break;
    }
    return $out;
}

function reddit_receipt_embed(array $c): string {
    $perm  = htmlspecialchars($c['permalink'], ENT_QUOTES);
    $title = htmlspecialchars(mb_substr($c['title'], 0, 140), ENT_QUOTES);
    $sub   = htmlspecialchars($c['sub'], ENT_QUOTES);
    $user  = htmlspecialchars($c['user'], ENT_QUOTES);
    return '<blockquote class="reddit-embed-bq" style="height:316px" data-embed-height="316">'
         . '<a href="' . $perm . '">' . $title . '</a><br> by <a href="https://www.reddit.com/user/' . $user . '">u/' . $user . '</a>'
         . ' in <a href="https://www.reddit.com/r/' . $sub . '/">r/' . $sub . '</a></blockquote>';
}

function term_find_reddit_receipt(string $term, string $lane, array $exclude = [], string $meaning = ''): ?array {
    $pdo = db();
    $cands = array_values(array_filter(term_reddit_candidates($term), function ($c) use ($pdo, $exclude) {
        if (in_array($c['permalink'], $exclude, true)) return false;
        $dup = $pdo->prepare("SELECT 1 FROM terms WHERE scene_embed_url=? OR embed_url=? LIMIT 1");
        $dup->execute([$c['permalink'], $c['permalink']]);
        return !$dup->fetch();
    }));
    if (!$cands) return null;
    $list = '';
    foreach ($cands as $i => $c) $list .= ($i + 1) . ". [r/{$c['sub']}, {$c['score']} upvotes] \"{$c['title']}\"\n";
    $sys = 'You curate "receipts" for a Gen Z slang dictionary: a real social post showing a term used naturally. '
        . 'From these real Reddit posts, pick the ONE that shows "' . $term . '"'
        . ($meaning ? ' (meaning: ' . mb_substr($meaning, 0, 120) . ')' : '') . ' used in its ACTUAL SLANG SENSE. '
        . 'CRITICAL: if a post uses the letters/word with a DIFFERENT meaning (an acronym for a subreddit/brand/community name, '
        . 'someone\'s initials, an unrelated abbreviation, or the literal non-slang meaning), REJECT it — that is NOT a receipt. '
        . 'REJECT if the post names or is about any POLITICIAN, political party, election, or public political figure (in any country). '
        . 'REJECT any profanity, slur, or hate term in ANY language (e.g. non-English slurs), not just English. '
        . 'Also REJECT NSFW, graphic, violent, or national/religious-conflict content. '
        . 'Better to pick null than to ship a post that uses the term in the wrong sense, names a politician, or is off-topic. Output STRICT JSON only.';
    $user = $list . "\nReturn {\"pick\": <number or null>, \"reason\":\"short\"}.";
    $res = ai_chat([['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]], ['nvidia_director', 'gemini', 'openrouter', 'nvidia'], 0.1);
    $jj = ai_json($res['content'] ?? '');
    $pick = $jj['pick'] ?? null;
    if (!$pick || !is_numeric($pick) || !isset($cands[$pick - 1])) return null;
    $c = $cands[$pick - 1];
    return ['html' => reddit_receipt_embed($c), 'provider' => 'reddit', 'url' => $c['permalink'], 'judge' => $jj['reason'] ?? ''];
}

/**
 * Find the REAL viral/source post for a term (the receipts, not stock art).
 * Same article lookup as the drafter (Bing News + KYM for memes), harvested
 * via fs_harvest_social(), DEDUPED against posts already used by other terms,
 * validated through real oEmbed at attach time. Falls back to a judged Reddit
 * receipt. Returns ['html','provider','url'] or null (=> page ships imageless).
 */
function term_find_source_post(string $term, string $lane, array $exclude = []): ?array {
    require_once __DIR__ . '/embeds.php';
    $pdo = db();

    // gather article pages exactly like the feasibility test
    $q = ['slang' => "$term slang", 'meme' => "$term meme", 'gaming' => "$term gaming", 'music' => "$term trend"][$lane] ?? $term;
    $urls = fs_news_search($q, 3);
    if ($lane === 'meme') {
        $sh = fs_http_get('https://knowyourmeme.com/search?q=' . urlencode($term), 15);
        if ($sh && preg_match('#href="(/memes/[a-z0-9-]+)"#i', $sh, $mm)) $urls[] = 'https://knowyourmeme.com' . $mm[1];
    }

    // harvest candidates from each page
    $cands = [];
    foreach (array_slice($urls, 0, 4) as $u) {
        $html = fs_http_get($u, 20);
        if (!$html) continue;
        foreach (fs_harvest_social($html, 4) as $hit) $cands[] = $hit;
        if (count($cands) >= 6) break;
    }
    if (!$cands) return term_find_reddit_receipt($term, $lane, $exclude);

    // order by viral-source priority, dedupe list itself
    $prio = ['tiktok' => 0, 'twitter' => 1, 'youtube' => 2];
    usort($cands, fn($a, $b) => ($prio[$a['provider']] ?? 9) <=> ($prio[$b['provider']] ?? 9));
    $seen = [];
    foreach ($cands as $c) {
        $url = $c['url'];
        if (isset($seen[$url]) || in_array($url, $exclude, true)) continue;
        $seen[$url] = 1;
        // DEDUP guard: never reuse a post already attached to another term
        $dup = $pdo->prepare("SELECT 1 FROM terms WHERE scene_embed_url = ? OR embed_url = ? LIMIT 1");
        $dup->execute([$url, $url]);
        if ($dup->fetch()) continue;
        // oEmbed validation at attach time: real HTML or it doesn't ship
        $emb = embed_for_url($url);
        if ($emb && !empty($emb['html'])) {
            return ['html' => $emb['html'], 'provider' => $emb['provider'], 'url' => $url];
        }
    }
    // no embedded tweet/tiktok found in articles -> judged, ad-safe Reddit receipt
    return term_find_reddit_receipt($term, $lane, $exclude);
}

/** Small JSON GET helper for the lexicographic APIs. */
function term_api_get(string $url): ?array {
    $raw = fs_http_get($url, 15);
    if (!$raw) return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

/**
 * Gather explainer sources for a slang term. Structured, datacenter-friendly
 * APIs first (Wikipedia, Wiktionary, Urban Dictionary), web search only as a
 * last resort. Returns [ ['url','publisher','date','excerpt','title'], ... ].
 */
/**
 * SEO-BATCH-1 : ORIGIN COMES FROM RETRIEVAL, NEVER FROM RECALL.
 *
 * The drafter used to be asked to write an origin, and its prompt told it that
 * when the sources were vague the honest fallback was to say "origins are
 * debated". That instruction is what produced the false pages: for
 * tung-tung-tung-sahur the origin is documented everywhere (a @noxaasht TikTok,
 * 28 Feb 2025, 31M views) but the retrieval never surfaced it, so the drafter
 * dutifully asserted that no origin could be pinpointed.
 *
 * So we now GO AND FIND the artifact before any writing happens, and hand it to
 * the model as input. Returns ['url','date','platform','handle'] or null. Null
 * is a legitimate answer: the caller must then send the term back to the queue
 * rather than write around the hole.
 *
 * Wikipedia/KYM references are mined for the FIRST dated primary link, because
 * those are exactly the pages that carry "originally posted by X on DATE".
 */
/**
 * SUPERSEDED by gate_term_artifact_type() + tfoa_artifact_urls(). Retrieval and
 * the gate now share ONE definition of a valid artifact, so retrieval can never
 * hand the gate something the gate rejects. Kept only because the shape list is
 * still referenced by gate_term_social_post_shape() documentation.
 */
function tfoa_post_patterns(): array {
    // AN ARTIFACT IS A SPECIFIC POST, NEVER A PROFILE. An early version matched
    // any platform URL on the page and returned "https://x.com/SKWrestling_" for
    // tung tung tung sahur — a share-widget profile link. Every pattern requires
    // the post-shaped path (a status id, a video id, a comments thread).
    return [
        'TikTok'    => '#https?://(?:www\.)?tiktok\.com/@[A-Za-z0-9._-]{2,30}/video/\d{6,}#i',
        'X'         => '#https?://(?:www\.)?(?:x|twitter)\.com/[A-Za-z0-9_]{2,30}/status/\d{6,}#i',
        'YouTube'   => '#https?://(?:(?:www\.)?youtube\.com/(?:watch\?v=|shorts/)|youtu\.be/)[A-Za-z0-9_-]{11}#i',
        'Instagram' => '#https?://(?:www\.)?instagram\.com/(?:p|reel)/[A-Za-z0-9_-]{5,}#i',
        'Reddit'    => '#https?://(?:www\.)?reddit\.com/r/[A-Za-z0-9_]{2,30}/comments/[a-z0-9]{5,}#i',
        'Twitch'    => '#https?://(?:www\.)?twitch\.tv/(?:videos/\d{6,}|[A-Za-z0-9_]{2,30}/clip/[A-Za-z0-9_-]{5,})#i',
    ];
}

/**
 * Date shapes, MOST SPECIFIC FIRST so a full date wins over a bare year at the
 * same position. Bare years are accepted now (owner arbitration): gaming origins
 * are genuinely dated to a year — "by 2000", "a 2011 promotional video" — and
 * demanding month+year both zeroed gaming recall and gave the model a reason to
 * invent a month. Truth is enforced by ATTRIBUTION (a source URL stored with the
 * claim), not by precision.
 */
function tfoa_date_re(): string {
    return '/\b(?:(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2},?\s+(?:19|20)\d{2}'
         . '|\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+(?:19|20)\d{2}'
         . '|(?:19|20)\d{2}-\d{2}-\d{2}'
         . '|(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+(?:19|20)\d{2}'
         . '|(?:19|20)\d{2})\b/i';
}

/**
 * Every URL in a block that the GATE would accept as an artifact, in document
 * order. Retrieval defers to gate_term_artifact_type() deliberately: one source
 * of truth means retrieval can never hand the gate something the gate rejects,
 * and expanding the allowlist expands both at once.
 */
function tfoa_artifact_urls(string $html): array {
    if (!preg_match_all('#https?://[^\s"\'<>\\\\)]{6,300}#i', $html, $mm, PREG_OFFSET_CAPTURE)) return [];
    $out = [];
    foreach ($mm[0] as [$raw, $off]) {
        $u = rtrim(html_entity_decode($raw), '.,);\'"');
        $t = gate_term_artifact_type($u);
        if ($t === null) continue;
        $out[] = ['url' => $u, 'type' => $t, 'off' => $off];
    }
    return $out;
}

/**
 * Cut the ORIGIN/HISTORY section out of an encyclopedia-style article.
 *
 * This is the whole point of the Wikipedia/KYM route. On a news page a date and
 * a platform link sitting near each other means very little — that is how
 * `sadge` (a ~2019 Twitch emote) got bound to a 2007 YouTube video whose own
 * upload date happened to sit beside an unrelated embed. Inside a section
 * literally headed "Origin", a dated post link IS the origin claim, and the
 * encyclopedia has already done the editorial work of checking it.
 * Returns the section HTML, or '' when the article has no such heading.
 */
function tfoa_origin_section(string $html): string {
    if (!preg_match_all('#<h[23][^>]*>(.*?)</h[23]>#is', $html, $hm, PREG_OFFSET_CAPTURE)) return '';
    foreach ($hm[1] as $i => [$heading, $_off]) {
        $txt = strtolower(trim(strip_tags($heading)));
        if (!preg_match('/\b(origin|origins|history|background|creation|etymology)\b/', $txt)) continue;
        $start = $hm[0][$i][1] + strlen($hm[0][$i][0]);
        $end   = isset($hm[0][$i + 1]) ? $hm[0][$i + 1][1] : min(strlen($html), $start + 20000);
        return substr($html, $start, max(0, $end - $start));
    }
    return '';
}

/**
 * Find a post-shaped artifact + its date inside a block of HTML.
 * $strict=false is used for encyclopedia ORIGIN sections, where the whole
 * section is already about where the thing came from, so a date anywhere in the
 * section legitimately belongs to the origin claim. $strict=true is used for
 * ordinary web pages, where the date must sit beside the link AND the passage
 * must actually name the term.
 */
function tfoa_scan(string $html, string $term, string $via, bool $strict = true): ?array {
    $dateRe = tfoa_date_re();
    $head   = preg_split('/\s+/', trim($term))[0] ?? $term;
    foreach (tfoa_artifact_urls($html) as $a) {
        {
            $cand = $a['url']; $off = $a['off']; $label = $a['type'];
            if ($strict) {
                $near = strip_tags(substr($html, max(0, $off - 1500), 3000));
                if (!preg_match($dateRe, $near, $dm)) continue;
                if (mb_stripos($near, $term) === false && mb_stripos($near, $head) === false) continue;
            } else {
                // NEAREST date, not the first one. Taking the section's first
                // date gave the right artifact with the WRONG date: the
                // @noxaasht TikTok came back stamped "17 May 2026" (a later
                // event in the same History section) instead of its real
                // 28 Feb 2025. A correct URL wearing someone else's date is
                // still a fabricated fact.
                if (!preg_match_all($dateRe, $html, $dall, PREG_OFFSET_CAPTURE)) continue;
                $best = null; $bestDist = PHP_INT_MAX;
                foreach ($dall[0] as [$dTxt, $dOff]) {
                    $dist = abs($dOff - $off);
                    if ($dist < $bestDist) { $bestDist = $dist; $best = $dTxt; }
                }
                if ($best === null) continue;
                $dm = [$best];
            }
            $handle = '';
            if (preg_match('#/@([A-Za-z0-9._-]{2,30})#', $cand, $hm2)) $handle = '@' . $hm2[1];
            elseif (preg_match('#(?:x|twitter)\.com/([A-Za-z0-9_]{2,30})/status#i', $cand, $hm2)) $handle = '@' . $hm2[1];
            elseif (preg_match('#twitch\.tv/([A-Za-z0-9_]{2,30})/clip#i', $cand, $hm2)) $handle = '@' . $hm2[1];
            return ['url' => $cand, 'date' => trim($dm[0]),
                    'platform' => $label, 'type' => $label, 'handle' => $handle,
                    'via' => $via, 'date_src' => $via];
        }
    }
    return null;
}

/**
 * Read the origin claim the way a person does: the ORIGIN SENTENCE states the
 * date and names the creator, and the actual post URL lives further down in the
 * references. Pairing them by HANDLE is what makes the result verifiable.
 *
 * This exists because scanning for "a post URL with a date near it" got the
 * right artifact with the wrong date on the control case: Wikipedia's History
 * section says "On 28 February 2025 ... TikTok creator @noxaasht uploaded the
 * first reported Tung Tung Tung Sahur post", but the tiktok.com link sits in the
 * citation list beside an access-date of 17 May 2026 — so the scanner stamped
 * the artifact 2026. Prose date + handle-matched URL fixes that, and the handle
 * match doubles as a check that the URL really belongs to the person named.
 */
/**
 * Resolve a Wikipedia footnote marker to the URL it cites.
 *
 * This is the attribution chain the arbitration asks for, done properly. Gaming
 * origin sections state the claim in prose and put the evidence in the
 * REFERENCES, with nothing to pair them by — no @handle, no creator name. But
 * the sentence carries its own citation marker, and that marker is the link:
 *
 *   griefing's History: "...in use by the year 2000 or earlier, as illustrated
 *   by postings to the rec.games.computer.ultima.online USENET group.[2]"
 *   -> cite_note-2 -> https://groups.google.com/group/rec.games.computer.ultima.online/...
 *
 * That is the exact Usenet thread the encyclopedia is citing for the date. No
 * check is loosened: the resolved URL still has to pass gate_term_artifact_type()
 * host verification like any other artifact.
 */
function tfoa_footnote_artifact(string $full, string $noteId): ?array {
    // GOTCHA: Wikipedia's parse API entity-encodes underscores inside id
    // attributes — the reference list entry is literally
    //     <li id="cite&#95;note-2">
    // while the in-text marker is a plain href="#cite_note-2". Matching only
    // the plain form found ZERO references and silently killed this whole path.
    // Delimiter is ~ , NOT # — the '#' inside the &#95; entity closes a
    // #-delimited pattern and throws "Unknown modifier '9'".
    $u  = '(?:_|&#95;)';
    $id = str_replace('_', $u, preg_quote($noteId, '~'));
    $pat = '~<li[^>]*id="cite' . $u . 'note-' . $id . '"[^>]*>(.*?)</li>~is';
    if (!preg_match($pat, $full, $m)) return null;
    $arts = tfoa_artifact_urls($m[1]);
    return $arts ? $arts[0] : null;
}

function tfoa_encyclopedia_origin(string $full, string $section, string $via): ?array {
    // ---- FOOTNOTE PATH (most precise): a date in the origin section, and the
    // citation marker that immediately follows it, resolved to its source URL.
    $dateRe = tfoa_date_re();
    if (preg_match_all($dateRe, $section, $dm2, PREG_OFFSET_CAPTURE)) {
        foreach ($dm2[0] as [$dTxt, $dOff]) {
            // the marker attached to THIS claim: look ahead a short way only
            $ahead = substr($section, $dOff, 900);
            if (!preg_match_all('#cite_note-([A-Za-z0-9_:.\-]+)#', $ahead, $cm)) continue;
            foreach (array_unique($cm[1]) as $noteId) {
                $a = tfoa_footnote_artifact($full, $noteId);
                if (!$a) continue;
                return ['url' => $a['url'], 'date' => trim($dTxt), 'platform' => $a['type'],
                        'type' => $a['type'], 'handle' => '', 'via' => $via,
                        'date_src' => $via];
            }
        }
    }

    $dateRe = tfoa_date_re();
    $txt = preg_replace('/\s+/', ' ', strip_tags($section));
    if (!preg_match_all('/[^.]*?\b(?:uploaded|posted|published|released|debuted|created|originated|first)\b[^.]*\./iu',
                        $txt, $sm)) return null;
    foreach ($sm[0] as $sent) {
        if (!preg_match($dateRe, $sent, $dm)) continue;
        $date = trim($dm[0]);
        // Who is named as the creator? Either an @handle, or a proper-noun name
        // ("a vox pop YouTube channel, Tim & Dee TV, released a video"). Both
        // give us something to TIE THE URL TO, which is what makes the pairing
        // checkable rather than positional.
        $names = [];
        if (preg_match('/@([A-Za-z0-9._]{2,30})/', $sent, $hm)) $names[] = $hm[1];
        if (preg_match_all('/\b([A-Z][A-Za-z0-9\']+(?:\s+(?:&|and)\s+[A-Z][A-Za-z0-9\']+|\s+[A-Z][A-Za-z0-9\']+){0,3})\b/u',
                           $sent, $nm)) {
            foreach ($nm[1] as $n) {
                $n = trim($n);
                // skip sentence-initial noise, months and countries
                if (preg_match('/^(On|In|The|A|An|By|After|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\b/i', $n)) continue;
                // a platform is not a creator — "@YouTube" is not an attribution
                if (preg_match('/^(YouTube|TikTok|Twitter|X|Instagram|Reddit|Twitch|Facebook|Internet|American|British)$/i', $n)) continue;
                if (mb_strlen($n) >= 4) $names[] = $n;
            }
        }
        // NO NAMED CREATOR is normal outside social media. Wikipedia's griefing
        // History says the term was in use "by the year 2000 or earlier, as
        // illustrated by postings to the rec.games.computer.ultima.online USENET
        // group" — a real, sourced origin with no personal handle to match on.
        // In that case take an artifact from the ORIGIN SECTION ITSELF: the whole
        // section is the encyclopedia's origin claim, so a typed artifact inside
        // it belongs to that claim, and the section's own page is the source that
        // attributes the date.
        if (!$names) {
            foreach (tfoa_artifact_urls($section) as $a) {
                return ['url' => $a['url'], 'date' => $date, 'platform' => $a['type'],
                        'type' => $a['type'], 'handle' => '', 'via' => $via, 'date_src' => $via];
            }
            continue;
        }

        foreach (tfoa_artifact_urls($full) as $a) {
            $ctx = strip_tags(substr($full, max(0, $a['off'] - 400), 800));
            foreach ($names as $n) {
                $key = preg_replace('/[^a-z0-9]/i', '', $n);
                if ($key === '') continue;
                // the URL itself carries the handle, OR the citation around
                // it names the same creator the origin sentence named
                if (stripos(preg_replace('/[^a-z0-9]/i', '', $a['url']), $key) === false
                    && stripos(preg_replace('/[^a-z0-9]/i', '', $ctx), $key) === false) continue;
                return ['url' => $a['url'], 'date' => $date, 'platform' => $a['type'],
                        'type' => $a['type'],
                        'handle' => (str_starts_with($n, '@') ? $n : '@' . $n),
                        'via' => $via, 'date_src' => $via];
            }
        }
    }
    return null;
}

/** The Wikipedia article for a term, as parsed HTML (refs included), or ''. */
function tfoa_wikipedia_html(string $term, string $lane): array {
    // MEASURED: opensearch('tung tung tung sahur meme') returns an EMPTY array,
    // while opensearch('tung tung tung sahur') finds the article. Appending the
    // lane word kills the match. So try the BARE term first, then the hinted
    // form (which helps ordinary words like "gank" that collide with unrelated
    // articles).
    $title = null;
    $needle = mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $term));
    foreach ([$term, ($lane === 'meme' ? "$term meme" : "$term $lane")] as $q) {
        $os = term_api_get('https://en.wikipedia.org/w/api.php?action=opensearch&limit=5&format=json&search=' . urlencode($q));
        foreach (($os[1] ?? []) as $cand) {
            $c = mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $cand));
            if ($needle !== '' && (str_contains($c, $needle) || str_contains($needle, $c))) { $title = $cand; break 2; }
        }
    }
    if (!$title) return ['', ''];
    $j = term_api_get('https://en.wikipedia.org/w/api.php?action=parse&format=json&prop=text&redirects=1&page=' . urlencode($title));
    $html = (string)($j['parse']['text']['*'] ?? '');
    // Reject disambiguation / index pages. "gank" matched an 842KB A-Z glossary
    // list whose headings were "0-9 | A | B | C ...", which has no origin claim
    // in it and would only supply noise.
    if ($html !== '') {
        if (stripos($html, 'disambiguation') !== false && strlen($html) < 40000) return ['', ''];
        if (preg_match('#<h[23][^>]*>\s*(?:0[-–]9|A)\s*</h[23]>#i', $html)) return ['', ''];
        if (strlen($html) > 400000) return ['', ''];          // list/index article
        // LANE-FIT CHECK. "camping" matched /wiki/Camping — the outdoor
        // recreation article — and happily returned the Camping and Caravanning
        // Club, founded 1901, as the origin of a gaming behaviour. Same class of
        // error as the PSA acronym collision. An article is only usable if it is
        // recognisably about this lane; otherwise we are reading the wrong topic
        // with total confidence.
        $laneWords = ['gaming' => ['video game', 'gamer', 'gameplay', 'multiplayer', 'esport',
                                   'twitch', 'online game', 'player'],
                      'meme'   => ['meme', 'internet', 'viral', 'tiktok', 'social media'],
                      'slang'  => ['slang', 'internet', 'phrase', 'expression'],
                      'music'  => ['music', 'song', 'album', 'artist']][$lane] ?? [];
        if ($laneWords) {
            $probe = strtolower(strip_tags(substr($html, 0, 60000)));
            $hits = 0;
            foreach ($laneWords as $w) if (str_contains($probe, $w)) $hits++;
            if ($hits < 2) return ['', ''];      // wrong topic entirely
        }
    }
    return [$html, 'https://en.wikipedia.org/wiki/' . str_replace(' ', '_', $title)];
}

/** The Know Your Meme ENTRY page (title-matched), or ''. */
function tfoa_kym_html(string $term): array {
    $sh = fs_http_get('https://knowyourmeme.com/search?q=' . urlencode($term), 15);
    if (!$sh || !preg_match_all('#href="(/memes/[a-z0-9-]+)"#i', $sh, $mm)) return ['', ''];
    $needle = mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $term));
    foreach (array_slice(array_unique($mm[1]), 0, 6) as $path) {
        $url = 'https://knowyourmeme.com' . $path;
        $kp = fs_http_get($url, 12);
        if (!$kp) continue;
        preg_match('#<title>(.*?)</title>#is', $kp, $tm);
        $tsq = mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $tm[1] ?? ''));
        if ($needle !== '' && !str_contains($tsq, $needle)) continue;   // not this meme
        return [$kp, $url];
    }
    return ['', ''];
}

/**
 * SEO-BATCH-1 / Option A : ORIGIN COMES FROM RETRIEVAL, NEVER FROM RECALL.
 *
 * The drafter used to be asked to write an origin, and its prompt told it that
 * when the sources were vague the honest fallback was "origins are debated".
 * That instruction produced the false pages: for tung-tung-tung-sahur the origin
 * is documented everywhere, but retrieval never surfaced it, so the drafter
 * dutifully asserted no origin could be pinpointed.
 *
 * ORDER MATTERS, and it was learned the hard way. The first build searched NEWS
 * articles and scanned them for platform links; it produced confident nonsense
 * (a share-widget profile URL; a 2019 Twitch emote bound to a 2007 video),
 * because on a news page "a dated link near the term" is not the same claim as
 * "this is where it came from". Encyclopedia ORIGIN SECTIONS state that claim
 * explicitly and have already been edited for it. So:
 *      1. Wikipedia  -> Origin/History section
 *      2. Know Your Meme entry -> Origin section
 *      3. news search -> strict proximity (last resort)
 *
 * Returns ['url','date','platform','handle','via'] or null. NULL IS A VALID
 * ANSWER: the caller must send the term back to the queue rather than write
 * around the hole, and the truth gate will refuse the page.
 */
function term_find_origin_artifact(string $term, string $lane = 'slang'): ?array {
    // 1) WIKIPEDIA — the origin section, then the article body.
    [$wHtml, $wUrl] = tfoa_wikipedia_html($term, $lane);
    if ($wHtml !== '') {
        $sec = tfoa_origin_section($wHtml);
        // prose date + handle-matched URL FIRST — the only pairing that is
        // actually verifiable; the positional scans below are weaker fallbacks.
        if ($sec !== '' && ($hit = tfoa_encyclopedia_origin($wHtml, $sec, $wUrl))) return $hit;
        if ($sec !== '' && ($hit = tfoa_scan($sec, $term, $wUrl, false))) return $hit;
    }

    // 2) KNOW YOUR MEME — commodity as a CITATION, but legitimate for origin
    //    DISCOVERY: its Origin section names the post and the date.
    [$kHtml, $kUrl] = tfoa_kym_html($term);
    if ($kHtml !== '') {
        $sec = tfoa_origin_section($kHtml);
        if ($sec !== '' && ($hit = tfoa_encyclopedia_origin($kHtml, $sec, $kUrl))) return $hit;
        if ($sec !== '' && ($hit = tfoa_scan($sec, $term, $kUrl, false))) return $hit;
    }

    // 3) NO NEWS TIER — REMOVED ON EVIDENCE, DO NOT ADD IT BACK.
    //
    // Scanning news articles for platform links produced EVERY false artifact
    // seen while building this, and not one true one:
    //   - "https://x.com/SKWrestling_"  — a share-widget PROFILE link
    //   - sadge (a ~2019 Twitch emote) bound to a 2007 YouTube video, off a
    //     Know Your Meme /videos/ gallery page, which passed even the strict
    //     term+date proximity test because the page does discuss sadge and the
    //     embed does carry its own date.
    // Meanwhile the encyclopedia route produced the one correct answer:
    // @noxaasht's TikTok for tung tung tung sahur, straight out of Wikipedia's
    // History section.
    //
    // The difference is not tunable. On a news page "a dated link near the term"
    // is not the claim "this is where it came from"; inside a section headed
    // Origin/History it is, and an editor has already checked it. Returning a
    // plausible-but-wrong origin is precisely the bug this batch exists to
    // remove, so when the encyclopedias are silent we return NOTHING and the
    // term goes back to the queue.
    return null;
}

/**
 * SEO-BATCH-1: DETERMINISTIC CITATION HARVEST.
 * The model's weak link is QUOTE SELECTION, not evidence: measured across three
 * pogchamp runs on the same excerpts, it produced 2, 1 and 1 valid citations,
 * failing mostly on anaphora — picking "It's often invoked when..." where the
 * sentence two lines up says "the PogChamp emote". The usable sentences are
 * sitting in text WE fetched. So harvest them in code: split each real source's
 * excerpt into sentences, keep the ones that contain the term and survive the
 * gate's own definitional test, and stamp them with the source's own declared
 * publication date and URL. Nothing here is generated — the quote is verbatim
 * fetched text, the date is the page's own declaration — and every harvested
 * row still goes through gate_term_valid_citations() unchanged. This SUPPLIES
 * evidence to the gate; it does not lower it.
 * Shared by draft_term.php and term_redraft.php (both citation writers) so the
 * two paths cannot drift apart an eighth time.
 */
function term_harvest_citations(array $sources, string $term, array $have, int $need = 3): array {
    $out = [];
    $seen = [];
    foreach ($have as $c) $seen[mb_strtolower(trim((string)($c['quote'] ?? '')))] = 1;
    foreach ($sources as $s) {
        if (count($have) + count($out) >= $need + 2) break;          // a little slack, never a flood
        $url  = (string)($s['url'] ?? '');
        $date = trim((string)($s['date'] ?? ''));
        if ($date === '' || $url === '') continue;                    // undated page -> cannot cite honestly
        if (gate_term_is_commodity_source($url)) continue;            // the gate would discard it anyway
        if (gate_term_is_definitional((string)($s['title'] ?? '') . ' ' . $url)) continue;   // explainer article
        $ex = (string)($s['excerpt'] ?? '');
        if (mb_strlen($ex) < 200) continue;
        $per = 0;
        foreach (preg_split('/(?<=[.!?])\s+(?=[A-Z"\'“])/u', $ex) ?: [] as $sent) {
            $sent = trim($sent);
            if (mb_strlen($sent) < 40 || mb_strlen($sent) > 300) continue;
            if (mb_stripos($sent, $term) === false) continue;         // must contain the term itself
            if (gate_term_is_definitional($sent)) continue;           // gloss, not usage
            $k = mb_strtolower($sent);
            if (isset($seen[$k])) continue;
            $seen[$k] = 1;
            $out[] = [
                'platform'    => '',
                'handle'      => '',
                'publication' => (string)($s['publisher'] ?? ''),
                'title'       => mb_substr((string)($s['title'] ?? ''), 0, 200),
                'date'        => $date,
                'url'         => mb_substr($url, 0, 400),
                'quote'       => mb_substr($sent, 0, 300),
            ];
            if (++$per >= 2) break;                                   // max 2 per article
        }
    }
    return $out;
}

/**
 * SEO-BATCH-1: REAL SOURCES FIRST, THEN TRUNCATE.
 * The list was sliced to $want with the commodity hits (Know Your Meme search,
 * Wikipedia, Wiktionary, Urban Dictionary) sitting in the first slots, because
 * they are gathered first. So a hard-won Rolling Stone article fetched in step 4
 * was collected and then SLICED STRAIGHT BACK OFF the end, and the page shipped
 * citing only the tier we compete against. Order by value before cutting.
 */
function tfs_real_first(array $sources, int $cap): array {
    $real = $commodity = [];
    foreach ($sources as $s) {
        if (gate_term_is_commodity_source((string)($s["url"] ?? ""))) $commodity[] = $s;
        else $real[] = $s;
    }
    // SEO-BATCH-1: keep room for the commodity GROUNDING sources on top of the
    // real ones. Raising the real-source floor to 4 filled the whole cap with
    // news and evicted Wikipedia — whose long extract is what feeds body depth —
    // so pogchamp's citations improved (0 valid -> 1) while its body fell 400 ->
    // 307 words and failed the depth check instead. Commodity sources were never
    // meant to compete for slots; they ride along as grounding and simply cannot
    // END the search. Two spare slots restores that without touching a threshold.
    return array_slice(array_merge($real, $commodity), 0, max($cap, 3) + 2);
}

/**
 * SEO-BATCH-1 (owner decision 2026-08-04): TOPICAL FIT LIVES HERE, in the SHARED
 * fetcher, not in one caller.
 *
 * `cracked` was rebuilt from a Lumma Stealer malware article, a Denuvo DRM piece
 * and the Wikipedia page for the humour magazine — all real, all non-commodity,
 * and all about a DIFFERENT SENSE of the word. I first wired the screen into
 * term_redraft.php only, which left the other two call sites unscreened —
 * including draft_term(), the path every NEW page takes. Same sibling drift as
 * db_alive() and the origin instruction: it belongs in the one function all
 * three callers share.
 *
 * Too few surviving sources is NOT a reason to lower the bar. draft_term()
 * already refuses to write on fewer than 2 sources, so the term simply SKIPS and
 * returns to the queue instead of being rebuilt on whatever matched the string.
 */
function fetch_term_sources(string $term, int $want = 3, string $lane = 'slang', string $senseHint = ''): array {
    $sources = fetch_term_sources_raw($term, $want, $lane);
    if (!$sources) return $sources;
    require_once __DIR__ . '/verify_term.php';
    // Derive the DOMINANT CURRENT sense independently — never from a stored
    // short_def. On an already-corrupted page that screens the corruption
    // against itself and cheerfully keeps the wrong-topic sources.
    if (trim($senseHint) === '') {
        $mc = meaning_currency_check($term, '', '');
        $senseHint = trim((string)($mc['dominant_meaning'] ?? ''));
    }
    $keep = sources_topical_fit($term, $lane, $senseHint, $sources);
    $dropped = count($sources) - count($keep);
    foreach ($sources as $i => $s0) {
        if (!in_array($i, $keep, true)) {
            echo "  OFF-TOPIC source discarded: " . substr((string)($s0['url'] ?? ''), 0, 84) . "\n";
        }
    }
    $sources = array_values(array_intersect_key($sources, array_flip($keep)));
    foreach ($sources as $k => $v) $sources[$k]['topical_fit'] = true;   // gate backstop
    if ($dropped > 0) echo "  topical fit: kept " . count($sources) . ", dropped {$dropped}\n";
    return $sources;
}

// SEO-BATCH-1: RETRIEVAL MUST MATCH WHAT THE GATE ASKS FOR.
// The gate requires >= 3 dated citations. Retrieval stopped as soon as it had
// 2 real (non-commodity) sources. But commodity sources can never be cited, and
// neither can an explainer article ("X meaning: ...") — so a term that stopped
// at 2 real sources where one was an explainer had a HARD CEILING of one valid
// citation, and no model, prompt or validator could have bridged the gap.
// Measured on pogchamp: avclub (usable) + inverse (explainer) + wikipedia
// (commodity) = 3 sources, ceiling of 1 citation, gate wants 3.
// Named constant, not a literal, so the two numbers can never drift apart again.
if (!defined('TERM_REAL_SOURCE_FLOOR')) define('TERM_REAL_SOURCE_FLOOR', 4);

function fetch_term_sources_raw(string $term, int $want = 3, string $lane = 'slang'): array {
    $sources = [];
    $today = date('Y-m-d');

    // 0) KNOWYOURMEME | the meme authority (meme lane only). We CITE the search URL,
    // never a deep /memes/ link: grabbing the first /memes/ href caught the search
    // page chrome (the amogus -> ma-got-pranked-ybg-wallace bug that hit 13 pages),
    // and meme slang names rarely match KYM's canonical slug (amogus != among-us),
    // so a deep link can't be verified reliably. The search URL is always relevant.
    // We still pull excerpt text from a TITLE-matched entry to ground the writing.
    if ($lane === 'meme') {
        $searchUrl = 'https://knowyourmeme.com/search?q=' . urlencode($term);
        $sh = fs_http_get($searchUrl, 15);
        $excerpt = '';
        if ($sh && preg_match_all('#href="(/memes/[a-z0-9-]+)"#i', $sh, $mm)) {
            $needle = mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $term));
            foreach (array_slice(array_unique($mm[1]), 0, 6) as $path) {
                $kp = fs_http_get('https://knowyourmeme.com' . $path, 12);
                if (!$kp) continue;
                preg_match('#<title>(.*?)</title>#is', $kp, $tm);                 // match term to the ENTRY title only
                $tsq = mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $tm[1] ?? ''));
                if ($needle === '' || strpos($tsq, $needle) === false) continue;  // not this meme -> skip excerpt
                $t = fs_extract_text($kp);
                if (mb_strlen($t) > 400) { $excerpt = mb_substr($t, 0, 3200); break; }
            }
        }
        $sources[] = [
            'url'       => $searchUrl,
            'publisher' => 'knowyourmeme.com',
            'date'      => $today,
            'excerpt'   => $excerpt ?: "Know Your Meme search results for '{$term}'.",
            'title'     => 'Know Your Meme: ' . $term,
        ];
    }

    // 1) WIKIPEDIA | find the right article via opensearch, then pull its summary
    $wq = $lane === 'meme' ? "$term meme" : $term;
    $os = term_api_get('https://en.wikipedia.org/w/api.php?action=opensearch&limit=3&format=json&search=' . urlencode($wq));
    $wikiTitle = null;
    foreach (($os[1] ?? []) as $cand) {
        if (stripos($cand, $term) !== false || stripos($term, $cand) !== false) { $wikiTitle = $cand; break; }
    }
    if (!$wikiTitle && !empty($os[1][0])) $wikiTitle = $os[1][0];
    if ($wikiTitle) {
        // FULL article plaintext, not the 1-2 sentence REST summary. The summary
        // endpoint starved the drafter (~1-2 sentences of real grounding), which is
        // what pushed thin term pages to either pad or fabricate an origin. The
        // query+extracts API returns the whole article; we cap the excerpt below.
        $extract = '';
        $qx = term_api_get('https://en.wikipedia.org/w/api.php?action=query&prop=extracts&explaintext=1&redirects=1&format=json&titles=' . rawurlencode(str_replace(' ', '_', $wikiTitle)));
        foreach (($qx['query']['pages'] ?? []) as $pg) { $extract = trim((string)($pg['extract'] ?? '')); break; }
        if (mb_strlen($extract) < 200) { // fall back to the summary endpoint if the full extract is unavailable
            $sum = term_api_get('https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $wikiTitle)));
            $extract = trim($sum['extract'] ?? '');
        }
        // only accept if the article is actually about the slang/term (mentions it).
        // Wikipedia is our richest, most reliable source: keep a generous slice
        // (full history + documented variants) so honest pages can reach real depth
        // without padding. Dictionary/crowd sources stay tighter (capped below).
        if (mb_strlen($extract) > 200 && stripos($extract, explode(' ', $term)[0]) !== false) {
            $sources[] = [
                'url'       => 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $wikiTitle)),
                'publisher' => 'en.wikipedia.org',
                'date'      => $today,
                'excerpt'   => mb_substr($extract, 0, 6000),
                'title'     => 'Wikipedia: ' . $wikiTitle,
            ];
        }
    }

    // 2) WIKTIONARY | structured definitions (the lexicographic primary source)
    $wk = term_api_get('https://en.wiktionary.org/api/rest_v1/page/definition/' . rawurlencode(str_replace(' ', '_', strtolower($term))));
    if (!empty($wk['en'])) {
        $parts = [];
        foreach ($wk['en'] as $entry) {
            $pos = $entry['partOfSpeech'] ?? '';
            foreach (($entry['definitions'] ?? []) as $def) {
                $d = trim(html_entity_decode(strip_tags($def['definition'] ?? ''), ENT_QUOTES, 'UTF-8'));
                if ($d !== '') $parts[] = ($pos ? "($pos) " : '') . $d;
                foreach (($def['examples'] ?? []) as $exh) {
                    $ex = trim(html_entity_decode(strip_tags($exh), ENT_QUOTES, 'UTF-8'));
                    if ($ex !== '') $parts[] = "Example: $ex";
                }
            }
        }
        $txt = implode("\n", array_slice($parts, 0, 12));
        if (mb_strlen($txt) > 80) {
            $sources[] = [
                'url'       => 'https://en.wiktionary.org/wiki/' . rawurlencode(str_replace(' ', '_', strtolower($term))),
                'publisher' => 'en.wiktionary.org',
                'date'      => $today,
                'excerpt'   => mb_substr("Wiktionary definitions for '{$term}':\n" . $txt, 0, 3200),
                'title'     => "Wiktionary: {$term}",
            ];
        }
    }

    // 3) URBAN DICTIONARY | how people actually use it (raw material only; the
    //    drafter writes clean copy in our voice, never quotes this directly)
    $ud = term_api_get('https://api.urbandictionary.com/v0/define?term=' . urlencode($term));
    if (!empty($ud['list'])) {
        usort($ud['list'], fn($a, $b) => ($b['thumbs_up'] ?? 0) <=> ($a['thumbs_up'] ?? 0));
        $parts = [];
        foreach (array_slice($ud['list'], 0, 3) as $def) {
            $d = trim(str_replace(['[', ']'], '', $def['definition'] ?? ''));
            $e = trim(str_replace(['[', ']'], '', $def['example'] ?? ''));
            if ($d !== '') $parts[] = "Definition (" . ($def['thumbs_up'] ?? 0) . " upvotes): $d" . ($e !== '' ? "\nUsage example: $e" : '');
        }
        $txt = implode("\n\n", $parts);
        if (mb_strlen($txt) > 100) {
            $sources[] = [
                'url'       => 'https://www.urbandictionary.com/define.php?term=' . urlencode($term),
                'publisher' => 'urbandictionary.com',
                'date'      => $today,
                'excerpt'   => mb_substr("Top community definitions of '{$term}' (informal source; verify against others):\n" . $txt, 0, 2800),
                'title'     => "Urban Dictionary: {$term}",
            ];
        }
    }

    // SEO-BATCH-1: THIS EARLY RETURN IS WHY 78 OF 86 PAGES CITE NOTHING REAL.
    // Steps 0-3 above only ever yield COMMODITY sources (Know Your Meme SEARCH
    // urls, Wikipedia, Wiktionary, Urban Dictionary). The old shortcut fired as
    // soon as any TWO sources existed, so for every term with a Wikipedia entry
    // this function returned BEFORE the Bing News step below ever ran, and the
    // page shipped citing only the tier we are trying to outrank. Measured after
    // the fix went in: slang 4/54, meme 3/17, gaming 1/15 had >=2 real sources.
    // The shortcut now requires REAL sources, so real journalism actually gets
    // fetched. Commodity sources still ride along as grounding, they just cannot
    // end the search on their own.
    $realN = 0;
    foreach ($sources as $s) {
        if (!gate_term_is_commodity_source((string)($s['url'] ?? ''))) $realN++;
    }
    if ($realN >= TERM_REAL_SOURCE_FLOOR) return tfs_real_first($sources, max($want, TERM_REAL_SOURCE_FLOOR));

    // 4) BING NEWS | real publisher articles (never rate-limited); helps the
    //    culture lanes where dictionary sources are thin
    // SEO-BATCH-1: QUOTES KILL THIS SEARCH. Measured against the live backend:
    //   '"tung tung tung sahur" origin first posted date'  -> 0 results
    //   'tung tung tung sahur meme'                        -> 4 real articles
    // Every lane query here was quoted, so step 4 reliably returned NOTHING and
    // the page shipped on the commodity sources from steps 0-3. That is the
    // other half of why 78 of 86 pages cite only Urban Dictionary and wikis
    // (the first half was the early return above, which never even reached
    // here). Unquoted, and try more than one phrasing before giving up.
    $newsQs = ['slang'  => ["$term slang", "$term meaning"],
               'meme'   => ["$term meme", "$term explained"],
               'gaming' => ["$term gaming", "$term meaning"],
               'music'  => ["$term trend", "$term meaning"]][$lane] ?? [$term];
    // Collect from EVERY phrasing before filtering. Stopping at the first five
    // URLs threw away Rolling Stone and Sportskeeda for `hawk tuah` and left the
    // page one real source short of the gate. Most candidates die downstream
    // anyway (paywalled, blocked, or not actually about the term), so gather
    // wide and let the filters do the work.
    $newsHits = [];
    foreach ($newsQs as $nq) {
        foreach (fs_news_search($nq, 5) as $nu) $newsHits[$nu] = 1;
    }
    // REACH (2026-08-05): merge Exa semantic-search hits into the SAME candidate
    // pool, so every downstream filter (fetch, length floor, topical screen,
    // commodity test, real-source floor) applies unchanged. Bing News only sees
    // the news cycle; Exa's index reaches evergreen press — acid-tested on
    // "spawn camping", where Bing gave 0 real sources and Exa returned dated
    // Inverse (2020) + WIRED (2012) articles on the first call. Best-effort:
    // reach_exa_search() answers [] on any failure and costs one HTTP call.
    require_once __DIR__ . '/reach.php';
    foreach (reach_exa_search($term . ' ' . ($lane === 'gaming' ? 'gaming term' : ($lane === 'meme' ? 'meme' : 'slang')) . ' article', 6) as $xh) {
        $newsHits[$xh['url']] = 1;
    }
    foreach (array_keys($newsHits) as $u) {
        // SEO-BATCH-1: the cap must count REAL sources. Stopping at $want
        // total meant 3 commodity hits from steps 0-3 filled the quota and
        // the loop broke after a single real article — one short of the two
        // the truth gate requires. Keep going until we have 2 real ones.
        $realSoFar = 0;
        foreach ($sources as $_s) if (!gate_term_is_commodity_source((string)($_s["url"] ?? ""))) $realSoFar++;
        if (count($sources) >= $want && $realSoFar >= TERM_REAL_SOURCE_FLOOR) break;
        if (count($sources) >= $want + 4) break;   // hard stop
        $html = fs_http_get($u);
        $text = ''; $title = ''; $date = '';
        if ($html) {
            $text = fs_extract_text($html);
            $date = fs_published_date($html);
            if (preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $t)) {
                $title = trim(html_entity_decode(strip_tags($t[1]), ENT_QUOTES, 'UTF-8'));
            }
        } else {
            // REACH (2026-08-05): bot-walled publisher -> Jina Reader fallback
            // (keyless readable markdown; live-verified on Cambridge from this
            // host). Markdown IS readable text; title/date parsed from its
            // header lines, date stays '' when the page declares none.
            $md = reach_jina_read($u, 25);
            if ($md) {
                if (preg_match('/^Title:\s*(.+)$/m', $md, $t)) $title = trim($t[1]);
                if (preg_match('/^Published Time:\s*(\d{4}-\d{2}-\d{2})/mi', $md, $p)) $date = $p[1];
                $text = trim(preg_replace('/\s+/', ' ', preg_replace('/^(Title|URL Source|Published Time|Markdown Content):.*$/mi', '', $md)));
            }
        }
        if (mb_strlen($text) < 350) continue;
        // the article must actually be about the term
        if (stripos($text, explode(' ', $term)[0]) === false) continue;
        $sources[] = [
            'url'       => $u,
            'publisher' => fs_publisher($u),
            // SEO-BATCH-1: the article's OWN date, not today's. See fs_published_date().
            'date'      => $date,
            'excerpt'   => mb_substr($text, 0, 3200),
            'title'     => mb_substr($title, 0, 200),
        ];
    }
    // SEO-BATCH-1: same rule after the news step — only REAL sources may end the
    // search, otherwise the web-search fallback below never gets a turn either.
    $realN = 0;
    foreach ($sources as $s) {
        if (!gate_term_is_commodity_source((string)($s['url'] ?? ''))) $realN++;
    }
    if ($realN >= TERM_REAL_SOURCE_FLOOR) return tfs_real_first($sources, max($want, TERM_REAL_SOURCE_FLOOR));

    // 5) LAST RESORT | web search (can be rate-limited; never rely on it)
    // SEO-BATCH-1: unquoted and shorter, same reason as step 4 above.
    foreach (fs_search("$term meaning origin", 5) as $u) {
        // SEO-BATCH-1: the cap must count REAL sources. Stopping at $want
        // total meant 3 commodity hits from steps 0-3 filled the quota and
        // the loop broke after a single real article — one short of the two
        // the truth gate requires. Keep going until we have 2 real ones.
        $realSoFar = 0;
        foreach ($sources as $_s) if (!gate_term_is_commodity_source((string)($_s["url"] ?? ""))) $realSoFar++;
        if (count($sources) >= $want && $realSoFar >= TERM_REAL_SOURCE_FLOOR) break;
        if (count($sources) >= $want + 4) break;   // hard stop
        $html = fs_http_get($u);
        if (!$html) continue;
        $text = fs_extract_text($html);
        if (mb_strlen($text) < 350) continue;
        $title = '';
        if (preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $t)) {
            $title = trim(html_entity_decode(strip_tags($t[1]), ENT_QUOTES, 'UTF-8'));
        }
        $sources[] = [
            'url'       => $u,
            'publisher' => fs_publisher($u),
            // SEO-BATCH-1: the article's OWN date, not today's. See fs_published_date().
            'date'      => fs_published_date($html),
            'excerpt'   => mb_substr($text, 0, 3200),
            'title'     => mb_substr($title, 0, 200),
        ];
    }
    return tfs_real_first($sources, max($want, TERM_REAL_SOURCE_FLOOR));
}

/**
 * expand_term: deepen an EXISTING thin entry in place (keeps slug, cover, video,
 * images, sources). Re-fetches sources if needed, rewrites the body deeper,
 * updates the row. Returns ['page_id','words'] or ['error'].
 */
function expand_term(int $page_id): array {
    $pdo = db();
    $row = $pdo->prepare("SELECT t.*, p.slug FROM terms t JOIN pages p ON p.id=t.page_id WHERE t.page_id=?");
    $row->execute([$page_id]);
    $t = $row->fetch(PDO::FETCH_ASSOC);
    if (!$t) return ['error' => 'term not found'];
    $jd = fn($v) => (is_array($x = json_decode($v ?? '[]', true)) ? $x : []);
    $lane = $t['lane'] ?? 'slang';

    // rebuild a source block from stored sources (fetch fresh if too few)
    $stored = $jd($t['sources']);
    if (count($stored) < 2) $stored = fetch_term_sources($t['term'], 3, $lane);
    $srcBlock = '';
    foreach ($stored as $i => $s) {
        $ex = $s['excerpt'] ?? ($s['title'] ?? '');
        $srcBlock .= "SOURCE " . ($i + 1) . ": " . ($s['publisher'] ?? '') . " " . ($s['url'] ?? '') . "\n" . mb_substr($ex, 0, 1500) . "\n\n";
    }

    $cur = ['meaning' => $jd($t['meaning']), 'origin' => $jd($t['origin']), 'why_trending' => $jd($t['why_trending']), 'examples' => $jd($t['examples'])];
    $res = ai_chat([
        ['role' => 'system', 'content' => "Deepen a slang/culture encyclopedia entry for '{$t['term']}'. Rewrite ONLY meaning, origin, why_trending and examples to be richer: deepen MEANING/usage (always knowable from usage) for the substance, add origin/why_trending detail ONLY where the sources support it, and NEVER invent a person, date, or view/follower count to reach any word count; examples has at least 4 entries. Keep the same confident non-cringe Gen Z voice. Output STRICT JSON: {\"meaning\":[..],\"origin\":[..],\"why_trending\":[..],\"examples\":[{\"text\":..,\"context\":..}]}"],
        ['role' => 'user',   'content' => "SOURCES:\n{$srcBlock}\nCURRENT (too thin):\n" . json_encode($cur, JSON_UNESCAPED_SLASHES)],
    ], ['nvidia_director', 'gemini', 'openrouter', 'nvidia'], 0.5);
    if (isset($res['error'])) return ['error' => $res['error']];
    $j = ai_json($res['content']);
    if (!$j || empty($j['meaning'])) return ['error' => 'expand returned no JSON'];
    $j = term_clean($j);
    $words = str_word_count(strip_tags(implode(' ', array_merge(
        array_map(fn($x) => is_string($x) ? $x : '', $j['meaning'] ?? []),
        array_map(fn($x) => is_string($x) ? $x : '', $j['origin'] ?? []),
        array_map(fn($x) => is_string($x) ? $x : '', $j['why_trending'] ?? [])
    ))));
    $pdo->prepare("UPDATE terms SET meaning=?, origin=?, why_trending=?, examples=? WHERE page_id=?")
        ->execute([
            json_encode($j['meaning'], JSON_UNESCAPED_UNICODE),
            json_encode($j['origin'] ?: $jd($t['origin']), JSON_UNESCAPED_UNICODE),
            json_encode($j['why_trending'] ?: $jd($t['why_trending']), JSON_UNESCAPED_UNICODE),
            json_encode($j['examples'] ?: $jd($t['examples']), JSON_UNESCAPED_UNICODE),
            $page_id,
        ]);
    $pdo->prepare("UPDATE pages SET updated_at=NOW() WHERE id=?")->execute([$page_id]);
    return ['page_id' => $page_id, 'slug' => $t['slug'], 'words' => $words];
}

/**
 * draft_term: $input = ['term'=>string, 'sources'=>[...]] (>=2 sources).
 * Returns ['page_id'=>,'slug'=>] or ['error'=>].
 */
function draft_term(array $input): array {
    $term = trim($input['term'] ?? '');
    if ($term === '') return ['error' => 'no term given'];
    $lane = $input['lane'] ?? 'slang';
    $L = lanes()[$lane] ?? null;
    if (!$L) return ['error' => "unknown lane '{$lane}'"];
    $sources = $input['sources'] ?? [];
    if (count($sources) < 2) {
        // try to fetch our own
        $sources = fetch_term_sources($term, 3, $lane);
    }
    // OWNER RULE 2026-08-22, same as the drama lane: what we build the page
    // FROM is a source, and the source COUNT is a Google question, not a
    // build question. One real source is enough to write an attributed page;
    // the second is what we want before asking Google to rank it. This single
    // line was rejecting slang/meme/gaming topics outright at build time
    // ('could not gather >= 2 sources for pov meme').
    if (count($sources) < 1) return ['error' => 'no usable source for "' . $term . '"'];

    // Ground-truth check (root fix for confabulated creators/view-counts/dates): an excerpt only counts
    // as real grounding if it carries actual text — a "search results for X" placeholder or a near-empty
    // stub is LABELLED so the model can never mistake it for a source of facts.
    $srcBlock = ''; $realGrounding = 0;
    foreach ($sources as $i => $s) {
        $n = $i + 1;
        $ex = trim((string)($s['excerpt'] ?? ''));
        $isStub = $ex === '' || mb_strlen($ex) < 80 || (bool)preg_match('/search results for/i', $ex);
        if (!$isStub) $realGrounding++;
        $shown = $isStub ? '(no article text retrieved — a search/index stub only; do NOT treat as a source of facts)' : $ex;
        // SEO-BATCH-1: the block used to show publisher+url+excerpt ONLY. A
        // published_usage citation needs a headline and a PUBLICATION DATE, and
        // neither was ever put in front of the model — so it left those fields
        // blank (correctly, rather than inventing) and the gate discarded every
        // citation. Hand it the real date we extracted, and say plainly when we
        // do not have one so the blank stays a blank.
        $sd  = trim((string)($s['date'] ?? ''));
        $st  = trim((string)($s['title'] ?? ''));
        $dsh = $sd !== '' ? $sd : '(publication date not declared by this page — leave date empty for it)';
        $srcBlock .= "SOURCE {$n}: publisher={$s['publisher']} url={$s['url']}\n"
                   . "TITLE: {$st}\nPUBLISHED: {$dsh}\nEXCERPT: {$shown}\n\n";
    }

    $laneHint = [
        'slang'  => "The H1 should read like: What Does '{$term}' Mean?",
        'meme'   => "The H1 should read like: The '{$term}' Meme, Explained. The 'examples' field = notable variants and where the meme shows up. The 'related' field = related memes or formats.",
        'gaming' => "The H1 should read like: What Does '{$term}' Mean in Gaming? The 'examples' field = how it gets used in chat/streams.",
        'music'  => "The H1 should read like: What Is '{$term}'? The 'examples' field = how the trend shows up in the wild.",
    ][$lane];
    $sys = "You are the GenZHype desk. {$L['desk_role']} {$laneHint} ABSOLUTE RULES: "
        . "1) Use ONLY facts present in the provided source excerpts. NEVER invent an origin, date, person, handle, platform, or any view/like/follower/subscriber COUNT the sources do not state — this rule OVERRIDES the depth rule below. It is ALWAYS better to write a shorter, fully-grounded page than a longer one padded with invented specifics; if a specific is not in the excerpts, OMIT it. The ORIGIN is supplied to you by retrieval below; NEVER recall or reconstruct one. "
        . "NEVER write that an origin is debated, undocumented, unclear, impossible to trace, that no creator can be credited, or that the term 'spread through a decentralized process'. Those are claims about the whole internet that you cannot possibly have checked, and writing them is how this site published provably false pages. If no origin artifact was supplied, simply DO NOT discuss origin at all and write only what the sources support. "
        . "1.5) CURRENT MEANING FIRST: slang evolves. Define the DOMINANT CURRENT usage among US Gen Z online (the last ~18 months), even when older dictionary senses exist. If an older or literal sense also exists, cover it in the meaning section explicitly labeled as the older sense, NEVER as the primary definition. Dictionary-style sources often lag; weigh recent social usage over old glossaries. "
        . "2) Write for a US Gen Z reader: clear, confident, never cringe, never parent-explainer tone. No moralizing. "
        . "3) Answer-first: the short_def must define the term in ONE plain sentence a 10-year-old could grasp. "
        . "4) title_tag 40-60 chars, meta_desc 120-132 chars, summary 120-300 chars. "
        . "5) Provide REAL example sentences that show natural usage (you may compose natural example sentences that demonstrate the meaning; mark them as examples, not as quotes from real people). "
        . "6) status_label is the lifecycle: emerging (new), mainstream (everyone uses it), peaking (everywhere right now), fading (on the way out), cringe (now used to mock). Pick from the sources' sense of how current it is. "
        . "7) DEPTH where the sources support it: aim for the best page on this term. Meaning is knowable from usage, so give it as 2-3 full paragraphs of real nuance and the different ways it's used; give origin + why_trending ONLY the substance the sources actually support; write AT LEAST 4 example sentences and AT LEAST 3 FAQs. But NEVER manufacture length by inventing an origin story, a creator, a date, or a view count — a tight, fully-grounded page beats a padded, fabricated one. If origin is undocumented, say so in one honest line instead of inventing it. "
        . "8) Output STRICT JSON only, no commentary.";

    // Competitor Engine: append the live competitive bar so term pages outrank rival entries.
    require_once __DIR__ . '/distill.php';
    try { $sys .= comp_brief_for_drafter(db()); } catch (Throwable $e) { /* no rules yet -> draft as before */ }

    // SEO-BATCH-1: the ORIGIN is RETRIEVED and handed to the model as input.
    // It is never something the model is asked to remember. If retrieval found
    // nothing, we say so plainly and forbid characterising the origin at all —
    // the truth gate will then refuse the page and the term returns to the queue.
    $artifact = $input['origin_artifact'] ?? term_find_origin_artifact($term, $lane);
    $originBlock = $artifact
        ? "ORIGIN ARTIFACT (retrieved, use EXACTLY this and nothing else):\n"
          . "  url: {$artifact['url']}\n  date: {$artifact['date']}\n"
          . "  platform: {$artifact['platform']}\n  handle: " . ($artifact['handle'] ?: '(not resolved)') . "\n"
          . "  found via: {$artifact['via']}\n\n"
        : "ORIGIN ARTIFACT: NONE FOUND by retrieval. Do NOT discuss where the term came from at all. Do not call it debated, undocumented or unclear — write only meaning and usage.\n\n";

    $user = "TERM: {$term}\n\nGROUNDING: {$realGrounding} of " . count($sources) . " sources carry real article text (the rest are search/index stubs). If grounding is thin, define the MEANING + usage you can genuinely support — do NOT fill the gap with invented facts.\n\n{$originBlock}{$srcBlock}\nReturn JSON exactly in this shape:\n{\n"
        . " \"title\": \"H1, e.g. What Does 'Delulu' Mean?\",\n"
        . " \"title_tag\": \"40-60 chars\",\n"
        . " \"meta_desc\": \"120-132 chars\",\n"
        . " \"short_def\": \"ONE-sentence definition, <= 180 chars\",\n"
        . " \"summary\": \"answer-first 120-300 chars: definition + why people use it\",\n"
        . " \"part_of_speech\": \"noun|verb|adjective|interjection|phrase\",\n"
        . " \"pronunciation\": \"simple phonetic, e.g. duh-LOO-loo (or empty)\",\n"
        . " \"category\": \"1-3 words, e.g. dating, gaming, aesthetic, reaction\",\n"
        . " \"also_known_as\": [\"variant or related spelling\"],\n"
        . " \"status_label\": \"emerging|mainstream|peaking|fading|cringe\",\n"
        . " \"first_seen\": \"the DATE from the supplied ORIGIN ARTIFACT (e.g. '28 Feb 2025'), or a date a source explicitly states. If no artifact and no source date, return an EMPTY STRING — never 'debated', never 'not well-documented', never invented\",\n"
        . " \"origin\": [\"the origin ONLY as supported by the supplied ORIGIN ARTIFACT and source excerpts. If no artifact was supplied, omit origin discussion entirely rather than characterising it — never 'debated', never 'undocumented', never a fabricated story, person, date or number\"],\n"
        . " \"meaning\": [\"2-3 full paragraphs fully explaining the meaning, the nuance, and the different ways it gets used\"],\n"
        . " \"why_trending\": [\"1-2 full paragraphs on why it's everywhere now / its current life\"],\n"
        . " \"usage_note\": \"one line: register + is-it-cringe-yet guidance\",\n"
        . " \"examples\": [{\"text\":\"natural example sentence using the term\",\"context\":\"where you'd hear it\"}] (at least 4),\n"
        . " \"related\": [{\"term\":\"related slang word\",\"note\":\"one-line how it relates\"}],\n"
        . " \"image_query\": \"3-6 plain words describing a concrete photographable scene that captures the vibe (never the term itself, never a person name)\",\n"
        // SEO-BATCH-1: TWO citation shapes, matching the gate. This spec used to
        // offer ONLY the social-post shape and demand "a post that actually
        // exists with a real handle" — but fetch_term_sources() supplies
        // JOURNALISM, so the model had no posts to draw on and every new page
        // scored "0 valid". term_redraft.php was given both shapes; this file,
        // the path every NEW page takes, was not. Same sibling drift as
        // db_alive(), topical fit and the origin instruction.
        . " \"citations\": ["
        . "{\"platform\":\"TikTok|X|Reddit|YouTube|Twitch|Instagram\",\"handle\":\"@name\",\"date\":\"any precision\",\"url\":\"post URL on that platform\"},"
        . "{\"publication\":\"outlet name\",\"date\":\"any precision\",\"url\":\"article URL\",\"title\":\"article headline\",\"quote\":\"the sentence from the article where the term is USED\"}"
        . "],\n"
        . "   (at least 3 dated sightings of the term IN USE. TWO kinds are accepted: (a) a real social post — platform, handle, date, post URL; or (b) PUBLISHED USAGE — a publication quoting someone USING the term, with the exact sentence copied verbatim from the source excerpt. The quote MUST contain the term and MUST show it being used, never defined or glossed; an article whose purpose is explaining what the term means is NOT a citation. Take everything ONLY from the source excerpts. An empty list is correct and honest; a fabricated handle is not.\n"
        // SEO-BATCH-1: THE QUOTE-PICKING RULE, made concrete. Measured twice on
        // the SAME AV Club article: run 1 picked Twitch's statement (valid usage)
        // and run 2 picked "PogChamp, which stands for 'Pog Champion,' is one of
        // Twitch's oldest emotes" (a gloss — correctly rejected). The rule was
        // stated abstractly, so which sentence the model grabbed was luck. News
        // articles ABOUT a term are full of glosses; the usable sentences are the
        // ones that treat the term as an ordinary word. Show the contrast.
        . "   HOW TO PICK THE QUOTE — this is where citations usually fail. Choose a sentence that treats the term as an ordinary word it expects you to know. REJECT any sentence that explains it.\n"
        . "     GOOD (term used):    \"We've made the decision to remove the PogChamp emote following statements from the face of the emote.\"\n"
        . "     BAD  (term defined): \"PogChamp, which stands for 'Pog Champion,' is one of Twitch's oldest emotes.\"\n"
        . "   Both sentences sit in the same article. The first uses the word; the second teaches it. Only the first is evidence. Quotes from named people or official statements are usually the best candidates. If an excerpt contains no such sentence, return no citation for that source rather than downgrading to a gloss.\n"
        // SEO-BATCH-1: say out loud which sources can never become citations.
        // Measured on pogchamp: 4 real publisher sources were supplied and the
        // model spent its third citation slot on Wikipedia — which the gate
        // discards as commodity — while an unused AV Club article sat right
        // there. The gate already enforced this; the model was never told.
        . "   WHERE CITATIONS MAY COME FROM: only the news/publisher sources above. NEVER cite Wikipedia, Wiktionary, Urban Dictionary, Know Your Meme, or any dictionary/slang-glossary site — those are excluded by policy and a citation from one is wasted. Prefer three DIFFERENT articles; two articles from the same outlet are fine if each carries a genuinely different usage sentence.)\n"
        . " \"faqs\": [{\"q\":\"...\",\"a\":\"...\"}]\n}";

    // SEO-BATCH-1 RESILIENCE. Measured 2026-08-04, all four providers probed:
    //   gemini            HTTP 429  (free-tier quota spent)
    //   openrouter        HTTP 404  (its free Llama model id is retired)
    //   nvidia            works, but SLOW (28s on a one-word prompt) and the
    //                     long drafting prompt timed out at the default 120s,
    //                     falling through to meta/llama-3.2-90b-vision-instruct
    //                     which answers HTTP 0.
    //   nvidia_director   works, 14.5s
    // So drafting had NO working provider and died with "all providers failed".
    // Same fix the video Director already carries (video_factory.php ~806):
    // put the healthy dedicated endpoint first and give it a real timeout.
    // CLI/cron only — no web caller waits on this.
    $res = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $user],
    ], ['nvidia_director', 'gemini', 'openrouter', 'nvidia'], 0.4, 300);
    if (isset($res['error'])) return $res;

    $j = ai_json($res['content']);
    if (!$j) return ['error' => 'model did not return valid JSON', 'raw' => substr($res['content'], 0, 400)];
    foreach (['title','title_tag','meta_desc','short_def','summary','meaning'] as $k) {
        if (empty($j[$k])) return ['error' => "draft missing field: $k"];
    }
    $j = term_clean($j); // remove AI tells (em dashes, odd hyphens) across all fields

    // TOPICAL-FIT gate (count-binface miss): with the drafted definition in hand, reject entries that
    // are really a named real person / place / product / political topic mis-filed as slang. Adversarial
    // + fail-open — only a CONFIDENT off-lane verdict blocks, so genuine new coinages still publish.
    require_once __DIR__ . '/verify_term.php';
    $fit = topical_fit_check($term, $lane, $j['short_def'] ?? '');
    if (!$fit['pass']) return ['error' => "off-lane term rejected by topical-fit gate: {$term} ({$fit['why']})"];

    // MEANING CURRENCY CONTROL (system rule, crash-out case 2026-06-12): a
    // DIFFERENT provider audits whether the drafted sense is the dominant
    // CURRENT usage. Confident outdated/wrong => one self-correcting rewrite.
    // Unknown/verifier-down => proceed (new coinages must still publish).
    $meaningVerdict = null;
    try {
        require_once __DIR__ . '/verify_term.php';
        $mc = meaning_currency_check($term, $j['short_def'], $j['summary'] ?? '');
        $meaningVerdict = $mc;
        if (!$mc['pass'] && $mc['dominant_meaning'] !== '') {
            $fix2 = meaning_correct_fields($term, $j, $mc['dominant_meaning']);
            if ($fix2) {
                unset($fix2['res']);
                $j = array_merge($j, array_filter($fix2, fn($v) => $v !== '' && $v !== []));
                echo "  MEANING-CORRECTED {$term}: drafted sense was {$mc['verdict']} (conf {$mc['confidence']}); now: " . mb_substr($mc['dominant_meaning'], 0, 90) . "\n";
            }
        }
    } catch (Throwable $e) { /* meaning check never blocks a draft */ }

    // AUTO-EXPAND: the gate needs >=380 body words. Models often write short;
    // one corrective pass adds genuine depth from the same sources (no filler).
    $bodyWords = function ($x) {
        $t = '';
        foreach (['meaning', 'origin', 'why_trending'] as $f) foreach ((array)($x[$f] ?? []) as $p) $t .= ' ' . (is_string($p) ? $p : '');
        return str_word_count(strip_tags($t));
    };
    if ($bodyWords($j) < 400) {
        $r2 = ai_chat([
            ['role' => 'system', 'content' => "Expand a slang/culture entry. Rewrite ONLY the 'meaning', 'origin', 'why_trending' and 'examples' fields to be richer: deepen MEANING/usage (always knowable from usage) for the substance, add origin/why_trending detail ONLY where the sources support it, and NEVER invent a person, date, or view/follower count to hit any length; 'examples' has at least 4 entries. Keep the exact JSON keys. Output STRICT JSON: {\"meaning\":[..],\"origin\":[..],\"why_trending\":[..],\"examples\":[{\"text\":..,\"context\":..}]}"],
            ['role' => 'user',   'content' => "SOURCES:\n{$srcBlock}\nCURRENT (too short):\n" . json_encode(['meaning' => $j['meaning'] ?? [], 'origin' => $j['origin'] ?? [], 'why_trending' => $j['why_trending'] ?? [], 'examples' => $j['examples'] ?? []], JSON_UNESCAPED_SLASHES)],
        ], ['nvidia_director', 'gemini', 'openrouter', 'nvidia'], 0.5);
        if (!isset($r2['error'])) {
            $j2 = ai_json($r2['content']);
            if ($j2 && !empty($j2['meaning']) && $bodyWords($j2) > $bodyWords($j)) {
                $j2 = term_clean($j2);
                foreach (['meaning', 'origin', 'why_trending', 'examples'] as $f) if (!empty($j2[$f])) $j[$f] = $j2[$f];
            }
        }
    }

    // auto-repair exact char limits (same approach as drama drafter)
    $fix = function (string $text, string $what, int $min, int $max) {
        $len = mb_strlen($text);
        if ($len >= $min && $len <= $max) return $text;
        $r = ai_chat([
            ['role' => 'system', 'content' => "Rewrite the {$what} to be between {$min} and {$max} characters. Keep the meaning and the confident, non-cringe tone. Reply with ONLY the rewritten text, no quotes."],
            ['role' => 'user',   'content' => $text],
        ], ['nvidia_director', 'gemini', 'openrouter', 'nvidia'], 0.2);
        $out = isset($r['content']) ? trim($r['content'], " \n\"'") : $text;
        if (mb_strlen($out) > $max) $out = truncate_words($out, $max);          // word-safe: no "...and Un." mid-word cut
        if (mb_strlen($out) < $min && mb_strlen($text) >= $min) $out = truncate_words($text, $max);
        return $out;
    };
    $j['title_tag'] = $fix($j['title_tag'], 'title tag', 40, 60);
    $j['meta_desc'] = meta_tidy($fix($j['meta_desc'], 'meta description', 120, 132));
    $j['summary']   = $fix($j['summary'], 'answer-first summary', 120, 300);
    if (mb_strlen($j['short_def']) > 180) $j['short_def'] = rtrim(mb_substr($j['short_def'], 0, 179), ' ,;.') . '.';

    $pos = in_array($j['part_of_speech'] ?? '', ['noun','verb','adjective','interjection','phrase']) ? $j['part_of_speech'] : 'phrase';
    $status = in_array($j['status_label'] ?? '', ['emerging','mainstream','peaking','fading','cringe']) ? $j['status_label'] : 'mainstream';

    $pdo  = db();
    // SEO-BATCH-1: retrieval + drafting above can run for minutes of HTTP, which
    // idles the cached connection past MySQL's wait_timeout. Reconnect before the
    // writes rather than throw away a finished draft ("MySQL server has gone
    // away" killed the first gaming draft at exactly this line).
    $pdo = db_alive();
    $slug = term_slugify($term);
    $dup  = $pdo->prepare("SELECT id FROM pages WHERE slug=?");
    $dup->execute([$slug]);
    if ($dup->fetch()) return ['error' => 'a page for slug "' . $slug . '" already exists'];
    // near-duplicate guard: "brainrot" vs "brain-rot" must never become two pages
    $norm = str_replace('-', '', $slug);
    $nd = $pdo->prepare("SELECT slug FROM pages WHERE REPLACE(slug,'-','') = ? AND type='term' LIMIT 1");
    $nd->execute([$norm]);
    if ($hit = $nd->fetchColumn()) return ['error' => 'near-duplicate of existing page "' . $hit . '" already exists'];

    $coverSub = ['slang' => 'meaning, origin &amp; how to use it', 'meme' => 'origin, spread &amp; the variants', 'gaming' => 'what it means in the chat', 'music' => 'the trend, explained'][$lane];
    $cover = term_make_cover($slug, $term, $pos, $L['kicker'], $coverSub);

    // SEO-BATCH-1 ORIGIN-OPTIONAL, ENFORCED IN CODE.
    // If retrieval produced no artifact, the page asserts NOTHING about where
    // the term came from — no origin paragraphs, no first_seen. This is done
    // here rather than by asking the model nicely, because "please omit the
    // origin" is exactly the kind of instruction that gets partially obeyed,
    // and a half-obeyed origin is an unverified origin. Cambridge ranks #1 for
    // "delulu meaning" with no etymology at all; absence is publishable,
    // invention is not.
    if (!$artifact) {
        $j['origin'] = [];
        $j['first_seen'] = '';
    }

    // normalise source list for storage
    $srcStore = [];
    foreach ($sources as $s) {
        $srcStore[] = [
            'url'       => $s['url'],
            'publisher' => $s['publisher'] ?? (parse_url($s['url'], PHP_URL_HOST) ?: 'source'),
            'title'     => $s['title'] ?? '',
            // SEO-BATCH-1: CARRY THE SCREENING VERDICT. This rebuild used to drop
            // `topical_fit`, so sources that HAD been screened were stored without
            // the stamp and the gate's backstop then reported "never screened for
            // topical fit" on every page. The check was running; only its result
            // was being thrown away one line before it was persisted.
            'topical_fit' => ($s['topical_fit'] ?? false) === true,
            // SEO-BATCH-1: and carry the PUBLICATION DATE for the same reason.
            // fs_published_date() extracts it, the prompt shows it to the model,
            // and this rebuild then dropped it — so the stored row read
            // "date: (none)" and nothing downstream (audit, re-gate, redraft)
            // could ever tell a 2021 article from a 2026 one.
            'date'        => (string)($s['date'] ?? ''),
        ];
    }

    // SEO-BATCH-1: reconnect HERE, not before the cover build. term_make_cover()
    // renders an SVG and rasterizes it, which is slow enough that the connection
    // opened earlier had already died by the time we reached COMMIT — the insert
    // statements ran, then commit() threw "There is no active transaction" and
    // the finished draft was lost. Open the transaction on a connection that is
    // known-live as of this instant.
    $pdo = db_alive();
    // SEO-BATCH-1: the schema check MUST run OUTSIDE the transaction. It was
    // called between the two INSERTs, and gate_term_install() issues ALTER
    // TABLE — which MySQL treats as an IMPLICIT COMMIT. That silently ended the
    // transaction mid-write, so commit() then threw "There is no active
    // transaction" and every draft was reported as failed even though both rows
    // had actually landed. Idempotent, so calling it here costs nothing.
    gate_term_install($pdo);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO pages (type,slug,path,h1,title_tag,meta_desc,summary,status,robots,author_id,cover,published_at,updated_at)
                       VALUES ('term',?,?,?,?,?,?,'draft','noindex',1,?,NOW(),NOW())")
            ->execute([$slug, $L['prefix'] . $slug . '/', $j['title'], $j['title_tag'], $j['meta_desc'], $j['summary'], $cover]);
        $pageId = (int)$pdo->lastInsertId();

        // SEO-BATCH-1: persist the RETRIEVED artifact + the model's dated
        // citations so gate_check_term() can verify evidence rather than prose.
        $citeStore = [];
        foreach ((array)($j['citations'] ?? []) as $c) {
            if (!is_array($c)) continue;
            // SEO-BATCH-1: `publication` and `title` were asked for by the prompt
            // and READ by the gate (the explainer check builds $titleish from
            // title+url) but never persisted here — so every published_usage
            // citation reached the gate stripped of its outlet and headline.
            // Sixth sibling drift: the prompt was fixed, the writer was not.
            $citeStore[] = [
                'platform'    => mb_substr((string)($c['platform'] ?? ''), 0, 40),
                'handle'      => mb_substr((string)($c['handle'] ?? ''), 0, 60),
                'publication' => mb_substr((string)($c['publication'] ?? ''), 0, 80),
                'title'       => mb_substr((string)($c['title'] ?? ''), 0, 200),
                'date'        => mb_substr((string)($c['date'] ?? ''), 0, 32),
                'url'         => mb_substr((string)($c['url'] ?? ''), 0, 400),
                'quote'       => mb_substr((string)($c['quote'] ?? ''), 0, 300),
            ];
        }
        // SEO-BATCH-1: top up with machine-harvested citations when the model's
        // own picks fall short of the gate's 3. Only rows that INDEPENDENTLY
        // clear gate_term_valid_citations() are added — the gate is untouched.
        $validNow = gate_term_valid_citations($citeStore, $term);
        if (count($validNow) < 3) {
            foreach (term_harvest_citations($sources, $term, $citeStore) as $hc) {
                if (count(gate_term_valid_citations([$hc], $term)) === 1) $citeStore[] = $hc;
                if (count(gate_term_valid_citations($citeStore, $term)) >= 3) break;
            }
        }
        $pdo->prepare("INSERT INTO terms
            (page_id,lane,term,also_known_as,part_of_speech,pronunciation,short_def,category,origin,first_seen,usage_note,status_label,meaning,why_trending,examples,related,faqs,sources,origin_url,origin_date,origin_date_src,origin_type,citations)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $pageId,
                $lane,
                $term,
                json_encode($j['also_known_as'] ?? [], JSON_UNESCAPED_UNICODE),
                $pos,
                mb_substr($j['pronunciation'] ?? '', 0, 120),
                mb_substr($j['short_def'], 0, 300),
                mb_substr($j['category'] ?? '', 0, 60),
                json_encode($j['origin'] ?? [], JSON_UNESCAPED_UNICODE),
                mb_substr($j['first_seen'] ?? '', 0, 160),
                mb_substr($j['usage_note'] ?? '', 0, 500),
                $status,
                json_encode($j['meaning'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($j['why_trending'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($j['examples'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($j['related'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($j['faqs'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($srcStore, JSON_UNESCAPED_UNICODE),
                $artifact['url']      ?? null,
                $artifact['date']     ?? null,
                $artifact['date_src'] ?? null,   // SEO-BATCH-1: what STATES the date
                $artifact['type']     ?? null,   // social_post|archive|official|video|lexicographic
                json_encode($citeStore, JSON_UNESCAPED_UNICODE),
            ]);
        $pdo->commit();
    } catch (Throwable $e) {
        // rollBack() itself throws "There is no active transaction" when the
        // connection dropped mid-transaction — and that second exception MASKED
        // the real insert error entirely. Guard it so the actual cause survives.
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $ignored) {}
        return ['error' => 'db insert failed: ' . $e->getMessage()];
    }

    // real-life layer: attach an example video embed (best-effort, never fatal)
    try {
        $vid = term_find_video($term, $lane);
        if ($vid) {
            $pdo->prepare("UPDATE terms SET embed_html=?, embed_provider=?, embed_url=?, embed_title=? WHERE page_id=?")
                ->execute([$vid['html'], $vid['provider'], $vid['url'], $vid['title'], $pageId]);
        }
    } catch (Throwable $e) { /* page works without video */ }

    // FEATURED IMAGE (image beast, operator approved 2026-06-12): reasoning
    // chain - classify concept -> meme DB / stock / generation -> vision judge.
    // Memes get THE actual meme + an inline gallery (the KYM gap). The beast
    // falls back to the legacy engine internally; never imageless.
    try {
        require_once __DIR__ . '/image_beast.php';
        // ALL term lanes: pull REAL internet content from GIPHY by the term name
        // (real memes for meme lane, real reaction content for slang/gaming) ->
        // featured + 3 stills = 3-4 REAL images, no stock. Beast only if GIPHY empty.
        $memeDone = false;
        $mr = meme_real_images($term, $slug, $j['short_def'] ?? '');
        if ($mr) {
            $pdo->prepare("UPDATE pages SET cover=?, featured_img=?, cover_credit=?, cover_credit_url=? WHERE id=?")
                ->execute([$mr['img'], $mr['img'], $mr['credit'], $mr['credit_url'], $pageId]);
            echo "  IMG {$slug}: {$mr['count']} REAL images (GIPHY)\n";
            $memeDone = true;
        }
        if (!$memeDone) {
            $imgCtx = trim(implode(' | ', array_filter([
                $j['first_seen'] ?? '',
                is_array($j['origin'] ?? null) ? ($j['origin'][0] ?? '') : '',
                is_array($j['examples'] ?? null) ? ($j['examples'][0]['text'] ?? '') : '',
            ])));
            $feat = fetch_featured_image(['page_type' => 'term', 'subject' => $term,
                                          'meaning' => $j['short_def'], 'slug' => $slug, 'people' => [],
                                          'context' => $imgCtx], ['gallery' => true]);
            if ($feat) {
                $pdo->prepare("UPDATE pages SET cover=?, featured_img=?, cover_credit=?, cover_credit_url=? WHERE id=?")
                    ->execute([$feat['img'], $feat['img'], $feat['credit'], $feat['credit_url'], $pageId]);
                echo "  IMG {$slug}: {$feat['source']} fit=" . var_export($feat['fit_score'], true) . "\n";
            }
        }
    } catch (Throwable $e) { /* branded card remains as cover */ }

    // in-article receipt (unchanged): the ACTUAL viral/source post, embedded.
    try {
        $own = $pdo->prepare("SELECT embed_url FROM terms WHERE page_id=?");
        $own->execute([$pageId]);
        $post = term_find_source_post($term, $lane, array_filter([$own->fetchColumn() ?: null]));
        if ($post) {
            $pdo->prepare("UPDATE terms SET scene_embed_html=?, scene_embed_provider=?, scene_embed_url=? WHERE page_id=?")
                ->execute([$post['html'], $post['provider'], $post['url'], $pageId]);
        }
    } catch (Throwable $e) { /* page works imageless */ }

    ai_log($pageId, 'draft', $res, ['term' => $term, 'fields' => array_keys($j)], true);
    if ($meaningVerdict !== null) {
        ai_log($pageId, 'verify', $meaningVerdict['res'] ?? [],
               ['type' => 'meaning', 'verdict' => $meaningVerdict['verdict'],
                'confidence' => $meaningVerdict['confidence'],
                'corrected' => !$meaningVerdict['pass'] && $meaningVerdict['dominant_meaning'] !== ''],
               $meaningVerdict['pass']);
    }
    return ['page_id' => $pageId, 'slug' => $slug, 'provider' => $res['provider']];
}
