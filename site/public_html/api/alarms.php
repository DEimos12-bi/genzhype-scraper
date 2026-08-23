<?php
/**
 * GenZHype | THE GOVERNOR'S BOARD — organ 13 (site side)
 *
 * An alarm nobody sees is the same as no alarm. The Governor runs on the tick
 * and records what it finds; this is how those findings reach the one screen the
 * owner actually looks at.
 *
 *   POST {token, action:"board"}                      -> {alarms:[...], last_run, stale}
 *   POST {token, action:"ack", code, note?}           -> acknowledged (seen, not fixed)
 *   POST {token, action:"resolve", code, note?}       -> resolved by the owner
 *
 * It ships the Governor's OWN last-run time with every response on purpose. A
 * dead watchman and a clean board look identical otherwise, and that is the
 * failure this whole organ exists to prevent.
 *
 * This endpoint cannot raise an alarm and cannot fix one. It reads, and it
 * records the owner's ruling on what he has read.
 */
declare(strict_types=1);
$APP = dirname(__DIR__, 2) . '/app';
$GLOBALS['CONFIG'] = require $APP . '/config.php';
require $APP . '/helpers.php';
require $APP . '/db.php';
require $APP . '/governor.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit; }
$IN = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($IN)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad json']); exit; }
if (!hash_equals($CONFIG['ingest_token'] ?? '', (string)($IN['token'] ?? ''))) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad token']); exit;
}

$pdo = db();
$action = (string)($IN['action'] ?? 'board');

try {
    if ($action === 'board') {
        echo json_encode(['ok' => true] + gov_board($pdo),
                         JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'ack' || $action === 'resolve') {
        $code = trim((string)($IN['code'] ?? ''));
        $note = mb_substr(trim((string)($IN['note'] ?? '')), 0, 300);
        if ($code === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'need code']); exit; }
        $status = $action === 'ack' ? 'acknowledged' : 'resolved';
        $st = $pdo->prepare("UPDATE governor_alarm SET status=?, resolved_note=? WHERE code=?");
        $st->execute([$status, $note !== '' ? $note : ('owner marked ' . $status), $code]);
        // An acknowledged fault that keeps happening will be re-raised by the next
        // round (gov_raise only re-opens 'resolved'), so acknowledging silences the
        // noise without hiding a fault that is still live.
        echo json_encode(['ok' => true, 'code' => $code, 'status' => $status,
                          'changed' => $st->rowCount()]);
        exit;
    }

    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'board failed', 'detail' => mb_substr($e->getMessage(), 0, 200)]);
}
