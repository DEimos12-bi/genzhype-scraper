<?php
// GenZHype | shared helpers.

// Composer libraries (embed/embed, readability.php, simplepie). Guarded so the
// app still runs if vendor/ is ever missing | every consumer keeps a fallback.
$gzh_autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($gzh_autoload)) require_once $gzh_autoload;
define('GZH_LIBS', class_exists(\Embed\Embed::class));

// Escape for HTML output.
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Build an absolute URL from a path.
function url($path = '') {
    global $CONFIG;
    return rtrim($CONFIG['base_url'], '/') . '/' . ltrim($path, '/');
}

// Render a template file with variables, return as string.
function view($__tpl, array $__vars = []) {
    extract($__vars, EXTR_SKIP);
    ob_start();
    include __DIR__ . '/templates/' . $__tpl . '.php';
    return ob_get_clean();
}

// Send a 404 and render the 404 view.
function not_found() {
    http_response_code(404);
    echo view('404');
    exit;
}

/**
 * SEO-BATCH-1: 410 GONE for a page we deliberately retired.
 *
 * Setting robots=noindex and dropping a URL from sitemap.xml does NOT deindex
 * anything — the three pages proven factually wrong were still answering HTTP
 * 200, so Google could keep crawling and ranking them. 410 is the unambiguous
 * "this is permanently gone" signal and is acted on faster than 404.
 * Driven by pages.status='archived', never by a hardcoded slug list.
 */
function gone() {
    http_response_code(410);
    echo view('410');
    exit;
}

/**
 * Encyclopedia inline links (KYM pattern, design study 2026-06-12): inside an
 * ALREADY-ESCAPED body paragraph, link the first mention of every OTHER
 * published term. First occurrence only, whole-word, page-wide cap so body
 * text never turns into link soup.
 */
/**
 * Contextual internal linker (the in-house pattern, generalised from terms-only to terms +
 * creators so DRAMA bodies get internal links too — dramas had none, which starved crawl paths
 * and internal PageRank on the deeper pages Google left "Discovered - not indexed"). Escaped-HTML
 * in, per-paragraph, first-occurrence only, longest names first, self excluded, capped per page.
 * The per-paragraph call is the safety mechanism (it never sees headings/anchors/code).
 */
function linkify_internal(string $escapedHtml, string $currentSlug): string {
    static $map = null, $linked = [], $page = '';
    if ($page !== $currentSlug) { $page = $currentSlug; $linked = []; }
    if ($map === null) {
        $map = [];
        try {
            require_once __DIR__ . '/lanes.php';
            $pdo = db();
            foreach ($pdo->query("SELECT p.slug, t.term name, t.lane FROM pages p JOIN terms t ON t.page_id=p.id
                                  WHERE p.status='published' AND p.robots='index'") as $r) {
                if (mb_strlen($r['name']) < 3) continue;          // 'NPC' ok, 1-2 letter junk not
                $pre = lanes()[$r['lane']]['prefix'] ?? '/slang/';
                $map[] = ['name' => $r['name'], 'url' => $pre . $r['slug'] . '/', 'key' => 't:' . $r['slug']];
            }
            foreach ($pdo->query("SELECT p.slug, c.name FROM pages p JOIN creators c ON c.page_id=p.id
                                  WHERE p.status='published' AND p.robots='index'") as $r) {
                if (mb_strlen($r['name']) < 3) continue;
                $map[] = ['name' => $r['name'], 'url' => '/creator/' . $r['slug'] . '/', 'key' => 'c:' . $r['slug']];
            }
            usort($map, fn($a, $b) => mb_strlen($b['name']) <=> mb_strlen($a['name']));  // longest first
        } catch (Throwable $e) { $map = []; }
    }
    if (!$map || count($linked) >= 8) return $escapedHtml;
    foreach ($map as $m) {
        $key = $m['key'];
        if (str_ends_with($key, ':' . $currentSlug) || isset($linked[$key]) || count($linked) >= 8) continue;
        $pat = '/\b(' . preg_quote(e($m['name']), '/') . ')\b(?![^<]*>)/iu';
        $out = preg_replace($pat, '<a href="' . e($m['url']) . '">$1</a>', $escapedHtml, 1, $n);
        if ($n > 0) { $escapedHtml = $out; $linked[$key] = 1; }
    }
    return $escapedHtml;
}
/** Back-compat alias — term.php calls this; now backed by the generalised linker. */
function linkify_terms(string $escapedHtml, string $currentSlug): string {
    return linkify_internal($escapedHtml, $currentSlug);
}

/**
 * CACHE-BUSTING (2026-06-14): append the file's modified-time as ?v=. When an
 * image is regenerated the URL changes, so browsers reload it instead of
 * showing a stale copy (the card-vs-hero mismatch). Same file -> same URL ->
 * card and hero always match.
 */
function asset_v(?string $path): string {
    if (!$path) return '';
    $m = @filemtime(dirname(__DIR__) . '/public_html' . $path);
    return $m ? ($path . '?v=' . $m) : $path;
}

/** srcset attribute for a webp that has -480/-768 renditions (null if none). Cache-busted. */
function img_srcset(?string $img): ?string {
    if (!$img || !str_ends_with($img, '.webp')) return null;
    $base = substr($img, 0, -5);
    $root = dirname(__DIR__) . '/public_html';
    $set = [];
    foreach ([480, 768] as $w) if (is_file("{$root}{$base}-{$w}.webp")) $set[] = asset_v("{$base}-{$w}.webp") . " {$w}w";
    if (!$set) return null;
    $set[] = asset_v($img) . " 1200w";
    return implode(', ', $set);
}

/**
 * Word-safe truncation. Never cuts mid-word (no broken "...and Un." endings) and
 * drops a dangling connective so a clipped title doesn't end on "and"/"the"/"of".
 * Used by the drafters' char-limit repair so title tags read like a human wrote them.
 */
/** Ensure a meta description / summary reads as a finished thought: drop any dangling
 *  trailing connective and always close on terminal punctuation. Fixes truncated metas
 *  like "...public reactions" (word-safe cut left them hanging mid-sentence). */
function meta_tidy(string $s): string {
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if ($s === '' || preg_match('/[.!?\x{2026}]$/u', $s)) return $s;
    $s = preg_replace('/[\s,;:]+(and|or|but|the|a|an|to|of|in|on|for|as|at|by|from|with|that|which|who|is|are|was|were|its|their|his|her|amid|over|after|before)$/iu', '', $s);
    $s = rtrim($s, " ,;:—–-");
    return $s === '' ? $s : $s . '.';
}

function truncate_words(string $text, int $max): string {
    $text = trim($text);
    if (mb_strlen($text) <= $max) return $text;
    $cut = mb_substr($text, 0, $max);
    if (preg_match('/^(.*\S)\s+\S*$/u', $cut, $m)) $cut = $m[1];            // back off to last whole word
    $cut = rtrim($cut, " ,;:.\xE2\x80\xA6-");
    $cut = preg_replace('/\s+(and|or|the|a|an|of|to|with|for|in|on|after|about|that|as|when)$/iu', '', $cut);
    return rtrim($cut, " ,;:.-");
}

/**
 * Quote extraction (Citation Engine): find a real VERBATIM quoted sentence inside the
 * fetched source excerpts. No LLM -> it can only surface text that genuinely appears
 * in a source, never a hallucination. Returns ['quote'=>.., 'by'=>publisher] or null.
 */
/**
 * Citation tracker (Citation Engine, component 5): log when an AI answer-engine crawler
 * fetches a page (GPTBot/OAI-SearchBot=ChatGPT, PerplexityBot, ClaudeBot, Google-Extended
 * =AI Overviews, etc.). This is the free, real precursor signal to being CITED — if these
 * bots aren't crawling you, no AI engine can cite you. Aggregated per day/bot, shown in
 * the admin Monitor tab. Cheap: only AI-bot requests touch the DB.
 */
/**
 * The AI-bot registry, ported from the community-canonical ai-robots-txt/ai.robots.txt list.
 * token => [operator, category]. Category is the whole point: 'search' = a REAL person's AI query
 * fetched the page live (ChatGPT-User, Perplexity-User, Claude-User, OAI-SearchBot…) = your content
 * was ADOPTED into an answer; 'training' = a scraper adding you to an AI's knowledge base (passive).
 */
function ai_bots(): array {
    return [
        // user-triggered — a live human AI query pulled this page (the high-value "adoption" signal)
        'ChatGPT-User'    => ['OpenAI · ChatGPT', 'search'],
        'OAI-SearchBot'   => ['OpenAI · SearchGPT', 'search'],
        'Claude-User'     => ['Anthropic · Claude', 'search'],
        'Perplexity-User' => ['Perplexity', 'search'],
        'PerplexityBot'   => ['Perplexity', 'search'],
        'DuckAssistBot'   => ['DuckDuckGo', 'search'],
        'Amazonbot'       => ['Amazon · Alexa', 'search'],
        'cohere-ai'       => ['Cohere', 'search'],
        'YouBot'          => ['You.com', 'search'],
        // training / scraping — you're being added to their AI's knowledge (passive)
        'GPTBot'          => ['OpenAI', 'training'],
        'ClaudeBot'       => ['Anthropic', 'training'],
        'anthropic-ai'    => ['Anthropic', 'training'],
        'Claude-Web'      => ['Anthropic', 'training'],
        'Google-Extended' => ['Google · Gemini', 'training'],
        'Bytespider'      => ['ByteDance', 'training'],
        'Meta-ExternalAgent' => ['Meta', 'training'],
        'FacebookBot'     => ['Meta', 'training'],
        'Applebot-Extended' => ['Apple', 'training'],
        'CCBot'           => ['Common Crawl', 'training'],
        'cohere-training-data-crawler' => ['Cohere', 'training'],
        'DeepSeekBot'     => ['DeepSeek', 'training'],
        'Diffbot'         => ['Diffbot', 'training'],
        'Timpibot'        => ['Timpi', 'training'],
        'omgili'          => ['Webz.io', 'training'],
        'Brightbot'       => ['Bright Data', 'training'],
    ];
}

function track_ai_crawler(string $ua, string $path): void {
    if ($ua === '' || stripos($ua, 'GenZHype') !== false) return;          // never count our own build/self-scan agent
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '127.0.0.1' || $ip === '::1') return;                       // never count loopback (our server hitting itself)
    $hit = '';
    foreach (ai_bots() as $token => $meta) if (stripos($ua, $token) !== false) { $hit = $token; break; }
    if ($hit === '') return;
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_crawls (day DATE NOT NULL, bot VARCHAR(40) NOT NULL,
                    hits INT NOT NULL DEFAULT 0, last_path VARCHAR(255), PRIMARY KEY (day,bot)) ENGINE=InnoDB");
        $pdo->prepare("INSERT INTO ai_crawls (day,bot,hits,last_path) VALUES (CURDATE(),?,1,?)
                       ON DUPLICATE KEY UPDATE hits=hits+1, last_path=VALUES(last_path)")
            ->execute([$hit, mb_substr($path, 0, 255)]);
    } catch (Throwable $e) { /* never break a page render over telemetry */ }
}

function find_pull_quote(array $sources, bool $safe = false): ?array {
    // $safe (dramas): skip quotes that state a hard accusation, to avoid republishing
    // a defamatory claim as a featured quote. First-person quotes (the subject's own
    // words) are preferred — primary, on-the-record, and the safest to surface.
    // per-quote dignity filter: drop any quote that is grim/violent/sexual so a
    // featured blockquote can never republish something cruel or unsafe.
    $bad = '/\b(murder\w*|rape\w*|sexual\w*|assault\w*|abus\w*|molest\w*|pedophile|groom(ed|ing)?|kill(ed|ing|s)?|died|dead|death|dying|deceased|funeral|grief|stabb\w*|shot|wound\w*|breathing|unconscious|overdose\w*|suicid\w*|corpse|autopsy|coroner|hospitaliz\w*)\b/i';
    $best = null;
    foreach ($sources as $s) {
        $ex = is_array($s) ? ($s['excerpt'] ?? '') : '';
        if ($ex === '') continue;
        if (!preg_match_all('/[\x{201C}"]([^\x{201D}"]{30,180})[\x{201D}"]/u', $ex, $m)) continue;
        $pub = is_array($s) ? ($s['publisher'] ?? '') : '';
        if ($pub === '' && is_array($s)) $pub = parse_url($s['url'] ?? '', PHP_URL_HOST) ?: 'source';
        foreach ($m[1] as $q) {
            $q = trim($q);
            if (substr_count($q, ' ') < 4 || !preg_match('/[a-z]/', $q) || preg_match('#https?://|\.(com|org|net)\b#i', $q)) continue;
            if ($safe && preg_match($bad, $q)) continue;
            $cand = ['quote' => $q, 'by' => $pub ?: 'source'];
            if (preg_match('/\b(I|I\x27m|we|my|me)\b/', $q)) return $cand;  // first-person -> use immediately
            if (!$best) $best = $cand;
        }
    }
    return $best;
}

/**
 * Outbound-link health check (the PHP stand-in for lychee, which can't run on this
 * host). HEAD-then-GET with redirect following; returns the final status so the link
 * auditor + publish gate can catch broken/hallucinated citations before they ship.
 */
/**
 * SEO-BATCH-1 (2026-08-04): hosts that answer bots with a wall, not the truth.
 *
 * Measured — the audit reported 6 broken source links and only 2 were dead:
 *   reddit.com/r/RecklessBen/...        403 to a bot, 403 to a browser UA, ALIVE
 *   x.com/KSI/status/2065859247871754750  404 to a bot, but the syndication CDN
 *                                         returns the real tweet JSON. ALIVE.
 *   twitter.com/MrBeast/status/1852...    503 to a bot, 200 to a browser UA. ALIVE.
 * A 4-in-6 false-positive rate, and `--fix-dead` would have blanked all of them.
 * These hosts are never reported broken on an HTTP code alone.
 */
function link_botwalled_host(string $host): bool {
    $h = strtolower($host);
    foreach (['reddit.com', 'redd.it', 'x.com', 'twitter.com', 'instagram.com',
              'facebook.com', 'tiktok.com', 'linkedin.com', 'quora.com',
              'medium.com', 'cloudflare.com'] as $d) {
        if ($h === $d || str_ends_with($h, '.' . $d)) return true;
    }
    return false;
}

/**
 * Authoritative existence check for an X/Twitter status, via the same syndication
 * endpoint post_cards.php already relies on. Returns true (alive), false (deleted)
 * or null (could not tell).
 */
function link_tweet_alive(string $url): ?bool {
    if (!preg_match('#(?:x|twitter)\.com/[^/]+/status/(\d{6,})#i', $url, $m)) return null;
    // SEO-BATCH-1: curl, not file_get_contents. Measured on this host: curl gets
    // HTTP 200 + JSON for a live tweet and HTTP 404 + HTML for a missing one,
    // while file_get_contents returned false for BOTH — so this helper answered
    // null for every url it was ever given and verified precisely nothing.
    // curl also hands us the 404 BODY, which is what lets us tell dead from
    // unreachable below.
    $ch = curl_init('https://cdn.syndication.twimg.com/tweet-result?id=' . $m[1] . '&token=x');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Gecko/20100101 Firefox/130.0',
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $raw === '') return null;           // network failure, not a verdict
    if ($code === 404) return false;                          // id does not resolve
    // Discriminate on __typename, NOT on the presence of a "text" key: a
    // TweetTombstone payload CONTAINS a "text" field ("This Post was deleted by
    // the Post author"), so a naive text-key check reports a deleted tweet as
    // alive. That mistake is how x.com/KSI/status/2065859247871754750 was first
    // called ALIVE when it is in fact deleted.
    // SEO-BATCH-1: an HTML body means NOT FOUND, not "could not tell". The
    // syndication endpoint answers JSON for every tweet that exists; when the id
    // does not resolve it serves an HTML error page (measured: HTTP 404, 3656
    // bytes of markup for the fabricated id 1347012726114451456). Returning null
    // there let an INVENTED tweet url read as merely unverifiable.
    if (preg_match('#^\s*<(?:!doctype|html)#i', $raw)) return false;
    $j = json_decode($raw, true);
    $type = is_array($j) ? (string)($j['__typename'] ?? '') : '';
    if ($type === 'TweetTombstone') return false;             // genuinely deleted
    if ($type === 'Tweet' || (is_array($j) && isset($j['user']))) return true;
    return null;                                              // could not tell
}

// CLAIM-5 (2026-08-05): the tweet's CONTENT, from the same syndication CDN the
// liveness check uses. Term pages render verified social citations as dated
// evidence cards; a card needs the post's text and author, and fetching them
// at pageview time is not acceptable — this feeds a store-time/CLI enrichment
// pass, never the render path.
function link_tweet_payload(string $url): ?array {
    if (!preg_match('#(?:x|twitter)\.com/[^/]+/status/(\d{6,})#i', $url, $m)) return null;
    $ch = curl_init('https://cdn.syndication.twimg.com/tweet-result?id=' . $m[1] . '&token=x');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Gecko/20100101 Firefox/130.0',
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$raw || $code !== 200) return null;
    $j = json_decode($raw, true);
    if (!is_array($j) || (($j['__typename'] ?? '') !== 'Tweet' && !isset($j['user']))) return null;
    return [
        'text'   => trim((string)($j['text'] ?? '')),
        'name'   => trim((string)($j['user']['name'] ?? '')),
        'handle' => '@' . trim((string)($j['user']['screen_name'] ?? '')),
        'date'   => substr((string)($j['created_at'] ?? ''), 0, 10),
    ];
}

function link_status(string $url, int $timeout = 12): array {
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) return ['status' => 0, 'final' => $url, 'ok' => false, 'redirected' => false, 'blocked' => false];
    $hit = function (bool $head) use ($url, $timeout) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => $head,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
            // SEO-BATCH-1: a self-identifying bot UA is refused on sight by
            // Reddit, X and anything behind Cloudflare. We are checking whether a
            // link resolves, not scraping content, so present as a normal browser.
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                                 . '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.9',
                                   'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_ENCODING => '',
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return [$code, $final];
    };
    [$code, $final] = $hit(true);
    if (in_array($code, [0, 403, 405, 400, 429, 503], true)) [$code, $final] = $hit(false);  // some hosts block HEAD

    $host    = (string)parse_url($url, PHP_URL_HOST);
    $ok      = ($code >= 200 && $code < 400);
    $blocked = false;

    if (!$ok) {
        // X/Twitter lies to bots: it served 404 for a status that demonstrably
        // exists. Ask the syndication CDN, which is authoritative for existence.
        $tw = link_tweet_alive($url);
        if ($tw === true)  { $ok = true;  $blocked = true; }
        elseif ($tw === false) { $ok = false; $blocked = false; }   // genuinely deleted
        elseif (link_botwalled_host($host) && in_array($code, [0, 401, 403, 405, 429, 451, 503], true)) {
            // A wall, not a dead link. NEVER report these as broken: `--fix-dead`
            // blanks broken URLs, and that would delete live evidence.
            $blocked = true;
        }
    }
    return ['status' => $code, 'final' => $final ?: $url, 'ok' => $ok, 'blocked' => $blocked,
            'redirected' => rtrim($final, '/') !== rtrim($url, '/') && $final !== ''];
}

/**
 * Mobile readability: split any oversized paragraph into scannable chunks at sentence
 * boundaries (target ~maxWords each). Fixes the "wall of text" on existing AND future
 * pages at render time, so no page needs regenerating. Returns a flat list of paragraphs.
 */
function split_long_paras(array $paras, int $maxWords = 70): array {
    $out = [];
    foreach ($paras as $p) {
        $p = trim((string)$p);
        if ($p === '') continue;
        if (str_word_count($p) <= $maxWords) { $out[] = $p; continue; }
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"\x27])/u', $p) ?: [$p];
        $buf = ''; $bw = 0;
        foreach ($sentences as $s) {
            $sw = str_word_count($s);
            if ($bw > 0 && $bw + $sw > $maxWords) { $out[] = trim($buf); $buf = ''; $bw = 0; }
            $buf .= ($buf === '' ? '' : ' ') . $s; $bw += $sw;
        }
        if (trim($buf) !== '') $out[] = trim($buf);
    }
    return $out;
}
