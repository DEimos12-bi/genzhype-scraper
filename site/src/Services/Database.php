<?php
namespace GenZHype;

use PDO;
use PDOException;
use Throwable;

class Database {
    private static ?PDO $pdo = null;

    public static function getConnection(bool $force = false): PDO {
        if (self::$pdo !== null && !$force) {
            return self::$pdo;
        }

        global $CONFIG;
        $d = $CONFIG['db'] ?? [];
        $host = $d['host'] ?? $_ENV['DB_HOST'] ?? 'localhost';
        $name = $d['name'] ?? $_ENV['DB_NAME'] ?? 'genzhype';
        $user = $d['user'] ?? $_ENV['DB_USER'] ?? 'root';
        $pass = $d['pass'] ?? $_ENV['DB_PASS'] ?? '';
        $charset = $d['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die("Database Connection Failed: " . $e->getMessage());
        }

        return self::$pdo;
    }

    public static function alive(): PDO {
        try {
            self::getConnection()->query('SELECT 1');
            return self::getConnection();
        } catch (Throwable $e) {
            return self::getConnection(true);
        }
    }
}
