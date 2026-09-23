<?php
/**
 * GenZHype | Maintenance Utility
 * Combined CLI tool for all repair and backfill scripts.
 */

require_once __DIR__ . '/../site/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.");
}

$command = $argv[1] ?? 'help';

switch ($command) {
    case 'help':
        echo "GenZHype Maintenance Utility\n";
        echo "Usage: php maintenance.php <command>\n\n";
        echo "Commands:\n";
        echo "  repair:videos    Fix missing video scripts or corrupted status\n";
        echo "  repair:terms     Cleanup orphaned terms or resolve conflicts\n";
        echo "  backfill:data    Refresh Wikipedia/Wiktionary popularity data\n";
        echo "  list:junk        List files currently in the archive for review\n";
        break;

    case 'repair:videos':
        echo "Running video repair...\n";
        // Migration from *_repair.php logic goes here
        break;

    case 'repair:terms':
        echo "Running term repair...\n";
        // Migration from *_repair.php logic goes here
        break;

    default:
        echo "Unknown command: $command\n";
        exit(1);
}
