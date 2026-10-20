<?php
/**
 * Database.php - the single PDO connection to PostgreSQL.
 *
 * Every query in this app goes through a prepared statement. Emulated prepares
 * are disabled so PostgreSQL (not PDO) parses the statement and binds the
 * values, which is what makes the prepared-statement guarantee real.
 */
declare(strict_types=1);

namespace Src\Support;

use PDO;
use PDOException;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;connect_timeout=5',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_SSLMODE
        );

        try {
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            error_log('[db] ' . $e->getMessage());

            abort(500, APP_DEBUG
                ? 'Database connection failed: ' . $e->getMessage()
                : 'Database connection failed. Check the PostgreSQL settings in .env and that the server is running.');
        }

        return self::$pdo;
    }

    /**
     * Run a callback inside a transaction, rolling back on any throwable.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();

        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /** Drop the cached handle (used by tests / CLI after a config change). */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
