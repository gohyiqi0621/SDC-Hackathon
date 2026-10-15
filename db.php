<?php
/**
 * db.php - single PDO connection, created on first use.
 */
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('[db] ' . $e->getMessage());
        abort(500, APP_DEBUG
            ? 'Database connection failed: ' . $e->getMessage()
            : 'Database connection failed. Check your .env settings and that MySQL is running.');
    }

    return $pdo;
}
