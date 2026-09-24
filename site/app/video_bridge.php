<?php
/**
 * GenZHype | r37 GITHUB BUS bridge (runs ON the Hostinger host via SSH/cron).
 *
 * Why: bitninja blackholes SOME GitHub-runner IPs at TCP connect, so the
 * runner<->site HTTP path is a lottery (run #154 lost a finished video; run
 * #156 could not even GET the feed). GitHub is the one host both sides always
 * reach. This script is the server side of that bus:
 *
 *   feed    — export the pending job feed + its visual files into a work dir
 *             (the shell wrapper commits it to the `video-feed` branch).
 *   ingest  — take a directory pulled from the `video-drop` branch and finish
 *             the delivery exactly like api/video_receive.php would have:
 *             store the mp4 + sheet, mark the row ready, rewrite the feed.
 *
 * Usage:  php app/video_bridge.php feed   <outdir>
 *         php app/video_bridge.php ingest <dropdir>
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$APP = __DIR__;
$GLOBALS['CONFIG'] = require $APP . '/config.php';
require $APP . '/helpers.php';
require $APP . '/db.php';
require_once $APP . '/video_feed.php';
require_once $APP . '/video_factory.php';

$mode = $argv[1] ?? '';
$dir  = rtrim((string)($argv[2] ?? ''), '/');
if (!in_array($mode, ['feed', 'ingest'], true) || $dir === '') {
    fwrite(STDERR, "usage: video_bridge.php feed|ingest <dir>\n");
    exit(2);
}
$pdo = db();

if ($mode === 'feed') {
    // Same query/shape video_feed_static_write() uses — reuse its builder.
    $rows = $pdo->query("SELECT v.page_id, v.slug, v.title, v.hook, v.script, v.image, v.broll, v.shotlist, v.gravity, v.force_render, v.footage_clips
                         FROM video_scripts v JOIN pages p ON p.id=v.page_id
                         WHERE p.status='published' AND v.video_status='pending'
                           -- r176: a Director-template script (tpl>=2) with no shot list is
                           -- waiting for the hourly Director (a judge reject NULLs it for a
                           -- replan). Rendered now it falls back to v3 stock b-roll: page 692,
                           -- forced before the replan, shipped 6 stock scenes and a dead frame.
                           AND NOT (v.tpl >= 2 AND v.shotlist IS NULL)
                         -- r188 (owner 2026-09-16: render the newest pages first). Measured that
                         -- day: our delivered videos were on average 33 DAYS old at render
                         -- (slowest 96), while freshness is an official ranking factor for
                         -- news. The queue sorted by when the SCRIPT was written; a backlog of
                         -- old scripts therefore outranked today's story. The STORY's publish
                         -- date now decides.
                         ORDER BY v.force_render DESC, (v.shotlist IS NOT NULL) DESC, (v.tpl >= 2) DESC, p.published_at DESC, v.created_at DESC
                         LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    $posts = [];
    foreach ($rows as $r) { $posts[] = video_feed_build_post($pdo, $r); }
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) { fwrite(STDERR, "cannot mkdir $dir\n"); exit(1); }
    $vd = $dir . '/visuals';
    if (!is_dir($vd)) { mkdir($vd, 0755, true); }
    // Stage every visual/photo URL as visuals/<sha1(url)> so a blackholed
    // runner never needs genzhype.com for images either. Local-domain URLs
    // are copied straight off disk; anything else is fetched from here.
    $staged = 0;
    $stage = function (?string $u) use ($vd, &$staged): void {
        if (!$u || !preg_match('#^https?://#', $u)) { return; }
        $dest = $vd . '/' . sha1($u);
        if (is_file($dest) && filesize($dest) > 2000) { return; }
        $host = parse_url($u, PHP_URL_HOST) ?: '';
        $bytes = '';
        if (stripos($host, 'genzhype.com') !== false) {
            $local = dirname(__DIR__) . '/public_html' . (parse_url($u, PHP_URL_PATH) ?: '');
            if (is_file($local)) { $bytes = (string)file_get_contents($local); }
        }
        if ($bytes === '') {
            $ctx = stream_context_create(['http' => ['timeout' => 25, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
            $bytes = (string)@file_get_contents($u, false, $ctx);
        }
        if (strlen($bytes) > 2000) { file_put_contents($dest, $bytes); $staged++; }
    };
    foreach ($posts as $post) {
        foreach (($post['visuals'] ?? []) as $u) { $stage(is_string($u) ? $u : null); }
        $stage($post['image'] ?? null);
        foreach (($post['people'] ?? []) as $pe) {
            if (is_array($pe)) {
                $stage($pe['photo'] ?? null);
                foreach (($pe['photos'] ?? []) as $pu) { $stage(is_string($pu) ? $pu : null); }
            }
        }
        // receipts are URL STRINGS (tweet-card PNGs on genzhype.com — exactly
        // what a blackholed runner cannot fetch; run #163 died on these).
        foreach (($post['receipts'] ?? []) as $rc) {
            if (is_string($rc)) { $stage($rc); }
            elseif (is_array($rc)) { $stage($rc['image'] ?? null); }
        }
        foreach (($post['receipt_meta'] ?? []) as $rm) {
            if (is_array($rm)) { $stage($rm['og_image'] ?? null); }
        }
    }
    // r104 STAGE THE CLIPS THE RUNNER CANNOT GET. TikTok serves our runners a
    // challenge page from every angle we tried, but a public resolver hands
    // THIS server a working link (5 of 5 measured). So the download happens
    // here and the file travels the same road our images already take — into
    // the feed branch, read locally by the runner. Only the beats that are
    // actually bound to a clip are fetched: a story holds up to eight, a video
    // plays three or four, and each one is 15-40MB.
    $cd = $dir . '/clips';
    if (!is_dir($cd)) @mkdir($cd, 0755, true);
    $clipsStaged = 0; $clipMB = 0;
    require_once __DIR__ . '/clip_fetch.php';
    // r140 (2026-09-06): stage EVERY clip the server can fetch — X, TikTok,
    // YouTube (when the android_vr client answers), direct files — for every
    // story in the feed, not only shot-bound TikToks. Budget: 6 per story,
    // 250 MB per run, trimmed windows (12-25s). Per-platform counts are logged
    // so the admin shows planned vs staged instead of anyone claiming 'done'.
    require_once __DIR__ . '/clip_supply.php';
    $runCapMB = 250; $perStory = 6;
    // r143 THE BRAIN: clips per story is a lever; the maker's own levers travel
    // in feed/levers.env (the workflow loads them into its environment) and in
    // feed.json for the record. Defaults stand if the brain is unreachable.
    $leversEnv = '';
    try { require_once __DIR__ . '/brain.php'; $perStory = max(3, min(8, (int)round(brain_lever('clips_per_story', 6.0)))); $leversEnv = brain_levers_env(); } catch (Throwable $e) {}
    if ($leversEnv !== '') @file_put_contents($dir . '/levers.env', $leversEnv);
    try { $pdoS = db_alive(); clip_supply_install($pdoS); } catch (Throwable $e) { $pdoS = null; }
    foreach ($posts as &$post) {
        $want = [];
        foreach ((array)(($post['shotlist']['shots'] ?? [])) as $sh) {   // shot-bound first
            $u = (string)($sh['clip_url'] ?? '');
            if ($u !== '') $want[$u] = 0;
        }
        foreach ((array)($post['clips'] ?? []) as $c) {
            $u = is_array($c) ? (string)($c['url'] ?? '') : (string)$c;
            if ($u !== '' && !isset($want[$u])) $want[$u] = is_array($c) ? (int)($c['start'] ?? 0) : 0;
        }
        // r149 SLICES FOR EVERY VIDEO TYPE (2026-09-09, owner watched p179):
        // r148 cuts extra windows out of a clip, but only inside the DRAMA
        // timeline writer. A term video has no shotlist, so its 1-2 clips
        // reached the maker as 1-2 files and every remaining beat got the same
        // frozen still ("the last 10 secs was only loops of imgs repeating").
        // Cut the same extra windows HERE, where every video type passes. A
        // slice costs no download: cf_fetch reuses the cached source.
        // Count only what this server can actually DOWNLOAD. p110 listed 8 clips,
        // 4 of them YouTube (walled from here, 0/27 proven), so a planned count of 8
        // said "enough" while only 4 files ever existed and half the video went to
        // stills. A tiktok/x fetch can still fail after this count; that residue is
        // visible in clip_stage_log as planned-minus-staged.
        $fetchable = [];
        foreach ($want as $u => $st) {
            if (clip_fetchable($u)) $fetchable[] = $u;   // r160: ONE list, clip_supply.php (adds twitch + kick)
        }
        if ($fetchable && count($fetchable) < $perStory) {
            $add = [];
            // r150: ask the clip how long it is instead of guessing. Offsets 12/24/36
            // were tried on every clip every ten minutes; 315 of 347 cuts in a day came
            // back "the window starts past the end" because most TikToks are ~20s. The
            // source is already on disk after the first run, so one ffprobe (about 20ms,
            // local file, no network) turns a guess into an answer.
            $windowsFor = function (string $u): array {
                $src = cf_path($u . '#full');
                if (!is_file($src)) $src = cf_path($u);
                $dur = is_file($src) ? cf_probe_duration($src) : 0.0;
                // Unknown length (never fetched yet): try the two safest windows only.
                if ($dur <= 0) return [12, 24];
                $out = [];
                foreach ([12, 24, 6, 18, 30, 36] as $off) {
                    if ($off + 3 <= $dur) $out[] = $off;   // same 3s floor cf_cut enforces
                }
                return $out;
            };
            $plan = [];
            foreach ($fetchable as $u) {
                if (str_contains($u, '#t=')) continue;
                // youtube is walled from this server; slicing it buys nothing
                if (!clip_fetchable($u)) continue;
                $plan[$u] = $windowsFor($u);
            }
            // round-robin so one long clip cannot eat every slot
            for ($round = 0; $round < 6; $round++) {
                foreach ($plan as $u => $offs) {
                    if (!isset($offs[$round])) continue;
                    if (count($fetchable) + count($add) >= $perStory) break 2;
                    $sl = $u . '#t=' . $offs[$round];
                    if (isset($want[$sl]) || isset($add[$sl])) continue;
                    $add[$sl] = true;
                }
            }
            foreach (array_keys($add) as $sl) {
                $want[$sl] = 0;
                $post['clips'][] = ['url' => $sl, 'start' => 0,
                                    'platform' => clip_platform($sl),
                                    'slice_of' => preg_replace('/#t=\d+$/', '', $sl)];
            }
            if ($add) error_log('bridge: page ' . (int)($post['page_id'] ?? 0) . ' had '
                . count($fetchable) . " fetchable clip(s) for {$perStory} slots; added "
                . count($add) . ' extra window(s)');
        }
        $map = []; $per = []; $n = 0;
        foreach ($want as $u => $start) {
            $plat = clip_platform($u);
            if (!clip_fetchable_platform($plat) && $plat !== 'youtube') continue;   // server routes only (youtube stays in the PLANNED count so its wall keeps being measured)
            $per[$plat]['planned'] = ($per[$plat]['planned'] ?? 0) + 1;
            if ($n >= $perStory || $clipMB >= $runCapMB) continue;
            try { $p = cf_fetch($u, (int)$start); } catch (Throwable $e) { $p = null; }
            if (!$p || !is_file($p)) { error_log("bridge: no local copy for {$u}"); continue; }
            $name = sha1($u) . '.mp4';
            if (!is_file($cd . '/' . $name) && !@copy($p, $cd . '/' . $name)) continue;
            $map[$u] = 'clips/' . $name;
            $mb = (int)round(filesize($cd . '/' . $name) / 1048576);
            $clipsStaged++; $clipMB += $mb; $n++;
            // r141 THE EYES: a rival TikTok on our own topic, already on disk —
            // measure its visual shape now (one ffmpeg pass, nothing downloaded twice)
            if ($plat === 'tiktok' && $pdoS) {
                try { require_once __DIR__ . '/video_eyes.php'; eyes_ingest_rival($pdoS, $u, $p, (int)($post['page_id'] ?? 0)); } catch (Throwable $e) {}
            }
            $per[$plat]['staged'] = ($per[$plat]['staged'] ?? 0) + 1;
            $per[$plat]['mb'] = ($per[$plat]['mb'] ?? 0) + $mb;
        }
        if ($pdoS) foreach ($per as $plat => $c) {
            try { $pdoS->prepare("INSERT INTO clip_stage_log (at,page_id,platform,planned,staged,mb) VALUES (NOW(),?,?,?,?,?)")
                ->execute([(int)($post['page_id'] ?? 0), $plat, (int)($c['planned'] ?? 0), (int)($c['staged'] ?? 0), (int)($c['mb'] ?? 0)]); } catch (Throwable $e) {}
        }
        // the maker reads this map and plays the local file instead of
        // spawning a download it cannot win
        if ($map) $post['clip_files'] = $map;
    }
    unset($post);
    try { cf_prune(14); } catch (Throwable $e) {}   // the server store is a cache, not an archive

    $feedLevers = [];
    try { $feedLevers = brain_levers_all(); } catch (Throwable $e) {}
    file_put_contents($dir . '/feed.json', json_encode(
        ['generated' => date('c'), 'levers' => $feedLevers, 'posts' => $posts], JSON_UNESCAPED_SLASHES));
    echo "feed: " . count($posts) . " job(s), $staged visual(s) staged, "
       . "{$clipsStaged} clip(s) staged ({$clipMB} MB)\n";
    exit(0);
}

// ---- ingest ---------------------------------------------------------------
$metas = glob($dir . '/drop-meta-*.json') ?: [];
// A failed-run drop has no meta sidecar but may carry video-<pid>.mp4 anyway;
// those are diagnosis-only (the judge said no) — ingest ONLY metad videos.
$done = 0;
// 2026-09-24: every drop decision is written to app/video_bridge.log. Page 1226
// was rendered and marked done by the maker on 09-24 but never arrived, and the
// bridge's output went nowhere, so the loss could not be traced.
$blog = static function (string $line): void {
    @file_put_contents(__DIR__ . '/video_bridge.log', date('c') . ' ' . $line . "\n", FILE_APPEND);
};
$blog('drop: ' . count($metas) . ' meta file(s); files: ' . implode(' ', array_map('basename', glob($dir . '/*') ?: [])));
foreach ($metas as $mf) {
    $m = json_decode((string)file_get_contents($mf), true);
    if (!is_array($m)) { $blog('SKIP ' . basename($mf) . ': unreadable meta'); continue; }
    $pid  = (int)($m['page_id'] ?? 0);
    $slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($m['slug'] ?? '')));
    $mp4  = $dir . '/' . basename((string)($m['mp4'] ?? ''));
    if ($pid <= 0 || $slug === '' || !is_file($mp4) || filesize($mp4) < 200000) {
        $blog("SKIP page {$pid}: " . (!is_file($mp4) ? 'mp4 missing from the drop' : 'bad meta or mp4 under 200 KB'));
        continue;
    }
    $row = $pdo->prepare("SELECT video_status, video_made_at FROM video_scripts WHERE page_id=?");
    $row->execute([$pid]);
    $cur = $row->fetch(PDO::FETCH_ASSOC);
    // idempotent: skip if a video newer than this drop already landed via HTTP
    if ($cur && $cur['video_status'] === 'ready'
            && $cur['video_made_at'] && strtotime((string)$cur['video_made_at']) > filemtime($mp4)) {
        $blog("SKIP page {$pid}: a newer copy already arrived over HTTP");
        continue;
    }
    $mdir = dirname(__DIR__) . '/public_html/media/video';
    if (!is_dir($mdir)) { mkdir($mdir, 0755, true); }
    $rel = '/media/video/' . $slug . '-' . $pid . '.mp4';
    copy($mp4, dirname(__DIR__) . '/public_html' . $rel);
    chmod(dirname(__DIR__) . '/public_html' . $rel, 0644);
    $sheet = $m['sheet'] ? $dir . '/' . basename((string)$m['sheet']) : '';
    if ($sheet && is_file($sheet)) {
        copy($sheet, dirname(__DIR__) . '/public_html/media/video/' . $slug . '-' . $pid . '-sheet.jpg');
    }
    if (!empty($m['report']) && is_array($m['report'])) {
        $rep = $m['report'];
        $rep['page_id'] = $pid; $rep['slug'] = $slug; $rep['at'] = date('c');
        $rep['via'] = 'video-drop';
        $line = json_encode($rep, JSON_UNESCAPED_SLASHES);
        @file_put_contents(dirname(__DIR__) . '/public_html/media/render-report-' . $pid . '.json', $line);
        @file_put_contents(dirname(__DIR__) . '/public_html/media/render-report.log', $line . "\n", FILE_APPEND);
    }
    video_factory_install($pdo);
    $pdo->prepare("UPDATE video_scripts SET video_path=?, video_status='ready', video_made_at=NOW(), force_render=0 WHERE page_id=?")
        ->execute([$rel, $pid]);
    echo "ingested page $pid -> $rel (" . number_format(filesize($mp4)) . " bytes, via video-drop)\n";
    // r188c: the owner's delivery email, same as the direct route
    require_once __DIR__ . '/video_notify.php';
    $blog("INGESTED page {$pid} -> {$rel}");
    video_notify_delivered($pdo, (int)$pid, (string)$rel, (int)filesize($mp4), 'video-drop');
    // r141 THE EYES: measure our own finished video the moment it lands
    try { require_once __DIR__ . '/video_eyes.php'; eyes_ingest_ours($pdo, (int)$pid, dirname(__DIR__) . '/public_html' . $rel); } catch (Throwable $e) {}
    // THE RECORD (organ 02): delivery + judge from the maker's report; plan
    // refreshed now that the row says ready. Observer only.
    try {
        require_once __DIR__ . '/record.php';
        $rep = (!empty($m['report']) && is_array($m['report'])) ? $m['report'] : [];
        $delivery = ['accepted' => true, 'mp4' => $rel, 'bytes' => filesize($mp4), 'via' => 'video-drop'];
        foreach (['substitutions', 'scenes', 'duration', 'style', 'render_ms', 'stages', 'warnings', 'unsatisfied_shots'] as $k) {
            if (array_key_exists($k, $rep)) $delivery[$k] = $rep[$k];
        }
        record_touch($pdo, 'video', $pid, $slug, 'delivery', $delivery);
        if (!empty($rep['judge']) || !empty($rep['judge_scores'])) {
            record_touch($pdo, 'video', $pid, $slug, 'judgment', ['judge' => ['pass' => true] + (array)($rep['judge'] ?? ['scores' => $rep['judge_scores']])]);
        }
        record_video_plan_for_page($pdo, $pid);
    } catch (Throwable $e) { error_log('record delivery hook: ' . $e->getMessage()); }
    $done++;
}
// THE RECORD: rejected renders have no meta sidecar but leave a render.log —
// the judge's reasons on rejects are what the learning loop needs most.
try {
    require_once __DIR__ . '/record.php';
    $rj = record_ingest_render_log($pdo, $dir . '/render.log');
    if ($rj) echo "record: {$rj} judged page(s) from render.log\n";
} catch (Throwable $e) { error_log('record render.log hook: ' . $e->getMessage()); }
video_feed_static_write($pdo);
echo "ingest: $done video(s); feed rewritten\n";
