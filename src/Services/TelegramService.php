<?php
/**
 * TelegramService.php - talks to the Telegram Bot API.
 *
 *   verify()        getMe (is the token real? which bot is it?) and getChat
 *                   (can the bot reach the staff chat?)
 *   sendMessage     one message (staff confirmation on connect)
 *   sendText        a reply of any length (split at Telegram's 4096 limit)
 *   sendTyping      "typing..." while Flowise is thinking
 *   setWebhook / deleteWebhook / webhookInfo   customer messages -> this app
 *
 * The token is part of every Bot API URL, so URLs are never logged or put
 * in error messages.
 */
declare(strict_types=1);

namespace Src\Services;

use RuntimeException;
use Src\Support\Http;

final class TelegramService
{
    private function url(string $token, string $method): string
    {
        // TELEGRAM_API_BASE is only for local testing against a fake server.
        $base = rtrim((string) env('TELEGRAM_API_BASE', 'https://api.telegram.org'), '/');

        return $base . '/bot' . $token . '/' . $method;
    }

    /**
     * @return array{ok: bool, message: string, field?: string, bot_username?: string, chat_title?: string}
     *         field: the form field the problem belongs to, when it failed
     */
    public function verify(string $token, ?string $expectedUsername, ?string $chatId): array
    {
        try {
            $me = Http::request('GET', $this->url($token, 'getMe'));
        } catch (RuntimeException $e) {
            return ['ok' => false, 'field' => 'form', 'message' => 'Could not reach Telegram from the server (' . $e->getMessage() . ').'];
        }

        $result = is_array($me['json']) ? $me['json'] : [];
        if (empty($result['ok']) || empty($result['result']['is_bot'])) {
            return ['ok' => false, 'field' => 'bot_token', 'message' => 'Telegram rejected this bot token. Copy it again from @BotFather (it looks like 123456789:AA…).'];
        }

        $username = (string) ($result['result']['username'] ?? '');

        if ($expectedUsername !== null && $expectedUsername !== '' && strcasecmp($expectedUsername, $username) !== 0) {
            return [
                'ok'           => false,
                'field'        => 'bot_username',
                'bot_username' => $username,
                'message'      => 'This token belongs to @' . $username . ', not @' . $expectedUsername . '. Fix the username or the token.',
            ];
        }

        $chatTitle = '';
        if ($chatId !== null && $chatId !== '') {
            try {
                $chat = Http::request('GET', $this->url($token, 'getChat') . '?' . http_build_query(['chat_id' => $chatId]));
            } catch (RuntimeException $e) {
                return ['ok' => false, 'field' => 'form', 'message' => 'Could not reach Telegram from the server (' . $e->getMessage() . ').'];
            }

            $chatResult = is_array($chat['json']) ? $chat['json'] : [];
            if (empty($chatResult['ok'])) {
                return [
                    'ok'           => false,
                    'field'        => 'staff_telegram_chat_id',
                    'bot_username' => $username,
                    'message'      => '@' . $username . ' cannot reach chat ' . $chatId . '. Add the bot to that group (or send the bot a message from that account) and try again.',
                ];
            }

            $c = $chatResult['result'] ?? [];
            $chatTitle = (string) ($c['title'] ?? trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
        }

        return [
            'ok'           => true,
            'bot_username' => $username,
            'chat_title'   => $chatTitle,
            'message'      => 'Telegram recognised @' . $username
                . ($chatTitle !== '' ? ' and it can reach the staff chat "' . $chatTitle . '".' : '.'),
        ];
    }

    /** Best effort: returns false instead of throwing. */
    public function sendMessage(string $token, string $chatId, string $text): bool
    {
        try {
            $response = Http::request('POST', $this->url($token, 'sendMessage'), [], [
                'chat_id' => $chatId,
                'text'    => $text,
            ]);
        } catch (RuntimeException $e) {
            return false;
        }

        return is_array($response['json']) && !empty($response['json']['ok']);
    }

    /**
     * A reply of any length. Telegram allows 4096 characters per message, so
     * longer replies are split on paragraph / line breaks.
     */
    public function sendText(string $token, string $chatId, string $text): bool
    {
        $ok = true;
        foreach ($this->chunks($text, 4000) as $part) {
            $ok = $this->sendMessage($token, $chatId, $part) && $ok;
        }

        return $ok;
    }

    /** Shows "typing..." in the chat for a few seconds. Best effort. */
    public function sendTyping(string $token, string $chatId): void
    {
        try {
            Http::request('POST', $this->url($token, 'sendChatAction'), [], ['chat_id' => $chatId, 'action' => 'typing'], 5);
        } catch (RuntimeException $e) {
            // not important
        }
    }

    /**
     * Point the bot's customer messages at this app. Telegram only calls
     * public HTTPS addresses, and sends $secret back in the
     * X-Telegram-Bot-Api-Secret-Token header on every call.
     *
     * @return array{ok: bool, message: string}
     */
    public function setWebhook(string $token, string $url, string $secret): array
    {
        if (!str_starts_with($url, 'https://')) {
            return ['ok' => false, 'message' => 'Telegram needs a public https:// address. Set PUBLIC_APP_URL in .env (for example an ngrok URL) and connect again.'];
        }

        try {
            $response = Http::request('POST', $this->url($token, 'setWebhook'), [], [
                'url'                  => $url,
                'secret_token'         => $secret,
                'allowed_updates'      => json_encode(['message']),
                'drop_pending_updates' => 'false',
            ]);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => 'Could not reach Telegram to register the webhook (' . $e->getMessage() . ').'];
        }

        $json = is_array($response['json']) ? $response['json'] : [];

        return !empty($json['ok'])
            ? ['ok' => true, 'message' => 'Customer messages to the bot now come to this app.']
            : ['ok' => false, 'message' => 'Telegram refused the webhook: ' . (string) ($json['description'] ?? 'unknown error') . '.'];
    }

    public function deleteWebhook(string $token): bool
    {
        try {
            $response = Http::request('POST', $this->url($token, 'deleteWebhook'), [], ['drop_pending_updates' => 'false']);
        } catch (RuntimeException $e) {
            return false;
        }

        return is_array($response['json']) && !empty($response['json']['ok']);
    }

    /** Telegram's view of the webhook (url, pending updates, last error). */
    public function webhookInfo(string $token): ?array
    {
        try {
            $response = Http::request('GET', $this->url($token, 'getWebhookInfo'));
        } catch (RuntimeException $e) {
            return null;
        }

        return is_array($response['json']) && !empty($response['json']['ok']) ? (array) $response['json']['result'] : null;
    }

    /** @return string[] */
    private function chunks(string $text, int $max): array
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return [$text];
        }

        $parts = [];
        while (mb_strlen($text) > $max) {
            $slice = mb_substr($text, 0, $max);
            $cut   = max((int) mb_strrpos($slice, "\n\n"), (int) mb_strrpos($slice, "\n"));
            if ($cut < $max / 2) {
                $cut = (int) mb_strrpos($slice, ' ') ?: $max;
            }
            $parts[] = rtrim(mb_substr($text, 0, $cut));
            $text    = ltrim(mb_substr($text, $cut));
        }
        if ($text !== '') {
            $parts[] = $text;
        }

        return $parts;
    }
}
