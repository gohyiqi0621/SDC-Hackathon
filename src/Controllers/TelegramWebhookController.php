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
 *
 * "/start staff_<code>" (from the "Link my Telegram" button in AI Settings) is
 * not sent to the AI: it saves that chat as the business's staff chat.
 * A photo/PDF from a customer with an order awaiting payment is not sent to
 * the AI either: it is the payment receipt (OrderService::attachReceipt).
 *
 * Every step, including why a message was ignored, is written to
 * storage/logs/telegram-flow-<date>.log (Src\Support\FlowLog).
 */
declare(strict_types=1);

namespace Src\Controllers;

use RuntimeException;
use Src\Models\TelegramIntegration;
use Src\Services\AssistantReplyService;
use Src\Services\OrderService;
use Src\Services\TelegramService;
use Src\Support\Crypto;
use Src\Support\FlowLog;
use Throwable;

final class TelegramWebhookController
{
    public function handle(): void
    {
        $integrations = new TelegramIntegration();
        $botId        = (int) ($_GET['bot'] ?? 0);
        $secret       = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        $raw          = (string) file_get_contents('php://input');
        $update       = json_decode($raw, true);
        $message      = is_array($update) ? ($update['message'] ?? null) : null;

        FlowLog::write('webhook.received', [
            'bot'        => $botId,
            'has_secret' => $secret !== '',
            'update_id'  => $update['update_id'] ?? null,
            'chat_id'    => is_array($message) ? ($message['chat']['id'] ?? null) : null,
            'chat_type'  => is_array($message) ? ($message['chat']['type'] ?? null) : null,
            'from'       => is_array($message) ? trim(($message['from']['first_name'] ?? '') . ' @' . ($message['from']['username'] ?? '')) : null,
            'text'       => is_array($message) ? ($message['text'] ?? $message['caption'] ?? '(no text)') : null,
            'update_type' => is_array($update) ? implode(',', array_diff(array_keys($update), ['update_id'])) : 'invalid JSON',
        ]);

        try {
            $integration = $integrations->findById($botId);
        } catch (Throwable $e) {
            FlowLog::write('webhook.error', ['reason' => 'Database error: ' . $e->getMessage()]);
            $this->respond(500);
            return;
        }

        if ($integration === null || empty($integration['webhook_secret'])
            || !hash_equals((string) $integration['webhook_secret'], $secret)) {
            FlowLog::write('webhook.rejected', ['reason' => $integration === null
                ? 'No Telegram integration with id ' . $botId . ' (reconnect the bot in AI Settings).'
                : 'Secret header does not match (an old webhook is still registered: reconnect the bot or run bin\\telegram-webhook.php).']);
            $this->respond(403);
            return;
        }

        if (!is_array($update) || !isset($update['update_id'])) {
            FlowLog::write('webhook.ignored', ['reason' => 'Not a Telegram update.']);
            $this->respond(200); // nothing we can use; do not make Telegram retry
            return;
        }

        if ($integration['connection_status'] !== 'connected' || !$this->isTrue($integration['is_enabled'])
            || empty($integration['bot_token_encrypted'])) {
            FlowLog::write('webhook.ignored', ['reason' => 'The bot is not connected/enabled in AI Settings (status: ' . $integration['connection_status'] . ').']);
            $this->respond(200);
            return;
        }

        if (!$integrations->claimUpdate((int) $integration['id'], (int) $update['update_id'])) {
            FlowLog::write('webhook.ignored', ['reason' => 'Duplicate update (Telegram retry), already handled.']);
            $this->respond(200);
            return;
        }

        $chat = is_array($message) ? ($message['chat'] ?? []) : [];

        if (!is_array($message)) {
            FlowLog::write('webhook.ignored', ['reason' => 'Not a new message (e.g. an edited message).']);
            $this->respond(200);
            return;
        }
        if (($chat['type'] ?? '') !== 'private') {
            FlowLog::write('webhook.ignored', ['reason' => 'Group/channel chat: the assistant only answers private chats.']);
            $this->respond(200);
            return;
        }
        if (!empty($message['from']['is_bot'])) {
            FlowLog::write('webhook.ignored', ['reason' => 'Message from a bot.']);
            $this->respond(200);
            return;
        }

        // Reply to Telegram now; the rest runs after the connection is closed.
        $this->respond(200, true);

        try {
            $token = Crypto::decrypt((string) $integration['bot_token_encrypted']);
        } catch (RuntimeException $e) {
            error_log('[telegram] cannot decrypt bot token for integration ' . $integration['id']);
            FlowLog::write('webhook.error', ['reason' => 'Cannot decrypt the bot token (APP_ENCRYPTION_KEY changed?). Reconnect the bot.']);
            return;
        }

        if ($this->linkStaff($integrations, $integration, $token, $message)) {
            return;
        }

        // A photo / PDF from a customer whose order awaits payment is the
        // payment receipt: attach it to the order instead of asking the AI.
        if ((!empty($message['photo']) || !empty($message['document']))
            && (new OrderService())->attachReceipt($integration, $token, $message)) {
            FlowLog::write('flow.done', ['chat_id' => (string) $chat['id'], 'handled_as' => 'payment receipt']);
            return;
        }

        $this->answer($integration, $token, $message);
        FlowLog::write('flow.done', ['chat_id' => (string) $chat['id']]);
    }

    /**
     * "/start staff_<code>" from the Link-my-Telegram button: this chat becomes
     * the business's staff chat. Returns true when the message was handled.
     */
    private function linkStaff(TelegramIntegration $integrations, array $integration, string $token, array $message): bool
    {
        $text = trim((string) ($message['text'] ?? ''));
        if (!preg_match('/^\/start(?:@\w+)?\s+staff_([A-Za-z0-9]{16,40})$/', $text, $m)) {
            return false;
        }

        $chatId   = (string) $message['chat']['id'];
        $telegram = new TelegramService();
        $code     = (string) ($integration['staff_link_code'] ?? '');
        $expires  = strtotime((string) ($integration['staff_link_expires_at'] ?? '')) ?: 0;

        if ($code === '' || !hash_equals($code, $m[1]) || $expires < time()) {
            FlowLog::write('staff.link_failed', ['chat_id' => $chatId, 'reason' => $code === '' ? 'no link pending' : ($expires < time() ? 'link expired' : 'wrong code')]);
            $telegram->sendText($token, $chatId, 'This staff link has expired or was already used. Open AI Settings → Telegram and click "Link my Telegram" again.');
            return true;
        }

        $from = $message['from'] ?? [];
        $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        if (!empty($from['username'])) {
            $name = trim($name . ' (@' . $from['username'] . ')');
        }

        $integrations->save((int) $integration['user_id'], [
            'staff_telegram_chat_id' => $chatId,
            'staff_linked_name'      => mb_substr($name !== '' ? $name : 'Telegram user ' . $chatId, 0, 120),
            'staff_link_code'        => null,
            'staff_link_expires_at'  => null,
        ]);
        FlowLog::write('staff.linked', ['user_id' => (int) $integration['user_id'], 'chat_id' => $chatId, 'name' => $name]);

        $telegram->sendText($token, $chatId, '✅ Linked! Staff alerts for ' . APP_NAME . ' will come to this chat.');
        return true;
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
