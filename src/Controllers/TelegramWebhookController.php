<?php
/**
 * TelegramWebhookController.php - customer messages from Telegram.
 *
 *   POST /telegram/webhook?bot=<telegram_integrations.id>
 *
 * Flow for one message:
 *   1. find the bot's integration from ?bot= (this is the bot -> company map:
 *      telegram_integrations.user_id is the business)
 *   2. check X-Telegram-Bot-Api-Secret-Token against its webhook_secret
 *   3. ignore a repeat of an update already handled (Telegram retries)
 *   4. answer Telegram with 200 straight away, then keep working, so a slow
 *      Flowise reply never makes Telegram resend the message
 *   5. AssistantReplyService -> Flowise -> reply sent back to the same chat
 *
 * No login and no CSRF token (Telegram cannot send either); the secret header
 * is what authenticates the call. Only private chats are answered, so the bot
 * stays quiet in the staff group.
 */
declare(strict_types=1);

namespace Src\Controllers;

use RuntimeException;
use Src\Models\TelegramIntegration;
use Src\Services\AssistantReplyService;
use Src\Services\TelegramService;
use Src\Support\Crypto;
use Throwable;

final class TelegramWebhookController
{
    public function handle(): void
    {
        $integrations = new TelegramIntegration();
        $integration  = $integrations->findById((int) ($_GET['bot'] ?? 0));
        $secret       = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');

        if ($integration === null || empty($integration['webhook_secret'])
            || !hash_equals((string) $integration['webhook_secret'], $secret)) {
            $this->respond(403);
            return;
        }

        $update = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($update) || !isset($update['update_id'])) {
            $this->respond(200); // nothing we can use; do not make Telegram retry
            return;
        }

        if ($integration['connection_status'] !== 'connected' || !$this->isTrue($integration['is_enabled'])
            || empty($integration['bot_token_encrypted'])) {
            $this->respond(200);
            return;
        }

        if (!$integrations->claimUpdate((int) $integration['id'], (int) $update['update_id'])) {
            $this->respond(200); // already handled (Telegram retry)
            return;
        }

        $message = $update['message'] ?? null;
        $chat    = is_array($message) ? ($message['chat'] ?? []) : [];

        if (!is_array($message) || ($chat['type'] ?? '') !== 'private' || !empty($message['from']['is_bot'])) {
            $this->respond(200);
            return;
        }

        // Reply to Telegram now; the rest runs after the connection is closed.
        $this->respond(200, true);

        try {
            $token = Crypto::decrypt((string) $integration['bot_token_encrypted']);
        } catch (RuntimeException $e) {
            error_log('[telegram] cannot decrypt bot token for integration ' . $integration['id']);
            return;
        }

        $this->answer($integration, $token, $message);
    }

    /* ---------------------------------------------------------- private ---- */

    private function answer(array $integration, string $token, array $message): void
    {
        $telegram = new TelegramService();
        $chatId   = (string) $message['chat']['id'];
        [$text, $context] = $this->readMessage($message);

        if ($text === '') {
            $telegram->sendText($token, $chatId, 'Sorry, I can only read text messages, photos and PDF/PNG files.');
            return;
        }

        $telegram->sendTyping($token, $chatId);

        try {
            $result = (new AssistantReplyService())->reply((int) $integration['user_id'], 'telegram', $chatId, $text, $context);
        } catch (Throwable $e) {
            error_log('[telegram] reply failed: ' . $e->getMessage());
            $result = ['status' => 'error', 'reply' => AssistantReplyService::FALLBACK_REPLY, 'error' => $e->getMessage()];
        }

        if ($result['status'] === 'error') {
            error_log('[telegram] integration ' . $integration['id'] . ': ' . ($result['error'] ?? 'unknown error'));
        }

        if ($result['reply'] !== null && $result['reply'] !== '') {
            $telegram->sendText($token, $chatId, $result['reply']);
        }
    }

    /**
     * Text to send to Flowise, plus details for its vars.
     * Files (e.g. a payment receipt) are described in the text so the
     * assistant knows a file was received; the Telegram file_id is passed in
     * vars so a Flowise tool can fetch it later.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function readMessage(array $message): array
    {
        $from    = $message['from'] ?? [];
        $context = [
            'customer_name'     => trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')),
            'customer_username' => (string) ($from['username'] ?? ''),
        ];

        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));

        $file = null;
        if (!empty($message['document'])) {
            $doc  = $message['document'];
            $file = ['id' => $doc['file_id'] ?? '', 'name' => $doc['file_name'] ?? 'file', 'type' => $doc['mime_type'] ?? 'unknown'];
        } elseif (!empty($message['photo']) && is_array($message['photo'])) {
            $photo = end($message['photo']); // largest size
            $file  = ['id' => $photo['file_id'] ?? '', 'name' => 'photo.jpg', 'type' => 'image/jpeg'];
        }

        if ($file !== null) {
            $context['attachment_file_id'] = (string) $file['id'];
            $context['attachment_name']    = (string) $file['name'];
            $context['attachment_type']    = (string) $file['type'];
            $text = trim($text . "\n\n[The customer uploaded a file: " . $file['name'] . ' (' . $file['type'] . ')]');
        }

        return [$text, $context];
    }

    /**
     * Send the HTTP response. With $thenContinue the connection is closed
     * first and PHP keeps running (works with Apache mod_php and PHP-FPM).
     */
    private function respond(int $status, bool $thenContinue = false): void
    {
        $body = json_encode(['ok' => $status === 200]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        set_status($status);
        header('Content-Type: application/json');

        if (!$thenContinue) {
            echo $body;
            return;
        }

        ignore_user_abort(true);
        set_time_limit(max(120, FLOWISE_TIMEOUT + 30));
        header('Connection: close');
        header('Content-Length: ' . strlen((string) $body));
        echo $body;

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }
}
