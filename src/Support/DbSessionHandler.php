<?php
/**
 * DbSessionHandler.php - PHP sessions stored in PostgreSQL instead of files.
 *
 * Why: on Replit (autoscale) the app can run on several machines and a
 * machine is replaced when the app sleeps or is redeployed. Session files on
 * one machine's disk are then gone or invisible to the next request, and the
 * user is signed out. PHP also deletes session files after 24 minutes by
 * default. A database row survives all of that; the app's own idle /
 * lifetime limits (SESSION_IDLE_MINUTES, SESSION_LIFETIME_MINUTES) still apply.
 *
 * The table creates itself (migration 024 does the same). If the database is
 * unreachable, bootstrap falls back to normal file sessions.
 */
declare(strict_types=1);

namespace Src\Support;

use PDO;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use Throwable;

final class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private PDO $db;

    public function __construct(private int $lifetimeSeconds)
    {
        $this->db = Database::connection();
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS app_sessions (
                id          VARCHAR(128) PRIMARY KEY,
                data        TEXT         NOT NULL DEFAULT \'\',
                updated_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
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
        try {
            $stmt = $this->db->prepare(
                "SELECT data FROM app_sessions WHERE id = ? AND updated_at > CURRENT_TIMESTAMP - (CAST(? AS INTEGER) * INTERVAL '1 second')"
            );
            $stmt->execute([$id, $this->lifetimeSeconds]);
            $data = $stmt->fetchColumn();
            // Stored base64-encoded: session data may hold bytes that are not valid UTF-8.
            $decoded = is_string($data) ? base64_decode($data, true) : false;
            return $decoded !== false ? $decoded : '';
        } catch (Throwable $e) {
            error_log('[session] read: ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $this->db->prepare(
                'INSERT INTO app_sessions (id, data, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
                 ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, updated_at = CURRENT_TIMESTAMP'
            )->execute([$id, base64_encode($data)]);
            return true;
        } catch (Throwable $e) {
            error_log('[session] write: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->db->prepare('DELETE FROM app_sessions WHERE id = ?')->execute([$id]);
        } catch (Throwable $e) {
            error_log('[session] destroy: ' . $e->getMessage());
        }
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM app_sessions WHERE updated_at < CURRENT_TIMESTAMP - (CAST(? AS INTEGER) * INTERVAL '1 second')");
            $stmt->execute([max($max_lifetime, $this->lifetimeSeconds)]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** session.use_strict_mode: only accept ids this server created. */
    public function validateId(string $id): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT 1 FROM app_sessions WHERE id = ? AND updated_at > CURRENT_TIMESTAMP - (CAST(? AS INTEGER) * INTERVAL '1 second')");
            $stmt->execute([$id, $this->lifetimeSeconds]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }
}
