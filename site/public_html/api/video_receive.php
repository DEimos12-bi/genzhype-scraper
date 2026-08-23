<?php
/**
 * GenZHype | VIDEO factory receiver. The external maker (GitHub Actions: edge-tts + FFmpeg)
 * POSTs the finished 9:16 MP4 here. We store it under /media/video/ and mark the script row
 * ready so the admin Video tab can preview/download it. Token-gated; mirrors reddit_ingest.php.
 *
 * POST multipart: token, page_id (int), slug, file (.mp4)  ->  {"ok":true,"url":...}
 */
declare(strict_types=1);
$APP = dirname(__DIR__, 2) . '/app';
$GLOBALS['CONFIG'] = require $APP . '/config.php';
require $APP . '/helpers.php';
require $APP . '/db.php';
require_once $APP . '/video_factory.php';
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }

// PRIMARY: JSON body {token, page_id, slug, video_b64} — the img_ingest pattern. Hostinger's
// WAF 403-blocks multipart file uploads from datacenter IPs (run #4) but passes JSON POSTs
// (the scraper/image-engine deliver this way daily). Multipart kept as a fallback for
// local/manual use.
$json  = json_decode(file_get_contents('php://input') ?: '', true);
$isJson = is_array($json) && isset($json['video_b64']);
$tok   = is_array($json) ? (string)($json['token'] ?? ($_POST['token'] ?? '')) : (string)($_POST['token'] ?? '');
if (!hash_equals($CONFIG['ingest_token'] ?? '', $tok)) { http_response_code(403); echo json_encode(['error' => 'bad token']); exit; }

// r29 HEARTBEAT: the maker POSTs its current render stage every few seconds so
// a hang can be pinpointed to the exact stage (Actions logs need admin to read).
// {token, action:"heartbeat", page_id, stage, elapsed} -> append one line to
// media/heartbeat.log. Cheap, non-fatal, no DB touch.
if (is_array($json) && (string)($json['action'] ?? '') === 'heartbeat') {
    $pid  = (int)($json['page_id'] ?? 0);
    $stg  = preg_replace('/[^a-z0-9_:.\- ]/i', '', (string)($json['stage'] ?? ''));
    $el   = (float)($json['elapsed'] ?? 0);
    $note = trim((string)($json['note'] ?? ''));
    $note = $note !== '' ? ' note=' . preg_replace('/[[:cntrl:]]/', ' ', mb_substr($note, 0, 800)) : '';
    $line = gmdate('Y-m-d\TH:i:s\Z') . " pid=$pid stage=$stg elapsed=" . number_format($el, 1) . "s$note\n";
    @file_put_contents(dirname(__DIR__) . '/media/heartbeat.log', $line, FILE_APPEND | LOCK_EX);
    echo json_encode(['ok' => true]);
    exit;
}

// r30 DIAG: action=diag — the maker ships an IMAGE that is not a delivery: the
// filmstrip of a REJECTED render, or a raw article screenshot. Without this the
// only evidence of a judge rejection is the judge's own prose, and three fix
// rounds were spent guessing at frames nobody could see. Saved under
// media/diag/<page_id>-<name>.<ext>; never touches the DB or the video row.
// {token, action:"diag", page_id, name, img_b64}
if (is_array($json) && (string)($json['action'] ?? '') === 'diag') {
    $pid  = (int)($json['page_id'] ?? 0);
    $name = preg_replace('/[^a-z0-9_.\-]/i', '', (string)($json['name'] ?? 'diag'));
    // r34: the same channel carries TEXT — the render's own stdout/stderr.
    // Actions logs need repo admin to read and there is no GitHub token on
    // this host, so a driver crash was invisible: the heartbeat just stopped.
    // The runner now ships its log here and the traceback is readable.
    if (!empty($json['text_b64'])) {
        $txt = base64_decode((string)$json['text_b64'], true) ?: '';
        if ($pid < 0 || $txt === '' || strlen($txt) > 2 * 1024 * 1024) {
            http_response_code(400); echo json_encode(['error' => 'bad text_b64']); exit;
        }
        $dir = dirname(__DIR__) . '/media/diag';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $dest = $dir . '/' . $pid . '-' . $name . '.txt';
        @file_put_contents($dest, $txt);
        @chmod($dest, 0644);
        echo json_encode(['ok' => true, 'url' => url('/media/diag/' . basename($dest)), 'bytes' => strlen($txt)], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $raw  = base64_decode((string)($json['img_b64'] ?? ''), true) ?: '';
    if ($pid <= 0 || $name === '' || $raw === '' || strlen($raw) > 6 * 1024 * 1024) {
        http_response_code(400); echo json_encode(['error' => 'need page_id + name + img_b64 (<=6MB)']); exit;
    }
    $ext = (substr($raw, 0, 8) === "\x89PNG\r\n\x1a\n") ? 'png' : 'jpg';
    $dir = dirname(__DIR__) . '/media/diag';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $dest = $dir . '/' . $pid . '-' . $name . '.' . $ext;
    if (@file_put_contents($dest, $raw) === false) {
        http_response_code(500); echo json_encode(['error' => 'store failed']); exit;
    }
    @chmod($dest, 0644);
    echo json_encode(['ok' => true, 'url' => url('/media/diag/' . basename($dest)), 'bytes' => strlen($raw)], JSON_UNESCAPED_SLASHES);
    exit;
}

// r16 CLOSED LOOP: action=replan — the maker's judge caught a said-vs-seen
// mismatch video. NULLing the shotlist sends the story back to the Director
// (the cron re-directs pending NULL-shotlist rows every tick); the maker does
// NOT mark it done, so the next run re-renders the re-planned story.
if (is_array($json) && (string)($json['action'] ?? '') === 'replan') {
    $pid = (int)($json['page_id'] ?? 0);
    if ($pid <= 0) { http_response_code(400); echo json_encode(['error' => 'need page_id']); exit; }
    $pdo = db();
    video_factory_install($pdo);
    $reasons = array_slice(array_map('strval', (array)($json['reasons'] ?? [])), 0, 8);
    // TIMELINE CONTRACT pages (tpl=3): the shotlist is DETERMINISTIC — a
    // NULLed shotlist would wait forever on the paused Director cron (and a
    // Director rewrite would break the contract). Regenerate it in place
    // instead; the sentence AI gets a fresh roll, the artifact joins recompute.
    $tpl = (int)$pdo->query("SELECT tpl FROM video_scripts WHERE page_id=" . $pid)->fetchColumn();
    if ($tpl === 3) {
        ignore_user_abort(true);   // regen takes ~1 AI call; survive a
        set_time_limit(180);       // maker-side POST timeout mid-write
        $ok = false;
        try { $ok = (bool)video_write_timeline_script($pdo, $pid); }
        catch (Throwable $e) { error_log('replan timeline regen failed: ' . $e->getMessage()); }
        error_log('video_receive: REPLAN(timeline) page ' . $pid . ' regen=' . ($ok ? 'ok' : 'FAILED')
            . ' reasons=' . mb_substr(json_encode($reasons, JSON_UNESCAPED_SLASHES), 0, 500));
        echo json_encode(['ok' => true, 'replanned' => $ok ? 1 : 0, 'timeline' => true]);
        exit;
    }
    $st = $pdo->prepare("UPDATE video_scripts SET shotlist=NULL WHERE page_id=? AND video_status='pending'");
    $st->execute([$pid]);
    error_log('video_receive: REPLAN page ' . $pid . ' (' . $st->rowCount() . ' row) reasons='
        . mb_substr(json_encode($reasons, JSON_UNESCAPED_SLASHES), 0, 500));
    echo json_encode(['ok' => true, 'replanned' => $st->rowCount()]);
    exit;
}

$pageId = (int)(($isJson ? ($json['page_id'] ?? 0) : ($_POST['page_id'] ?? 0)));
$slug   = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($isJson ? ($json['slug'] ?? '') : ($_POST['slug'] ?? ''))));
if ($pageId <= 0 || $slug === '') { http_response_code(400); echo json_encode(['error' => 'need page_id + slug']); exit; }

$bytes = '';
if ($isJson) {
    $bytes = base64_decode((string)$json['video_b64'], true) ?: '';
    if ($bytes === '') { http_response_code(400); echo json_encode(['error' => 'bad base64']); exit; }
} else {
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { http_response_code(400); echo json_encode(['error' => 'no file uploaded (err ' . ($f['error'] ?? '-') . ')']); exit; }
    $bytes = (string)file_get_contents($f['tmp_name']);
}
if (strlen($bytes) > 120 * 1024 * 1024) { http_response_code(413); echo json_encode(['error' => 'file too large']); exit; }

// verify it really is an MP4 (ftyp box near the start), not just named one
if (strpos(substr($bytes, 0, 16), 'ftyp') === false) { http_response_code(415); echo json_encode(['error' => 'not an mp4']); exit; }

$dir = dirname(__DIR__) . '/media/video';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) { http_response_code(500); echo json_encode(['error' => 'cannot create media dir']); exit; }
$rel  = '/media/video/' . $slug . '-' . $pageId . '.mp4';
$dest = dirname(__DIR__) . $rel;
if (file_put_contents($dest, $bytes) === false) { http_response_code(500); echo json_encode(['error' => 'store failed']); exit; }
chmod($dest, 0644);
$fsize = strlen($bytes);

// r19 FILMSTRIP: the maker also ships a 12-frame contact sheet (frames + the
// words spoken at each) so the operator's AI can SEE the render. Optional.
if ($isJson && !empty($json['sheet_b64'])) {
    $sb = base64_decode((string)$json['sheet_b64'], true) ?: '';
    if ($sb !== '' && strlen($sb) < 4 * 1024 * 1024) {
        @file_put_contents(dirname(__DIR__) . '/media/video/' . $slug . '-' . $pageId . '-sheet.jpg', $sb);
        @chmod(dirname(__DIR__) . '/media/video/' . $slug . '-' . $pageId . '-sheet.jpg', 0644);
    }
}

// CAROUSEL ARTIFACT FRAMES (2026-08-06): real frames of each timeline clip
// beat, named clipframe-<page>-<event_id>-<j>.jpg by the maker. Stored in the
// receipts namespace; app/carousel.php joins them per beat by event_id so a
// video beat's card shows THAT video, never a substitute image.
if ($isJson && !empty($json['clip_frames']) && is_array($json['clip_frames'])) {
    $cfDir = dirname(__DIR__) . '/assets/receipts/video';
    if (!is_dir($cfDir)) @mkdir($cfDir, 0755, true);
    $saved = 0;
    foreach (array_slice($json['clip_frames'], 0, 12) as $cf) {
        $name = basename((string)($cf['name'] ?? ''));
        if (!preg_match('/^clipframe-\d+-\d+-\d\.jpg$/', $name)) continue;
        $fb = base64_decode((string)($cf['b64'] ?? ''), true) ?: '';
        if ($fb === '' || strlen($fb) > 2 * 1024 * 1024) continue;
        @file_put_contents($cfDir . '/' . $name, $fb);
        @chmod($cfDir . '/' . $name, 0644);
        $saved++;
    }
    if ($saved) error_log("video_receive: stored $saved carousel clip frame(s) for page $pageId");
}

// r25 RENDER REPORT: what the planner actually did (ck_mode, footage/gap-fill/
// frozen counts, per-scene seq). Saved per-page + appended to a rolling log so
// the operator can see every render's decisions without GitHub Actions logs.
if ($isJson && !empty($json['report']) && is_array($json['report'])) {
    $rep = $json['report'];
    $rep['page_id'] = $pageId; $rep['slug'] = $slug; $rep['at'] = date('c');
    $line = json_encode($rep, JSON_UNESCAPED_SLASHES);
    @file_put_contents(dirname(__DIR__) . '/media/render-report-' . $pageId . '.json', $line);
    @file_put_contents(dirname(__DIR__) . '/media/render-report.log', $line . "\n", FILE_APPEND);
}

$pdo = db();
video_factory_install($pdo);
// r66 STYLE MEASUREMENT: persist which A/B treatment produced this video so
// per-style performance can be joined against platform_metrics later. Testing
// without recording which variant ran is just variety, not a test.
if ($isJson && !empty($json['report']['style'])) {
    $sty = preg_replace('/[^a-z]/', '', strtolower((string)$json['report']['style']));
    if ($sty !== '') {
        $pdo->prepare("UPDATE video_scripts SET style=? WHERE page_id=?")
            ->execute([$sty, $pageId]);
    }
}
$pdo->prepare("UPDATE video_scripts SET video_path=?, video_status='ready', video_made_at=NOW(), force_render=0 WHERE page_id=?")
    ->execute([$rel, $pageId]);

echo json_encode(['ok' => true, 'url' => url($rel), 'bytes' => $fsize], JSON_UNESCAPED_SLASHES);
