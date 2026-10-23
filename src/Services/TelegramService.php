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
 *   setMyName       the bot's display name ("AI chatbot name")
 *
 * Replies, webhook changes and failures are written to the flow log
 * (Src\Support\FlowLog) with the token masked.
 *
 * The token is part of every Bot API URL, so URLs are never logged or put
 * in error messages.
 */
declare(strict_types=1);

namespace Src\Services;

use RuntimeException;
use Src\Support\FlowLog;
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

    /**
     * Send one message. Retries up to 3 times when Telegram cannot be reached
     * or is busy (timeouts, HTTP 429/5xx), so a short network blip does not
     * lose the customer's reply. Best effort: returns false instead of throwing.
     *
     * $html: the text is Telegram HTML (see markdownToHtml); if Telegram
     * refuses the formatting, the plain text is sent instead.
     */
    public function sendMessage(string $token, string $chatId, string $text, ?string $html = null): bool
    {
        $started = microtime(true);
        $params  = $html !== null
            ? ['chat_id' => $chatId, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']
            : ['chat_id' => $chatId, 'text' => $text];
        $response = null;
        $error    = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::request('POST', $this->url($token, 'sendMessage'), [], $params, 20);
                $error    = null;
            } catch (RuntimeException $e) {
                $response = null;
                $error    = $e->getMessage();
            }

            $status = $response['status'] ?? 0;
            if ($response !== null && $status !== 429 && $status < 500) {
                break;
            }
            if ($attempt < 3) {
                $wait = (int) ($response['json']['parameters']['retry_after'] ?? $attempt);
                FlowLog::write('telegram.send_retry', ['chat_id' => $chatId, 'attempt' => $attempt, 'status' => $status, 'error' => $error]);
                sleep(max(1, min(5, $wait)));
            }
        }

        if ($response === null) {
            FlowLog::write('telegram.send_failed', ['chat_id' => $chatId, 'error' => $error, 'attempts' => 3]);
            return false;
        }

        $ok = is_array($response['json']) && !empty($response['json']['ok']);

        // Formatting rejected ("can't parse entities"): send it as plain text.
        if (!$ok && $html !== null && $response['status'] === 400) {
            FlowLog::write('telegram.html_refused', ['description' => $response['json']['description'] ?? null]);
            return $this->sendMessage($token, $chatId, $text);
        }

        FlowLog::write('telegram.send', [
            'chat_id'     => $chatId,
            'status'      => $response['status'],
            'ok'          => $ok,
            'ms'          => (int) round((microtime(true) - $started) * 1000),
            'text'        => $text,
            'description' => $ok ? null : (string) ($response['json']['description'] ?? $response['raw']),
        ]);

        return $ok;
    }

    /**
     * The AI writes Markdown (**bold**, ### headings, `code`, [links](url));
     * Telegram shows that as raw symbols. Convert the common parts to
     * Telegram HTML. Everything else is escaped, so it is always safe.
     */
    public static function markdownToHtml(string $text): string
    {
        // Every pattern below uses /u (UTF-8). Without it, \R and \s treat the
        // byte 0x85 inside emoji and Chinese characters as a line break or
        // space and cut those characters in half (shown as "�" in Telegram).
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            // Table separator rows (|---|---|) and horizontal rules add nothing in a chat.
            if (preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/u', $line) || preg_match('/^\s*([-*_])\1{2,}\s*$/u', $line)) {
                continue;
            }
            $line = htmlspecialchars($line, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

            // Table rows: | a | b |  ->  a · b
            if (preg_match('/^\s*\|(.+)\|\s*$/u', $line, $m)) {
                $line = implode(' · ', array_map('trim', explode('|', $m[1])));
            }
            // ### Heading -> bold line
            if (preg_match('/^\s*#{1,6}\s+(.+)$/u', $line, $m)) {
                $line = '<b>' . trim($m[1], " *") . '</b>';
            }
            // - item / * item -> bullet
            $line = (string) preg_replace('/^(\s*)[-*]\s+/u', '$1• ', $line);

            $line = (string) preg_replace('/`([^`]+)`/u', '<code>$1</code>', $line);
            $line = (string) preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $line);
            $line = (string) preg_replace('/__(.+?)__/u', '<b>$1</b>', $line);
            $line = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<i>$1</i>', $line);
            $line = (string) preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u', '<a href="$2">$1</a>', $line);

            $out[] = $line;
        }

        return trim((string) preg_replace("/\n{3,}/u", "\n\n", implode("\n", $out)));
    }

    /** Send an image file from this server (e.g. the payment QR code). Best effort. */
    public function sendPhoto(string $token, string $chatId, string $path, string $caption = ''): bool
    {
        if (!is_file($path)) {
            return false;
        }

        try {
            $response = Http::request('POST', $this->url($token, 'sendPhoto'), [], [
                'chat_id' => $chatId,
                'caption' => mb_substr($caption, 0, 1000),
                'photo'   => new \CURLFile($path, (string) (mime_content_type($path) ?: 'image/png'), basename($path)),
            ], 30);
        } catch (RuntimeException $e) {
            FlowLog::write('telegram.photo_failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            return false;
        }

        $ok = is_array($response['json']) && !empty($response['json']['ok']);
        FlowLog::write('telegram.photo', ['chat_id' => $chatId, 'ok' => $ok, 'caption' => $caption,
                                          'description' => $ok ? null : ($response['json']['description'] ?? null)]);

        return $ok;
    }

    /** Send a file (e.g. the official receipt PDF) with an optional caption. */
    public function sendDocument(string $token, string $chatId, string $path, string $fileName, string $caption = ''): bool
    {
        if (!is_file($path)) {
            return false;
        }

        try {
            $response = Http::request('POST', $this->url($token, 'sendDocument'), [], [
                'chat_id'  => $chatId,
                'caption'  => mb_substr($caption, 0, 1000),
                'document' => new \CURLFile($path, (string) (mime_content_type($path) ?: 'application/pdf'), $fileName),
            ], 30);
        } catch (RuntimeException $e) {
            FlowLog::write('telegram.document_failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            return false;
        }

        $ok = is_array($response['json']) && !empty($response['json']['ok']);
        FlowLog::write('telegram.document', ['chat_id' => $chatId, 'ok' => $ok, 'file' => $fileName,
                                             'description' => $ok ? null : ($response['json']['description'] ?? null)]);

        return $ok;
    }

    /**
     * Set the bot's display name (what customers see at the top of the chat).
     * Best effort: the bot still works if Telegram refuses (e.g. rate limit).
     *
     * @return array{ok: bool, message: string}
     */
    public function setMyName(string $token, string $name): array
    {
        try {
            $response = Http::request('POST', $this->url($token, 'setMyName'), [], ['name' => $name]);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => 'Could not reach Telegram to set the chatbot name.'];
        }

        $json = is_array($response['json']) ? $response['json'] : [];
        FlowLog::write('telegram.set_name', ['name' => $name, 'status' => $response['status'], 'ok' => !empty($json['ok']),
                                             'description' => $json['description'] ?? null]);

        return !empty($json['ok'])
            ? ['ok' => true, 'message' => 'Chatbot name set to "' . $name . '".']
            : ['ok' => false, 'message' => 'Telegram did not change the name: ' . (string) ($json['description'] ?? 'unknown error') . '.'];
    }

    /**
     * Download a file a customer sent (photo / PDF) by its file_id.
     *
     * @return array{bytes: string, name: string}|null null when Telegram refuses or the file is too big
     */
    public function downloadFile(string $token, string $fileId, int $maxBytes = 10485760): ?array
    {
        try {
            $info = Http::request('GET', $this->url($token, 'getFile') . '?' . http_build_query(['file_id' => $fileId]));
        } catch (RuntimeException $e) {
            FlowLog::write('telegram.get_file_failed', ['error' => $e->getMessage()]);
            return null;
        }

        $file = is_array($info['json']) && !empty($info['json']['ok']) ? (array) $info['json']['result'] : [];
        $path = (string) ($file['file_path'] ?? '');
        if ($path === '' || (int) ($file['file_size'] ?? 0) > $maxBytes) {
            FlowLog::write('telegram.get_file_failed', ['status' => $info['status'], 'file_size' => $file['file_size'] ?? null,
                                                        'description' => $info['json']['description'] ?? null]);
            return null;
        }

        $base = rtrim((string) env('TELEGRAM_API_BASE', 'https://api.telegram.org'), '/');
        try {
            $download = Http::request('GET', $base . '/file/bot' . $token . '/' . $path, [], null, 30);
        } catch (RuntimeException $e) {
            FlowLog::write('telegram.download_failed', ['error' => $e->getMessage()]);
            return null;
        }

        if ($download['status'] !== 200 || $download['raw'] === '' || strlen($download['raw']) > $maxBytes) {
            FlowLog::write('telegram.download_failed', ['status' => $download['status']]);
            return null;
        }

        return ['bytes' => $download['raw'], 'name' => basename($path)];
    }

    /**
     * Forward a customer's message (e.g. the receipt photo) to the staff chat
     * with a new caption. Best effort.
     */
    public function copyMessage(string $token, string $toChatId, string $fromChatId, int $messageId, string $caption = ''): bool
    {
        try {
            $response = Http::request('POST', $this->url($token, 'copyMessage'), [], array_filter([
                'chat_id'      => $toChatId,
                'from_chat_id' => $fromChatId,
                'message_id'   => (string) $messageId,
                'caption'      => $caption !== '' ? mb_substr($caption, 0, 1000) : null,
            ], static fn ($v) => $v !== null));
        } catch (RuntimeException $e) {
            FlowLog::write('telegram.copy_failed', ['error' => $e->getMessage()]);
            return false;
        }

        $ok = is_array($response['json']) && !empty($response['json']['ok']);
        FlowLog::write('telegram.copy', ['to' => $toChatId, 'ok' => $ok, 'description' => $ok ? null : ($response['json']['description'] ?? null)]);

        return $ok;
    }

    /** The bot's current display name, or '' (best effort). */
    public function myName(string $token): string
    {
        try {
            $response = Http::request('GET', $this->url($token, 'getMyName'), [], null, 8);
        } catch (RuntimeException $e) {
            return '';
        }

        return is_array($response['json']) ? (string) ($response['json']['result']['name'] ?? '') : '';
    }

    /**
     * A reply of any length. Telegram allows 4096 characters per message, so
     * longer replies are split on paragraph / line breaks.
     */
    public function sendText(string $token, string $chatId, string $text): bool
    {
        $ok = true;
        foreach ($this->chunks($text, 3500) as $part) {
            $ok = $this->sendMessage($token, $chatId, $part, self::markdownToHtml($part)) && $ok;
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
        FlowLog::write('telegram.set_webhook', ['url' => $url, 'status' => $response['status'], 'ok' => !empty($json['ok']),
                                                'description' => $json['description'] ?? null]);

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
    public function webhookInfo(string $token, int $timeout = 15): ?array
    {
        try {
            $response = Http::request('GET', $this->url($token, 'getWebhookInfo'), [], null, $timeout);
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
