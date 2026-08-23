<?php
/**
 * Thin wrapper kept for convenience — the link checker now lives in monitor.php and
 * is the canonical "php app/cli.php linkaudit" command. Both write to the link_issues
 * table shown in the admin Monitor tab.
 *   php app/link_audit.php            # full sweep
 *   php app/link_audit.php --fix-dead # also blank genuinely dead term-source URLs
 */
$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/monitor.php';
$r = monitor_links(db(), 280, in_array('--fix-dead', $_SERVER['argv'] ?? [], true));
echo "done: {$r['ok']} ok, {$r['broken']} broken, {$r['blocked']} blocked(bot-wall), {$r['redirect']} redirect" . ($r['complete'] ? '' : ' (partial — rerun)') . "\n";
