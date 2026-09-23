<?php
require_once __DIR__ . '/../src/Services/Database.php';

function db(bool $force = false) {
    return \GenZHype\Database::getConnection($force);
}

function db_alive(): PDO {
    return \GenZHype\Database::alive();
}
