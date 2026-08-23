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
                         ORDER BY v.force_render DESC, (v.shotlist IS NOT NULL) DESC, (v.tpl >= 2) DESC, v.created_at DESC
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
    foreach ($posts as &$post) {
        $bound = [];
        foreach ((array)(($post['shotlist']['shots'] ?? [])) as $sh) {
            $u = (string)($sh['clip_url'] ?? '');
            if ($u !== '' && str_contains($u, 'tiktok.com')) $bound[$u] = true;
        }
        $map = [];
        foreach (array_keys($bound) as $u) {
            try {
                $p = cf_fetch($u);
            } catch (Throwable $e) { $p = null; }
            if (!$p || !is_file($p)) { error_log("bridge: no local copy for {$u}"); continue; }
            $name = sha1($u) . '.mp4';
            if (!is_file($cd . '/' . $name) && !@copy($p, $cd . '/' . $name)) continue;
            $map[$u] = 'clips/' . $name;
            $clipsStaged++;
            $clipMB += (int)round(filesize($cd . '/' . $name) / 1048576);
        }
        // the maker reads this map and plays the local file instead of
        // spawning a download it cannot win
        if ($map) $post['clip_files'] = $map;
    }
    unset($post);

    file_put_contents($dir . '/feed.json', json_encode(
        ['generated' => date('c'), 'posts' => $posts], JSON_UNESCAPED_SLASHES));
    echo "feed: " . count($posts) . " job(s), $staged visual(s) staged, "
       . "{$clipsStaged} clip(s) staged ({$clipMB} MB)\n";
    exit(0);
}

// ---- ingest ---------------------------------------------------------------
$metas = glob($dir . '/drop-meta-*.json') ?: [];
// A failed-run drop has no meta sidecar but may carry video-<pid>.mp4 anyway;
// those are diagnosis-only (the judge said no) — ingest ONLY metad videos.
$done = 0;
foreach ($metas as $mf) {
    $m = json_decode((string)file_get_contents($mf), true);
    if (!is_array($m)) { continue; }
    $pid  = (int)($m['page_id'] ?? 0);
    $slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($m['slug'] ?? '')));
    $mp4  = $dir . '/' . basename((string)($m['mp4'] ?? ''));
    if ($pid <= 0 || $slug === '' || !is_file($mp4) || filesize($mp4) < 200000) { continue; }
    $row = $pdo->prepare("SELECT video_status, video_made_at FROM video_scripts WHERE page_id=?");
    $row->execute([$pid]);
    $cur = $row->fetch(PDO::FETCH_ASSOC);
    // idempotent: skip if a video newer than this drop already landed via HTTP
    if ($cur && $cur['video_status'] === 'ready'
            && $cur['video_made_at'] && strtotime((string)$cur['video_made_at']) > filemtime($mp4)) {
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
