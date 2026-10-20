<?php
/**
 * ToolToken.php - short-lived signed token that ties a Flowise tool call to
 * one business.
 *
 * The website puts a token in each Flowise request (overrideConfig.vars). A
 * Flowise custom tool sends it back in the Authorization header when it calls
 * /tools/... . The website then knows which business's data to read,
 * without trusting any id that Flowise or the customer could change.
 *
 * Format: <user id>.<expiry unix time>.<HMAC-SHA256 hex>, keyed from
 * APP_ENCRYPTION_KEY (no extra secret to manage).
 */
declare(strict_types=1);

namespace Src\Support;

final class ToolToken
{
    public const TTL_SECONDS = 1800;

    public static function issue(int $userId, int $ttl = self::TTL_SECONDS): ?string
    {
        $key = self::key();
        if ($key === null) {
            return null;
        }

        $payload = $userId . '.' . (time() + $ttl);

        return $payload . '.' . hash_hmac('sha256', 'tool|' . $payload, $key);
    }

    /** @return int|null the user id, or null when the token is missing, wrong or expired */
    public static function verify(string $token): ?int
    {
        $key = self::key();
        if ($key === null || !preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{64})$/', $token, $m)) {
            return null;
        }

        $expected = hash_hmac('sha256', 'tool|' . $m[1] . '.' . $m[2], $key);

        if (!hash_equals($expected, $m[3]) || (int) $m[2] < time()) {
            return null;
        }

        return (int) $m[1];
    }

    private static function key(): ?string
    {
        $secret = trim((string) env('APP_ENCRYPTION_KEY', ''));

        return $secret === '' ? null : hash('sha256', 'smart-sme-tool-token|' . $secret, true);
    }
}
