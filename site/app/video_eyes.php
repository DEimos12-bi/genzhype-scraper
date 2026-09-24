<?php
/* GenZHype | THE EYES (r141, 2026-09-06) — the learning system finally looks
 * INSIDE videos.
 *
 * OWNER (2026-09-06): winners on TikTok/IG "use a lot of clips, only images
 * when they can't find clips, and they keep showing clips for the FULL script,
 * not only the main event... did the machine learning ever recommend this?"
 * It could not. The rival learner (video_intel.php) reads titles, lengths and
 * upload hours from the YouTube API and never sees a frame. Three watchers,
 * none watching the picture. This is the fourth.
 *
 * HOW IT SEES (deterministic, no AI, no quota): ffmpeg `signalstats` at 4
 * frames/s on a 160px copy gives YDIF = how much the luma changed since the
 * previous frame. CALIBRATED 2026-09-06 on this server:
 *   real TikTok footage      YDIF mean 23.5 / median 34 / 53 cuts in 2 min
 *   our slideshow videos     YDIF mean 4.7-6.8 / median 2.5-3.9 / 4-7% live
 * So a frame with 8 <= YDIF < 40 is LIVE footage (moving picture), >= 40 is a
 * CUT, < 8 is a STILL (a photo with a slow pan). live_ratio = live/all.
 *
 * WHOSE VIDEOS. Rival accounts cannot be listed (TikTok's list API is signed,
 * the resolver's user endpoints sit behind Cloudflare, Exa does not index
 * profiles — all measured). But the clip HUNT already downloads the TikToks
 * people made about OUR exact stories, and the resolver returns their play /
 * like / share counts. Those are the rivals that matter: same topic, real
 * numbers. Every staged TikTok is measured on arrival; every finished video of
 * ours is measured at ingest. Nothing is downloaded twice.
 *
 * OUTPUT. comp_rule scope=video key 'visual_shape': winners (top quartile by
 * plays, or >= 500k) vs the rest vs OURS, with evidence counts. Advisory, like
 * every rival rule; the Strategist reads it with the others. It refuses to
 * write below EYES_MIN_SAMPLE rivals, exactly like the title learner. */

const EYES_MIN_SAMPLE = 12;
const EYES_FFMPEG = '/home/u219414635/bin/ffmpeg';

function eyes_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS vid_shape (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kind ENUM('rival','ours') NOT NULL,
        platform VARCHAR(16) NOT NULL DEFAULT '',
        ref VARCHAR(500) NOT NULL,
        page_id INT UNSIGNED NULL,
        author VARCHAR(120) NOT NULL DEFAULT '',
        title VARCHAR(255) NOT NULL DEFAULT '',
        plays BIGINT NOT NULL DEFAULT 0,
        likes BIGINT NOT NULL DEFAULT 0,
        shares BIGINT NOT NULL DEFAULT 0,
        posted_on DATE NULL,
        duration_s DECIMAL(6,1) NOT NULL DEFAULT 0,
        frames INT NOT NULL DEFAULT 0,
        live_ratio DECIMAL(4,3) NOT NULL DEFAULT 0,
        still_ratio DECIMAL(4,3) NOT NULL DEFAULT 0,
        cuts INT NOT NULL DEFAULT 0,
        cuts_per_min DECIMAL(6,1) NOT NULL DEFAULT 0,
        ydif_mean DECIMAL(6,2) NOT NULL DEFAULT 0,
        ydif_median DECIMAL(6,2) NOT NULL DEFAULT 0,
        trimmed TINYINT(1) NOT NULL DEFAULT 0,
        measured_at DATETIME NOT NULL,
        UNIQUE KEY u_ref (ref(191)),
        KEY idx_kind (kind, measured_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // r148 (2026-09-09): the per-video verdict. Added with try/catch so an
    // existing table upgrades in place on any host.
    foreach ([
        "ALTER TABLE vid_shape ADD COLUMN repeat_ratio DECIMAL(4,3) NOT NULL DEFAULT 0",
        "ALTER TABLE vid_shape ADD COLUMN worst_repeat INT NOT NULL DEFAULT 0",
        "ALTER TABLE vid_shape ADD COLUMN worst_share DECIMAL(4,3) NOT NULL DEFAULT 0",
        "ALTER TABLE vid_shape ADD COLUMN pictures INT NOT NULL DEFAULT 0",
        "ALTER TABLE vid_shape ADD COLUMN verdict ENUM('ok','weak','bad','unknown') NOT NULL DEFAULT 'unknown'",
        "ALTER TABLE vid_shape ADD COLUMN verdict_note VARCHAR(255) NOT NULL DEFAULT ''",
        "ALTER TABLE vid_shape ADD KEY idx_verdict (kind, verdict, measured_at)",
    ] as $sql) { try { $pdo->exec($sql); } catch (Throwable $e) { /* already there */ } }
}

/* Measure one file. Returns the shape or null. ~2-10s per video. */
function eyes_measure(string $file): ?array {
    if (!is_file($file) || !is_file(EYES_FFMPEG)) return null;
    // -threads 1 / -filter_threads 1 AFTER -i. Without them this measurement is a
    // COIN FLIP: it succeeds on a quiet host and dies with rc=245 while the host
    // is busy (measured 2026-09-09 at load 2.8 — the same run that worked minutes
    // earlier at low load). A check that only works on quiet days is not a check.
    $cmd = [EYES_FFMPEG, '-hide_banner', '-loglevel', 'info', '-i', $file,
            '-threads', '1', '-filter_threads', '1',
            '-vf', 'scale=160:-2,fps=4,signalstats,metadata=print:key=lavfi.signalstats.YDIF',
            '-an', '-f', 'null', '-'];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return null;
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($p);
    if (!preg_match_all('/YDIF=([\d.]+)/', $err, $m) || count($m[1]) < 8) return null;
    $y = array_map('floatval', $m[1]);
    array_shift($y);                                   // frame 0 has no predecessor
    $n = count($y);
    $live = 0; $still = 0; $cuts = 0;
    foreach ($y as $v) { if ($v >= 40) $cuts++; elseif ($v >= 8) $live++; else $still++; }
    $s = $y; sort($s);
    $dur = $n / 4.0;
    return [
        'duration_s'   => round($dur, 1),
        'frames'       => $n,
        'live_ratio'   => round($live / $n, 3),
        'still_ratio'  => round($still / $n, 3),
        'cuts'         => $cuts,
        'cuts_per_min' => $dur > 0 ? round($cuts / ($dur / 60), 1) : 0,
        'ydif_mean'    => round(array_sum($y) / $n, 2),
        'ydif_median'  => round($s[intdiv($n, 2)], 2),
    ];
}

function eyes_store(PDO $pdo, string $kind, string $platform, string $ref, array $shape, array $meta = []): void {
    eyes_install($pdo);
    $pdo->prepare("INSERT INTO vid_shape (kind,platform,ref,page_id,author,title,plays,likes,shares,posted_on,duration_s,frames,live_ratio,still_ratio,cuts,cuts_per_min,ydif_mean,ydif_median,trimmed,measured_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE plays=GREATEST(plays,VALUES(plays)), likes=GREATEST(likes,VALUES(likes)), shares=GREATEST(shares,VALUES(shares)),
                     live_ratio=VALUES(live_ratio), still_ratio=VALUES(still_ratio), cuts=VALUES(cuts), cuts_per_min=VALUES(cuts_per_min),
                     ydif_mean=VALUES(ydif_mean), ydif_median=VALUES(ydif_median), duration_s=VALUES(duration_s), frames=VALUES(frames), measured_at=NOW()")
        ->execute([$kind, $platform, mb_substr($ref, 0, 500), $meta['page_id'] ?? null, mb_substr((string)($meta['author'] ?? ''), 0, 120),
                   mb_substr((string)($meta['title'] ?? ''), 0, 255), (int)($meta['plays'] ?? 0), (int)($meta['likes'] ?? 0), (int)($meta['shares'] ?? 0),
                   $meta['posted_on'] ?? null, $shape['duration_s'], $shape['frames'], $shape['live_ratio'], $shape['still_ratio'],
                   $shape['cuts'], $shape['cuts_per_min'], $shape['ydif_mean'], $shape['ydif_median'], (int)($meta['trimmed'] ?? 0)]);
}

/* A staged rival clip (the bridge calls this right after cf_fetch). The
 * resolver's numbers travel in $GLOBALS['__cf_tiktok_meta'][$url]. */
function eyes_ingest_rival(PDO $pdo, string $url, string $file, int $pageId = 0): bool {
    try {
        $meta = $GLOBALS['__cf_tiktok_meta'][$url] ?? [];
        if (!$meta && str_contains($url, 'tiktok.com')) {   // cached file, never resolved this run: ask once
            require_once __DIR__ . '/clip_fetch.php';
            cf_resolve_tiktok_via_resolver($url);
            $meta = $GLOBALS['__cf_tiktok_meta'][$url] ?? [];
        }
        $shape = eyes_measure($file);
        if (!$shape) return false;
        eyes_store($pdo, 'rival', 'tiktok', $url, $shape, [
            'page_id' => $pageId ?: null, 'author' => $meta['author'] ?? '', 'title' => $meta['title'] ?? '',
            'plays' => $meta['play_count'] ?? 0, 'likes' => $meta['digg_count'] ?? 0, 'shares' => $meta['share_count'] ?? 0,
            'posted_on' => !empty($meta['create_time']) ? date('Y-m-d', (int)$meta['create_time']) : null,
            'trimmed' => 1,   // staged files are cut to the first 25s / the beat: the hook window
        ]);
        return true;
    } catch (Throwable $e) { error_log('eyes: rival measure failed: ' . $e->getMessage()); return false; }
}

function eyes_ingest_ours(PDO $pdo, int $pageId, string $file): bool {
    // r148: the full measurement (shape + repetition + verdict). This is only a
    // convenience for whichever door notices first; the reconciliation loop is
    // the guarantee, so a missing hook can no longer hide a video from the eyes.
    try { return eyes_measure_ours($pdo, $pageId, $file) !== null; }
    catch (Throwable $e) { error_log('eyes: ours measure failed: ' . $e->getMessage()); return false; }
}

/* Backfill our library a few per tick (each measure = one ffmpeg pass). */
function eyes_backfill_ours(PDO $pdo, int $limit = 3): int {
    eyes_install($pdo);
    $n = 0;
    $rows = $pdo->query("SELECT v.page_id, v.video_path FROM video_scripts v
                         LEFT JOIN vid_shape s ON s.kind='ours' AND s.page_id=v.page_id
                         WHERE v.video_status='ready' AND v.video_path<>'' AND s.id IS NULL
                         ORDER BY v.video_made_at DESC LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $f = dirname(__DIR__) . '/public_html' . $r['video_path'];
        if (!is_file($f)) { eyes_store($pdo, 'ours', 'genzhype', 'page:' . $r['page_id'], ['duration_s' => 0, 'frames' => 0, 'live_ratio' => 0, 'still_ratio' => 0, 'cuts' => 0, 'cuts_per_min' => 0, 'ydif_mean' => 0, 'ydif_median' => 0], ['page_id' => (int)$r['page_id'], 'title' => 'FILE MISSING']); continue; }
        if (eyes_ingest_ours($pdo, (int)$r['page_id'], $f)) $n++;
    }
    return $n;
}

/* Backfill: rival TikToks already planned and already on disk (the clip store),
 * not yet measured. A few per call; each = one resolver call + one ffmpeg pass. */
function eyes_backfill_rivals(PDO $pdo, int $limit = 6): int {
    require_once __DIR__ . '/clip_fetch.php';
    eyes_install($pdo);
    $done = [];
    foreach ($pdo->query("SELECT ref FROM vid_shape WHERE kind='rival'")->fetchAll(PDO::FETCH_COLUMN) as $r) $done[$r] = 1;
    $n = 0;
    foreach ($pdo->query("SELECT page_id, footage_clips FROM video_scripts WHERE footage_clips LIKE '%tiktok.com%' ORDER BY clips_replanned_at DESC, created_at DESC LIMIT 60") as $row) {
        foreach ((array)json_decode((string)$row['footage_clips'], true) as $c) {
            if ($n >= $limit) return $n;
            $u = (string)($c['url'] ?? '');
            if ($u === '' || !str_contains($u, 'tiktok.com') || isset($done[$u])) continue;
            $f = cf_path($u);
            if (!is_file($f) || filesize($f) < 100000) continue;
            $done[$u] = 1;
            if (eyes_ingest_rival($pdo, $u, $f, (int)$row['page_id'])) $n++;
            usleep(800000);
        }
    }
    return $n;
}

function eyes_median(array $xs): float { if (!$xs) return 0.0; sort($xs); $n = count($xs); return $n % 2 ? (float)$xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2; }

/* LEARN: winners vs the rest vs ours → comp_rule 'visual_shape'. */
function eyes_learn(PDO $pdo): array {
    require_once __DIR__ . '/video_intel.php';
    eyes_install($pdo);
    $riv = $pdo->query("SELECT * FROM vid_shape WHERE kind='rival' AND frames >= 20 AND plays > 0")->fetchAll(PDO::FETCH_ASSOC);
    $ours = $pdo->query("SELECT * FROM vid_shape WHERE kind='ours' AND frames >= 20")->fetchAll(PDO::FETCH_ASSOC);
    $out = ['rivals' => count($riv), 'ours' => count($ours), 'written' => false];
    if (count($riv) < EYES_MIN_SAMPLE) { $out['note'] = 'below sample floor (' . EYES_MIN_SAMPLE . '), nothing written'; return $out; }
    $plays = array_column($riv, 'plays'); sort($plays);
    $q3 = (float)$plays[(int)floor(0.75 * (count($plays) - 1))];
    $win = array_filter($riv, fn($r) => (int)$r['plays'] >= max($q3, 500000));
    $rest = array_filter($riv, fn($r) => (int)$r['plays'] < max($q3, 500000));
    $stat = function (array $rows): array {
        return ['n' => count($rows),
                'live_ratio'   => round(eyes_median(array_map('floatval', array_column($rows, 'live_ratio'))), 2),
                'still_ratio'  => round(eyes_median(array_map('floatval', array_column($rows, 'still_ratio'))), 2),
                'cuts_per_min' => round(eyes_median(array_map('floatval', array_column($rows, 'cuts_per_min'))), 1),
                'ydif_median'  => round(eyes_median(array_map('floatval', array_column($rows, 'ydif_median'))), 1),
                'median_plays' => (int)eyes_median(array_map('intval', array_column($rows, 'plays')))];
    };
    $W = $stat(array_values($win)); $R = $stat(array_values($rest)); $O = $stat($ours);
    $value = ['winners' => $W, 'rest' => $R, 'ours' => $O,
              'read' => sprintf('Winners show moving footage %d%% of the time and cut every %.1fs; ours %d%% and every %.1fs.',
                        (int)round(100 * $W['live_ratio']), $W['cuts_per_min'] > 0 ? 60 / $W['cuts_per_min'] : 0,
                        (int)round(100 * $O['live_ratio']), $O['cuts_per_min'] > 0 ? 60 / $O['cuts_per_min'] : 0),
              'note' => 'rival files are the hook window (first 25s / the beat); ours are full videos', 'measured_at' => date('Y-m-d')];
    $conf = min(90, 50 + 2 * count($riv));
    vi_put_rule($pdo, 'visual_shape', $value, $conf, count($win) . ' winners vs ' . count($rest) . ' others (TikToks on our own story topics) vs ' . count($ours) . ' of ours');
    $out['written'] = true; $out['value'] = $value;
    return $out;
}

/* ============================ r148 (2026-09-09) ============================
 * THE OWNER WATCHED ONE VIDEO AND SAW WHAT THE MACHINE DID NOT: p179 opened on
 * three real clips and then spent 23 seconds on ten stills and four cards, with
 * ONE image (a frozen frame cut out of a staged TikTok) shown three separate
 * times and a second image shown three times. The vision judge passed it. The
 * eyes said nothing, for three reasons that were all mine:
 *   1. the measure hook sat on ONE of the two delivery doors (the file drop),
 *      and this video came through the other one (direct upload);
 *   2. the daily catch-up lived in the block the tick has been skipping;
 *   3. the eyes were a LEARNER (averages into one rule) with no verdict on a
 *      single video and no voice to raise one.
 *
 * The concepts this rebuild respects, chosen for THIS host (shared Hostinger,
 * no daemons, no Redis server, no containers, shell_exec disabled):
 *   - RECONCILIATION LOOP instead of event hooks. The loop asks "which ready
 *     videos have no measurement?" and fixes the gap. It cannot miss a door
 *     because it never depends on being told. (Defect 1 and 2 die together.)
 *   - SINGLE WRITER via a MariaDB advisory lock (GET_LOCK), so two ticks can
 *     never measure the same file twice. Verified available on this server.
 *   - IDEMPOTENCE: measuring twice is harmless (upsert keyed by ref), so the
 *     loop is safe to retry, which is what makes at-least-once acceptable.
 *   - BOUNDED WORK: a hard budget in seconds and a cap per run, because the
 *     tick is killed at 1800s and ffmpeg is the expensive part.
 *   - PERCEPTUAL HASHING / CONTENT-ADDRESSED DEDUPE: a picture is identified by
 *     what it LOOKS like, not by its filename, so the same image reused under a
 *     different name is still caught. 16x16 grey average-hash, one frame per
 *     second, grouped by Hamming distance.
 * ========================================================================= */

const EYES_HASH_THRESHOLD = 18;    // bits of 256 that may differ and still count as "the same picture"
const EYES_LOCK           = 'genzhype_eyes_reconcile';

/** One average-hash per sampled second. 256-bit strings. [] on any failure. */
function eyes_frame_hashes(string $file, int $maxFrames = 180): array {
    if (!is_file($file) || !is_file(EYES_FFMPEG)) return [];
    $tmp = sys_get_temp_dir() . '/eyes-' . getmypid() . '-' . substr(md5($file), 0, 10) . '.raw';
    // -threads 1 is REQUIRED on this host: the static ffmpeg's threaded encoder
    // fails here with "ff_frame_thread_encoder_init failed" and writes 0 bytes,
    // which is exactly why the first version of this detector silently found
    // nothing. Measured 2026-09-09: 0 bytes with threads, 6912 bytes without.
    // '-threads 1' must sit AFTER -i: before the input it only sets the DECODER,
    // and the failure is in the rawvideo ENCODER. Placed first it still returns
    // rc=245 and an empty file (measured both ways on this host, 2026-09-09).
    $cmd = [EYES_FFMPEG, '-y', '-loglevel', 'error', '-i', $file, '-threads', '1',
            '-vf', 'fps=1,scale=16:16,format=gray', '-f', 'rawvideo', $tmp];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return [];
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($p);
    if (!is_file($tmp)) return [];
    $raw = (string)file_get_contents($tmp);
    @unlink($tmp);
    $out = [];
    $n = min(intdiv(strlen($raw), 256), $maxFrames);
    for ($i = 0; $i < $n; $i++) {
        $bytes = unpack('C256', substr($raw, $i * 256, 256));
        if (!$bytes) continue;
        $mean = array_sum($bytes) / 256;
        $bits = '';
        foreach ($bytes as $b) $bits .= $b > $mean ? '1' : '0';
        $out[] = $bits;
    }
    return $out;
}

/** Group the sampled seconds into distinct pictures; report the repetition. */
function eyes_repeat_stats(array $hashes): array {
    // NOTE the key is 'sampled', not 'frames': eyes_measure() already returns a
    // 'frames' count at 4 fps, and merging the two arrays let that one win, so the
    // first report said "109 sampled seconds" for a 27-second video.
    $total = count($hashes);
    if ($total === 0) return ['sampled' => 0, 'pictures' => 0, 'repeat_ratio' => 0.0, 'worst_repeat' => 0, 'longest_hold' => 0, 'worst_share' => 0.0];
    $clusters = [];
    foreach ($hashes as $idx => $h) {
        $best = -1; $bestD = PHP_INT_MAX;
        foreach ($clusters as $i => $c) {
            $d = 0;
            for ($k = 0; $k < 256; $k++) { if ($h[$k] !== $c['rep'][$k] && ++$d > EYES_HASH_THRESHOLD) break; }
            if ($d <= EYES_HASH_THRESHOLD && $d < $bestD) { $bestD = $d; $best = $i; }
        }
        // 'at' keeps WHEN each picture was on screen, which is what separates a
        // long single shot from an image that keeps coming back.
        if ($best >= 0) { $clusters[$best]['n']++; $clusters[$best]['at'][] = $idx; }
        else $clusters[] = ['rep' => $h, 'n' => 1, 'at' => [$idx]];
    }
    // WHAT COUNTS AS REPETITION (fixed 2026-09-09, same day it was built). The
    // first version counted how many seconds a picture filled, which called a
    // single 10-second shot of real footage "one picture repeated 10 times" —
    // a false alarm on a video that was actually fine. The owner's complaint was
    // never about a long shot; it was about an image that GOES AWAY AND COMES
    // BACK. So the measure is RETURNS: how many separate times one picture
    // appears. A continuous run is one appearance, however long.
    $pictures = count($clusters);
    $worstReturns = 0; $returnFrames = 0;
    foreach ($clusters as $c) {
        $segs = 1;
        for ($i = 1, $k = count($c['at']); $i < $k; $i++) if ($c['at'][$i] !== $c['at'][$i - 1] + 1) $segs++;
        if ($segs > 1) $returnFrames += $c['n'];
        if ($segs > $worstReturns) $worstReturns = $segs;
    }
    $counts = array_column($clusters, 'n');
    rsort($counts);
    return ['sampled' => $total, 'pictures' => $pictures,
            'repeat_ratio'  => round($returnFrames / $total, 3),   // share of the video spent on pictures that come back
            'worst_repeat'  => $worstReturns,                      // how many separate times the worst offender appears
            'longest_hold'  => (int)($counts[0] ?? 0),             // longest single stretch of one picture, in seconds
            'worst_share'   => round(($counts[0] ?? 0) / $total, 3)];
}

/** The verdict on ONE video, in the owner's words, against the learned winners. */
function eyes_verdict_for(PDO $pdo, array $shape, array $rep): array {
    $winners = 0.85;
    try {
        $rv = $pdo->query("SELECT rule_value FROM comp_rule WHERE scope='video' AND rule_key='visual_shape' AND active=1 ORDER BY updated_at DESC LIMIT 1")->fetchColumn();
        $rule = $rv ? json_decode((string)$rv, true) : null;
        if (!empty($rule['winners']['live_ratio'])) $winners = (float)$rule['winners']['live_ratio'];
    } catch (Throwable $e) {}
    $live = (float)($shape['live_ratio'] ?? 0);
    $notes = []; $bad = false; $weak = false;
    // A PICTURE THAT COMES BACK is the hard fault: it is what the owner saw and
    // it is unambiguous. Three separate appearances of one image = bad.
    $returns = (int)($rep['worst_repeat'] ?? 0);
    if ($returns >= 3) { $bad = true; $notes[] = sprintf('one picture comes back %d separate times', $returns); }
    elseif ($returns === 2 && ($rep['repeat_ratio'] ?? 0) >= 0.30) { $weak = true; $notes[] = sprintf('a picture returns twice and fills %d%% of the video', round(((float)$rep['repeat_ratio']) * 100)); }
    if (($rep['longest_hold'] ?? 0) >= 8) { $weak = true; $notes[] = sprintf('one picture is held for %d seconds without a change', (int)$rep['longest_hold']); }
    // The motion figure is REPORTED but does not on its own condemn a video: our
    // finished videos are composed (crossfades, slow pushes) while the rival
    // numbers come from raw phone footage, so the two are not yet comparable.
    // Measured 2026-09-09: an all-stills version and an all-clips version of the
    // same story both scored ~20%, which means this threshold is not calibrated
    // and must not be allowed to cry wolf until it is.
    if ($live < 0.30) { $notes[] = sprintf('the picture changes little (%d%% by our motion measure, uncalibrated against composed video)', round($live * 100)); }
    return ['verdict' => $bad ? 'bad' : ($weak ? 'weak' : 'ok'),
            'note' => $notes ? implode('; ', $notes) : 'no repeated pictures and no frozen holds'];
}

/** Measure ONE of our videos completely: shape + repetition + verdict. */
function eyes_measure_ours(PDO $pdo, int $pageId, string $file): ?array {
    $shape = eyes_measure($file);
    if (!$shape) return null;
    $rep = eyes_repeat_stats(eyes_frame_hashes($file));
    $v = eyes_verdict_for($pdo, $shape, $rep);
    $title = '';
    try { $title = (string)$pdo->query("SELECT h1 FROM pages WHERE id=" . (int)$pageId)->fetchColumn(); } catch (Throwable $e) {}
    eyes_store($pdo, 'ours', 'genzhype', 'page:' . $pageId, $shape, ['page_id' => $pageId, 'title' => $title]);
    try {
        $pdo->prepare("UPDATE vid_shape SET repeat_ratio=?, worst_repeat=?, worst_share=?, pictures=?, verdict=?, verdict_note=?
                       WHERE kind='ours' AND page_id=?")
            ->execute([$rep['repeat_ratio'], $rep['worst_repeat'], $rep['worst_share'], $rep['pictures'], $v['verdict'], mb_substr($v['note'], 0, 255), $pageId]);
    } catch (Throwable $e) { error_log('eyes verdict store: ' . $e->getMessage()); }
    return $shape + $rep + $v;
}

/**
 * THE RECONCILIATION LOOP. Desired state: every finished video of ours has a
 * measurement. Actual state: whatever is in vid_shape. This closes the gap and
 * never asks which door the video came through. Single writer (advisory lock),
 * bounded by seconds and by count, safe to run every tick.
 */
function eyes_reconcile(PDO $pdo, int $budgetSec = 120, int $max = 5): array {
    eyes_install($pdo);
    $out = ['measured' => 0, 'bad' => 0, 'weak' => 0, 'missing_file' => 0, 'left' => 0, 'busy' => false];
    try { $got = (int)$pdo->query("SELECT GET_LOCK('" . EYES_LOCK . "', 0)")->fetchColumn(); }
    catch (Throwable $e) { $got = 1; }
    if ($got !== 1) { $out['busy'] = true; return $out; }
    try {
        $t0 = time();
        $rows = $pdo->query("SELECT v.page_id, v.video_path FROM video_scripts v
                             LEFT JOIN vid_shape s ON s.kind='ours' AND s.page_id=v.page_id
                             WHERE v.video_status='ready' AND v.video_path<>'' AND s.id IS NULL
                             ORDER BY v.video_made_at DESC LIMIT " . (int)($max * 4))->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if ($out['measured'] >= $max || time() - $t0 > $budgetSec) break;
            $f = dirname(__DIR__) . '/public_html' . $r['video_path'];
            if (!is_file($f)) {   // the row says ready but the file is gone: record it so it is not retried forever
                eyes_store($pdo, 'ours', 'genzhype', 'page:' . $r['page_id'],
                           ['duration_s' => 0, 'frames' => 0, 'live_ratio' => 0, 'still_ratio' => 0, 'cuts' => 0, 'cuts_per_min' => 0, 'ydif_mean' => 0, 'ydif_median' => 0],
                           ['page_id' => (int)$r['page_id'], 'title' => 'FILE MISSING']);
                try { $pdo->prepare("UPDATE vid_shape SET verdict='unknown', verdict_note='the video file named by the database is not on disk' WHERE kind='ours' AND page_id=?")->execute([(int)$r['page_id']]); } catch (Throwable $e) {}
                $out['missing_file']++;
                continue;
            }
            $res = eyes_measure_ours($pdo, (int)$r['page_id'], $f);
            if (!$res) continue;
            $out['measured']++;
            if (($res['verdict'] ?? '') === 'bad')  $out['bad']++;
            if (($res['verdict'] ?? '') === 'weak') $out['weak']++;
        }
        $out['left'] = (int)$pdo->query("SELECT COUNT(*) FROM video_scripts v LEFT JOIN vid_shape s ON s.kind='ours' AND s.page_id=v.page_id
                                         WHERE v.video_status='ready' AND v.video_path<>'' AND s.id IS NULL")->fetchColumn();
    } catch (Throwable $e) {
        error_log('eyes_reconcile: ' . $e->getMessage());
    } finally {
        try { $pdo->query("SELECT RELEASE_LOCK('" . EYES_LOCK . "')"); } catch (Throwable $e) {}
    }
    return $out;
}

/** The eyes now have a voice: bad videos and a starved queue reach the Governor. */
function eyes_governor_check(PDO $pdo): array {
    eyes_install($pdo);
    $found = [];
    // NOT YET TRUSTED TO RAISE AN ALARM. On 2026-09-09 this judge called both the
    // all-stills version and the all-clips version of the same story "bad": its
    // motion threshold is borrowed from raw phone footage and its picture matcher
    // works at 16x16 grey, where two different talking heads can look like one.
    // It keeps measuring and it shows every verdict in the admin, but a watchman
    // that cries wolf is worse than none — so it stays quiet until a calibration
    // pass proves it separates a good video from a bad one. Create app/EYES_TRUSTED
    // to let it speak.
    $trusted = is_file(__DIR__ . '/EYES_TRUSTED');
    $bad = $trusted ? $pdo->query("SELECT page_id, title, verdict_note FROM vid_shape WHERE kind='ours' AND verdict='bad' AND measured_at >= NOW() - INTERVAL 7 DAY ORDER BY measured_at DESC")->fetchAll(PDO::FETCH_ASSOC) : [];
    if ($bad) {
        $w = $bad[0];
        $found[] = ['code' => 'eyes_bad_video', 'severity' => 'alarm',
                    'title' => count($bad) . ' shipped video(s) are mostly still pictures or repeat one image',
                    'detail' => 'The eyes measure every finished video. These fall below what winning videos on our own topics look like. Newest: page ' . $w['page_id'] . ' — ' . $w['verdict_note'],
                    'evidence' => implode('; ', array_map(fn($b) => 'p' . $b['page_id'] . ': ' . mb_substr((string)$b['verdict_note'], 0, 80), array_slice($bad, 0, 4)))];
    }
    $left = (int)$pdo->query("SELECT COUNT(*) FROM video_scripts v LEFT JOIN vid_shape s ON s.kind='ours' AND s.page_id=v.page_id
                              WHERE v.video_status='ready' AND v.video_path<>'' AND s.id IS NULL")->fetchColumn();
    if ($left > 25) $found[] = ['code' => 'eyes_backlog', 'severity' => 'watch',
                                'title' => "$left finished videos have never been looked at",
                                'detail' => 'The reconciliation loop measures a few per tick. A growing number means the tick is skipping it again.',
                                'evidence' => "unmeasured: $left"];
    return $found;
}

function eyes_summary(PDO $pdo): array {
    eyes_install($pdo);
    $r = $pdo->query("SELECT kind, COUNT(*) n, ROUND(AVG(live_ratio),3) live, ROUND(AVG(cuts_per_min),1) cpm, ROUND(AVG(ydif_median),1) yd FROM vid_shape WHERE frames>=20 GROUP BY kind")->fetchAll(PDO::FETCH_ASSOC);
    $rule = $pdo->query("SELECT rule_value, confidence, evidence, updated_at FROM comp_rule WHERE scope='video' AND rule_key='visual_shape' ORDER BY updated_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $top = $pdo->query("SELECT author, title, plays, live_ratio, cuts_per_min, ref FROM vid_shape WHERE kind='rival' AND frames>=20 ORDER BY plays DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    $ours = $pdo->query("SELECT page_id, title, live_ratio, cuts_per_min, duration_s FROM vid_shape WHERE kind='ours' AND frames>=20 ORDER BY measured_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    return ['by_kind' => $r, 'rule' => $rule ? ['value' => json_decode((string)$rule['rule_value'], true), 'confidence' => $rule['confidence'], 'evidence' => $rule['evidence'], 'updated_at' => $rule['updated_at']] : null, 'top' => $top, 'ours' => $ours];
}

/* ------------------------------------------------------------------ r149
 * THE OWNER'S VERDICT — the missing half of the eyes.
 *
 * The judge measures every video (repeat returns, longest hold, live share)
 * but it has never been told what those numbers MEAN. It scored the all-stills
 * p179 at 19% live and the all-clips p179 at 20% and called both bad, which is
 * why eyes_governor_check is gated behind app/EYES_TRUSTED. A machine cannot
 * calibrate itself against nothing: it needs a handful of videos a human
 * watched and labelled good / ok / bad. That is what this table is.
 *
 * eyes_calibrate() then does the only honest thing with them: for each metric
 * it searches every threshold and keeps the one that best separates the videos
 * the owner called bad from the ones he called good (Youden's J = sensitivity
 * + specificity - 1, the standard cut-point rule). It reports how well the best
 * one actually separates. It does NOT write the threshold or trip the trust
 * flag by itself — a 6-video sample can look perfect by luck.
 */
function eyes_labels_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS eyes_label (
        page_id INT UNSIGNED NOT NULL PRIMARY KEY,
        label ENUM('good','ok','bad') NOT NULL,
        note VARCHAR(255) NOT NULL DEFAULT '',
        labelled_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function eyes_label_set(PDO $pdo, int $pageId, string $label, string $note = ''): bool {
    if (!in_array($label, ['good', 'ok', 'bad'], true) || $pageId <= 0) return false;
    eyes_labels_install($pdo);
    $pdo->prepare("INSERT INTO eyes_label (page_id, label, note, labelled_at) VALUES (?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE label=VALUES(label), note=VALUES(note), labelled_at=NOW()")
        ->execute([$pageId, $label, mb_substr($note, 0, 255)]);
    return true;
}

function eyes_label_clear(PDO $pdo, int $pageId): void {
    try { $pdo->prepare("DELETE FROM eyes_label WHERE page_id=?")->execute([$pageId]); } catch (Throwable $e) {}
}

/** The metrics the judge can act on, and which direction is worse. */
function eyes_metrics(): array {
    return [
        'worst_repeat' => ['dir' => 'high', 'label' => 'times one picture comes back'],
        'repeat_ratio' => ['dir' => 'high', 'label' => 'share of the video that is a repeat'],
        'worst_share'  => ['dir' => 'high', 'label' => 'share held by the single most-repeated picture'],
        'live_ratio'   => ['dir' => 'low',  'label' => 'share of seconds with real motion'],
        'cuts_per_min' => ['dir' => 'low',  'label' => 'cuts per minute'],
        'ydif_median'  => ['dir' => 'low',  'label' => 'frame-to-frame change'],
    ];
}

/**
 * Search every cut-point for every metric and keep the best separator.
 * Returns per-metric {threshold, j, acc, misses} plus a readable verdict on
 * whether the sample is big enough to trust yet.
 */
function eyes_calibrate(PDO $pdo): array {
    eyes_labels_install($pdo);
    $rows = $pdo->query(
        "SELECT l.page_id, l.label, s.live_ratio, s.cuts_per_min, s.ydif_median,
                s.repeat_ratio, s.worst_repeat, s.worst_share, s.duration_s, s.verdict
         FROM eyes_label l JOIN vid_shape s ON s.page_id = l.page_id AND s.kind='ours'
         WHERE s.frames >= 20")->fetchAll(PDO::FETCH_ASSOC);
    $nBad  = count(array_filter($rows, fn($r) => $r['label'] === 'bad'));
    $nGood = count(array_filter($rows, fn($r) => $r['label'] === 'good'));
    $out = ['labelled' => count($rows), 'bad' => $nBad, 'good' => $nGood, 'metrics' => [], 'ready' => false, 'note' => ''];
    if ($nBad < 2 || $nGood < 2) {
        $out['note'] = "need at least 2 videos called good AND 2 called bad; have {$nGood} good / {$nBad} bad";
        return $out;
    }
    foreach (eyes_metrics() as $key => $meta) {
        $vals = array_values(array_unique(array_map(fn($r) => (float)$r[$key], $rows)));
        sort($vals);
        $best = null;
        foreach ($vals as $i => $v) {
            // cut between this value and the next, plus the extremes
            $cuts = [$v];
            if (isset($vals[$i + 1])) $cuts[] = ($v + $vals[$i + 1]) / 2;
            foreach ($cuts as $t) {
                $tp = $fp = $tn = $fn = 0;
                foreach ($rows as $r) {
                    if ($r['label'] === 'ok') continue;              // ok is the grey zone, not evidence
                    $x = (float)$r[$key];
                    $flag = $meta['dir'] === 'high' ? ($x >= $t) : ($x <= $t);   // flag = "the machine calls this bad"
                    if ($r['label'] === 'bad') { $flag ? $tp++ : $fn++; }
                    else                        { $flag ? $fp++ : $tn++; }
                }
                if ($tp + $fn === 0 || $fp + $tn === 0) continue;
                $sens = $tp / ($tp + $fn); $spec = $tn / ($fp + $tn);
                $j = $sens + $spec - 1;
                $acc = ($tp + $tn) / max(1, $tp + $tn + $fp + $fn);
                if ($best === null || $j > $best['j']) {
                    $best = ['threshold' => round($t, 3), 'j' => round($j, 3), 'acc' => round($acc, 3),
                             'dir' => $meta['dir'], 'label' => $meta['label'],
                             'misses' => $fp + $fn, 'sens' => round($sens, 2), 'spec' => round($spec, 2)];
                }
            }
        }
        if ($best) $out['metrics'][$key] = $best;
    }
    uasort($out['metrics'], fn($a, $b) => $b['j'] <=> $a['j']);
    $top = $out['metrics'] ? reset($out['metrics']) : null;
    $topKey = $out['metrics'] ? array_key_first($out['metrics']) : '';
    // A rule earns trust when it separates cleanly on a sample worth believing.
    $out['ready'] = $top && $top['j'] >= 0.75 && count($rows) >= 6 && $nBad >= 3 && $nGood >= 3;
    $out['best'] = $top ? ['metric' => $topKey] + $top : null;
    $out['note'] = $top
        ? sprintf('%s %s %s separates best (J=%.2f, %d miss(es) on %d labelled)',
                  $topKey, $top['dir'] === 'high' ? '>=' : '<=', $top['threshold'], $top['j'], $top['misses'], count($rows))
        : 'no metric separated the labels';
    if (!$out['ready'] && $top) $out['note'] .= ' — not enough evidence to trust it yet (want 6+ labelled, 3 good, 3 bad, J>=0.75)';
    return $out;
}
