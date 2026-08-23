<?php
// GenZHype | REAL-POST card generator (v6 "platform-native evidence").
//
// Owner's round-6 verdict: evidence must look PLATFORM-NATIVE, not
// GenZHype-branded — "we're not as big as those platforms to be the
// evidence". So a post card now renders as an AUTHENTIC X SCREENSHOT:
// X dark "Lights out" UI (pure black, #E7E9EA text), circular REAL avatar,
// display name + verified-style badge + @handle, the REAL tweet text
// verbatim, an X-style timestamp line ("10:53 PM · May 24, 2026"), and the
// REAL engagement counts from X's own syndication JSON when available
// (reply/repost/like glyph row; counts formatted 12.4K). ZERO GenZHype
// branding — the only additions are a small "via x.com/<handle>/status/<id>"
// provenance footer. Our brand appears in videos ONLY on the separate promo
// card (receipt_cards.php).
//
// REAL DATA ONLY - HARD RULE: every string on a card comes verbatim from the
// stored embed HTML (optionally enriched by X's own syndication CDN, which
// serves the canonical tweet JSON). Nothing is ever invented, summarized or
// paraphrased. Engagement counts render ONLY when the syndication JSON
// serves them; missing counts are OMITTED (glyphs alone), never guessed.
// If a blockquote has no parseable text, NO post card is made — the event's
// ordinary receipt card (real event description) already covers that event,
// so the fallback is automatic.
//
// ENRICHMENT (verified working from this host, 2026-07-13):
//   https://cdn.syndication.twimg.com/tweet-result?id=<id>&token=x
// returns the canonical tweet JSON (full text incl. line breaks, exact
// author name/handle, avatar URL, favorite_count, conversation_count,
// created_at, is_blue_verified). HTTP 200 from Hostinger. Deleted tweets
// return a TweetTombstone -> we fall back to the blockquote data we stored
// at build time (which is itself real, captured while the tweet was live).
// The JSON carries NO repost count -> the repost glyph renders without a
// number (a real X UI state; never a made-up figure).
//
// Storage: public_html/assets/receipts/video/{page_id}-p{k}.png (0644) with
// sidecar {page_id}-posts.json (content hash per card, blockquote-derived so
// it is stable even when the syndication CDN is unreachable). v6 bumps the
// hash salt (v2x) so every existing card regenerates in the native style.
//
// Wire-in: receipt_cards_for_page() (receipt_cards.php) appends these cards
// to its indexed list with kind='post' — the Director addresses them through
// the same receipt_i mechanism the maker already renders via the text-heavy
// CONTAIN path.

if (empty($GLOBALS['CONFIG']) && is_file(__DIR__ . '/config.php')) {
    $GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
}
if (!function_exists('url')) { require_once __DIR__ . '/helpers.php'; }
require_once __DIR__ . '/receipt_cards.php';   // rc_fonts / rc_wrap / rc_fit / rc_date_label

// ---------------------------------------------------------------------------
// parsing — the stored oEmbed blockquote IS the source of truth
// ---------------------------------------------------------------------------

/** Per-line font-safe normalization: fancy quotes/dashes -> ASCII like
 *  rc_norm, but LINE BREAKS ARE PRESERVED (tweets are written in lines). */
function pc_norm_multiline(string $s): string {
    $lines = preg_split('/\R/u', $s) ?: [$s];
    $outLines = [];
    foreach ($lines as $ln) {
        $ln = rc_norm($ln);          // quotes/dashes/ellipsis + inner space collapse
        if ($ln !== '') $outLines[] = $ln;
    }
    return implode("\n", $outLines);
}

/** The card fonts (Inter/Display TTF) carry no emoji glyphs; a pictograph
 *  would render as a tofu box, which reads broken. We DROP pictographs and
 *  keep every word of the real text unchanged (no substitution, no
 *  invention). The full original text still travels in 'excerpt'. */
function pc_strip_unrenderable(string $s): string {
    $s = preg_replace(
        '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}'
        . '\x{FE00}-\x{FE0F}\x{200D}\x{203C}\x{2049}\x{20E3}'
        . '\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{25A0}-\x{25FF}\x{2900}-\x{297F}]/u',
        '', $s) ?? $s;
    $s = preg_replace('/[ \t]{2,}/', ' ', $s) ?? $s;
    return trim($s);
}

/**
 * Parse a standard X/Twitter oEmbed blockquote into its REAL parts.
 * Shape (as stored by embeds.php, script tag already stripped):
 *   <blockquote class="twitter-tweet" ...><p ...>TWEET TEXT</p>
 *   &mdash; Author Name (@handle) <a href="https://x.com/.../status/ID...">Mon D, YYYY</a></blockquote>
 * Returns null when any required part is missing — never guesses.
 */
function pc_parse_tweet_blockquote(string $html): ?array {
    if (stripos($html, 'twitter-tweet') === false) return null;

    // 1. tweet text: the first <p>...</p>; <br> -> newline; entities decoded
    if (!preg_match('#<p[^>]*>(.*?)</p>#is', $html, $pm)) return null;
    $p = preg_replace('#<br\s*/?\s*>#i', "\n", $pm[1]) ?? $pm[1];
    $text = html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // oEmbed appends the media link as visible text — it is a URL, not words
    $text = preg_replace('#\s*(?:https?://t\.co/\S+|pic\.(?:twitter|x)\.com/\S+)\s*$#i', '', $text) ?? $text;
    $text = trim($text);

    // 2. author + handle: "(em dash) Name (@handle)" after the text paragraph
    $tail = substr($html, (int)strpos($html, '</p>'));
    $tailTxt = html_entity_decode(strip_tags($tail), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!preg_match('#\x{2014}\s*(.+?)\s*\(@([A-Za-z0-9_]{1,20})\)#u', $tailTxt, $am)) return null;

    // 3. tweet id + human date from the status permalink
    if (!preg_match('#href="https?://(?:x|twitter)\.com/[^"]*/status/(\d+)[^"]*"[^>]*>([^<]+)</a>#i', $html, $lm)) return null;

    if ($text === '') return null;      // no real text -> no post card (hard rule)
    return [
        'text'     => $text,
        'author'   => trim($am[1]),
        'handle'   => $am[2],
        'tweet_id' => $lm[1],
        'date'     => trim(html_entity_decode($lm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
    ];
}

// ---------------------------------------------------------------------------
// enrichment — X's own syndication CDN (canonical tweet JSON), best effort
// ---------------------------------------------------------------------------

/** Canonical tweet JSON from cdn.syndication.twimg.com (token=x works from
 *  this host). Returns ['text','author','handle','avatar','likes','replies',
 *  'created_at','verified'] or null (deleted tweet / network hiccup / shape
 *  change -> blockquote data stands alone; counts stay null = omitted). */
function pc_syndication(string $tweetId): ?array {
    $ch = curl_init('https://cdn.syndication.twimg.com/tweet-result?id='
                    . urlencode($tweetId) . '&token=x');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) GenZHypeDesk/1.0',
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$raw) return null;
    $j = json_decode($raw, true);
    if (!is_array($j) || ($j['__typename'] ?? '') !== 'Tweet' || empty($j['text'])) return null;
    $text = (string)$j['text'];
    // display_text_range cuts the trailing media t.co link off the raw text
    $rng = $j['display_text_range'] ?? null;
    if (is_array($rng) && isset($rng[1]) && (int)$rng[1] > 0) {
        $text = mb_substr($text, (int)($rng[0] ?? 0), (int)$rng[1] - (int)($rng[0] ?? 0));
    }
    $text = preg_replace('#\s*https?://t\.co/\S+\s*$#i', '', $text) ?? $text;
    // display_text_range sometimes slices MID-URL when emoji shift the offsets
    // (seen live: "ATLANTAAAAA h" — the 'h' is the head of the media link).
    // Drop a trailing partial "h/ht/htt/http/https://..." fragment.
    $text = preg_replace('/\s+h(?:t(?:t(?:p(?:s(?::(?:\/(?:\/\S*)?)?)?)?)?)?)?$/i', '', $text) ?? $text;
    $avatar = (string)($j['user']['profile_image_url_https'] ?? '');
    if ($avatar !== '') $avatar = str_replace('_normal.', '_200x200.', $avatar);
    return [
        'text'       => trim($text),
        'author'     => trim((string)($j['user']['name'] ?? '')),
        'handle'     => trim((string)($j['user']['screen_name'] ?? '')),
        'avatar'     => $avatar,
        // REAL engagement, only what X itself serves (no repost count exists
        // in this JSON -> the glyph renders without a number, never a guess)
        'likes'      => isset($j['favorite_count']) ? (int)$j['favorite_count'] : null,
        'replies'    => isset($j['conversation_count']) ? (int)$j['conversation_count'] : null,
        'created_at' => (string)($j['created_at'] ?? ''),
        'verified'   => !empty($j['user']['is_blue_verified']) || !empty($j['user']['verified']),
    ];
}

/** Avatar bytes (small, best effort). Null on any failure. */
function pc_avatar_blob(string $url): ?string {
    if ($url === '') return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT => 'GenZHypeDesk/1.0 (+https://genzhype.com)',
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && is_string($raw) && strlen($raw) > 500 && strlen($raw) < 2_000_000)
        ? $raw : null;
}

/** X-style count: 999 -> "999", 12400 -> "12.4K", 1200000 -> "1.2M". */
function pc_count_fmt(int $n): string {
    if ($n >= 1000000) return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    if ($n >= 1000)    return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    return (string)$n;
}

/** X detail-view timestamp: created_at ISO -> "10:53 PM · May 24, 2026";
 *  no created_at -> the blockquote's own date alone (still real). */
function pc_timestamp(string $createdAt, string $fallbackDate): string {
    $ts = $createdAt !== '' ? strtotime($createdAt) : false;
    if ($ts) return gmdate('g:i A', $ts) . ' · ' . gmdate('M j, Y', $ts);
    return $fallbackDate;
}

// ---------------------------------------------------------------------------
// rendering — 1080x1350 AUTHENTIC X SCREENSHOT (Imagick primary, GD fallback)
// X "Lights out" palette: bg #000000, text #E7E9EA, muted #71767B,
// hairline #2F3336, verified blue #1D9BF0. Layout (vertically centered like
// a screenshot of the tweet detail view):
//   (real avatar) Display Name [badge]
//                 @handle
//   The real tweet text, big, verbatim, line breaks kept
//   10:53 PM · May 24, 2026
//   ────────────────────────────
//   (reply glyph) 105   (repost glyph)   (heart glyph) 2.3K
//   ────────────────────────────
//   via x.com/{handle}/status/{id}          <- provenance only, no branding
// ---------------------------------------------------------------------------

const PC_BG    = '#000000';
const PC_TEXT  = '#E7E9EA';
const PC_MUTED = '#71767B';
const PC_LINE  = '#2F3336';
const PC_BLUE  = '#1D9BF0';
const PC_FOOT  = '#536471';

/** Multi-paragraph fit: like rc_fit but respects the tweet's own \n breaks. */
function pc_fit_multiline(callable $measure, string $text, int $maxW, int $maxH,
                          int $start, int $min, float $lh): array {
    $paras = explode("\n", $text);
    for ($size = $start; $size >= $min; $size -= 2) {
        $lines = [];
        foreach ($paras as $para) {
            foreach (rc_wrap($measure, $para, $maxW, $size) as $ln) $lines[] = $ln;
        }
        $step = (int)round($size * $lh);
        $h = count($lines) * $step;
        $wide = 0;
        foreach ($lines as $ln) $wide = max($wide, $measure($ln, $size));
        if ($h <= $maxH && $wide <= $maxW) return [$size, $lines, $h];
    }
    // even $min overflows (effectively never for <=~1000 chars): word-safe cut
    $size = $min;
    $lines = [];
    foreach ($paras as $para) {
        foreach (rc_wrap($measure, $para, $maxW, $size) as $ln) $lines[] = $ln;
    }
    $step = (int)round($size * $lh);
    $cap = max(1, (int)floor($maxH / $step));
    if (count($lines) > $cap) {
        $lines = array_slice($lines, 0, $cap);
        $lines[$cap - 1] = rtrim($lines[$cap - 1], ' .,;:') . ' ...';
    }
    return [$size, $lines, count($lines) * $step];
}

function pc_render(array $t, string $out): bool {
    // r86: a card is EVIDENCE, so it may never be drawn half-empty. Both
    // renderers read these keys directly; a caller handing over a raw
    // syndication payload used to produce a card with a blank timestamp and
    // a truncated "via x.com/handle/status/" line instead of failing.
    $t['tweet_id']  = (string)($t['tweet_id'] ?? $t['id'] ?? '');
    $t['handle']    = (string)($t['handle'] ?? '');
    if ((string)($t['timestamp'] ?? '') === '' && !empty($t['created_at'])) {
        $ts = strtotime((string)$t['created_at']);
        if ($ts) $t['timestamp'] = date('g:i A · M j, Y', $ts);
    }
    if ($t['tweet_id'] === '' || $t['handle'] === ''
            || (string)($t['timestamp'] ?? '') === '') {
        error_log('post_cards: incomplete card data (id/handle/timestamp); no card drawn');
        return false;
    }
    if (extension_loaded('imagick')) {
        try { if (pc_render_imagick($t, $out)) return true; }
        catch (Throwable $e) { error_log('post_cards imagick: ' . $e->getMessage()); }
    }
    if (function_exists('imagecreatetruecolor') && function_exists('imagettftext')) {
        try { if (pc_render_gd($t, $out)) return true; }
        catch (Throwable $e) { error_log('post_cards gd: ' . $e->getMessage()); }
    }
    return false;
}

function pc_render_imagick(array $t, string $out): bool {
    [$fDisplay, $fBody] = rc_fonts();
    if (!$fDisplay || !$fBody) return false;
    $W = RC_W; $H = RC_H; $PAD = 84;

    $im = new Imagick();
    $im->newImage($W, $H, new ImagickPixel(PC_BG));
    $im->setImageFormat('png');

    $mdB = new ImagickDraw(); $mdB->setFont($fBody); $mdB->setTextEncoding('UTF-8');
    $mdT = new ImagickDraw(); $mdT->setFont($fDisplay); $mdT->setTextEncoding('UTF-8');
    $measB = function (string $s, int $px) use ($im, $mdB): int {
        $mdB->setFontSize($px);
        return (int)ceil($im->queryFontMetrics($mdB, $s)['textWidth']);
    };
    $measT = function (string $s, int $px) use ($im, $mdT): int {
        $mdT->setFontSize($px);
        return (int)ceil($im->queryFontMetrics($mdT, $s)['textWidth']);
    };
    $rect = function (float $x1, float $y1, float $x2, float $y2, string $color) use ($im): void {
        $d = new ImagickDraw();
        $d->setFillColor(new ImagickPixel($color));
        $d->rectangle($x1, $y1, $x2, $y2);
        $im->drawImage($d);
    };
    $text = function (float $x, float $baseY, string $s, string $font, int $px,
                      string $color) use ($im): void {
        $d = new ImagickDraw();
        $d->setTextEncoding('UTF-8');
        $d->setFont($font);
        $d->setFontSize($px);
        $d->setFillColor(new ImagickPixel($color));
        $im->annotateImage($d, $x, $baseY, 0, $s);
    };
    $circle = function (float $cx, float $cy, float $r, string $color) use ($im): void {
        $d = new ImagickDraw();
        $d->setFillColor(new ImagickPixel($color));
        $d->circle($cx, $cy, $cx + $r, $cy);
        $im->drawImage($d);
    };

    // measure the tweet text FIRST so the whole block can be vertically centered
    $avSize = 116;
    $maxTextW = $W - 2 * $PAD;
    [$size, $lines, $blockH] = pc_fit_multiline($measB, $t['text'], $maxTextW, 640, 56, 28, 1.36);
    // content stack heights: avatar row 116 + gap 60 + text + 64 ts + 40 sep gap
    // + 78 icon row + 48 tail
    $contentH = $avSize + 60 + $blockH + 64 + 40 + 78 + 48;
    $top = max(90, (int)(($H - $contentH - 120) / 2));

    // avatar (real profile image when the syndication CDN served one)
    $avX = $PAD; $avY = $top;
    $drewAvatar = false;
    if (!empty($t['avatar_blob'])) {
        try {
            $av = new Imagick();
            $av->readImageBlob($t['avatar_blob']);
            $av->cropThumbnailImage($avSize, $avSize);
            $mask = new Imagick();
            $mask->newImage($avSize, $avSize, new ImagickPixel('transparent'));
            $md = new ImagickDraw();
            $md->setFillColor(new ImagickPixel('white'));
            $md->circle($avSize / 2, $avSize / 2, $avSize / 2, 1);
            $mask->drawImage($md);
            $av->compositeImage($mask, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
            $im->compositeImage($av, Imagick::COMPOSITE_OVER, (int)$avX, (int)$avY);
            $av->clear(); $mask->clear();
            $drewAvatar = true;
        } catch (Throwable $e) { $drewAvatar = false; }
    }
    if (!$drewAvatar) {          // neutral initial disc (still real: their initial)
        $circle($avX + $avSize / 2, $avY + $avSize / 2, $avSize / 2, '#333639');
        $initial = mb_strtoupper(mb_substr(trim($t['author']), 0, 1));
        $iwid = $measT($initial, 54);
        $text($avX + ($avSize - $iwid) / 2, $avY + $avSize / 2 + 20, $initial, $fDisplay, 54, PC_TEXT);
    }

    // display name + verified-style badge + @handle
    $nameX = $avX + $avSize + 30;
    $nameSize = 42;
    $name = $t['author'];
    while ($nameSize > 30 && $measT($name, $nameSize) > $W - $PAD - $nameX - 70) $nameSize -= 2;
    $text($nameX, $avY + 48, $name, $fDisplay, $nameSize, PC_TEXT);
    if (!empty($t['verified'])) {
        $bx = $nameX + $measT($name, $nameSize) + 34; $by = $avY + 34; $br = 19;
        $circle($bx, $by, $br, PC_BLUE);
        $chk = new ImagickDraw();
        $chk->setStrokeColor(new ImagickPixel('#FFFFFF'));
        $chk->setStrokeWidth(5);
        $chk->setStrokeLineCap(Imagick::LINECAP_ROUND);
        $chk->setStrokeLineJoin(Imagick::LINEJOIN_ROUND);
        $chk->setFillColor(new ImagickPixel('none'));
        $chk->polyline([
            ['x' => $bx - 9, 'y' => $by + 1],
            ['x' => $bx - 2, 'y' => $by + 8],
            ['x' => $bx + 10, 'y' => $by - 7],
        ]);
        $im->drawImage($chk);
    }
    $text($nameX, $avY + 98, '@' . $t['handle'], $fBody, 34, PC_MUTED);

    // the REAL tweet text, big and readable (verbatim; line breaks kept)
    $y = $avY + $avSize + 60 + $size;
    foreach ($lines as $ln) {
        if ($ln !== '') $text($PAD, $y, $ln, $fBody, $size, PC_TEXT);
        $y += (int)round($size * 1.36);
    }
    $y -= (int)round($size * 1.36);      // back to last baseline

    // timestamp line (X detail view style)
    $tsY = $y + 64;
    $text($PAD, $tsY, $t['timestamp'], $fBody, 32, PC_MUTED);

    // hairline
    $sep1 = $tsY + 40;
    $rect($PAD, $sep1, $W - $PAD, $sep1 + 2, PC_LINE);

    // engagement glyph row: reply bubble, repost arrows, like heart.
    // Counts ONLY when the syndication JSON served them.
    $gy = $sep1 + 52;                    // glyph vertical center
    $slot = (int)(($W - 2 * $PAD) / 3);
    $gxs = [$PAD + 24, $PAD + 24 + $slot, $PAD + 24 + 2 * $slot];
    // reply: ring + tail
    $circle($gxs[0], $gy, 17, PC_MUTED);
    $circle($gxs[0], $gy, 12, PC_BG);
    $tail = new ImagickDraw();
    $tail->setFillColor(new ImagickPixel(PC_MUTED));
    $tail->polygon([
        ['x' => $gxs[0] - 14, 'y' => $gy + 9],
        ['x' => $gxs[0] - 4,  'y' => $gy + 15],
        ['x' => $gxs[0] - 15, 'y' => $gy + 20],
    ]);
    $im->drawImage($tail);
    // repost: two arrows (top ->, bottom <-)
    $ra = new ImagickDraw();
    $ra->setStrokeColor(new ImagickPixel(PC_MUTED));
    $ra->setStrokeWidth(4);
    $ra->setStrokeLineCap(Imagick::LINECAP_ROUND);
    $ra->setFillColor(new ImagickPixel('none'));
    $ra->polyline([['x' => $gxs[1] - 16, 'y' => $gy - 7], ['x' => $gxs[1] + 12, 'y' => $gy - 7]]);
    $ra->polyline([['x' => $gxs[1] + 16, 'y' => $gy + 7], ['x' => $gxs[1] - 12, 'y' => $gy + 7]]);
    $im->drawImage($ra);
    $rh = new ImagickDraw();
    $rh->setFillColor(new ImagickPixel(PC_MUTED));
    $rh->polygon([['x' => $gxs[1] + 8, 'y' => $gy - 14], ['x' => $gxs[1] + 19, 'y' => $gy - 7], ['x' => $gxs[1] + 8, 'y' => $gy]]);
    $rh->polygon([['x' => $gxs[1] - 8, 'y' => $gy], ['x' => $gxs[1] - 19, 'y' => $gy + 7], ['x' => $gxs[1] - 8, 'y' => $gy + 14]]);
    $im->drawImage($rh);
    // like: solid heart (two lobes + point)
    $hx = $gxs[2]; $hy = $gy - 2;
    $circle($hx - 8, $hy - 4, 10, PC_MUTED);
    $circle($hx + 8, $hy - 4, 10, PC_MUTED);
    $hp = new ImagickDraw();
    $hp->setFillColor(new ImagickPixel(PC_MUTED));
    $hp->polygon([['x' => $hx - 17, 'y' => $hy], ['x' => $hx + 17, 'y' => $hy], ['x' => $hx, 'y' => $hy + 16]]);
    $im->drawImage($hp);
    // counts (real only; repost NEVER has one — X's JSON does not serve it)
    if (isset($t['replies']) && $t['replies'] !== null) {
        $text($gxs[0] + 34, $gy + 11, pc_count_fmt((int)$t['replies']), $fBody, 30, PC_MUTED);
    }
    if (isset($t['likes']) && $t['likes'] !== null) {
        $text($gxs[2] + 34, $gy + 11, pc_count_fmt((int)$t['likes']), $fBody, 30, PC_MUTED);
    }

    // closing hairline
    $sep2 = $gy + 44;
    $rect($PAD, $sep2, $W - $PAD, $sep2 + 2, PC_LINE);

    // provenance footer (small, muted — NOT branding)
    $src = 'via x.com/' . $t['handle'] . '/status/' . $t['tweet_id'];
    if ($measB($src, 26) > $W - 2 * $PAD) $src = 'via x.com/' . $t['handle'];
    $text($PAD, $H - 44, $src, $fBody, 26, PC_FOOT);

    $ok = $im->writeImage($out);
    $im->clear();
    if ($ok) @chmod($out, 0644);
    return (bool)$ok && is_file($out) && filesize($out) > 2000;
}

function pc_render_gd(array $t, string $out): bool {
    [$fDisplay, $fBody] = rc_fonts();
    if (!$fDisplay || !$fBody) return false;
    $W = RC_W; $H = RC_H; $PAD = 84;
    $pt = static fn(int $px): float => $px * 0.75;

    $img = imagecreatetruecolor($W, $H);
    $bg    = imagecolorallocate($img, 0x00, 0x00, 0x00);
    $cText = imagecolorallocate($img, 0xE7, 0xE9, 0xEA);
    $cMut  = imagecolorallocate($img, 0x71, 0x76, 0x7B);
    $cLine = imagecolorallocate($img, 0x2F, 0x33, 0x36);
    $cBlue = imagecolorallocate($img, 0x1D, 0x9B, 0xF0);
    $cFoot = imagecolorallocate($img, 0x53, 0x64, 0x71);
    $cDisc = imagecolorallocate($img, 0x33, 0x36, 0x39);
    $white = imagecolorallocate($img, 0xFF, 0xFF, 0xFF);
    imagefilledrectangle($img, 0, 0, $W, $H, $bg);

    $measB = function (string $s, int $px) use ($fBody, $pt): int {
        $b = imagettfbbox($pt($px), 0, $fBody, $s);
        return $b === false ? PHP_INT_MAX : (int)abs($b[2] - $b[0]);
    };
    $measT = function (string $s, int $px) use ($fDisplay, $pt): int {
        $b = imagettfbbox($pt($px), 0, $fDisplay, $s);
        return $b === false ? PHP_INT_MAX : (int)abs($b[2] - $b[0]);
    };

    $avSize = 116;
    $maxTextW = $W - 2 * $PAD;
    [$size, $lines, $blockH] = pc_fit_multiline($measB, $t['text'], $maxTextW, 640, 56, 28, 1.36);
    $contentH = $avSize + 60 + $blockH + 64 + 40 + 78 + 48;
    $top = max(90, (int)(($H - $contentH - 120) / 2));

    // avatar: initial disc (GD path skips remote avatar compositing)
    $avX = $PAD; $avY = $top;
    imagefilledellipse($img, $avX + (int)($avSize / 2), $avY + (int)($avSize / 2), $avSize, $avSize, $cDisc);
    $initial = mb_strtoupper(mb_substr(trim($t['author']), 0, 1));
    imagettftext($img, $pt(54), 0, (int)($avX + $avSize / 2 - $measT($initial, 54) / 2),
                 (int)($avY + $avSize / 2 + 20), $cText, $fDisplay, $initial);

    // name + badge + handle
    $nameX = $avX + $avSize + 30;
    $nameSize = 42;
    $name = $t['author'];
    while ($nameSize > 30 && $measT($name, $nameSize) > $W - $PAD - $nameX - 70) $nameSize -= 2;
    imagettftext($img, $pt($nameSize), 0, $nameX, $avY + 48, $cText, $fDisplay, $name);
    if (!empty($t['verified'])) {
        $bx = $nameX + $measT($name, $nameSize) + 34; $by = $avY + 34;
        imagefilledellipse($img, (int)$bx, (int)$by, 38, 38, $cBlue);
        imagesetthickness($img, 5);
        imageline($img, (int)($bx - 9), (int)($by + 1), (int)($bx - 2), (int)($by + 8), $white);
        imageline($img, (int)($bx - 2), (int)($by + 8), (int)($bx + 10), (int)($by - 7), $white);
        imagesetthickness($img, 1);
    }
    imagettftext($img, $pt(34), 0, $nameX, $avY + 98, $cMut, $fBody, '@' . $t['handle']);

    // tweet text (verbatim, line breaks kept)
    $y = $avY + $avSize + 60 + $size;
    foreach ($lines as $ln) {
        if ($ln !== '') imagettftext($img, $pt($size), 0, $PAD, (int)$y, $cText, $fBody, $ln);
        $y += (int)round($size * 1.36);
    }
    $y -= (int)round($size * 1.36);

    // timestamp + hairline
    $tsY = $y + 64;
    imagettftext($img, $pt(32), 0, $PAD, (int)$tsY, $cMut, $fBody, $t['timestamp']);
    $sep1 = (int)($tsY + 40);
    imagefilledrectangle($img, $PAD, $sep1, $W - $PAD, $sep1 + 2, $cLine);

    // glyph row
    $gy = $sep1 + 52;
    $slot = (int)(($W - 2 * $PAD) / 3);
    $gxs = [$PAD + 24, $PAD + 24 + $slot, $PAD + 24 + 2 * $slot];
    // reply ring + tail
    imagefilledellipse($img, $gxs[0], $gy, 34, 34, $cMut);
    imagefilledellipse($img, $gxs[0], $gy, 24, 24, $bg);
    imagefilledpolygon($img, [$gxs[0] - 14, $gy + 9, $gxs[0] - 4, $gy + 15, $gxs[0] - 15, $gy + 20], $cMut);
    // repost arrows
    imagesetthickness($img, 4);
    imageline($img, $gxs[1] - 16, $gy - 7, $gxs[1] + 12, $gy - 7, $cMut);
    imageline($img, $gxs[1] + 16, $gy + 7, $gxs[1] - 12, $gy + 7, $cMut);
    imagesetthickness($img, 1);
    imagefilledpolygon($img, [$gxs[1] + 8, $gy - 14, $gxs[1] + 19, $gy - 7, $gxs[1] + 8, $gy], $cMut);
    imagefilledpolygon($img, [$gxs[1] - 8, $gy, $gxs[1] - 19, $gy + 7, $gxs[1] - 8, $gy + 14], $cMut);
    // heart
    $hx = $gxs[2]; $hy = $gy - 2;
    imagefilledellipse($img, $hx - 8, $hy - 4, 20, 20, $cMut);
    imagefilledellipse($img, $hx + 8, $hy - 4, 20, 20, $cMut);
    imagefilledpolygon($img, [$hx - 17, $hy, $hx + 17, $hy, $hx, $hy + 16], $cMut);
    // counts (real only)
    if (isset($t['replies']) && $t['replies'] !== null) {
        imagettftext($img, $pt(30), 0, $gxs[0] + 34, $gy + 11, $cMut, $fBody, pc_count_fmt((int)$t['replies']));
    }
    if (isset($t['likes']) && $t['likes'] !== null) {
        imagettftext($img, $pt(30), 0, $gxs[2] + 34, $gy + 11, $cMut, $fBody, pc_count_fmt((int)$t['likes']));
    }
    $sep2 = $gy + 44;
    imagefilledrectangle($img, $PAD, $sep2, $W - $PAD, $sep2 + 2, $cLine);

    // provenance footer
    $src = 'via x.com/' . $t['handle'] . '/status/' . $t['tweet_id'];
    if ($measB($src, 26) > $W - 2 * $PAD) $src = 'via x.com/' . $t['handle'];
    imagettftext($img, $pt(26), 0, $PAD, $H - 44, $cFoot, $fBody, $src);

    $ok = imagepng($img, $out, 6);
    imagedestroy($img);
    if ($ok) @chmod($out, 0644);
    return $ok && is_file($out) && filesize($out) > 2000;
}

// ---------------------------------------------------------------------------
// public API
// ---------------------------------------------------------------------------

/**
 * Real-post cards for a drama page (X/Twitter embeds only for now — those are
 * the "fans/person said" receipts). Returns entries WITHOUT global idx (the
 * caller, receipt_cards_for_page, assigns idx in the combined list):
 *   [ ['kind'=>'post','url'=>...,'event_date'=>...,'excerpt'=>tweet text,
 *      'handle'=>...,'author'=>...], ... ]
 * Idempotent via content-hash sidecar {page_id}-posts.json; hash derives from
 * the BLOCKQUOTE parse (stable) so an unreachable syndication CDN never
 * causes re-render churn. v6 salt bump (v2x) regenerates every existing card
 * in the platform-native X style. Any single-card failure is skipped (the
 * event's ordinary receipt card remains the fallback visual for that fact).
 */
function post_cards_for_page(PDO $pdo, int $pageId, int $limit = 6): array {
    $q = $pdo->prepare(
        "SELECT e.event_date, e.embed_html
         FROM dramas d
         JOIN events e ON e.drama_id = d.id
         WHERE d.page_id = ? AND e.embed_provider IN ('twitter','x')
           AND e.embed_html IS NOT NULL AND e.embed_html <> ''
         ORDER BY e.sort_order, e.event_date
         LIMIT 12");
    $q->execute([$pageId]);
    $rows = $q->fetchAll();
    if (!$rows) return [];

    $dir = dirname(__DIR__) . '/public_html/assets/receipts/video';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('post_cards: cannot create ' . $dir);
        return [];
    }
    $metaFile = $dir . '/' . $pageId . '-posts.json';
    $meta = is_file($metaFile)
        ? (array)json_decode((string)@file_get_contents($metaFile), true) : [];

    $out = []; $newMeta = []; $seen = [];
    foreach ($rows as $r) {
        if (count($out) >= $limit) break;
        $tw = pc_parse_tweet_blockquote((string)$r['embed_html']);
        if (!$tw) continue;                        // no real text -> receipt card covers it
        if (isset($seen[$tw['tweet_id']])) continue;
        $seen[$tw['tweet_id']] = true;

        $k = count($out);
        $file = $dir . '/' . $pageId . '-p' . $k . '.png';
        $hash = md5($tw['tweet_id'] . '|' . $tw['text'] . '|' . $tw['author'] . '|'
                    . $tw['handle'] . '|' . $tw['date'] . '|v3x');   // v7: regen for the mid-URL-slice fix
        $key = (string)$k;

        if (!is_file($file) || (string)($meta[$key] ?? '') !== $hash) {
            // enrichment only at render time (canonical text/name + avatar +
            // REAL counts + timestamp); deleted tweets / network failures ->
            // the stored blockquote data stands alone, counts omitted.
            $render = $tw;
            $render['likes'] = $render['replies'] = null;
            $render['verified'] = false;
            $render['created_at'] = '';
            $syn = pc_syndication($tw['tweet_id']);
            if ($syn) {
                if ($syn['text'] !== '')   $render['text']   = $syn['text'];
                if ($syn['author'] !== '') $render['author'] = $syn['author'];
                if ($syn['handle'] !== '') $render['handle'] = $syn['handle'];
                if ($syn['avatar'] !== '') $render['avatar_blob'] = pc_avatar_blob($syn['avatar']);
                $render['likes']      = $syn['likes'];
                $render['replies']    = $syn['replies'];
                $render['verified']   = $syn['verified'];
                $render['created_at'] = $syn['created_at'];
            }
            $render['timestamp'] = pc_timestamp($render['created_at'], $tw['date']);
            $render['text']   = pc_strip_unrenderable(pc_norm_multiline($render['text']));
            $render['author'] = pc_strip_unrenderable(rc_norm($render['author']));
            if ($render['text'] === '') { continue; }   // emoji-only tweet: nothing readable to show
            $ok = pc_render($render, $file);
            if (!$ok && !is_file($file)) continue;
            $newMeta[$key] = $ok ? $hash : (string)($meta[$key] ?? '');
        } else {
            $newMeta[$key] = $hash;
        }
        @chmod($file, 0644);
        $out[] = [
            'kind'       => 'post',
            'url'        => url('assets/receipts/video/' . $pageId . '-p' . $k . '.png'),
            'event_date' => rc_date_label($r['event_date'] ?? null),
            'excerpt'    => mb_substr($tw['text'], 0, 300),   // verbatim stored text
            'handle'     => $tw['handle'],
            'author'     => $tw['author'],
            'tweet_id'   => $tw['tweet_id'],   // v7: recovery dedupe key
        ];
    }

    // prune post cards for tweets that no longer exist (count shrank)
    for ($k = count($out); $k <= 8; $k++) {
        $stale = $dir . '/' . $pageId . '-p' . $k . '.png';
        if (is_file($stale)) @unlink($stale);
    }
    @file_put_contents($metaFile, json_encode($newMeta));
    @chmod($metaFile, 0644);
    return $out;
}

// ---------------------------------------------------------------------------
// v7 RECOVERY — a REAL X post card from a bare status id (owner's round-7).
// receipt_cards_for_page() skips the fake platform-mastheaded clipping for a
// twitter/x-sourced event and hands the status id here instead: we fetch X's
// own canonical tweet JSON (same syndication CDN as the embed path) and render
// the authentic X-UI card. Tombstoned/unreachable/emoji-only -> NO card, ever.
// ---------------------------------------------------------------------------

/** Render-array for a tweet known only by status id (no stored blockquote).
 *  Every string comes from X's syndication JSON — nothing invented. Null when
 *  the tweet is deleted (tombstone), unreachable, or has no renderable text. */
function pc_recover_tweet(string $tweetId, string $fallbackDate = ''): ?array {
    $syn = pc_syndication($tweetId);
    if (!$syn || $syn['text'] === '' || $syn['handle'] === '') return null;   // give up: no real data
    $render = [
        'tweet_id'   => $tweetId,
        'text'       => pc_strip_unrenderable(pc_norm_multiline($syn['text'])),
        'author'     => pc_strip_unrenderable(rc_norm($syn['author'] !== '' ? $syn['author'] : '@' . $syn['handle'])),
        'handle'     => $syn['handle'],
        'likes'      => $syn['likes'],
        'replies'    => $syn['replies'],
        'verified'   => $syn['verified'],
        'created_at' => $syn['created_at'],
        'timestamp'  => pc_timestamp($syn['created_at'], $fallbackDate),
    ];
    if ($syn['avatar'] !== '') $render['avatar_blob'] = pc_avatar_blob($syn['avatar']);
    if ($render['text'] === '' || $render['author'] === '') return null;   // emoji-only: nothing readable
    return $render;
}

/**
 * Recovered post cards for a page. $wants = [['tweet_id'=>, 'event_date'=>label], ...]
 * (already deduped against the parsed post cards by the caller). Files live in
 * their own stable namespace {page_id}-r{tweet_id}.png so slots never shift;
 * sidecar {page_id}-recovered.json is keyed by tweet id and caches BOTH
 * outcomes: a success (card meta, reused with zero network) and a failure
 * (tombstone/unreachable, retried at most daily so a dead tweet can never
 * stall the feed). Entries returned WITHOUT idx (caller assigns).
 */
function post_cards_recovered(int $pageId, array $wants, int $limit = 4): array {
    if (!$wants) return [];
    $dir = dirname(__DIR__) . '/public_html/assets/receipts/video';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return [];
    $metaFile = $dir . '/' . $pageId . '-recovered.json';
    $meta = is_file($metaFile)
        ? (array)json_decode((string)@file_get_contents($metaFile), true) : [];

    $out = []; $newMeta = [];
    foreach ($wants as $w) {
        if (count($out) >= $limit) break;
        $tid = preg_replace('/\D+/', '', (string)($w['tweet_id'] ?? ''));
        if ($tid === '') continue;
        $file = $dir . '/' . $pageId . '-r' . $tid . '.png';
        $m = (array)($meta[$tid] ?? []);
        if (!empty($m['ok']) && ($m['v'] ?? '') === 'rec-v1' && is_file($file)) {
            $newMeta[$tid] = $m;                       // cached card: zero network
        } elseif (empty($m['ok']) && ($m['v'] ?? '') === 'rec-v1'
                  && (time() - (int)($m['ts'] ?? 0)) < 86400) {
            $newMeta[$tid] = $m;                       // negative-cached: retry daily
            continue;
        } else {
            $render = pc_recover_tweet($tid, (string)($w['event_date'] ?? ''));
            if (!$render || !pc_render($render, $file)) {
                $newMeta[$tid] = ['ok' => false, 'v' => 'rec-v1', 'ts' => time()];
                continue;                              // no real post -> NO card, never a fake
            }
            @chmod($file, 0644);
            $newMeta[$tid] = [
                'ok' => true, 'v' => 'rec-v1', 'ts' => time(),
                'handle'  => $render['handle'],
                'author'  => $render['author'],
                'excerpt' => mb_substr($render['text'], 0, 300),
                'date'    => (string)($w['event_date'] ?? ''),
            ];
        }
        $m = $newMeta[$tid];
        $out[] = [
            'kind'       => 'post',
            'url'        => url('assets/receipts/video/' . $pageId . '-r' . $tid . '.png'),
            'event_date' => (string)($m['date'] ?? ($w['event_date'] ?? '')),
            'excerpt'    => (string)($m['excerpt'] ?? ''),
            'handle'     => (string)($m['handle'] ?? ''),
            'author'     => (string)($m['author'] ?? ''),
            'tweet_id'   => $tid,
        ];
    }

    // prune recovered cards whose tweet is no longer wanted by any event
    foreach ($meta as $tid => $m) {
        if (!isset($newMeta[$tid])) {
            $stale = $dir . '/' . $pageId . '-r' . $tid . '.png';
            if (is_file($stale)) @unlink($stale);
        }
    }
    @file_put_contents($metaFile, json_encode($newMeta));
    @chmod($metaFile, 0644);
    return $out;
}
