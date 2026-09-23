<?php
/**
 * GenZHype | Bootstrap
 * Central entry point for all app files. Handles configuration and database.
 */

// 1. Load Configuration
// In the next phase, we will migrate this to $_ENV from a .env file.
// For now, we preserve the existing $CONFIG global to avoid breaking the app.
if (!isset($CONFIG)) {
    // Assume config.php is in the same directory
    require_once __DIR__ . '/config.php';
}

// 2. Database Initialization
// Move the logic from db.php here to make it a class or a centralized handler.
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
