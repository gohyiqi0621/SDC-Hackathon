<?php
/**
 * GmailConnection.php - every query against gmail_connections.
 *
 * Holds one user's Google tokens. Tokens never leave the server: views only
 * ever see google_email and whether a connection exists.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class GmailConnection
{
    public function find(int $userId): ?array
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT user_id, google_email, access_token, refresh_token, expires_at, scope, created_at, updated_at
                   FROM gmail_connections
                  WHERE user_id = ?
                  LIMIT 1'
            );
            $stmt->execute([$userId]);
        } catch (\PDOException $e) {
            // Table not created yet (migration 003 not run): treat as "not connected"
            // so the dashboard still opens instead of failing.
            if ($e->getCode() === '42P01') {
                error_log('[gmail] gmail_connections table is missing - run bin/migrate.php');
                return null;
            }

            throw $e;
        }

        return $stmt->fetch() ?: null;
    }

    /** False when migration 003 has not been run yet. */
    public function tableExists(): bool
    {
        $stmt = Database::connection()->prepare("SELECT to_regclass('public.gmail_connections') IS NOT NULL");
        $stmt->execute();

        return (bool) $stmt->fetchColumn();
    }

    /** Insert or replace the user's connection. */
    public function save(int $userId, string $email, string $accessToken, ?string $refreshToken, int $expiresIn, ?string $scope): void
    {
        $stmt = Database::connection()->prepare(
            "INSERT INTO gmail_connections (user_id, google_email, access_token, refresh_token, expires_at, scope)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP + make_interval(secs => ?), ?)
             ON CONFLICT (user_id) DO UPDATE
                SET google_email  = EXCLUDED.google_email,
                    access_token  = EXCLUDED.access_token,
                    -- Google only sends a refresh token on first consent; keep the old one otherwise.
                    refresh_token = COALESCE(EXCLUDED.refresh_token, gmail_connections.refresh_token),
                    expires_at    = EXCLUDED.expires_at,
                    scope         = EXCLUDED.scope,
                    updated_at    = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$userId, $email, $accessToken, $refreshToken, max(0, $expiresIn - 60), $scope]);
    }

    public function updateAccessToken(int $userId, string $accessToken, int $expiresIn): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE gmail_connections
                SET access_token = ?, expires_at = CURRENT_TIMESTAMP + make_interval(secs => ?), updated_at = CURRENT_TIMESTAMP
              WHERE user_id = ?'
        );
        $stmt->execute([$accessToken, max(0, $expiresIn - 60), $userId]);
    }

    public function isExpired(array $connection): bool
    {
        return strtotime((string) $connection['expires_at']) <= time();
    }

    public function delete(int $userId): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM gmail_connections WHERE user_id = ?');
        $stmt->execute([$userId]);
    }
}
