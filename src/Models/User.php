<?php
/**
 * User.php - every query against the users table lives here.
 *
 * This app has one kind of account: a regular user. There is deliberately no
 * role column and no administrator account, so no query can accidentally grant
 * elevated access.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class User
{
    /** Columns that are safe to hand to a view or keep in memory. */
    private const SAFE = 'id, name, email, status, last_login_at, created_at, updated_at';

    public const STATUSES = ['active', 'disabled'];

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::SAFE . ' FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** Includes password_hash, so this result must not be passed to a view. */
    public function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::SAFE . ', password_hash FROM users WHERE LOWER(email) = ? LIMIT 1'
        );
        $stmt->execute([self::normalizeEmail($email)]);

        return $stmt->fetch() ?: null;
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT 1 FROM users WHERE LOWER(email) = ?';
        $params = [self::normalizeEmail($email)];

        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        $stmt = Database::connection()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    /** Create an account. The caller hashes the password; nothing here does. */
    public function create(string $name, string $email, string $passwordHash, string $status = 'active'): int
    {
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'active';
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO users (name, email, password_hash, status)
             VALUES (?, ?, ?, ?)
             RETURNING id'
        );
        $stmt->execute([
            trim($name),
            self::normalizeEmail($email),
            $passwordHash,
            $status,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function updateName(int $id, string $name): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET name = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([trim($name), $id]);

        return $stmt->rowCount() > 0;
    }

    public function updatePassword(int $id, string $passwordHash): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$passwordHash, $id]);

        return $stmt->rowCount() > 0;
    }

    /** Current hash, used to verify the current password before a change. */
    public function passwordHash(int $id): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT password_hash FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $hash = $stmt->fetchColumn();

        return is_string($hash) ? $hash : null;
    }

    public function touchLogin(int $id): void
    {
        Database::connection()
            ->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
            ->execute([$id]);
    }

    public function count(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /** Just the display name, for an audit row or a mail greeting. */
    public function nameOf(int $id): ?string
    {
        $stmt = Database::connection()->prepare('SELECT name FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();

        return $name === false ? null : (string) $name;
    }
}
