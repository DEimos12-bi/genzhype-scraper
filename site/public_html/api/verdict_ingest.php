<?php
/**
 * GenZHype | THE RECORD — owner verdict intake (organ 03 -> organ 02)
 *
 * The build room on the owner's PC POSTs every verdict he records here, so his
 * word lands inside the video's story next to the judge's scores and the
 * platform numbers. His verdict OUTRANKS the vision judge — the learning loop
 * treats it as ground truth.
 *
 *   POST {token, video, verdict: bad|okay|good, reasons: [..], note, at}
 *     video = page id ("700"), a slug, or a TikTok/YouTube link we posted
 *   -> {"ok":true,"page_id":700,"matched_by":"id|slug|link"} | {"ok":false,"error":...}
 *
 * Token-gated (ingest token, constant-time compare), JSON only, bounded fields.
 */
declare(strict_types=1);
$APP = dirname(__DIR__, 2) . '/app';
$GLOBALS['CONFIG'] = require $APP . '/config.php';
require $APP . '/helpers.php';
require $APP . '/db.php';
require_once $APP . '/record.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit; }
$IN = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($IN)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad json']); exit; }
if (!hash_equals($CONFIG['ingest_token'] ?? '', (string)($IN['token'] ?? ''))) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad token']); exit;
}

$video   = trim((string)($IN['video'] ?? ''));
$verdict = (string)($IN['verdict'] ?? '');
$reasons = array_values(array_filter(array_map('strval', (array)($IN['reasons'] ?? [])), fn($r) => preg_match('/^[a-z_]{1,24}$/', $r)));
$note    = mb_substr(trim((string)($IN['note'] ?? '')), 0, 500);
$at      = (string)($IN['at'] ?? gmdate('c'));
if ($video === '' || !in_array($verdict, ['bad', 'okay', 'good'], true)) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'need video + verdict bad|okay|good']); exit;
}

$pdo = db();
$pageId = 0; $slug = ''; $by = '';
try {
    if (ctype_digit($video)) {
        $st = $pdo->prepare("SELECT page_id, slug FROM video_scripts WHERE page_id=?");
        $st->execute([(int)$video]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $pageId = (int)$r['page_id']; $slug = (string)$r['slug']; $by = 'id'; }
        elseif ((int)$pdo->query("SELECT COUNT(*) FROM pages WHERE id=" . (int)$video)->fetchColumn() > 0) { $pageId = (int)$video; $by = 'id'; }
    } elseif (preg_match('#^https?://#i', $video)) {
        // a link we posted: platform_videos stores NO url, only the platform's
        // own video id (TikTok numeric / YouTube 11-char) - which the link carries.
        $st = $pdo->prepare("SELECT pv.page_id, v.slug FROM platform_videos pv LEFT JOIN video_scripts v ON v.page_id=pv.page_id
                             WHERE pv.platform_video_id<>'' AND LENGTH(pv.platform_video_id)>=8
                               AND ? LIKE CONCAT('%', pv.platform_video_id, '%')
                             ORDER BY pv.posted_at DESC LIMIT 1");
        $st->execute([$video]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $pageId = (int)$r['page_id']; $slug = (string)($r['slug'] ?? ''); $by = 'link'; }
    } else {
        $s = preg_replace('/[^a-z0-9-]/', '', strtolower($video));
        $st = $pdo->prepare("SELECT page_id, slug FROM video_scripts WHERE slug=? OR slug LIKE ? LIMIT 1");
        $st->execute([$s, $s . '%']);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $pageId = (int)$r['page_id']; $slug = (string)$r['slug']; $by = 'slug'; }
    }
} catch (Throwable $e) { /* fall through to unmatched */ }

if ($pageId <= 0) {
    // Still keep it: an unmatched verdict is evidence too. page_id 0 + the raw
    // reference, so a later pass can resolve it by hand.
    record_touch($pdo, 'video', 0, mb_substr($video, 0, 120), 'judgment',
                 ['owner_verdict' => ['verdict' => $verdict, 'reasons' => $reasons, 'note' => $note, 'at' => $at, 'unmatched' => true]]);
    echo json_encode(['ok' => true, 'page_id' => 0, 'matched_by' => 'none', 'note' => 'kept unmatched']); exit;
}

record_touch($pdo, 'video', $pageId, $slug, 'judgment',
             ['owner_verdict' => ['verdict' => $verdict, 'reasons' => $reasons, 'note' => $note, 'at' => $at, 'via' => 'room']]);
echo json_encode(['ok' => true, 'page_id' => $pageId, 'matched_by' => $by]);
