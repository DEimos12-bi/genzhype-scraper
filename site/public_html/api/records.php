<?php
/**
 * GenZHype | THE RECORD — export (organ 02 reader)
 *
 * Token-gated, read-only. The Reflector (GitHub) and Claude (local) read the
 * joined stories from here instead of reaching into the DB.
 *
 *   POST {token, since?: ISO, page_id?: int, kind?: drama|term|video|post, limit?: <=200}
 *   GET  ?token=...&since=...            (same params; POST preferred — WAF)
 *
 *   -> {"records":[{kind,page_id,ref,plan,build,delivery,judgment,outcome,created_at,updated_at},...]}
 *
 * Same bootstrap + token pattern as api/video_next.php, verbatim by design.
 */
declare(strict_types=1);
$APP = dirname(__DIR__, 2) . '/app';
$GLOBALS['CONFIG'] = require $APP . '/config.php';
require $APP . '/helpers.php';
require $APP . '/db.php';
require_once $APP . '/record.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$IN = $_GET;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($body)) $IN = $body + $_GET;
}
if (!hash_equals($CONFIG['ingest_token'] ?? '', (string)($IN['token'] ?? ''))) {
    http_response_code(403); echo json_encode(['error' => 'bad token']); exit;
}

$pdo = db();
try {
    if (!empty($IN['page_id']) && ctype_digit((string)$IN['page_id'])) {
        $records = record_get($pdo, (int)$IN['page_id']);
    } else {
        $since = (string)($IN['since'] ?? '');
        $ts = $since !== '' ? strtotime($since) : false;
        $sinceSql = $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s', time() - 7 * 86400);   // default: last 7 days
        $kind = in_array((string)($IN['kind'] ?? ''), RECORD_KINDS, true) ? (string)$IN['kind'] : null;
        $records = record_since($pdo, $sinceSql, $kind, (int)($IN['limit'] ?? 200));
    }
    echo json_encode(['records' => $records, 'count' => count($records), 'at' => gmdate('c')],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'record export failed', 'detail' => mb_substr($e->getMessage(), 0, 200)]);
}
