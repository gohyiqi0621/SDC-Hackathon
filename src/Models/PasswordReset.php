<?php
/**
 * PasswordReset.php - one-time, expiring password reset tokens.
 *
 * A row is one issued token:
 *   token_hash   SHA-256 of the random token that was mailed. The token itself
 *                is never stored, so a leaked table cannot be replayed.
 *   expires_at   the token stops working after TTL_MINUTES
 *   used_at      single-use marker; redeeming a token consumes it
 *
 * Only the newest token for an account is live: issuing a new one retires the
 * older rows, while the rows themselves stay so the rate limiter keeps counting
 * them.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class PasswordReset
{
    /** Minutes a reset link stays valid. */
    public const TTL_MINUTES = 60;

    /** How many reset requests one account may make inside the window. */
    public const MAX_PER_WINDOW = 3;

    /** The rate-limit window, in minutes. */
    public const WINDOW_MINUTES = 15;

    public function create(int $userId, string $email, string $tokenHash, ?string $ip = null): int
    {
        return Database::transaction(static function ($pdo) use ($userId, $email, $tokenHash, $ip): int {
            self::purgeStale();

            $pdo->prepare(
                'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
            )->execute([$userId]);

            $stmt = $pdo->prepare(
                'INSERT INTO password_resets (user_id, email, token_hash, expires_at, requested_ip)
                 VALUES (?, ?, ?, NOW() + make_interval(mins => ?), ?)
                 RETURNING id'
            );
            $stmt->execute([$userId, $email, $tokenHash, self::TTL_MINUTES, $ip]);

            return (int) $stmt->fetchColumn();
        });
    }

    /** A live row matching the hash, or null when used/expired/unknown. */
    public function findValidByHash(string $tokenHash): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, email, token_hash, expires_at, used_at, created_at
               FROM password_resets
              WHERE token_hash = ?
                AND used_at IS NULL
                AND expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute([$tokenHash]);

        return $stmt->fetch() ?: null;
    }

    /** How many tokens this account requested inside the rate-limit window. */
    public function countRecent(int $userId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*)
               FROM password_resets
              WHERE user_id = ?
                AND created_at >= NOW() - make_interval(mins => ?)'
        );
        $stmt->execute([$userId, self::WINDOW_MINUTES]);

        return (int) $stmt->fetchColumn();
    }

    /** Mark a token as redeemed. Returns false if it was already used. */
    public function consume(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL RETURNING id'
        );
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    public function deleteForUser(int $userId): void
    {
        Database::connection()
            ->prepare('DELETE FROM password_resets WHERE user_id = ?')
            ->execute([$userId]);
    }

    /** Drop one row - a token whose email never left the server must not stay live. */
    public function delete(int $id): void
    {
        Database::connection()
            ->prepare('DELETE FROM password_resets WHERE id = ?')
            ->execute([$id]);
    }

    /** Housekeeping: remove rows that can never be redeemed again. */
    public static function purgeStale(): void
    {
        Database::connection()->exec(
            "DELETE FROM password_resets
              WHERE created_at < NOW() - INTERVAL '7 days'
                 OR (used_at IS NOT NULL AND used_at < NOW() - INTERVAL '1 day')"
        );
    }
}
