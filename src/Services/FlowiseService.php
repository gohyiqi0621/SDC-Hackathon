<?php
/**
 * FlowiseService.php - the only place that talks to Flowise.
 *
 * Calls the Prediction API:
 *
 *   POST {FLOWISE_API_URL}/api/v1/prediction/{chatflow id}
 *   Authorization: Bearer {FLOWISE_API_KEY}       (when set)
 *   {
 *     "question": "<customer message>",
 *     "overrideConfig": {
 *       "sessionId":     "<one per customer conversation, keeps memory>",
 *       "systemMessage": "<filled src/Prompts/system_message.md>",
 *       "modelName":     "<ai_assistant_settings.ai_model>",
 *       "temperature":   0.7,
 *       "vars": { company + tool details, see payload() }
 *     }
 *   }
 *
 * Flowise is not set up yet, so everything Flowise-specific is in payload()
 * and parseReply(). When the chatflow exists, adjust those two methods (for
 * example the exact override names its nodes accept) and nothing else.
 * In the chatflow, "Override Configuration" must allow systemMessage,
 * modelName, temperature and the vars used here.
 *
 * Chatflow choice: FLOWISE_CHATFLOW_ID, or a per-provider one
 * (FLOWISE_CHATFLOW_ID_ANTHROPIC / FLOWISE_CHATFLOW_ID_DEEPSEEK) when the
 * providers need different chat-model nodes.
 */
declare(strict_types=1);

namespace Src\Services;

use RuntimeException;
use Src\Support\Http;
use Src\Support\FlowLog;
use Src\Support\ToolToken;

final class FlowiseService
{
    public function isConfigured(?string $provider = null): bool
    {
        return FLOWISE_API_URL !== '' && $this->chatflowId($provider) !== '';
    }

    /**
     * @param array  $config  from AssistantConfigService::forUser()
     * @param array  $context extra details for Flowise vars (channel, customer name, attachment, ...)
     * @return array{ok:bool, reply:string, error:?string}
     */
    public function ask(array $config, string $systemMessage, string $question, string $sessionId, array $context = []): array
    {
        // A caller may pick its own chatflow (the staff assistant: FLOWISE_STAFF_CHATFLOW_ID).
        $chatflow = trim((string) ($context['_chatflow'] ?? '')) ?: $this->chatflowId($config['provider']);
        if (FLOWISE_API_URL === '' || $chatflow === '') {
            FlowLog::write('flowise.not_configured', ['flowise_api_url' => FLOWISE_API_URL, 'chatflow_id' => $chatflow]);
            return ['ok' => false, 'reply' => '', 'error' => 'Flowise is not configured (set FLOWISE_API_URL and FLOWISE_CHATFLOW_ID in .env).'];
        }

        $url     = FLOWISE_API_URL . '/api/v1/prediction/' . rawurlencode($chatflow);
        $headers = ['Content-Type' => 'application/json'];
        if (FLOWISE_API_KEY !== '') {
            $headers['Authorization'] = 'Bearer ' . FLOWISE_API_KEY;
        }

        $payload = $this->payload($config, $systemMessage, $question, $sessionId, $context);
        $body    = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Keys inside the payload (provider_api_key, tool_token) are masked by FlowLog.
        FlowLog::write('flowise.request', [
            'url'      => $url,
            'has_auth' => FLOWISE_API_KEY !== '',
            'timeout'  => FLOWISE_TIMEOUT,
            'payload'  => $payload,
        ]);
        $started = microtime(true);

        try {
            $response = Http::request('POST', $url, $headers, (string) $body, FLOWISE_TIMEOUT);
        } catch (RuntimeException $e) {
            // The error log never gets the body: it holds the provider API key.
            error_log('[flowise] request failed: ' . $e->getMessage());
            FlowLog::write('flowise.unreachable', ['error' => $e->getMessage(), 'ms' => (int) round((microtime(true) - $started) * 1000)]);
            return ['ok' => false, 'reply' => '', 'error' => 'Could not reach Flowise (' . $e->getMessage() . ').'];
        }

        FlowLog::write('flowise.response', [
            'status' => $response['status'],
            'ms'     => (int) round((microtime(true) - $started) * 1000),
            'body'   => $response['raw'],
        ]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $detail = is_array($response['json']) ? (string) ($response['json']['message'] ?? $response['json']['error'] ?? '') : '';
            error_log('[flowise] HTTP ' . $response['status'] . ($detail !== '' ? ': ' . mb_substr($detail, 0, 300) : ''));
            return ['ok' => false, 'reply' => '', 'error' => 'Flowise returned HTTP ' . $response['status'] . ($detail !== '' ? ' (' . mb_substr($detail, 0, 200) . ')' : '') . '.'];
        }

        $reply = $this->parseReply($response['json'], $response['raw']);
        if ($reply === '') {
            // Some models end with an empty answer after calling tools. The
            // tools may already have done the work (order placed, slot booked),
            // so answer from their results rather than "not available".
            $usedTools = is_array($response['json']) ? (array) ($response['json']['usedTools'] ?? []) : [];
            $fromTools = $this->replyFromTools($usedTools);
            if ($fromTools !== '') {
                FlowLog::write('flowise.empty_reply_recovered', ['source' => 'tool results', 'tools' => array_column($usedTools, 'tool')]);
                return ['ok' => true, 'reply' => $fromTools, 'error' => null];
            }

            // Nothing usable: ask once more in the same conversation.
            if (empty($context['_retry'])) {
                FlowLog::write('flowise.empty_reply_retry', ['tools' => array_column($usedTools, 'tool')]);
                $retry = $this->ask($config, $systemMessage,
                    '(Note from the system: your previous answer to the customer was empty. Answer the customer\'s last message now, '
                    . 'in their language, using the tool results you already have. Do not repeat buy_product or make_booking.) '
                    . 'Customer\'s last message: ' . $question,
                    $sessionId, $context + ['_retry' => '1']);
                if ($retry['ok']) {
                    return $retry;
                }
            }

            error_log('[flowise] empty reply');
            return ['ok' => false, 'reply' => '', 'error' => 'Flowise returned an empty reply.'];
        }

        return ['ok' => true, 'reply' => $reply, 'error' => null];
    }

    /**
     * The request body. Kept separate so it can be shown/tested without
     * calling Flowise, and adjusted in one place once the chatflow exists.
     */
    public function payload(array $config, string $systemMessage, string $question, string $sessionId, array $context = []): array
    {
        $vars = [
            'company_id'        => (string) $config['user_id'],
            'company_name'      => $config['company_name'],
            'assistant_name'    => $config['assistant_name'],
            'provider'          => $config['provider'],
            'model'             => $config['ai_model'],
            // Flowise custom tools call back into this app with these two values.
            'tool_base_url'     => PUBLIC_APP_URL . '/tools',
            // Signed for this business and this customer chat (buy_product uses the chat).
            'tool_token'        => ToolToken::issue((int) $config['user_id'], ToolToken::TTL_SECONDS,
                isset($context['channel'], $context['customer_chat_id']) ? $context['channel'] . ':' . $context['customer_chat_id'] : '') ?? '',
        ] + array_map('strval', $context);

        // The business's own AI provider key (Anthropic / DeepSeek), for a
        // chatflow that reads it from $vars. Switch off with
        // FLOWISE_SEND_PROVIDER_KEY=false if the key is stored in Flowise instead.
        if ($this->sendProviderKey() && !empty($config['api_key'])) {
            $vars['provider_api_key'] = $config['api_key'];
        }

        return [
            'question'       => $question,
            'overrideConfig' => [
                'sessionId'     => $sessionId,
                'systemMessage' => $systemMessage,
                'modelName'     => $config['ai_model'],
                'temperature'   => round((float) $config['temperature'], 2),
                'vars'          => $vars,
            ],
        ];
    }

    /**
     * A customer reply built from the last successful tool call, used when the
     * model returned no text. Only actions with a clear result are covered.
     */
    private function replyFromTools(array $usedTools): string
    {
        for ($i = count($usedTools) - 1; $i >= 0; $i--) {
            $tool = (string) ($usedTools[$i]['tool'] ?? '');
            $out  = $usedTools[$i]['toolOutput'] ?? null;
            $data = is_string($out) ? json_decode($out, true) : (is_array($out) ? $out : null);
            if (!is_array($data) || empty($data['success'])) {
                continue;
            }

            if ($tool === 'buy_product' && !empty($data['order_no'])) {
                $lines = array_map(static fn (array $l): string => '• ' . $l['quantity'] . ' × ' . $l['name'] . ' (' . $l['line_total'] . ')', (array) ($data['items'] ?? []));
                $qr    = str_contains((string) ($data['payment_qr'] ?? ''), 'already been sent') ? "\nThe payment QR code has been sent in this chat." : '';
                return "Your order {$data['order_no']} has been placed:\n" . implode("\n", $lines)
                    . "\nTotal: {$data['total']}\n\nHow to pay: " . ($data['payment_instructions'] ?? '-') . $qr
                    . "\n\nAfter paying, please send a photo or PDF of the payment receipt here. Our staff will verify it.";
            }
            if ($tool === 'make_booking' && !empty($data['booking_no'])) {
                return "Your booking is confirmed! ✅\nBooking no.: {$data['booking_no']}\nWhen: {$data['when']}\nPax: {$data['pax']}"
                    . (!empty($data['name']) ? "\nName: {$data['name']}" : '');
            }
            if ($tool === 'check_availability') {
                if (!empty($data['free_times'])) {
                    return "These times are available:\n• " . implode("\n• ", (array) $data['free_times']) . "\n\nWhich one would you like?";
                }
                if (isset($data['available'])) {
                    return $data['available']
                        ? "{$data['requested']} is available. Shall I book it for you?"
                        : 'Sorry, ' . ($data['reason'] ?? 'that time is not available.')
                            . (!empty($data['alternatives']) ? "\nAvailable instead:\n• " . implode("\n• ", (array) $data['alternatives']) : '');
                }
            }
        }
        return '';
    }

    /** Flowise usually answers {"text": "..."}; some flows answer a plain string or {"json": ...}. */
    private function parseReply(mixed $json, string $raw): string
    {
        if (is_array($json)) {
            foreach (['text', 'answer', 'output', 'message'] as $key) {
                if (isset($json[$key]) && is_string($json[$key])) {
                    return trim($json[$key]);
                }
            }
            if (isset($json['json'])) {
                return trim(is_string($json['json']) ? $json['json'] : (string) json_encode($json['json'], JSON_UNESCAPED_UNICODE));
            }
            return '';
        }

        return is_string($json) ? trim($json) : trim($raw);
    }

    private function chatflowId(?string $provider): string
    {
        if ($provider !== null) {
            $specific = trim((string) env('FLOWISE_CHATFLOW_ID_' . strtoupper((string) preg_replace('/[^A-Za-z]/', '', $provider)), ''));
            if ($specific !== '') {
                return $specific;
            }
        }

        return FLOWISE_CHATFLOW_ID;
    }

    private function sendProviderKey(): bool
    {
        return filter_var(env('FLOWISE_SEND_PROVIDER_KEY', 'true'), FILTER_VALIDATE_BOOL);
    }
}
