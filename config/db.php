<?php
/**
 * Database connection (PDO/MySQL).
 * Adjust credentials if your XAMPP MySQL setup differs from defaults.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'healthqueue_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function getDbConnection(): ?PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        // Database not set up yet (e.g. schema.sql not imported) — pages
        // that depend on it should fall back to static content gracefully.
        error_log('HealthQueue DB connection failed: ' . $e->getMessage());
        return null;
    }
}
