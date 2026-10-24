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
use Src\Support\Database;
use Src\Support\FlowLog;
use Throwable;

final class AssistantReplyService
{
    // What the customer sees when the AI cannot answer: a way forward, never the technical reason
    // (staff see the reason and the fix in Chat Interface).
    public const FALLBACK_REPLY = "Sorry, I can't reply right now. Please try again in a few minutes. "
        . 'You can also leave your question here and our team will get back to you as soon as possible.';

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
            $messages->setError($userId, $channel, $chatId, in_array('AI assistant settings are not saved yet.', $config['problems'], true)
                ? 'AI assistant settings are not saved yet.' : 'The AI assistant is switched off.');
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

        if ($result['ok']) {
            $result = $this->guardInventedOrders($userId, $config, $systemMessage, $message, $sessionId, $context, $result);
            $result['reply'] = self::stripWeekdays((string) $result['reply']);
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
        $messages->setError($userId, $channel, $chatId, trim((string) $result['error']
            . ($config['problems'] !== [] ? ' | Setup: ' . implode(' ', $config['problems']) : '')));

        return ['status' => 'error', 'reply' => self::FALLBACK_REPLY, 'error' => $result['error'], 'problems' => $config['problems']];
    }

    /** What the customer sees when the AI claimed an order that was never created (twice). */
    public const ORDER_NOT_CREATED_REPLY = "Sorry, I couldn't create your order just now. "
        . 'Please reply "confirm" to try again, or leave a message and our team will help you.';

    /**
     * The AI sometimes answers "Your order ORD-... is ready" without calling
     * buy_product, so the order number is made up and the customer pays for
     * an order that does not exist. Every order number in the reply is checked
     * against the database. An unknown one: ask the AI once more (telling it
     * the order does not exist); still unknown: send an honest message.
     */
    private function guardInventedOrders(int $userId, array $config, string $systemMessage, string $message,
                                         string $sessionId, array $context, array $result): array
    {
        $invented = $this->unknownOrderNumbers($userId, (string) $result['reply'], $message);
        if ($invented === []) {
            return $result;
        }

        FlowLog::write('guard.invented_order', [
            'order_no' => implode(', ', $invented),
            'tools'    => array_values(array_filter(array_map(
                static fn ($t): string => is_array($t) ? (string) ($t['tool'] ?? '') : '', (array) ($result['tools'] ?? [])))),
            'reply'    => mb_substr((string) $result['reply'], 0, 300),
        ]);

        if (empty($context['_guard'])) {
            try {
                $retry = (new FlowiseService())->ask($config, $systemMessage,
                    '(Note from the system, not typed by the customer: your previous reply mentioned order '
                    . implode(', ', $invented) . ', but that order does NOT exist: buy_product was not called or did not succeed. '
                    . 'The customer did not see that reply. If the customer has confirmed the order, call buy_product now with the '
                    . 'confirmed items and phone number, then reply using only the order number buy_product returns. '
                    . 'If it fails, tell the customer the order could not be created. Never write an order number yourself.) '
                    . "Customer's last message: " . $message,
                    $sessionId, $context + ['_guard' => '1']);
            } catch (Throwable $e) {
                $retry = ['ok' => false];
            }

            if (!empty($retry['ok']) && $this->unknownOrderNumbers($userId, (string) $retry['reply'], $message) === []) {
                FlowLog::write('guard.invented_order_fixed', ['reply' => mb_substr((string) $retry['reply'], 0, 300)]);
                return $retry;
            }
        }

        FlowLog::write('guard.invented_order_blocked', ['order_no' => implode(', ', $invented)]);
        return ['ok' => true, 'reply' => self::ORDER_NOT_CREATED_REPLY, 'error' => null, 'tools' => $result['tools'] ?? []];
    }

    /** Order numbers in $reply that this business does not have (numbers the customer typed are ignored). */
    private function unknownOrderNumbers(int $userId, string $reply, string $customerMessage): array
    {
        if (!preg_match_all('/\bORD-\d{6}-[A-Z0-9]{4}\b/i', $reply, $m)) {
            return [];
        }
        $numbers = array_values(array_unique(array_map('strtoupper', $m[0])));
        $numbers = array_values(array_filter($numbers, static fn (string $n): bool => stripos($customerMessage, $n) === false));
        if ($numbers === []) {
            return [];
        }

        try {
            $marks = implode(',', array_fill(0, count($numbers), '?'));
            $stmt  = Database::connection()->prepare("SELECT UPPER(order_no) FROM orders WHERE user_id = ? AND UPPER(order_no) IN ($marks)");
            $stmt->execute(array_merge([$userId], $numbers));
            $known = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return []; // cannot check: never block a reply because of the guard
        }
        return array_values(array_diff($numbers, $known));
    }

    /**
     * Customers asked for exact dates only. The AI still adds weekdays and
     * sometimes the wrong one ("26 Oct 2026 (Sunday)" for a Monday), so a
     * weekday written next to a date is taken out.
     */
    public static function stripWeekdays(string $text): string
    {
        $day = '(?:Mon|Tues|Wednes|Thurs|Fri|Satur|Sun)day|Mon|Tue|Wed|Thu|Fri|Sat|Sun';
        // "26 Oct 2026 (Sunday)" / "26 Oct (Mon)"
        $text = preg_replace('/\s*\((?:' . $day . ')\)/i', '', $text) ?? $text;
        // "Sunday, 26 Oct 2026" / "Monday 26 Oct" (a weekday right before a date)
        $text = preg_replace('/\b(?:' . $day . '),?\s+(?=\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]{3,9}\b)/i', '', $text) ?? $text;
        // "26 Oct 2026, Monday"
        $text = preg_replace('/(\d{1,2}\s+[A-Za-z]{3,9}(?:\s+\d{4})?),\s*(?:' . $day . ')\b/i', '$1', $text) ?? $text;
        return $text;
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
