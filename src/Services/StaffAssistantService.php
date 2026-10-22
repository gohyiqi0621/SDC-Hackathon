<?php
/**
 * StaffAssistantService.php - one staff message in, one reply out.
 *
 * Staff > Chat Interface -> this service -> Flowise (staff chatflow) -> reply.
 *
 *   settings   staff_ai_assistant_settings (Staff > Setting & Integration):
 *              name, personality, tone, AI model, temperature, key, Functions
 *   prompt     src/Prompts/staff_system_message.md, filled here
 *   Flowise    FLOWISE_STAFF_CHATFLOW_ID (falls back to FLOWISE_CHATFLOW_ID),
 *              overrideConfig: sessionId, systemMessage, modelName, temperature,
 *              vars (tool_base_url, tool_token for the STAFF tools, functions...)
 *   tools      POST tools/check_invoice | check_claims | check_orders
 *              (ToolController; only staff tokens, only ticked functions)
 *   history    chat_messages, channel 'staff', chat_id "staff-<user id>"
 */
declare(strict_types=1);

namespace Src\Services;

use DateTimeImmutable;
use DateTimeZone;
use Src\Controllers\AiSettingsController;
use Src\Models\ChatMessage;
use Src\Support\FlowLog;
use Throwable;

final class StaffAssistantService
{
    public const CHANNEL = 'staff';

    /** Function key -> Flowise custom tool name and what it is for (shown to the AI). */
    public const TOOLS = [
        'check_invoice' => ['check_invoice', 'invoices: number, party, issue/due date, amount, status (unpaid, partial, paid) and overdue ones'],
        'check_claims'  => ['check_claims',  'expense claims: claimant, title, category, expense date, amount, status (submitted, approved, rejected, reimbursed)'],
        'check_orders'  => ['check_orders',  'customer orders from the AI chatbot: order number, customer, items, total, status, and totals per day'],
        'check_email'   => ['check_email',   'the connected Gmail inbox (not available yet)'],
    ];

    public static function chatId(int $userId): string
    {
        return 'staff-' . $userId;
    }

    /**
     * @return array{status: string, reply: ?string, error: ?string, problems: list<string>}
     */
    public function reply(int $userId, string $staffName, string $message): array
    {
        $config   = (new AssistantConfigService())->forStaff($userId);
        $messages = new ChatMessage();
        $chatId   = self::chatId($userId);

        $this->log($messages, $userId, 'customer', $message, 'ok', $staffName);
        FlowLog::write('staff.config', ['user_id' => $userId, 'model' => $config['ai_model'], 'functions' => $config['functions'],
                                        'enabled' => $config['enabled'], 'problems' => $config['problems']]);

        if (!$config['enabled']) {
            $this->log($messages, $userId, 'assistant', '(staff assistant is switched off - no reply)', 'skipped');
            return ['status' => 'skipped', 'reply' => null,
                    'error' => 'The staff assistant is switched off or not saved yet (Staff > Setting & Integration).',
                    'problems' => $config['problems']];
        }

        $context = [
            'channel'          => self::CHANNEL,
            'customer_chat_id' => $chatId,          // the tool token is signed for "staff:staff-<id>"
            'audience'         => 'staff',
            'staff_name'       => $staffName,
            'functions'        => implode(',', $config['functions']),
            '_chatflow'        => self::chatflowId(),
        ];

        try {
            $result = (new FlowiseService())->ask($config, $this->systemMessage($config), $message,
                self::CHANNEL . ':' . $userId . ':' . $chatId, $context);
        } catch (Throwable $e) {
            error_log('[staff-assistant] ' . $e->getMessage());
            $result = ['ok' => false, 'reply' => '', 'error' => 'Unexpected error while asking Flowise.'];
        }

        if ($result['ok']) {
            $this->log($messages, $userId, 'assistant', $result['reply'], 'ok');
            return ['status' => 'ok', 'reply' => $result['reply'], 'error' => null, 'problems' => $config['problems']];
        }

        $fallback = 'Sorry, I could not answer just now. Please try again in a moment.';
        $this->log($messages, $userId, 'assistant', $fallback, 'error');
        return ['status' => 'error', 'reply' => $fallback, 'error' => $result['error'], 'problems' => $config['problems']];
    }

    /** The staff system message for this business (also shown on the chat page). */
    public function systemMessage(array $config): string
    {
        $zone = new DateTimeZone(date_default_timezone_get());
        $now  = new DateTimeImmutable('now', $zone);

        $on = $off = [];
        foreach (AiSettingsController::STAFF_FUNCTIONS as $key => [$label]) {
            $tool = self::TOOLS[$key] ?? null;
            if ($tool === null) {
                continue;
            }
            if (in_array($key, $config['functions'], true) && $key !== 'check_email') {
                $on[] = '- `' . $tool[0] . '`: ' . $tool[1] . '.';
            } else {
                $off[] = $key === 'check_email' && in_array($key, $config['functions'], true) ? $label . ' (not available yet)' : $label;
            }
        }

        $values = [
            'assistant_name'          => $config['assistant_name'],
            'personality'             => $config['personality'],
            'tone'                    => $config['tone'],
            'company_name'            => $config['company_name'],
            'business_category'       => $config['business_category'],
            'company_background'      => $config['company_background'] !== '' ? $config['company_background'] : 'Not provided.',
            'current_datetime'        => $now->format('l, j F Y, g:i A') . ' (' . $zone->getName() . ', UTC' . $now->format('P') . ')',
            'timezone'                => $zone->getName(),
            'functions'               => $on !== [] ? implode("\n", $on) : '- (none: every function is switched off)',
            'functions_off'           => $off !== []
                ? 'Not available to you: ' . implode(', ', $off) . '. If staff ask about these, say you cannot look that up (switch functions on in Staff > Setting & Integration).'
                : '',
            'additional_instructions' => trim((string) ($config['general'] ?? '')) !== ''
                ? "## 5. Additional instructions\n" . trim((string) $config['general']) : '',
        ];

        $template = (string) file_get_contents(SRC_ROOT . '/Prompts/staff_system_message.md');
        $text = (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/u',
            static fn (array $m): string => (string) ($values[$m[1]] ?? ''), $template);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text)) . "\n";
    }

    /** FLOWISE_STAFF_CHATFLOW_ID, or the customer chatflow when not set. */
    public static function chatflowId(): string
    {
        return trim((string) env('FLOWISE_STAFF_CHATFLOW_ID', ''));
    }

    private function log(ChatMessage $messages, int $userId, string $role, string $content, string $status, ?string $name = null): void
    {
        try {
            $messages->add($userId, self::CHANNEL, self::chatId($userId), $role, $content, $status, $name);
        } catch (Throwable $e) {
            // history only (run migration 017 for the 'staff' channel)
        }
    }
}
