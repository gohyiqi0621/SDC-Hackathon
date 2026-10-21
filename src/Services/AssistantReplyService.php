<?php
/**
 * AssistantReplyService.php - one customer message in, one reply out.
 *
 * Shared by the Telegram webhook and the website's Chat Interface:
 *
 *   1. load the business's configuration from PostgreSQL
 *   2. skip if the assistant is switched off
 *   3. fill the system message template
 *   4. ask Flowise (memory is kept per conversation through sessionId)
 *   5. log both sides in chat_messages
 *
 * When Flowise is not set up or fails, the customer gets a short, honest
 * fallback reply and the real reason is returned for the website / logs.
 */
declare(strict_types=1);

namespace Src\Services;

use Src\Models\ChatMessage;
use Src\Models\Order;
use Src\Support\FlowLog;
use Throwable;

final class AssistantReplyService
{
    public const FALLBACK_REPLY = 'Sorry, our assistant is not available right now. Our team will get back to you as soon as possible.';

    /**
     * @param string $channel 'telegram' or 'web'
     * @param array  $context extra Flowise vars (customer_name, attachment, ...)
     * @return array{status:string, reply:?string, error:?string, problems:string[]}
     *         status: 'ok' (Flowise answered), 'error' (fallback reply), 'skipped' (assistant off: send nothing)
     */
    public function reply(int $userId, string $channel, string $chatId, string $message, array $context = []): array
    {
        $config   = (new AssistantConfigService())->forUser($userId);
        $messages = new ChatMessage();

        $this->log($messages, $userId, $channel, $chatId, 'customer', $message, 'ok',
            $context['customer_name'] ?? null, $context['customer_username'] ?? null);

        FlowLog::write('system.config', [
            'channel'   => $channel,
            'user_id'   => $userId,
            'chat_id'   => $chatId,
            'message'   => $message,
            'enabled'   => $config['enabled'],
            'model'     => $config['ai_model'],
            'provider'  => $config['provider'],
            'temperature' => $config['temperature'],
            'has_api_key' => !empty($config['api_key']),
            'problems'  => $config['problems'],
        ]);

        if (!$config['enabled']) {
            $this->log($messages, $userId, $channel, $chatId, 'assistant', '(assistant is switched off - no reply sent)', 'skipped');
            FlowLog::write('system.skipped', ['reason' => in_array('AI assistant settings are not saved yet.', $config['problems'], true)
                ? 'AI assistant settings are not saved yet: open AI Settings > AI Assistant Settings and click Save.'
                : 'The AI assistant is switched off (AI Assistant Settings > Enabled).']);
            return ['status' => 'skipped', 'reply' => null, 'error' => 'The AI assistant is switched off in AI Settings.', 'problems' => $config['problems']];
        }

        // This customer's latest orders, so "one more roti" is not mixed up
        // with an order that was already paid.
        try {
            $recentOrders = (new Order())->recentForChat($userId, $channel, $chatId, 3);
        } catch (Throwable $e) {
            $recentOrders = []; // orders table not created yet
        }

        $systemMessage = (new SystemMessageBuilder())->build($config, $message, $recentOrders);
        $sessionId     = $channel . ':' . $userId . ':' . $chatId;
        $context      += ['channel' => $channel, 'customer_chat_id' => $chatId];

        try {
            $result = (new FlowiseService())->ask($config, $systemMessage, $message, $sessionId, $context);
        } catch (Throwable $e) {
            error_log('[assistant] ' . $e->getMessage());
            $result = ['ok' => false, 'reply' => '', 'error' => 'Unexpected error while asking Flowise.'];
        }

        FlowLog::write($result['ok'] ? 'system.reply' : 'system.fallback', [
            'reply' => $result['ok'] ? $result['reply'] : self::FALLBACK_REPLY,
            'error' => $result['error'],
        ]);

        if ($result['ok']) {
            $this->log($messages, $userId, $channel, $chatId, 'assistant', $result['reply'], 'ok');
            return ['status' => 'ok', 'reply' => $result['reply'], 'error' => null, 'problems' => $config['problems']];
        }

        $this->log($messages, $userId, $channel, $chatId, 'assistant', self::FALLBACK_REPLY, 'error');

        return ['status' => 'error', 'reply' => self::FALLBACK_REPLY, 'error' => $result['error'], 'problems' => $config['problems']];
    }

    /** Logging must never stop a reply (e.g. migration 007 not run yet). */
    private function log(ChatMessage $messages, int $userId, string $channel, string $chatId, string $role, string $content, string $status,
                         ?string $customerName = null, ?string $customerUsername = null): void
    {
        try {
            $messages->add($userId, $channel, $chatId, $role, $content, $status, $customerName, $customerUsername);
        } catch (Throwable $e) {
            error_log('[assistant] could not log message: ' . $e->getMessage());
        }
    }
}
