<?php
/**
 * GenZHype | Bootstrap
 * Central entry point for all app files. Handles configuration and database.
 */

// 1. Load Configuration from Environment
if (!isset($_ENV['DB_HOST'])) {
    $envFile = __DIR__ . '/../../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            list($name, $value) = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
}

// Map $_ENV to $CONFIG for backward compatibility with the rest of the app
if (!isset($CONFIG)) {
    $CONFIG = [
        'db' => [
            'host'    => $_ENV['DB_HOST'] ?? 'localhost',
            'name'    => $_ENV['DB_NAME'] ?? 'genzhype',
            'user'    => $_ENV['DB_USER'] ?? 'root',
            'pass'    => $_ENV['DB_PASS'] ?? '',
            'charset' => 'utf8mb4',
        ],
        'app_env' => $_ENV['APP_ENV'] ?? 'production',
    ];
}

// 2. Database Initialization
require_once __DIR__ . '/db.php';

// 3. Session Start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define a global helper for the DB to maintain compatibility
if (!function_exists('db')) {
    function db(bool $force = false) {
        return \GenZHype\Database::getConnection($force);
    }
}
