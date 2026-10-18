<?php
/**
 * PasswordReset.php - one-time codes for "Forgot password".
 *
 * A row is one issued code. The code is stored as a password_hash(), never in
 * the clear, so a leaked table cannot be replayed. Three guards bound the row:
 *
 *   expires_at  the code stops working after OTP_TTL minutes
 *   attempts    five wrong guesses retire the code (a 6-digit code is only
 *               safe while guessing is expensive)
 *   used_at     a code is single-use; redeeming it also deletes its siblings
 *
 * Everything here is scoped by user_id, and the email is frozen at request time
 * so the audit trail says where the code actually went.
 */
declare(strict_types=1);

class PasswordReset
{
    /** Minutes a code stays valid. */
    public const TTL_MINUTES = 10;

    /** Wrong guesses allowed per code before it is retired. */
    public const MAX_ATTEMPTS = 5;

    /** How many codes one account may request inside the window. */
    public const MAX_PER_WINDOW = 3;

    /** The rate-limit window, in minutes. */
    public const WINDOW_MINUTES = 15;

    /**
     * Issue a new code.
     *
     * Older unused codes are RETIRED (used_at = now), not deleted: only the
     * newest code may be redeemable, but the older rows are what countRecent()
     * counts. Deleting them here would reset the rate limiter on every request
     * and let a caller mint codes forever.
     */
    public function create(int $userId, string $email, string $otpHash): int
    {
        $this->purgeStale();

        db()->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
        )->execute([$userId]);

        $stmt = db()->prepare(
            'INSERT INTO password_resets (user_id, email, otp_hash, expires_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $email,
            $otpHash,
            date('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60),
        ]);

        return (int) db()->lastInsertId();
    }

    /** The newest live row for an account, or null. */
    public function latest(int $userId): ?array
    {
        $stmt = db()->prepare(
            'SELECT id, user_id, email, otp_hash, attempts, expires_at, used_at, created_at
               FROM password_resets
              WHERE user_id = ? AND used_at IS NULL
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /** How many codes this account asked for inside the rate-limit window. */
    public function countRecent(int $userId): int
    {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM password_resets
              WHERE user_id = ? AND created_at >= (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /** Count one wrong guess against a code. */
    public function bumpAttempts(int $id): void
    {
        db()->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?')->execute([$id]);
    }

    /** Is this row past its lifetime? Compared in PHP so the wall clock rules. */
    public function isExpired(array $row): bool
    {
        $ts = strtotime((string) ($row['expires_at'] ?? ''));
        return $ts === false || $ts <= time();
    }

    /** Seconds left, floored at zero - for the "expires in N minutes" copy. */
    public function secondsLeft(array $row): int
    {
        $ts = strtotime((string) ($row['expires_at'] ?? ''));
        return $ts === false ? 0 : max(0, $ts - time());
    }

    /** Mark a code as redeemed. */
    public function consume(int $id): void
    {
        db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$id]);
    }

    /** Drop every code for an account (used after a successful reset). */
    public function deleteForUser(int $userId): void
    {
        db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    }

    /** Drop one row - a code that was never delivered must not stay live. */
    public function delete(int $id): void
    {
        db()->prepare('DELETE FROM password_resets WHERE id = ?')->execute([$id]);
    }

    /**
     * Housekeeping: drop rows that can never be redeemed again. Cheap, and keeps
     * the table from growing one row per forgot-password click forever.
     */
    public function purgeStale(): void
    {
        db()->exec('DELETE FROM password_resets WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    }
}
