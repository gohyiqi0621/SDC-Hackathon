<?php
/**
 * ToolToken.php - short-lived signed token that ties a Flowise tool call to
 * one business, and (for orders) to one customer chat.
 *
 * The website puts a token in each Flowise request (overrideConfig.vars). A
 * Flowise custom tool sends it back in the Authorization header when it calls
 * /tools/... . The website then knows which business's data to read and which
 * customer is ordering, without trusting any id that Flowise or the customer
 * could change (a customer cannot talk the AI into ordering for someone else).
 *
 * Format: <user id>.<expiry unix time>[.<chat, base64url>].<HMAC-SHA256 hex>,
 * keyed from APP_ENCRYPTION_KEY (no extra secret to manage).
 * chat is "<channel>:<chat id>", e.g. "telegram:777".
 */
declare(strict_types=1);

namespace Src\Support;

final class ToolToken
{
    public const TTL_SECONDS = 1800;

    public static function issue(int $userId, int $ttl = self::TTL_SECONDS, string $chat = ''): ?string
    {
        $key = self::key();
        if ($key === null) {
            return null;
        }

        $payload = $userId . '.' . (time() + $ttl);
        if ($chat !== '') {
            $payload .= '.' . rtrim(strtr(base64_encode($chat), '+/', '-_'), '=');
        }

        return $payload . '.' . hash_hmac('sha256', 'tool|' . $payload, $key);
    }

    /** @return int|null the user id, or null when the token is missing, wrong or expired */
    public static function verify(string $token): ?int
    {
        return self::read($token)['user_id'] ?? null;
    }

    /**
     * @return array{user_id: int, channel: ?string, chat_id: ?string}|null
     *         channel/chat_id are null when the token was issued without a chat
     */
    public static function read(string $token): ?array
    {
        $key = self::key();
        if ($key === null || !preg_match('/^(\d+)\.(\d+)(?:\.([A-Za-z0-9_-]{1,200}))?\.([a-f0-9]{64})$/', $token, $m)) {
            return null;
        }

        $payload  = $m[1] . '.' . $m[2] . ($m[3] !== '' ? '.' . $m[3] : '');
        $expected = hash_hmac('sha256', 'tool|' . $payload, $key);

        if (!hash_equals($expected, $m[4]) || (int) $m[2] < time()) {
            return null;
        }

        $channel = null;
        $chatId  = null;
        if ($m[3] !== '') {
            $chat = (string) base64_decode(strtr($m[3], '-_', '+/'), true);
            if (str_contains($chat, ':')) {
                [$channel, $chatId] = explode(':', $chat, 2);
            }
        }

        return ['user_id' => (int) $m[1], 'channel' => $channel, 'chat_id' => $chatId];
    }

    private static function key(): ?string
    {
        $secret = trim((string) env('APP_ENCRYPTION_KEY', ''));

        return $secret === '' ? null : hash('sha256', 'smart-sme-tool-token|' . $secret, true);
    }
}
