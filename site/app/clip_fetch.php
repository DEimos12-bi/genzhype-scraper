<?php
/**
 * SERVER-SIDE CLIP FETCH (r96, extended r140 on 2026-09-06) — download the
 * clips HERE, because the runner cannot.
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
 * r140 (2026-09-06, owner: "fix the clip supply first ... this time really
 * fix it"). Measured from this server the same night:
 *  - X video: the syndication CDN (cdn.syndication.twimg.com/tweet-result)
 *    lists mp4 variants for any tweet, 12/12 tweets answered, ranged download
 *    206 in 0.1s. No key, no cookies. Route added.
 *  - YouTube: the Innertube player with the ANDROID_VR client (the one client
 *    yt-dlp's PO-Token guide lists as needing no token) returned 27 formats
 *    and a playable URL for one video, and "Sign in to confirm you're not a
 *    bot" for two others. So: a route, opportunistic, never relied upon.
 *  - Every attempt is now LOGGED (clip_supply_log) and every route PROBED
 *    daily (clip_route_probe): the supply is measured, not claimed.
 *  - Files are TRIMMED with the server's ffmpeg to the beat (12s around the
 *    reporter's timestamp, else the first 25s) so a 38 MB TikTok becomes a
 *    few MB the feed branch can carry.
 */
declare(strict_types=1);

const CF_DIR = __DIR__ . '/../storage/clips';
const CF_UA  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
             . '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const CF_MAX_BYTES = 60000000;      // 60 MB ceiling per clip
const CF_PAGE_TIMEOUT = 30;
const CF_VIDEO_TIMEOUT = 120;
const CF_FFMPEG = '/home/u219414635/bin/ffmpeg';

function cf_dir(): string {
    if (!is_dir(CF_DIR)) @mkdir(CF_DIR, 0755, true);
    return CF_DIR;
}

function cf_path(string $url): string {
    return cf_dir() . '/' . substr(md5($url), 0, 16) . '.mp4';
}

/* The store is a cache, not an archive: 14 days. */
function cf_prune(int $days = 14): int {
    $n = 0;
    foreach (glob(cf_dir() . '/*.mp4') ?: [] as $f) if (filemtime($f) < time() - $days * 86400) { @unlink($f); $n++; }
    return $n;
}

/* ---- TikTok (r96) ------------------------------------------------------ */
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
    // r141 THE EYES: the resolver also tells us how the clip performed. Keep
    // it for the measurement that happens right after staging.
    if ($d) $GLOBALS['__cf_tiktok_meta'][$url] = [
        'play_count' => (int)($d['play_count'] ?? 0), 'digg_count' => (int)($d['digg_count'] ?? 0),
        'share_count' => (int)($d['share_count'] ?? 0), 'create_time' => (int)($d['create_time'] ?? 0),
        'author' => (string)($d['author']['unique_id'] ?? ''), 'title' => (string)($d['title'] ?? ''),
        'duration' => (int)($d['duration'] ?? 0)];
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
    foreach (['playAddr', 'downloadAddr'] as $key) {
        if (preg_match('#"' . $key . '":"([^"]{40,})"#', $html, $m)) {
            $v = str_replace(['\\u002F', '\\/'], '/', $m[1]);
            if (str_starts_with($v, 'http')) return $v;
        }
    }
    error_log("clip_fetch: no playAddr in the page for {$url}");
    return null;
}

/* ---- X (r140): the syndication CDN lists mp4 variants ------------------ */
function cf_resolve_x(string $tweetId): ?string {
    $ch = curl_init('https://cdn.syndication.twimg.com/tweet-result?id=' . $tweetId . '&token=x');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => CF_UA]);
    $j = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    $best = null; $bestBr = -1;
    foreach ((array)($j['mediaDetails'] ?? []) as $md) {
        foreach ((array)($md['video_info']['variants'] ?? []) as $v) {
            if (($v['content_type'] ?? '') !== 'video/mp4') continue;
            $br = (int)($v['bitrate'] ?? 0);
            $h = preg_match('#/(\d+)x(\d+)/#', (string)$v['url'], $dm) ? (int)$dm[2] : 720;
            if ($h > 720) continue;                         // keep the feed light
            if ($br > $bestBr) { $bestBr = $br; $best = (string)$v['url']; }
        }
    }
    return $best;
}

/* ---- YouTube (r140): Innertube player, ANDROID_VR client, progressive mp4 */
function cf_resolve_youtube_server(string $vid, string &$err = ''): ?string {
    $ctx = ['clientName' => 'ANDROID_VR', 'clientVersion' => '1.62.27', 'deviceModel' => 'Quest 3'];
    $ch = curl_init('https://www.youtube.com/youtubei/v1/player?prettyPrint=false');
    curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => json_encode(['context' => ['client' => $ctx], 'videoId' => $vid,
                                           'contentCheckOk' => true, 'racyCheckOk' => true]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json',
            'User-Agent: com.google.android.apps.youtube.vr.oculus/1.62.27 (Linux; U; Android 12L; eureka-user Build/SQ3A.220605.009.A1) gzip',
            'X-YouTube-Client-Name: 28', 'X-YouTube-Client-Version: 1.62.27']]);
    $j = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    $st = (string)($j['playabilityStatus']['status'] ?? '');
    if ($st !== 'OK') {
        $err = 'youtube ' . ($st ?: 'no answer') . ': ' . mb_substr((string)($j['playabilityStatus']['reason'] ?? ''), 0, 60);
        return null;
    }
    $best = null; $bestH = -1;
    foreach ((array)($j['streamingData']['formats'] ?? []) as $f) {   // progressive = audio+video in one file
        $h = (int)($f['height'] ?? 0);
        if (!empty($f['url']) && $h <= 720 && $h > $bestH) { $bestH = $h; $best = (string)$f['url']; }
    }
    if (!$best) $err = 'youtube: no progressive mp4 under 720p';
    return $best;
}

/* ---- yt-dlp (r160, 2026-09-12): the platforms that need an extractor -------
 *
 * Twitch and Kick hand out no media address to a curl chain the way TikTok and
 * X do — the clip lives behind a GraphQL call (Twitch) or an m3u8 playlist
 * (Kick). yt-dlp already knows both. It is NOT on PATH on this host, but the
 * python MODULE is installed and works: /opt/alt/python311/bin/python3 -m
 * yt_dlp, yt_dlp 2026.07.04. No credentials of any kind — the owner could not
 * pass Twitch's 2FA and does not need to.
 *
 * MEASURED HERE 2026-09-12, which is why every bound below exists:
 *   twitch clips.twitch.tv/MushyLaconicHippoTinyFace-...  9.86 MB in seconds
 *   kick   kick.com/xqc/clips/clip_01KSKMXBF0C24XQ5XW6C37GCCK
 *          UNBOUNDED: 94 MB, ~5 minutes, 46 HLS fragments — it blew straight
 *          through --max-filesize, because nothing knows an HLS clip's size up
 *          front. Same clip with --download-sections "*0-30": 3.1 seconds.
 * So the bound that works on both roads is a TIME section, not a byte ceiling,
 * and both are set anyway. This runs inside an hourly cron: an unbounded
 * download here is an hour of the tick spent on one clip.
 */
const CF_YTDLP_PY      = '/opt/alt/python311/bin/python3';
const CF_YTDLP_TIMEOUT = 90;                 // hard wall, enforced by /usr/bin/timeout
const CF_YTDLP_SECONDS = 30;                 // seconds of clip to pull (cf_trim then keeps 25)
const CF_YTDLP_MAXSIZE = '40M';
const CF_YTDLP_LOCK    = CF_DIR . '/.ytdlp.lock';

/**
 * '' unless this URL is a CLIP yt-dlp can extract; else the platform name.
 *
 * The shape matters, not the host: twitch.tv/<channel> is a live page and
 * kick.com/<channel> a profile, and handing either to yt-dlp starts a download
 * of a stream that has no end. clip_platform() answers "twitch" for both, so
 * the clip test lives here.
 */
function cf_ytdlp_target(string $url): string {
    if (preg_match('#^https?://(?:www\.)?clips\.twitch\.tv/[A-Za-z0-9_-]{6,}#i', $url)) return 'twitch';
    if (preg_match('#^https?://(?:www\.|m\.)?twitch\.tv/[^/]+/clip/[A-Za-z0-9_-]{6,}#i', $url)) return 'twitch';
    if (preg_match('#^https?://(?:www\.)?kick\.com/[^/]+/clips/clip_[A-Za-z0-9]{6,}#i', $url)) return 'kick';
    return '';
}

/**
 * Download one clip through yt-dlp into $dest. Bounded five ways: a clip-shaped
 * URL only, ONE process at a time, a 90s hard kill, a 30s section, a 40 MB
 * ceiling, and the same ftyp/size check cf_download() applies.
 */
function cf_ytdlp(string $url, string $dest, string &$err = ''): bool {
    $plat = cf_ytdlp_target($url);
    if ($plat === '') { $err = 'no server route for this platform (not a clip URL)'; return false; }
    if (!is_file(CF_YTDLP_PY)) { $err = 'yt-dlp: no python at ' . CF_YTDLP_PY; return false; }

    // ONE AT A TIME. A Kick clip spawns an ffmpeg alongside the maker's own
    // encodes; three of them inside one tick would fight over the same core.
    cf_dir();
    $lock = @fopen(CF_YTDLP_LOCK, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        // Our own lock, not this clip's fault: do not dead-letter it.
        $GLOBALS['__cf_nonote'] = true;
        $err = 'yt-dlp busy (another clip is downloading)';
        error_log("clip_fetch: yt-dlp {$plat} skipped, another download holds the lock — {$url}");
        return false;
    }

    $tmp = $dest . '.dl.mp4';
    @unlink($tmp);
    $ef  = $dest . '.dl.err';
    $cmd = ['/usr/bin/timeout', '-k', '5', (string)CF_YTDLP_TIMEOUT,
            CF_YTDLP_PY, '-m', 'yt_dlp',
            '--no-playlist', '--no-warnings', '--no-progress', '--no-part', '--no-cache-dir',
            '--socket-timeout', '20', '--retries', '2',
            '--max-filesize', CF_YTDLP_MAXSIZE,
            '--download-sections', '*0-' . (int)CF_YTDLP_SECONDS,
            '--ffmpeg-location', CF_FFMPEG,
            '-f', 'best[height<=720]/best',
            '-o', $tmp, '--', $url];
    $t0 = microtime(true);
    // stderr to a FILE, not a pipe: yt-dlp spawns ffmpeg for HLS, and a
    // grandchild that outlives the timeout would hold a pipe open and hang the
    // tick that was supposed to be bounded. proc_close then waits only on
    // /usr/bin/timeout, which always returns.
    $p = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', $ef, 'w']], $pipes);
    $rc = is_resource($p) ? proc_close($p) : -1;
    flock($lock, LOCK_UN);
    fclose($lock);
    $stderr = is_file($ef) ? (string)file_get_contents($ef) : '';
    @unlink($ef);

    $size = is_file($tmp) ? (int)filesize($tmp) : 0;
    $sig  = '';
    if ($size > 0 && ($h = @fopen($tmp, 'rb'))) { $sig = substr((string)fread($h, 12), 4, 4); fclose($h); }
    $ok = ($rc === 0 && $size >= 100000 && $size <= CF_MAX_BYTES && $sig === 'ftyp' && @rename($tmp, $dest));
    if (!$ok) {
        @unlink($tmp);
        $line = '';
        foreach (array_reverse(preg_split('/\R/', trim($stderr)) ?: []) as $l) {
            $l = trim($l);
            if ($l !== '' && stripos($l, 'ERROR') !== false) { $line = $l; break; }
        }
        if ($line === '') $line = trim((string)preg_replace('/\s+/', ' ', mb_substr($stderr, -200)));
        $err = $rc === 124                       // /usr/bin/timeout had to kill it
            ? sprintf('yt-dlp: timed out after %ds', CF_YTDLP_TIMEOUT)
            : 'yt-dlp: ' . ($line !== '' ? mb_substr($line, 0, 150)
                                         : sprintf('rc=%d, %d bytes, sig \'%s\'', $rc, $size, $sig));
    }
    error_log(sprintf('clip_fetch: yt-dlp %s %s in %.1fs — %s', $plat,
        $ok ? 'ok ' . round($size / 1048576, 1) . ' MB' : 'FAILED (' . $err . ')',
        microtime(true) - $t0, $url));
    return $ok;
}

/* ---- download ------------------------------------------------------------ */
function cf_download(string $mediaUrl, string $pageUrl, string $dest, string $ua = CF_UA): bool {
    $fh = @fopen($dest, 'wb');
    if (!$fh) return false;
    $ch = curl_init($mediaUrl);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_TIMEOUT => CF_VIDEO_TIMEOUT, CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => [
            'Referer: ' . $pageUrl,          // <- the whole trick (TikTok CDN)
            'Accept: */*',
            'Sec-Fetch-Dest: video', 'Sec-Fetch-Mode: no-cors',
            'Range: bytes=0-' . CF_MAX_BYTES,
        ],
    ]);
    $ok   = (bool)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    $size = is_file($dest) ? (int)filesize($dest) : 0;
    $sig = '';
    if ($size > 0 && ($h = @fopen($dest, 'rb'))) {
        $sig = substr((string)fread($h, 12), 4, 4);
        fclose($h);
    }
    if (!$ok || $code >= 400 || $size < 100000 || $sig !== 'ftyp') {
        error_log("clip_fetch: media http {$code}, {$size} bytes, sig '{$sig}'" . ($cerr ? ", curl: {$cerr}" : '') . " — discarded");
        $GLOBALS['__cf_last_err'] = "http {$code}, {$size} bytes, sig '{$sig}'" . ($cerr ? ", {$cerr}" : '');
        @unlink($dest);
        return false;
    }
    return true;
}

/* ---- trim (r140): keep the beat, drop the rest ---------------------------- */
function cf_trim(string $dest, int $start = 0): void {
    if (!is_file(CF_FFMPEG)) return;
    $from = $start > 0 ? max(0, $start - 2) : 0;
    $len  = $start > 0 ? 12 : 25;
    $tmp = $dest . '.trim.mp4';
    $cmd = [CF_FFMPEG, '-y', '-loglevel', 'error', '-ss', (string)$from, '-i', $dest, '-t', (string)$len,
            '-c', 'copy', '-movflags', '+faststart', $tmp];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return;
    fclose($pipes[1]);
    $e = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    if ($rc === 0 && is_file($tmp) && filesize($tmp) > 100000) { rename($tmp, $dest); }
    else { @unlink($tmp); if ($e) error_log('clip_fetch: trim failed: ' . mb_substr($e, 0, 120)); }
}

/* ---- the meter (r140): every attempt is a row --------------------------- */
function cf_log(string $url, string $route, bool $ok, int $bytes, int $ms, string $err): void {
    try {
        require_once __DIR__ . '/db.php';
        require_once __DIR__ . '/clip_supply.php';
        $pdo = db_alive();
        clip_supply_install($pdo);
        $pdo->prepare("INSERT INTO clip_supply_log (at, url, platform, route, ok, bytes, ms, error) VALUES (NOW(),?,?,?,?,?,?,?)")
            ->execute([mb_substr($url, 0, 500), clip_platform($url), $route, $ok ? 1 : 0, $bytes, $ms, mb_substr($err, 0, 200)]);
    } catch (Throwable $e) { error_log('clip_fetch: log failed: ' . $e->getMessage()); }
}

/**
 * Get a local copy of one clip, trimmed to its beat. Returns the path, or
 * null if no server route can fetch it (then the runner still tries yt-dlp).
 */
/* r148 (2026-09-09) CLIP SLICES. A story with 15 beats and 4 clips used to give
 * 3 beats real footage; the maker then froze one clip into a jpg and showed that
 * picture three separate times (the owner watched it happen). A clip is 25-40
 * seconds of video: cutting a second and a third window out of it costs nothing
 * and gives those beats real motion. A slice is addressed as "<clip url>#t=SS",
 * so it hashes to its own file and travels the existing content-addressed road
 * to the runner, which sees it as an ordinary separate clip. */
const CF_SLICE_LEN = 10;

const CF_FFPROBE = '/home/u219414635/bin/ffprobe';

/** Run a command, return [rc, stderr]. */
function cf_run(array $cmd, int $timeoutSec = 180): array {
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return [-1, 'proc_open failed'];
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return [proc_close($p), (string)$err];
}

/** Seconds of playable video, or 0.0 when it cannot be read. */
function cf_probe_duration(string $file): float {
    if (!is_file(CF_FFPROBE) || !is_file($file)) return 0.0;
    $p = proc_open([CF_FFPROBE, '-v', 'error', '-show_entries', 'format=duration',
                    '-of', 'default=nw=1:nk=1', $file],
                   [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return 0.0;
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    fclose($pipes[2]); proc_close($p);
    return (float)trim((string)$out);
}

/**
 * Cut one window out of a clip.
 *
 * r149 (the p119 crash): the first version stream-copied (`-c copy`) with a fast
 * seek. ffmpeg accepts that and OUR ffmpeg even decodes the result without a
 * complaint — but the first packet is a mid-GOP frame, so MoviePy on the runner
 * died with "failed to read the first frame of video file ... might mean that
 * the file is corrupted" and took the whole render down with it. Two guards, in
 * the order that makes them cheap:
 *   1. probe the source first — a window that starts past the end must never be
 *      attempted (offset 24 on a 22.3s clip produced 1.3 MB of garbage that
 *      passed every size check);
 *   2. re-encode the window so its first frame IS a keyframe, then prove it by
 *      decoding that frame. A 10s vertical clip on veryfast costs a few seconds
 *      of one core, which is the price of a render that does not crash.
 */
function cf_cut(string $src, int $start, int $len, string $dest): bool {
    if (!is_file(CF_FFMPEG) || !is_file($src)) return false;
    $dur = cf_probe_duration($src);
    if ($dur > 0 && $start + 3 > $dur) {
        $GLOBALS['__cf_last_err'] = sprintf('window starts at %ds but the clip is only %.1fs', $start, $dur);
        return false;
    }
    // -threads 1 AFTER -i: this host's static ffmpeg cannot start its threaded
    // encoder, and placed before the input the flag only reaches the decoder.
    $cmd = [CF_FFMPEG, '-y', '-loglevel', 'error', '-ss', (string)$start, '-i', $src,
            '-threads', '1', '-t', (string)$len,
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '26', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '96k', '-movflags', '+faststart', $dest];
    [$rc, $err] = cf_run($cmd);
    $ok = $rc === 0 && is_file($dest) && filesize($dest) > 100000;
    if ($ok) {
        // Prove the runner can open it: decode frame one, exactly as MoviePy does.
        [$vrc, $verr] = cf_run([CF_FFMPEG, '-v', 'error', '-i', $dest, '-threads', '1',
                                '-frames:v', '1', '-f', 'null', '-'], 60);
        if ($vrc !== 0 || trim($verr) !== '') {
            $ok = false;
            $err = 'first frame unreadable: ' . ($verr ?: 'rc=' . $vrc);
        }
    }
    if (!$ok) { @unlink($dest); if ($err) $GLOBALS['__cf_last_err'] = mb_substr(preg_replace('/\s+/', ' ', $err), 0, 150); }
    return $ok;
}

function cf_fetch(string $url, int $start = 0, bool $sourceForSlices = false): ?string {
    // A slice: fetch the SOURCE once (kept untrimmed under its own key) and cut.
    if (!$sourceForSlices && preg_match('/^(.*)#t=(\d+)$/', $url, $sm)) {
        $dest = cf_path($url);
        if (is_file($dest) && filesize($dest) > 100000) return $dest;
        if (cf_dead_skip($url)) return null;   // r150: this window already proved impossible
        $t0 = microtime(true);
        $GLOBALS['__cf_last_err'] = '';
        $GLOBALS['__cf_nonote'] = false;
        $base = cf_fetch($sm[1], 0, true);   // may raise __cf_nonote if the yt-dlp lock refused the SOURCE
        $ok = $base && cf_cut($base, (int)$sm[2], CF_SLICE_LEN, $dest);
        // r149: ffmpeg exits 0 when -ss lands past the end of the source and
        // writes a header-only file. That empty slice used to be staged and
        // counted as footage the video never got. A real 10s window is bigger.
        if ($ok && (!is_file($dest) || filesize($dest) < 100000)) {
            @unlink($dest);
            $ok = false;
            $GLOBALS['__cf_last_err'] = 'empty cut (offset past the end of the clip)';
        }
        // Never write "source shorter than the offset" against a window whose
        // source was simply not downloaded because our own lock was held.
        if ($ok || empty($GLOBALS['__cf_nonote']))
            cf_dead_note($url, (bool)$ok, $ok ? '' : ('cut failed at ' . $sm[2] . 's: ' . ($GLOBALS['__cf_last_err'] ?: 'source shorter than the offset')));
        cf_log($url, 'slice', (bool)$ok, $ok ? (int)filesize($dest) : 0, (int)((microtime(true) - $t0) * 1000),
               $ok ? '' : ($base ? ('cut failed at ' . $sm[2] . 's: ' . ($GLOBALS['__cf_last_err'] ?: 'source shorter than the offset')) : 'source unavailable'));
        return $ok ? $dest : null;
    }
    $dest = $sourceForSlices ? cf_path($url . '#full') : cf_path($url);
    if (is_file($dest) && filesize($dest) > 100000) return $dest;   // cached
    // r150 DEAD LETTER LIST: 1,849 YouTube attempts in 24h, 0 successes, because
    // nothing remembered the answer. The route doctor sets $GLOBALS['__cf_force']
    // so it still measures the wall for real instead of reading our own note.
    if (cf_dead_skip($url)) return null;
    $t0 = microtime(true);
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $route = 'none'; $ok = false; $err = '';
    $GLOBALS['__cf_last_err'] = '';
    $GLOBALS['__cf_nonote'] = false;   // raised only by cf_ytdlp's lock; read below AND by a slice caller
    try {
        if (str_contains($host, 'tiktok.com')) {
            $route = 'tiktok:resolver';
            $media = cf_resolve_tiktok_via_resolver($url);
            $ok = $media && cf_download($media, $url, $dest);
            if (!$ok) {
                $route = 'tiktok:page';
                $media = cf_resolve_tiktok($url);
                $ok = $media && cf_download($media, $url, $dest);
                if (!$media) $err = 'tiktok: no media address';
            }
        } elseif (preg_match('#^https?://(?:www\.|mobile\.)?(?:x|twitter)\.com/[^/]+/status/(\d+)#i', $url, $m)) {
            $route = 'x:syndication';
            $media = cf_resolve_x($m[1]);
            $ok = $media && cf_download($media, 'https://x.com/', $dest);
            if (!$media) $err = 'no video on this tweet';
        } elseif (preg_match('#(?:youtube\.com/(?:watch\?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{6,})#', $url, $m)) {
            $route = 'youtube:server';
            $media = cf_resolve_youtube_server($m[1], $err);
            $ok = $media && cf_download($media, 'https://www.youtube.com/', $dest,
                'com.google.android.apps.youtube.vr.oculus/1.62.27 (Linux; U; Android 12L; eureka-user Build/SQ3A.220605.009.A1) gzip');
        } elseif (($ytPlat = cf_ytdlp_target($url)) !== '') {
            // r160: twitch + kick, no credentials. See cf_ytdlp() for the bounds.
            $route = 'ytdlp:' . $ytPlat;
            $ok = cf_ytdlp($url, $dest, $err);
        } elseif (preg_match('#\.(mp4|m4v|mov)(\?|$)#i', $url)) {
            $route = 'file';
            $ok = cf_download($url, $url, $dest);
        } elseif (str_contains($host, 'twitch.tv') || str_contains($host, 'kick.com')) {
            // the right host but not a clip (a live page, a profile, a clip
            // INDEX): permanent, so the dead letter list stops asking.
            $err = 'no server route for this platform (not a clip URL)';
        } else {
            $err = 'no server route for this platform';
        }
        if (!$ok && $err === '' && $GLOBALS['__cf_last_err'] !== '') $err = $GLOBALS['__cf_last_err'];
        // a slice SOURCE is kept whole: trimming it to the first 25s would leave
        // nothing for the second and third windows to cut from.
        if ($ok && !$sourceForSlices) cf_trim($dest, $start);
    } catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
    cf_log($url, $route, $ok, $ok ? (int)filesize($dest) : 0, (int)((microtime(true) - $t0) * 1000), $err);
    // r160: a "yt-dlp busy" refusal is OUR lock, not this clip's fault. Writing
    // it to the dead letter list would back a perfectly good clip off for an
    // hour because the tick happened to be downloading another one.
    // The flag is NOT cleared here: a slice that called us for its source has
    // to be able to see that the source was lock-refused, not broken.
    if (empty($GLOBALS['__cf_nonote'])) cf_dead_note($url, $ok, $err);
    return $ok ? $dest : null;
}

function cf_fetch_story(array $clips, int $max = 6): array {
    $out = [];
    foreach ($clips as $c) {
        if (count($out) >= $max) break;
        $u = is_array($c) ? (string)($c['url'] ?? '') : (string)$c;
        if ($u === '') continue;
        try {
            $p = cf_fetch($u, is_array($c) ? (int)($c['start'] ?? 0) : 0);
            if ($p) $out[$u] = $p;
        } catch (Throwable $e) {
            error_log('clip_fetch: ' . $e->getMessage());
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ r150
 * THE DEAD LETTER LIST — stop asking questions that can only ever fail.
 *
 * Measured 2026-09-10 over 24 hours: 1,849 YouTube fetch attempts across 17
 * links, ZERO successes (1,682 of them "Sign in to confirm you're not a bot",
 * 147 "no longer available"), plus 347 slice cuts on 8 clips of which 315 were
 * "the window starts past the end of the clip". The bridge runs every ten
 * minutes, so each dead link was re-asked 148 times in a day. Nothing anywhere
 * remembered that the answer is always no.
 *
 * A failure is written down here with a reason. PERMANENT reasons (the video is
 * gone, the window is past the end of the clip) are never retried. Everything
 * else backs off: 1h, 2h, 4h ... capped at 7 days, so a wall that comes down
 * (a proxy, new cookies) is still discovered — just not 148 times a day.
 * A success clears the record, so nothing is stuck dead by accident.
 */
function cf_dead_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS clip_dead (
        url_key CHAR(40) NOT NULL PRIMARY KEY,
        url VARCHAR(500) NOT NULL,
        platform VARCHAR(16) NOT NULL DEFAULT '',
        reason VARCHAR(160) NOT NULL DEFAULT '',
        permanent TINYINT(1) NOT NULL DEFAULT 0,
        tries INT NOT NULL DEFAULT 0,
        first_at DATETIME NOT NULL,
        last_at DATETIME NOT NULL,
        retry_after DATETIME NULL,
        KEY idx_retry (retry_after)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Reasons that can never become a success, no matter how long we wait. */
function cf_dead_is_permanent(string $err): bool {
    $e = mb_strtolower($err);
    foreach ([
        'window starts at',                 // our own duration guard: the clip is shorter
        'no longer available',
        'video unavailable',
        'private video',
        'has been removed',
        'account associated with this video has been terminated',
        'no server route for this platform',
    ] as $needle) {
        if (str_contains($e, $needle)) return true;
    }
    return false;
}

/** true = do not attempt this url right now (and why, in $GLOBALS['__cf_skip']). */
function cf_dead_skip(string $url): bool {
    // The daily route doctor MUST get a real answer, or the memory would keep
    // a route marked dead long after the wall came down and nobody would know.
    if (!empty($GLOBALS['__cf_force'])) return false;
    try {
        require_once __DIR__ . '/db.php';
        $pdo = db_alive();
        cf_dead_install($pdo);
        $st = $pdo->prepare("SELECT reason, permanent, tries, retry_after FROM clip_dead WHERE url_key=?");
        $st->execute([sha1($url)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return false;
        if ((int)$r['permanent'] === 1) {
            $GLOBALS['__cf_skip'] = 'known dead: ' . $r['reason'];
            return true;
        }
        if ($r['retry_after'] && strtotime((string)$r['retry_after']) > time()) {
            $GLOBALS['__cf_skip'] = sprintf('backing off after %d failure(s) until %s: %s',
                                            (int)$r['tries'], $r['retry_after'], $r['reason']);
            return true;
        }
    } catch (Throwable $e) { /* the memory is an optimisation, never a blocker */ }
    return false;
}

/** Record the outcome. Success forgets the url; failure schedules the next try. */
function cf_dead_note(string $url, bool $ok, string $err = ''): void {
    try {
        require_once __DIR__ . '/db.php';
        $pdo = db_alive();
        cf_dead_install($pdo);
        $key = sha1($url);
        if ($ok) { $pdo->prepare("DELETE FROM clip_dead WHERE url_key=?")->execute([$key]); return; }
        $perm = cf_dead_is_permanent($err) ? 1 : 0;
        // 1h, 2h, 4h, 8h ... capped at 7 days. Computed in PHP: a bound param
        // inside an SQL expression trips this server's collation mismatch.
        $st = $pdo->prepare("SELECT tries FROM clip_dead WHERE url_key=?");
        $st->execute([$key]);
        $tries = (int)$st->fetchColumn() + 1;
        $hours = min(168, 1 << min(10, $tries - 1));
        $retry = $perm ? null : gmdate('Y-m-d H:i:s', time() + $hours * 3600);
        $pdo->prepare("INSERT INTO clip_dead (url_key, url, platform, reason, permanent, tries, first_at, last_at, retry_after)
                       VALUES (?,?,?,?,?,1,NOW(),NOW(),?)
                       ON DUPLICATE KEY UPDATE reason=VALUES(reason), permanent=VALUES(permanent),
                                               tries=tries+1, last_at=NOW(), retry_after=VALUES(retry_after)")
            ->execute([$key, mb_substr($url, 0, 500), clip_platform($url),
                       mb_substr(preg_replace('/\s+/', ' ', $err), 0, 160), $perm, $retry]);
    } catch (Throwable $e) { /* never let bookkeeping break a fetch */ }
}

/** What the memory is holding back, for the admin meter. */
function cf_dead_stats(PDO $pdo): array {
    cf_dead_install($pdo);
    return [
        'rows'      => $pdo->query("SELECT COUNT(*) FROM clip_dead")->fetchAll(PDO::FETCH_COLUMN)[0] ?? 0,
        'permanent' => $pdo->query("SELECT COUNT(*) FROM clip_dead WHERE permanent=1")->fetchAll(PDO::FETCH_COLUMN)[0] ?? 0,
        'sleeping'  => $pdo->query("SELECT COUNT(*) FROM clip_dead WHERE permanent=0 AND retry_after > NOW()")->fetchAll(PDO::FETCH_COLUMN)[0] ?? 0,
        'by_platform' => $pdo->query("SELECT platform, COUNT(*) n, SUM(permanent) perm, SUM(tries) tries
                                      FROM clip_dead GROUP BY platform ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC),
        'worst' => $pdo->query("SELECT LEFT(url,70) url, platform, tries, permanent, LEFT(reason,70) reason
                                FROM clip_dead ORDER BY tries DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC),
    ];
}
