<?php
/**
 * DatabaseSessionHandler.php - PHP sessions stored in PostgreSQL.
 *
 * The default handler keeps sessions as files on the server's disk, which is
 * wiped whenever the host restarts or redeploys (Replit autoscale), and is not
 * shared when it runs more than one instance - every user was signed out. With
 * this handler a session lives in the `sessions` table until it expires.
 *
 * Only the SHA-256 of the session id is stored, so a database leak does not
 * give away live cookies. The table is created on first use when
 * migration 025 has not been run yet.
 */
declare(strict_types=1);

namespace Src\Support;

use PDOException;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(private int $lifetimeSeconds)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $data = $this->run(
            'SELECT data FROM sessions WHERE id = ? AND updated_at > NOW() - make_interval(secs => ?)',
            [self::key($id), $this->lifetimeSeconds]
        )->fetchColumn();

        return $data === false ? '' : (string) base64_decode((string) $data);
    }

    public function write(string $id, string $data): bool
    {
        $this->run(
            'INSERT INTO sessions (id, data, updated_at) VALUES (?, ?, NOW())
             ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, updated_at = NOW()',
            [self::key($id), base64_encode($data)]
        );

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->run('DELETE FROM sessions WHERE id = ?', [self::key($id)]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->run(
            'DELETE FROM sessions WHERE updated_at < NOW() - make_interval(secs => ?)',
            [max($max_lifetime, $this->lifetimeSeconds)]
        )->rowCount();
    }

    /** Strict mode: only accept ids this server issued and that have not expired. */
    public function validateId(string $id): bool
    {
        return $this->run(
            'SELECT 1 FROM sessions WHERE id = ? AND updated_at > NOW() - make_interval(secs => ?)',
            [self::key($id), $this->lifetimeSeconds]
        )->fetchColumn() !== false;
    }

    /** Session data unchanged (lazy_write): just keep it alive. */
    public function updateTimestamp(string $id, string $data): bool
    {
        $this->run('UPDATE sessions SET updated_at = NOW() WHERE id = ?', [self::key($id)]);

        return true;
    }

    /* ------------------------------------------------------------------ */

    private static function key(string $id): string
    {
        return hash('sha256', $id);
    }

    /** Run a statement, creating the table once if it does not exist yet. */
    private function run(string $sql, array $params): \PDOStatement
    {
        $pdo = Database::connection();
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $e) {
            if ($e->getCode() !== '42P01') { // undefined_table
                throw $e;
            }
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS sessions (
                    id          CHAR(64)     PRIMARY KEY,
                    data        TEXT         NOT NULL,
                    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS sessions_updated_idx ON sessions (updated_at);'
            );
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        return $stmt;
    }
}
