<?php
/**
 * SERVER-SIDE CLIP FETCH (r96) — download the clips HERE, because the runner
 * cannot.
 *
 * WHAT WE MEASURED (2026-08-11). Page 131 rendered with zero footage even
 * though every gate passed it. Seven yt-dlp strategies were tried on the
 * runner against the real URLs — with and without browser impersonation,
 * Chrome and Safari, with and without cookies, stable build and nightly. All
 * twenty-one attempts failed identically in about half a second with
 * "Unexpected response from webpage request". That is not a broken extractor
 * and not our flags: it is TikTok handing GitHub's IP range a challenge page.
 *
 * The same URLs answer THIS server normally: 200, a 396 KB page carrying the
 * real video blob. Pull the direct CDN address out of it, ask for that with a
 * Referer header, and TikTok returns 206 video/mp4 — 10.6 MB of valid MP4,
 * verified. Without the Referer the CDN returns 403; that header is the whole
 * trick, and no cookies are needed.
 *
 * So the download moves to the side of the wall that can reach TikTok, and the
 * file travels to the runner the way our images already do — staged into the
 * feed branch, because Hostinger's firewall blackholes runner requests.
 */
declare(strict_types=1);

const CF_DIR = __DIR__ . '/../storage/clips';
const CF_UA  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
             . '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const CF_MAX_BYTES = 60000000;      // 60 MB ceiling per clip
const CF_PAGE_TIMEOUT = 30;
const CF_VIDEO_TIMEOUT = 120;

function cf_dir(): string {
    if (!is_dir(CF_DIR)) @mkdir(CF_DIR, 0755, true);
    return CF_DIR;
}

/** Local filename for a clip URL — stable, so a second run reuses the file. */
function cf_path(string $url): string {
    return cf_dir() . '/' . substr(md5($url), 0, 16) . '.mp4';
}

/**
 * Read the video page and lift the direct CDN address out of it.
 * TikTok embeds it as "playAddr" inside __UNIVERSAL_DATA_FOR_REHYDRATION__,
 * with slashes escaped as /.
 */
/**
 * r103 THE WAY THROUGH. The owner pushed back on "TikTok is impossible" and he
 * was right — I had only tried to fetch it MYSELF, from the two machines we
 * own, both of which TikTok blocks. A public resolver does the lookup from its
 * own infrastructure and hands back a CDN link our server can then read
 * normally. Measured on five real story clips: five for five, valid MP4 every
 * time, about 1.2 seconds each — including the two JiDion clips that had
 * failed every render of the night.
 *
 * Treated as a courtesy, not an entitlement: one call per clip, results cached
 * on disk forever (cf_path), and a failure here simply falls through to the
 * direct attempt below. If the service disappears we lose a convenience, not a
 * capability — the TikTok POST CARDS keep working regardless.
 */
function cf_resolve_tiktok_via_resolver(string $url): ?string {
    $ch = curl_init('https://www.tikwm.com/api/?url=' . urlencode($url));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => 1, CURLOPT_USERAGENT => CF_UA]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $j = json_decode($body, true);
    $d = is_array($j) ? ($j['data'] ?? []) : [];
    foreach (['play', 'hdplay', 'wmplay'] as $k) {      // no-watermark first
        $v = (string)($d[$k] ?? '');
        if (str_starts_with($v, 'http')) return $v;
    }
    return null;
}

function cf_resolve_tiktok(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_TIMEOUT => CF_PAGE_TIMEOUT, CURLOPT_USERAGENT => CF_UA,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.9'],
    ]);
    $html = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || $html === '') {
        error_log("clip_fetch: page http {$code} for {$url}");
        return null;
    }
    // playAddr first (the streamable copy); downloadAddr is the fallback.
    foreach (['playAddr', 'downloadAddr'] as $key) {
        if (preg_match('#"' . $key . '":"([^"]{40,})"#', $html, $m)) {
            $v = str_replace(['\\u002F', '\\/'], '/', $m[1]);
            if (str_starts_with($v, 'http')) return $v;
        }
    }
    error_log("clip_fetch: no playAddr in the page for {$url}");
    return null;
}

/**
 * Fetch the media itself. The Referer is not optional: the CDN answers 403
 * without it and 206 with it (measured both ways on the same URL).
 */
function cf_download(string $mediaUrl, string $pageUrl, string $dest): bool {
    $fh = @fopen($dest, 'wb');
    if (!$fh) return false;
    $ch = curl_init($mediaUrl);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_TIMEOUT => CF_VIDEO_TIMEOUT, CURLOPT_USERAGENT => CF_UA,
        CURLOPT_HTTPHEADER => [
            'Referer: ' . $pageUrl,          // <- the whole trick
            'Accept: */*',
            'Sec-Fetch-Dest: video', 'Sec-Fetch-Mode: no-cors',
            'Range: bytes=0-' . CF_MAX_BYTES,
        ],
    ]);
    $ok   = (bool)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    $size = is_file($dest) ? (int)filesize($dest) : 0;
    // an MP4 begins with a size field then the literal "ftyp"; anything else
    // is an error page wearing a .mp4 name
    $sig = '';
    if ($size > 0 && ($h = @fopen($dest, 'rb'))) {
        $sig = substr((string)fread($h, 12), 4, 4);
        fclose($h);
    }
    if (!$ok || $code >= 400 || $size < 100000 || $sig !== 'ftyp') {
        error_log("clip_fetch: media http {$code}, {$size} bytes, sig '{$sig}' — discarded");
        @unlink($dest);
        return false;
    }
    return true;
}

/**
 * Get a local copy of one clip. Returns the path, or null if this platform is
 * not one we can serve (the runner still handles those itself).
 */
function cf_fetch(string $url): ?string {
    $dest = cf_path($url);
    if (is_file($dest) && filesize($dest) > 100000) return $dest;   // cached

    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if (str_contains($host, 'tiktok.com')) {
        // resolver first — it is the one route TikTok does not shut on us
        $media = cf_resolve_tiktok_via_resolver($url);
        if ($media && cf_download($media, $url, $dest)) return $dest;
        // then our own read of the page, which works until TikTok blocks the IP
        $media = cf_resolve_tiktok($url);
        if (!$media) return null;
        return cf_download($media, $url, $dest) ? $dest : null;
    }
    // A plain media URL (the jwplayer/CDN files some articles embed) needs no
    // resolving at all — it IS the file.
    if (preg_match('#\.(mp4|m4v|mov)(\?|$)#i', $url)) {
        return cf_download($url, $url, $dest) ? $dest : null;
    }
    return null;        // YouTube etc: the runner's yt-dlp is not blocked there
}

/**
 * Fetch every servable clip for a story. Returns [clip_url => local_path].
 * Never throws: a story with no downloadable clips renders exactly as before.
 */
function cf_fetch_story(array $clips, int $max = 6): array {
    $out = [];
    foreach ($clips as $c) {
        if (count($out) >= $max) break;
        $u = is_array($c) ? (string)($c['url'] ?? '') : (string)$c;
        if ($u === '') continue;
        try {
            $p = cf_fetch($u);
            if ($p) $out[$u] = $p;
        } catch (Throwable $e) {
            error_log('clip_fetch: ' . $e->getMessage());
        }
    }
    return $out;
}
