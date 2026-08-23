<?php
/* GenZHype | r123 — weekly trigger for the Strategist.
 * Curled by the strategist GitHub workflow (the owner's rule: everything
 * deploys through GitHub, nothing new lands on the Hostinger cron panel).
 * Token-gated; the run itself is read-only outside the strategist tables. */
declare(strict_types=1);

header('Content-Type: application/json');

$CONFIG = require dirname(__DIR__, 2) . '/app/config.php';
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if (!hash_equals((string)($CONFIG['ingest_token'] ?? ''), $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

ignore_user_abort(true);
set_time_limit(180);             // one AI call + reads; far under this

try {
    require dirname(__DIR__, 2) . '/app/strategist.php';
    $pdo = db();
    $report = strategist_run($pdo);
    echo json_encode([
        'ok' => $report !== null,
        'date' => $report['date'] ?? null,
        'recommendations' => count($report['recommendations'] ?? []),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => substr($e->getMessage(), 0, 200)]);
}
