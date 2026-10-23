<?php
/**
 * PasswordResetService.php - issue and redeem password reset tokens.
 *
 * The mail contains a 64-character random token in a link. Only its SHA-256
 * hash is stored, the link expires after PasswordReset::TTL_MINUTES, and the
 * row is consumed inside the same transaction that changes the password. That
 * makes a token both expiring and single-use even under concurrent requests.
 */
declare(strict_types=1);

namespace Src\Services;

use PDOException;
use Src\Models\PasswordReset;
use Src\Models\User;
use Src\Support\Database;
use Throwable;

final class PasswordResetService
{
    public const TTL_MINUTES = PasswordReset::TTL_MINUTES;

    /**
     * Start a reset without revealing whether the account exists or is active.
     *
     * @return array{ok:bool, sent:bool}
     */
    public function request(string $email, ?string $ip = null): array
    {
        $user = (new User())->findByEmail($email);

        // Keep unknown, disabled, rate-limited, and active accounts externally indistinguishable.
        if ($user === null) {
            return ['ok' => true, 'sent' => false];
        }
        if ($user['status'] !== 'active') {
            return ['ok' => true, 'sent' => false];
        }

        $resets = new PasswordReset();

        if ($resets->countRecent((int) $user['id']) >= PasswordReset::MAX_PER_WINDOW) {
            return ['ok' => true, 'sent' => false];
        }

        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $rowId = $resets->create(
            (int) $user['id'],
            (string) $user['email'],
            $tokenHash,
            $ip
        );

        $resetUrl = url('auth', ['action' => 'reset', 'token' => $token]);

        $mail = (new BrevoMailer())->sendPasswordReset(
            $user,
            $resetUrl,
            self::TTL_MINUTES
        );

        if (!$mail['ok']) {
            // The code that was never delivered must not stay live.
            $resets->delete($rowId);

            return ['ok' => true, 'sent' => false];
        }

        return ['ok' => true, 'sent' => true];
    }

    /** Validate a token for the GET form. Returns the reset row or null. */
    public function findValidToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return (new PasswordReset())->findValidByHash(hash('sha256', $token));
    }

    /**
     * Redeem a token and set the new password.
     *
     * @return array{ok:bool, message:string, code:int}
     */
    public function reset(string $token, string $newPassword): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return [
                'ok'      => false,
                'message' => 'That reset link is not valid.',
                'code'    => 422,
            ];
        }

        $tokenHash  = hash('sha256', $token);
        $newHash    = password_hash($newPassword, PASSWORD_DEFAULT);

        try {
            $outcome = Database::transaction(static function ($pdo) use ($tokenHash, $newHash): array {
                $stmt = $pdo->prepare(
                    'SELECT id, user_id, expires_at, used_at
                       FROM password_resets
                      WHERE token_hash = ?
                      FOR UPDATE'
                );
                $stmt->execute([$tokenHash]);
                $row = $stmt->fetch();

                if (!$row) {
                    return ['ok' => false, 'message' => 'That reset link is not valid.', 'code' => 422];
                }
                if ($row['used_at'] !== null) {
                    return ['ok' => false, 'message' => 'That reset link has already been used.', 'code' => 422];
                }
                if (strtotime((string) $row['expires_at']) <= time()) {
                    return ['ok' => false, 'message' => 'That reset link has expired. Request a new one.', 'code' => 422];
                }

                $pdo->prepare(
                    'UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$newHash, (int) $row['user_id']]);

                $pdo->prepare(
                    'UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL'
                )->execute([(int) $row['id']]);

                // Every other outstanding link for this account dies with it.
                $pdo->prepare(
                    'DELETE FROM password_resets WHERE user_id = ? AND id <> ?'
                )->execute([(int) $row['user_id'], (int) $row['id']]);

                return ['ok' => true, 'message' => 'Password updated.', 'code' => 200];
            });
        } catch (PDOException $e) {
            error_log('[password_reset] ' . $e->getMessage());

            return [
                'ok'      => false,
                'message' => 'We could not update your password just now. Please try again.',
                'code'    => 500,
            ];
        } catch (Throwable $e) {
            error_log('[password_reset] ' . $e->getMessage());

            return [
                'ok'      => false,
                'message' => 'We could not update your password just now. Please try again.',
                'code'    => 500,
            ];
        }

        return $outcome;
    }
}
