<?php
// GenZHype | THE PAUSE SWITCH: app/PAUSE (see cli.php 'cron'). The hourly run and the build worker
// stop on it; the runner-facing endpoints that make, change or post something call pause_gate() so
// the GitHub runners stop too (no posting keys, no work lists, no page repairs). Owner 2026-09-26.
//   pause: put a reason in app/PAUSE     resume: rm app/PAUSE

function pause_gate(): void {
    if (!is_file(__DIR__ . '/PAUSE')) return;
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'paused']);
    exit;
}
