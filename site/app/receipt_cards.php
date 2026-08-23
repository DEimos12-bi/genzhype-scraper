<?php
// GenZHype | RECEIPT-CARD generator (v6 "platform-native evidence", server side).
//
// Owner's round-6 verdict: evidence must look like it comes FROM THE SOURCE,
// not from us — "we're not as big as those platforms to be the evidence".
// So an event card now renders as a NEUTRAL PRESS-CLIPPING SNAPSHOT: warm
// newsprint paper, the SOURCE PUBLISHER as the masthead (big, top, centered),
// a dated line, the event headline + the event's REAL text as the clipping
// body, monochrome editorial ink. ZERO GenZHype branding — the only addition
// is the small "source: {domain}" provenance line. Our brand appears ONLY on
// the separate PROMO card (kind 'promo', appended LAST) which the Director
// may use solely on the final CTA line / when the script says "GenZHype".
//
// REAL DATA ONLY: every string comes verbatim from the DB. No AI, no
// invention, no summarizing. Pure Imagick (GD fallback) — fast enough to run
// inside the web feed (video_next.php) without a 504.
//
// Storage: public_html/assets/receipts/video/{page_id}-{idx}.png (0644),
// promo at {page_id}-promo.png. Idempotent: a per-page sidecar {page_id}.json
// stores a content hash per card; a card is re-rendered only when its file is
// missing or the event's text/date/source changed (events carry no
// updated_at, so the hash IS the freshness signal). v6 bumps the salt (v2)
// so every existing card regenerates in the clipping style.
//
// v7 (owner's round-7 deploy-blocker): a SOCIAL PLATFORM is never a masthead.
// Events sourced to twitter/x/instagram/tiktok/youtube/... get NO clipping;
// a twitter/x event instead has its REAL post recovered from the status id
// (post_cards.php) or nothing at all. Event-hash salt bumped to v3 so every
// platform-mastheaded clipping regenerates away on the next touch.
//
// v8 (owner's round-8 verdict): a PUBLISHER is never a masthead either — a
// giant "DEXERTO.COM" head renders a direct competitor's brand as the star of
// OUR video. The clipping masthead is now a NEUTRAL EVIDENCE HEADER: the big
// letterspaced event DATE ("JUN 8, 2026"), with a small "TIMELINE" dateline
// under the double rule. The publisher moves EXCLUSIVELY to the small footer
// "source: {domain}" line (honesty preserved, promotion killed). Event-hash
// salt bumped to v4 so every publisher-mastheaded clipping regenerates.
//
// r17 (owner's round-17 verdict): the beige rendered event cards are RETIRED
// COMPLETELY — they surfaced competitor names and read as fake. An 'event'
// receipt is now METADATA ONLY (url=''): the maker resolves it down the real
// evidence chain — clean article screenshot > the article's real og:image
// photo > subject photo. NEVER a beige card (the renderers are deleted, the
// old -N.png files are pruned on every touch). The X-look POST cards and the
// single branded PROMO card (ours, final-CTA only) are unchanged; the idx
// address space the Director points into is unchanged too.

if (empty($GLOBALS['CONFIG']) && is_file(__DIR__ . '/config.php')) {
    $GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
}
if (!function_exists('url')) { require_once __DIR__ . '/helpers.php'; }

// ---------------------------------------------------------------------------
// text + date helpers
// ---------------------------------------------------------------------------

/** Normalize font-unsafe punctuation (mirror of social_card.mjs `norm`):
 *  fancy dashes/quotes/ellipsis -> plain ASCII so no tofu glyphs, and per the
 *  house rule no em-dashes ever reach a rendered asset. */
function rc_norm(string $s): string {
    $s = str_replace(["\u{2018}", "\u{2019}", "\u{02BC}"], "'", $s);
    $s = str_replace(["\u{201C}", "\u{201D}"], '"', $s);
    $s = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $s) ?? $s;
    $s = str_replace("\u{2026}", '...', $s);
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/** Partial-date tolerant label: '2025-00-00' -> '2025', '2025-10-00' ->
 *  'Oct 2025', '2026-05-27' -> 'May 27, 2026'. */
function rc_date_label(?string $d): string {
    if (!$d || $d === '0000-00-00') return 'UNDATED';
    [$y, $m, $day] = array_pad(explode('-', $d), 3, '0');
    $y = (int)$y; $m = (int)$m; $day = (int)$day;
    if ($y <= 0) return 'UNDATED';
    $mon = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    if ($m < 1 || $m > 12) return (string)$y;
    if ($day < 1) return $mon[$m] . ' ' . $y;
    return $mon[$m] . ' ' . $day . ', ' . $y;
}

/** The site's real font files (social_card ships Display 800 + Inter). */
function rc_fonts(): array {
    $display = null; $body = null;
    foreach ([__DIR__ . '/social_card/fonts/display.ttf',
              dirname(__DIR__) . '/public_html/assets/fonts/display.ttf'] as $f) {
        if (is_file($f)) { $display = $f; break; }
    }
    foreach ([__DIR__ . '/social_card/fonts/body.ttf',
              dirname(__DIR__) . '/public_html/assets/fonts/body.ttf'] as $f) {
        if (is_file($f)) { $body = $f; break; }
    }
    if (!$display) $display = $body;
    if (!$body) $body = $display;
    return [$display, $body];
}

// ---------------------------------------------------------------------------
// wrap + auto-shrink (renderer-agnostic; $measure(line, px) -> width px)
// ---------------------------------------------------------------------------

/** Greedy word-wrap at $size px. Words are NEVER split mid-word: an over-long
 *  single word gets its own line (the shrink loop then reduces the size). */
function rc_wrap(callable $measure, string $text, int $maxW, int $size): array {
    $words = preg_split('/\s+/u', trim($text)) ?: [];
    $lines = []; $cur = '';
    foreach ($words as $w) {
        if ($w === '') continue;
        $try = $cur === '' ? $w : $cur . ' ' . $w;
        if ($cur === '' || $measure($try, $size) <= $maxW) { $cur = $try; }
        else { $lines[] = $cur; $cur = $w; }
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
}

/** Shrink from $start px until the wrapped block fits $maxW x $maxH.
 *  Returns [sizePx, lines[], blockHeightPx]. Only if even $min px overflows
 *  (700+ chars would still fit at 24px, so effectively never) the tail lines
 *  are dropped at a WORD boundary with a '...' marker — never mid-word. */
function rc_fit(callable $measure, string $text, int $maxW, int $maxH,
                int $start, int $min, float $lh): array {
    for ($size = $start; $size >= $min; $size -= 2) {
        $lines = rc_wrap($measure, $text, $maxW, $size);
        $step = (int)round($size * $lh);
        $h = count($lines) * $step;
        $wide = 0;
        foreach ($lines as $ln) $wide = max($wide, $measure($ln, $size));
        if ($h <= $maxH && $wide <= $maxW) return [$size, $lines, $h];
    }
    $size = $min;
    $lines = rc_wrap($measure, $text, $maxW, $size);
    $step = (int)round($size * $lh);
    $cap = max(1, (int)floor($maxH / $step));
    if (count($lines) > $cap) {
        $lines = array_slice($lines, 0, $cap);
        $lines[$cap - 1] = rtrim($lines[$cap - 1], " .,;:") . ' ...';
    }
    return [$size, $lines, count($lines) * $step];
}

// ---------------------------------------------------------------------------
// r17: the beige press-clipping renderers are GONE (rc_render/rc_render_imagick/
// rc_render_gd/rc_masthead/rc_subline deleted). Only the card canvas constants
// survive — post_cards.php and the promo card still render at this size.
// ---------------------------------------------------------------------------

const RC_W = 1080;
const RC_H = 1350;
const RC_PAD = 84;

/** v7 PLATFORM GUARD (owner's round-7 deploy-blocker): a social platform must
 *  NEVER be a newspaper masthead — a clipping headed "TWITTER (ORIGINAL POST)"
 *  reads fabricated (a platform rendered as a press outlet). Detects platform-
 *  flavored sources from the publisher label, the source domain AND the
 *  source-URL host. Returns 'twitter'|'instagram'|'tiktok'|'youtube'|'social'
 *  or null for a real news publisher (only real outlets may be a masthead). */
function rc_platform(?string $publisher, ?string $domain, ?string $url): ?string {
    $host = '';
    if ($url) { $h = parse_url(trim($url), PHP_URL_HOST); if (is_string($h)) $host = $h; }
    $s = ' ' . mb_strtolower(trim(($publisher ?? '') . ' ' . ($domain ?? '') . ' ' . $host)) . ' ';
    if (trim($s) === '') return null;
    // 'x.com' needs a left boundary (netflix.com must not match); the literal
    // labels the drafter writes ("Twitter (original post)", "Dani on X", "(X)")
    // are matched too.
    if (preg_match('/twitter|(?<![a-z0-9-])x\.com|(?<![a-z0-9-])t\.co(?![a-z0-9.])|\(x\)| x /u', $s)) return 'twitter';
    if (strpos($s, 'instagram') !== false) return 'instagram';
    if (strpos($s, 'tiktok') !== false) return 'tiktok';
    if (preg_match('/youtube|(?<![a-z0-9-])youtu\.be/', $s)) return 'youtube';
    if (preg_match('/reddit|facebook|(?<![a-z0-9-])fb\.com|threads\.(?:net|com)|twitch|discord|telegram|snapchat|bluesky|(?<![a-z0-9-])bsky\.app/', $s)) return 'social';
    return null;
}

/** Extract an X/Twitter status id from any blob (source URL, event
 *  description, stored embed HTML). Null when none is present. */
function rc_tweet_id(string ...$blobs): ?string {
    foreach ($blobs as $b) {
        if ($b === '') continue;
        if (preg_match('#(?:twitter|x)\.com/(?:\#!/)?@?[A-Za-z0-9_]{1,20}/status(?:es)?/(\d{8,25})#i', $b, $m)) {
            return $m[1];
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// v6 PROMO card — the ONLY branded card in a video. One per page: wordmark,
// "Full dated timeline", the drama title, genzhype.com. Appended LAST to the
// receipts list with kind 'promo'. Director law (video_factory.php): used
// ONLY on the literal final CTA line or when the script says "GenZHype";
// NEVER as evidence.
// ---------------------------------------------------------------------------

function gz_render_promo(array $s, string $out): bool {
    $renderers = [];
    if (extension_loaded('imagick')) $renderers[] = 'gz_render_promo_imagick';
    if (function_exists('imagecreatetruecolor') && function_exists('imagettftext')) {
        $renderers[] = 'gz_render_promo_gd';
    }
    foreach ($renderers as $fn) {
        try { if ($fn($s, $out)) return true; }
        catch (Throwable $e) { error_log('promo card ' . $fn . ': ' . $e->getMessage()); }
    }
    return false;
}

function gz_render_promo_imagick(array $s, string $out): bool {
    [$fDisplay, $fBody] = rc_fonts();
    if (!$fDisplay || !$fBody) return false;
    $W = RC_W; $H = RC_H; $PAD = RC_PAD; $maxW = $W - 2 * $PAD;

    $im = new Imagick();
    $im->newImage($W, $H, new ImagickPixel('#15110d'));   // house dark paper
    $im->setImageFormat('png');

    $mdT = new ImagickDraw(); $mdT->setFont($fDisplay); $mdT->setTextEncoding('UTF-8');
    $mdB = new ImagickDraw(); $mdB->setFont($fBody);    $mdB->setTextEncoding('UTF-8');
    $measT = function (string $t, int $px) use ($im, $mdT): int {
        $mdT->setFontSize($px);
        return (int)ceil($im->queryFontMetrics($mdT, $t)['textWidth']);
    };
    $measB = function (string $t, int $px) use ($im, $mdB): int {
        $mdB->setFontSize($px);
        return (int)ceil($im->queryFontMetrics($mdB, $t)['textWidth']);
    };
    $rect = function (float $x1, float $y1, float $x2, float $y2, string $color) use ($im): void {
        $d = new ImagickDraw();
        $d->setFillColor(new ImagickPixel($color));
        $d->rectangle($x1, $y1, $x2, $y2);
        $im->drawImage($d);
    };
    $text = function (float $x, float $baseY, string $t, string $font, int $px,
                      string $color, float $kern = 0.0) use ($im): void {
        $d = new ImagickDraw();
        $d->setTextEncoding('UTF-8');
        $d->setFont($font);
        $d->setFontSize($px);
        $d->setFillColor(new ImagickPixel($color));
        if ($kern > 0) $d->setTextKerning($kern);
        $im->annotateImage($d, $x, $baseY, 0, $t);
    };

    // wordmark, centered upper-middle
    $wm = 'GenZHype';
    $wmSize = 120;
    while ($wmSize > 60 && $measT($wm, $wmSize) > $maxW) $wmSize -= 6;
    $wmW = $measT($wm, $wmSize);
    $wmY = (int)($H * 0.30);
    $text(($W - $wmW) / 2, $wmY, $wm, $fDisplay, $wmSize, '#F7F4ED');
    // accent underline bar
    $rect(($W - 180) / 2, $wmY + 34, ($W + 180) / 2, $wmY + 42, '#FF6A5C');

    // label
    $label = 'FULL DATED TIMELINE';
    $lw = $measB($label, 34) + (int)(5.0 * (mb_strlen($label) - 1));
    $text(($W - $lw) / 2, $wmY + 132, $label, $fBody, 34, '#FF6A5C', 5.0);

    // drama title, centered block
    $titleTop = $wmY + 200;
    [$tSize, $tLines, $tH] = rc_fit($measB, (string)$s['title'], $maxW - 60, 420, 46, 28, 1.35);
    $y = $titleTop + $tSize;
    foreach ($tLines as $ln) {
        $text(($W - $measB($ln, $tSize)) / 2, $y, $ln, $fBody, $tSize, '#EDE8DD');
        $y += (int)round($tSize * 1.35);
    }

    // site, bottom center
    $site = 'genzhype.com';
    $sw = $measT($site, 48);
    $text(($W - $sw) / 2, $H - 150, $site, $fDisplay, 48, '#F7F4ED');
    $rect(($W - 90) / 2, $H - 128, ($W + 90) / 2, $H - 122, '#FF6A5C');

    $ok = $im->writeImage($out);
    $im->clear();
    if ($ok) @chmod($out, 0644);
    return (bool)$ok && is_file($out) && filesize($out) > 2000;
}

function gz_render_promo_gd(array $s, string $out): bool {
    [$fDisplay, $fBody] = rc_fonts();
    if (!$fDisplay || !$fBody) return false;
    $W = RC_W; $H = RC_H; $PAD = RC_PAD; $maxW = $W - 2 * $PAD;
    $pt = static fn(int $px): float => $px * 0.75;

    $img = imagecreatetruecolor($W, $H);
    $bg     = imagecolorallocate($img, 0x15, 0x11, 0x0d);
    $accent = imagecolorallocate($img, 0xFF, 0x6A, 0x5C);
    $cMain  = imagecolorallocate($img, 0xF7, 0xF4, 0xED);
    $cBody  = imagecolorallocate($img, 0xED, 0xE8, 0xDD);
    imagefilledrectangle($img, 0, 0, $W, $H, $bg);

    $measT = function (string $t, int $px) use ($fDisplay, $pt): int {
        $b = imagettfbbox($pt($px), 0, $fDisplay, $t);
        return $b === false ? PHP_INT_MAX : (int)abs($b[2] - $b[0]);
    };
    $measB = function (string $t, int $px) use ($fBody, $pt): int {
        $b = imagettfbbox($pt($px), 0, $fBody, $t);
        return $b === false ? PHP_INT_MAX : (int)abs($b[2] - $b[0]);
    };

    $wm = 'GenZHype';
    $wmSize = 120;
    while ($wmSize > 60 && $measT($wm, $wmSize) > $maxW) $wmSize -= 6;
    $wmY = (int)($H * 0.30);
    imagettftext($img, $pt($wmSize), 0, (int)(($W - $measT($wm, $wmSize)) / 2), $wmY,
                 $cMain, $fDisplay, $wm);
    imagefilledrectangle($img, (int)(($W - 180) / 2), $wmY + 34, (int)(($W + 180) / 2), $wmY + 42, $accent);

    $label = 'FULL DATED TIMELINE';
    imagettftext($img, $pt(34), 0, (int)(($W - $measB($label, 34)) / 2), $wmY + 132,
                 $accent, $fBody, $label);

    $titleTop = $wmY + 200;
    [$tSize, $tLines] = rc_fit($measB, (string)$s['title'], $maxW - 60, 420, 46, 28, 1.35);
    $y = $titleTop + $tSize;
    foreach ($tLines as $ln) {
        imagettftext($img, $pt($tSize), 0, (int)(($W - $measB($ln, $tSize)) / 2), (int)$y,
                     $cBody, $fBody, $ln);
        $y += (int)round($tSize * 1.35);
    }

    $site = 'genzhype.com';
    imagettftext($img, $pt(48), 0, (int)(($W - $measT($site, 48)) / 2), $H - 150,
                 $cMain, $fDisplay, $site);
    imagefilledrectangle($img, (int)(($W - 90) / 2), $H - 128, (int)(($W + 90) / 2), $H - 122, $accent);

    $ok = imagepng($img, $out, 6);
    imagedestroy($img);
    if ($ok) @chmod($out, 0644);
    return $ok && is_file($out) && filesize($out) > 2000;
}

/** Ensure the page's PROMO card exists (idempotent via its own hash sidecar)
 *  and return its entry (without idx), or null on failure. */
function promo_card_for_page(int $pageId, string $title): ?array {
    $dir = dirname(__DIR__) . '/public_html/assets/receipts/video';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    $title = rc_norm($title);
    if ($title === '') return null;
    $file = $dir . '/' . $pageId . '-promo.png';
    $metaFile = $dir . '/' . $pageId . '-promo.json';
    $hash = md5($title . '|promo-v1');
    $old = is_file($metaFile) ? trim((string)@file_get_contents($metaFile)) : '';
    if (!is_file($file) || $old !== $hash) {
        if (!gz_render_promo(['title' => $title], $file) && !is_file($file)) return null;
        @file_put_contents($metaFile, $hash);
        @chmod($metaFile, 0644);
    }
    @chmod($file, 0644);
    return [
        'kind'       => 'promo',
        'url'        => url('assets/receipts/video/' . $pageId . '-promo.png'),
        'event_date' => '',
        'excerpt'    => 'GenZHype promo card: Full dated timeline on genzhype.com',
    ];
}

// ---------------------------------------------------------------------------
// public API
// ---------------------------------------------------------------------------

/**
 * Return the page's receipt entries in idx order:
 *   [ ['idx'=>0, 'kind'=>'event', ...METADATA ONLY, url=''...], ... ,
 *     ['idx'=>N, 'kind'=>'post', ...authentic X screenshot card...], ... ,
 *     ['idx'=>LAST, 'kind'=>'promo', ...the ONLY branded card...] ]
 * r17: 'event' entries carry NO rendered PNG anymore (beige retired). They
 * are pure metadata — {event_date, excerpt, source_url, og_image} — and the
 * MAKER resolves each one down the real evidence chain: clean article
 * screenshot > the article's real og:image photo > subject photo. 'post'
 * cards (post_cards.php) and the single branded 'promo' card are unchanged.
 * idx stays the single address space the Director's receipt_i points into,
 * so the feed shape (receipts[] URL list, '' at event positions) holds.
 * Any single-entry failure is skipped; a total failure returns [].
 */
function receipt_cards_for_page(PDO $pdo, int $pageId): array {
    $q = $pdo->prepare(
        "SELECT e.event_date, e.title, e.description, e.embed_html,
                s.publisher, s.domain, s.url AS src_url
         FROM dramas d
         JOIN events e ON e.drama_id = d.id
         LEFT JOIN sources s ON s.id = e.source_id
         WHERE d.page_id = ?
         ORDER BY e.sort_order, e.event_date
         LIMIT 18");
    $q->execute([$pageId]);
    $events = $q->fetchAll();
    if (!$events) return [];

    $dir = dirname(__DIR__) . '/public_html/assets/receipts/video';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('receipt_cards: cannot create ' . $dir);
        return [];
    }

    // r17: og:image per news source URL (event_sources 30d disk cache; CLI
    // may fetch live, web requests read the cache only). This is the maker's
    // "real report photo" fallback when the article screenshot fails.
    $ogByUrl = [];
    try {
        require_once __DIR__ . '/event_sources.php';
        foreach (event_sources_for_page($pdo, $pageId) as $es) {
            $su = (string)($es['source_url'] ?? '');
            $og = (string)($es['og_image'] ?? '');
            if ($su !== '' && $og !== '') $ogByUrl[$su] = $og;
        }
    } catch (Throwable $e) { /* og enrichment is never fatal */ }

    $out = []; $recover = [];
    foreach ($events as $e) {
        $desc = rc_norm((string)($e['description'] ?? ''));
        $title = rc_norm((string)($e['title'] ?? ''));
        if ($desc === '') $desc = $title;              // REAL text only, best available
        if ($desc === '') continue;
        // THE INSPECTOR (organ 11): never print a day the source never gave.
        // draft.php used to demand a full date, so "as early as 2024" became
        // 2024-01-01 and video 700 burned "JAN 1, 2024" on screen as a receipt.
        require_once __DIR__ . '/inspector.php';
        $date = insp_date_label($e['event_date'] ?? null,
                                (string)($e['title'] ?? '') . ' ' . (string)($e['description'] ?? ''));

        // v7 PLATFORM GUARD (still load-bearing): a twitter/x-sourced event
        // gets its REAL post recovered from the status id (post_cards.php
        // further down); any other platform (instagram/tiktok/youtube/...)
        // has no article to screenshot and no og photo worth trusting -> no
        // receipt entry at all, never a fabricated one.
        $platform = rc_platform((string)($e['publisher'] ?? ''), (string)($e['domain'] ?? ''),
                                (string)($e['src_url'] ?? ''));
        if ($platform !== null) {
            if ($platform === 'twitter') {
                $tid = rc_tweet_id((string)($e['src_url'] ?? ''), (string)($e['description'] ?? ''),
                                   (string)($e['embed_html'] ?? ''));
                if ($tid !== null) $recover[] = ['tweet_id' => $tid, 'event_date' => $date];
            }
            continue;
        }

        $srcUrl = trim((string)($e['src_url'] ?? ''));
        $out[] = [
            'idx' => count($out),
            'kind' => 'event',
            // r17 BEIGE RETIRED: no rendered PNG. The maker resolves this
            // entry: clean article screenshot > og:image report photo >
            // subject photo. url stays in the array shape for idx alignment.
            'url' => '',
            'event_date' => $date,
            'excerpt' => mb_substr($desc, 0, 300),
            'source_url' => $srcUrl,
            'og_image' => $ogByUrl[$srcUrl] ?? null,
        ];
    }

    // REACH-POWERED VIDEO (2026-08-05): story-driven X search + per-story Exa
    // article harvest, stored per page by reach_ingest.php in
    // video_scripts.reach_posts. Articles join the event block here as
    // screenshot targets (dated press only, never a social platform, new
    // domains only); the tweet ids join the RECOVER wants further down, where
    // post_cards_recovered verifies every id against X's syndication CDN
    // (tombstone -> NO card, fail closed) — a fabricated or dead id can never
    // reach the screen. Never fatal: reach is an enhancement.
    $reach = ['posts' => [], 'articles' => []];
    try {
        $rq = $pdo->prepare("SELECT reach_posts FROM video_scripts WHERE page_id=?");
        $rq->execute([$pageId]);
        $rb = (array)json_decode((string)$rq->fetchColumn(), true);
        $reach['posts'] = (array)($rb['posts'] ?? []);
        $reach['articles'] = (array)($rb['articles'] ?? []);
    } catch (Throwable $e) { /* column may not exist yet */ }
    if ($reach['articles']) {
        $seenDom = [];
        foreach ($events as $e) {
            $d = strtolower((string)(parse_url((string)($e['src_url'] ?? ''), PHP_URL_HOST) ?: ''));
            if ($d !== '') $seenDom[preg_replace('/^www\./', '', $d)] = true;
        }
        $ra = 0;
        foreach ($reach['articles'] as $a) {
            if ($ra >= 3) break;
            $u = (string)($a['url'] ?? '');
            $t = rc_norm((string)($a['title'] ?? ''));
            $pub = (string)($a['published'] ?? '');
            if ($u === '' || $t === '' || $pub === '' || !preg_match('#^https?://#', $u)) continue;
            if (rc_platform(null, null, $u) !== null) continue;   // press only
            $dom = preg_replace('/^www\./', '', strtolower((string)(parse_url($u, PHP_URL_HOST) ?: '')));
            if ($dom === '' || isset($seenDom[$dom])) continue;   // new domains only
            $seenDom[$dom] = true;
            $out[] = [
                'idx' => count($out),
                'kind' => 'event',
                'url' => '',
                'event_date' => rc_date_label($pub),
                'excerpt' => mb_substr($t, 0, 300),
                'source_url' => $u,
                'og_image' => null,
            ];
            $ra++;
        }
    }

    // r17: prune the ENTIRE retired beige namespace ({page_id}-N.png) plus
    // its hash sidecar so a stale beige card can never be served again.
    // Post cards (-p{k}.png) and the promo (-promo.png) keep their own
    // namespaces untouched.
    for ($k = 0; $k <= 12; $k++) {
        $stale = $dir . '/' . $pageId . '-' . $k . '.png';
        if (is_file($stale)) @unlink($stale);
    }
    $metaFile = $dir . '/' . $pageId . '.json';
    if (is_file($metaFile)) @unlink($metaFile);

    // REAL-POST cards: append the actual tweets ("fans/person said" shots)
    // to the same idx space. Never fatal — the event cards stand alone.
    // v7: then RECOVER the real X post for twitter-sourced events that carry
    // no parsed embed (their fake clipping is gone; the REAL post replaces it
    // when the status id resolves on X's own syndication CDN — otherwise the
    // event simply has no card).
    try {
        require_once __DIR__ . '/post_cards.php';
        $seenTweets = [];
        foreach (post_cards_for_page($pdo, $pageId) as $pc) {
            if (!empty($pc['tweet_id'])) $seenTweets[(string)$pc['tweet_id']] = true;
            $pc['idx'] = count($out);
            $out[] = $pc;
        }
        $wants = [];
        foreach ($recover as $rv) {                     // dedupe vs parsed cards + self
            if (isset($seenTweets[$rv['tweet_id']]) || isset($wants[$rv['tweet_id']])) continue;
            $wants[$rv['tweet_id']] = $rv;
        }
        // reach-found viral posts (X search) AFTER the story-event tweets so
        // event evidence keeps priority inside the recovery limit
        foreach ($reach['posts'] as $rp) {
            $tid = preg_replace('/\D+/', '', (string)($rp['id'] ?? ''));
            if ($tid === '' || isset($seenTweets[$tid]) || isset($wants[$tid])) continue;
            $wants[$tid] = ['tweet_id' => $tid, 'event_date' => ''];
        }
        foreach (post_cards_recovered($pageId, array_values($wants), 6) as $pc) {
            $pc['idx'] = count($out);
            $out[] = $pc;
        }
    } catch (Throwable $e) {
        error_log('receipt_cards: post cards failed for page ' . $pageId . ': ' . $e->getMessage());
    }

    // r102 TIKTOK POST CARDS. TikTok will not give us the video from any IP we
    // control — 21 download attempts from a runner and a one-shot success from
    // this server before it blocked us. But its keyless oEmbed answers happily
    // with the creator, the real caption and a 1080x1920 frame, so the story
    // can still SHOW the post the way the X cards show a tweet. Verified per
    // post against TikTok itself: no confirmation, no card, never an invention.
    try {
        require_once __DIR__ . '/tiktok_cards.php';
        foreach (tt_cards_for_page($pdo, $pageId, 4) as $tc) {
            $tc['idx'] = count($out);
            $out[] = $tc;
        }
    } catch (Throwable $e) {
        error_log('tiktok cards failed for page ' . $pageId . ': ' . $e->getMessage());
    }

    // v6 PROMO card LAST (kind order: events, posts, promo). The ONLY branded
    // card; the Director may use it solely on the final CTA / GenZHype line.
    try {
        $tq = $pdo->prepare("SELECT COALESCE(NULLIF(h1,''), title_tag) FROM pages WHERE id=?");
        $tq->execute([$pageId]);
        $pTitle = (string)$tq->fetchColumn();
        if ($pTitle !== '') {
            $promo = promo_card_for_page($pageId, $pTitle);
            if ($promo) {
                $promo['idx'] = count($out);
                $out[] = $promo;
            }
        }
    } catch (Throwable $e) {
        error_log('receipt_cards: promo card failed for page ' . $pageId . ': ' . $e->getMessage());
    }
    return $out;
}
