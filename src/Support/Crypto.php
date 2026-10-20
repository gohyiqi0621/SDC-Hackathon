<?php
/**
 * Crypto.php - encrypt secrets before they reach PostgreSQL.
 *
 * Used for telegram_integrations.bot_token_encrypted and the Google
 * access/refresh tokens. AES-256-GCM (authenticated: a tampered value fails
 * to decrypt instead of returning garbage), random 12-byte IV per value.
 *
 * Stored format:  v1:<base64(iv . tag . ciphertext)>
 *
 * Key: APP_ENCRYPTION_KEY in .env, written as "base64:<44 chars>" (32 bytes).
 * Create one with:  C:\xampp\php\php.exe bin\generate-key.php
 * Changing or losing the key makes stored tokens unreadable: the user then
 * simply connects Telegram / Google again.
 */
declare(strict_types=1);

namespace Src\Support;

use RuntimeException;

final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'v1:';

    public static function isConfigured(): bool
    {
        try {
            self::key();
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function encrypt(string $plain): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** @throws RuntimeException when the value is not ours or the key is wrong */
    public static function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new RuntimeException('Unknown encrypted format.');
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Encrypted value is damaged.');
        }

        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        if ($plain === false) {
            throw new RuntimeException('Could not decrypt (was APP_ENCRYPTION_KEY changed?).');
        }

        return $plain;
    }

    private static function key(): string
    {
        $value = trim((string) env('APP_ENCRYPTION_KEY', ''));
        if (str_starts_with($value, 'base64:')) {
            $value = (string) base64_decode(substr($value, 7), true);
        }

        if (strlen($value) !== 32) {
            throw new RuntimeException('APP_ENCRYPTION_KEY is missing or invalid in .env. Run: php bin/generate-key.php');
        }

        return $value;
    }
}
