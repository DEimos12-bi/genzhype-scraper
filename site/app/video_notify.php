<?php
/**
 * r188c DELIVERY EMAIL, one function for both delivery routes.
 *
 * Owner 2026-09-16: "send me the link to my email when it's finished". The
 * first version lived inside api/video_receive.php only, but GitHub runners
 * are often refused by this server's firewall (HTTP 403 on 2026-09-24), and
 * then the video comes home through the video-drop branch and
 * app/video_bridge.php ingests it - a route that never sent the email (page
 * 1022 was delivered that way with no mail). Both routes now call this.
 *
 * Switch: app/NOTIFY_EMAIL holds the address; delete the file to stop.
 * Every attempt is written to app/notify.log, because the web SAPI's
 * error_log goes nowhere readable on this host. Never throws.
 */
function video_notify_delivered(PDO $pdo, int $pageId, string $rel, int $bytes, string $via): void {
    try {
        $nf = __DIR__ . '/NOTIFY_EMAIL';
        $to = is_file($nf) ? trim((string)file_get_contents($nf)) : '';
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
        $st = $pdo->prepare("SELECT title, hook, tpl, slug FROM video_scripts WHERE page_id=?");
        $st->execute([$pageId]);
        $v = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $base = 'https://genzhype.com';
        $body = "A new GenZHype video is ready.\n\n"
              . "Story: " . (string)($v['title'] ?? ('page ' . $pageId)) . "\n"
              . "Hook: " . (string)($v['hook'] ?? '') . "\n"
              . "Format: " . ((int)($v['tpl'] ?? 0) === 5 ? 'short cut' : 'long cut') . "\n"
              . "Size: " . round($bytes / 1048576, 1) . " MB\n\n"
              . "Watch: " . $base . $rel . "\n"
              . "Story page: " . $base . '/drama/' . (string)($v['slug'] ?? '') . "/\n\n"
              . "It posts automatically on the platforms' next scheduled runs.\n";
        // 2026-09-24: was mail(), which this host "accepts" and never delivers
        // (5 logged sends, 0 received). SMTP through app/mailer.php does deliver.
        require_once __DIR__ . '/mailer.php';
        $r = mailer_send($to, 'GenZHype video ready: ' . mb_substr((string)($v['hook'] ?? ('page ' . $pageId)), 0, 60), $body);
        @file_put_contents(__DIR__ . '/notify.log',
            date('c') . " page {$pageId} via {$via} smtp({$to}) = " . ($r['ok'] ? 'SENT' : 'FAILED: ' . $r['error']) . "\n", FILE_APPEND);
    } catch (Throwable $e) {
        @file_put_contents(__DIR__ . '/notify.log',
            date('c') . " page {$pageId} via {$via} FAILED: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}
