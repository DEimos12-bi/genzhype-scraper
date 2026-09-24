<?php
/**
 * GenZHype | the one way to email the owner (2026-09-24).
 *
 * PHP's mail() on this host answers "accepted" and delivers nothing. The r188c
 * video emails proved it again: notify.log shows 5 "accepted" sends between
 * 09-18 and 09-24 and the owner received none. Hostinger SMTP does deliver
 * (the weekly strategy mail has always gone out through it), so every mail to
 * the owner goes through here.
 *
 * Credentials, first match wins:
 *   1. app/config.php 'smtp' => ['user' => ..., 'pass' => ...]  (the owner adds
 *      them himself; then GenZHype no longer depends on another site's files)
 *   2. the agency mailer's login, read at send time (the r124 path, unchanged)
 */

function mailer_smtp(): ?array {
    $c = (array)($GLOBALS['CONFIG']['smtp'] ?? []);
    if (!empty($c['user']) && !empty($c['pass'])) {
        return ['host' => (string)($c['host'] ?? 'smtp.hostinger.com'), 'port' => (int)($c['port'] ?? 465),
                'user' => (string)$c['user'], 'pass' => (string)$c['pass']];
    }
    $s = @file_get_contents('/home/u219414635/domains/vsfagency.tech/public_html/send_mail.php');
    if (!$s) return null;
    if (!preg_match("/Username\s*=\s*'([^']+)'/", $s, $u)) return null;
    if (!preg_match("/Password\s*=\s*'([^']+)'/", $s, $p)) return null;
    return ['host' => 'smtp.hostinger.com', 'port' => 465, 'user' => $u[1], 'pass' => $p[1]];
}

/** Send a plain-text mail. Never throws; says why when it fails. */
function mailer_send(string $to, string $subject, string $body, string $fromName = 'GenZHype'): array {
    try {
        $smtp = mailer_smtp();
        if (!$smtp) return ['ok' => false, 'error' => 'no SMTP login found'];
        require_once __DIR__ . '/lib/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/lib/phpmailer/SMTP.php';
        require_once __DIR__ . '/lib/phpmailer/Exception.php';
        $m = new \PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host = $smtp['host'];
        $m->SMTPAuth = true;
        $m->Username = $smtp['user'];
        $m->Password = $smtp['pass'];
        $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $m->Port = $smtp['port'];
        $m->Timeout = 20;
        $m->CharSet = 'UTF-8';
        $m->setFrom($smtp['user'], $fromName);   // must be the logged-in mailbox or Hostinger refuses it
        $m->addAddress($to);
        $m->Subject = $subject;
        $m->Body = $body;
        $m->send();
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
    }
}
