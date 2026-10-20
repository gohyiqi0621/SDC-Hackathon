<?php
/**
 * TelegramService.php - talks to the Telegram Bot API.
 *
 *   verify()     getMe (is the token real? which bot is it?) and getChat
 *                (can the bot reach the staff chat?)
 *   sendMessage  used once on connect to confirm in the staff chat
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
            return ['ok' => false, 'field' => 'form', 'message' => 'Could not reach Telegram from the server. Check the internet connection and try again.'];
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
                return ['ok' => false, 'field' => 'form', 'message' => 'Could not reach Telegram from the server. Check the internet connection and try again.'];
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
}
