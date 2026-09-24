<?php
require_once __DIR__ . '/../src/Services/Database.php';

if (!function_exists('db')) {
    function db(bool $force = false) {
        return \GenZHype\Database::getConnection($force);
    }
}

if (!function_exists('db_alive')) {
    function db_alive(): PDO {
        return \GenZHype\Database::alive();
    }
}
