<?php
/**
 * StaffAssistantService.php - one staff message in, one reply out.
 *
 * Staff > Chat Interface -> this service -> Flowise (staff chatflow) -> reply.
 *
 *   settings   staff_ai_assistant_settings (Staff Assistant > Setup):
 *              name, personality, tone, AI model, temperature, key, Functions
 *   prompt     src/Prompts/staff_system_message.md, filled here
 *   Flowise    FLOWISE_STAFF_CHATFLOW_ID_<PROVIDER> (e.g. _ANTHROPIC for Claude),
 *              else FLOWISE_STAFF_CHATFLOW_ID, else the customer chatflow,
 *              overrideConfig: sessionId, systemMessage, modelName, temperature,
 *              vars (tool_base_url, tool_token for the STAFF tools, functions...)
 *   tools      POST tools/check_invoice | check_claims | check_orders |
 *              check_email | read_email | send_email_reply | send_email |
 *              review_receipt (shows the receipt; staff approve / reject with the card buttons)
 *              (ToolController; only staff tokens, only ticked functions)
 *   history    chat_messages, channel 'staff'. Each chat session has its own
 *              chat_id: "staff-<user id>" (the first one) or
 *              "staff-<user id>-<session>" (New chat); the Flowise sessionId
 *              follows it, so a new chat starts with a fresh memory.
 */
declare(strict_types=1);

namespace Src\Services;

use DateTimeImmutable;
use DateTimeZone;
use Src\Controllers\AiSettingsController;
use Src\Models\ChatMessage;
use Src\Support\AssistantErrorAdvice;
use Src\Support\FlowLog;
use Throwable;

final class StaffAssistantService
{
    public const CHANNEL = 'staff';

    /** Cards (email list, opened email, sent) are kept at the end of the saved reply: "<!--cards base64(json)-->". */
    private const CARDS_MARK = '<!--cards ';

    /** False when a message of this request could not be saved to chat_messages. */
    private bool $historySaved = true;

    /** Function key -> the Flowise custom tools it allows and what each is for (shown to the AI). */
    public const TOOLS = [
        'check_invoice' => [['check_invoice', 'invoices: number, party, issue/due date, amount, status (unpaid, partial, paid) and overdue ones']],
        'check_claims'  => [['check_claims',  'expense claims: claimant, title, category, expense date, amount, status (submitted, approved, rejected, reimbursed)']],
        'check_orders'  => [
            ['check_orders',   'customer orders from the AI chatbot: order number, customer, items, total, status, and totals per day'],
            ['review_receipt', 'show the payment receipt picture of an order (and the order) in the chat, so the staff member can check it and approve or reject it with the card\'s buttons'],
        ],
        'check_email'   => [
            ['check_email',      'list emails in the connected Gmail by folder (inbox, important, unread, starred, sent, spam, trash, primary, social, promotions, updates, all), search text and period'],
            ['read_email',       'open one email by its id (from check_email): sender, subject, date and full text, to summarise it and draft a reply'],
            ['send_email_reply', 'send a reply in that email\'s thread. ONLY after the staff member saw the exact final draft and said to send it'],
            ['send_email',       'send a NEW email (not a reply) to an address: to, subject, body (cc optional). ONLY after the staff member saw the draft with the address and subject and said to send it'],
        ],
    ];

    /** The chat id of a session: "staff-<user id>" or "staff-<user id>-<session>". */
    public static function chatId(int $userId, string $session = ''): string
    {
        return 'staff-' . $userId . ($session !== '' ? '-' . $session : '');
    }

    /** A new session key (short, URL-safe). */
    public static function newSession(): string
    {
        return base_convert((string) time(), 10, 36) . bin2hex(random_bytes(2));
    }

    /** The session key from a chat id of this user, or null when it is not theirs / not valid. */
    public static function sessionOf(int $userId, string $chatId): ?string
    {
        if ($chatId === self::chatId($userId)) {
            return '';
        }
        return preg_match('/^staff-' . $userId . '-([a-z0-9]{4,20})$/', $chatId, $m) ? $m[1] : null;
    }

    /**
     * @return array{status: string, reply: ?string, error: ?string, problems: list<string>}
     */
    public function reply(int $userId, string $staffName, string $message, string $session = ''): array
    {
        $config   = (new AssistantConfigService())->forStaff($userId);
        $messages = new ChatMessage();
        $chatId   = self::chatId($userId, preg_match('/^[a-z0-9]{4,20}$/', $session) ? $session : '');

        $this->log($messages, $userId, $chatId, 'customer', $message, 'ok', $staffName);
        FlowLog::write('staff.config', ['user_id' => $userId, 'model' => $config['ai_model'], 'functions' => $config['functions'],
                                        'enabled' => $config['enabled'], 'problems' => $config['problems']]);

        if (!$config['enabled']) {
            $this->log($messages, $userId, $chatId, 'assistant', '(staff assistant is switched off - no reply)', 'skipped');
            $messages->setError($userId, self::CHANNEL, $chatId, 'The AI assistant is switched off. | Setup: ' . implode(' ', $config['problems']));
            return ['history_saved' => $this->historySaved, 'status' => 'skipped', 'reply' => null,
                    'problem' => AssistantErrorAdvice::explain('The AI assistant is switched off.', 'skipped'),
                    'error' => 'The staff assistant is switched off or not saved yet (Staff Assistant > Setup).',
                    'problems' => $config['problems']];
        }

        $context = [
            'channel'          => self::CHANNEL,
            'customer_chat_id' => $chatId,          // the tool token is signed for "staff:staff-<id>"
            'audience'         => 'staff',
            'staff_name'       => $staffName,
            'functions'        => implode(',', $config['functions']),
            '_chatflow'        => self::chatflowId($config['provider']),
        ];

        // Flowise memory keeps only the text, not tool results: tell the AI which
        // emails the chat is showing, so "open 2" / "reply to Jocelyn" work.
        $question = $message . $this->emailContext($messages, $userId, $chatId);

        try {
            $result = (new FlowiseService())->ask($config, $this->systemMessage($config), $question,
                self::CHANNEL . ':' . $userId . ':' . $chatId, $context);
        } catch (Throwable $e) {
            error_log('[staff-assistant] ' . $e->getMessage());
            $result = ['ok' => false, 'reply' => '', 'error' => 'Unexpected error while asking Flowise.'];
        }

        // The model sometimes SAYS an email was sent without calling the send
        // tool. Check the claim against the tools it really used; ask it once
        // to really send, and never show a false "sent".
        if ($result['ok'] && $this->claimsSent($message, (string) $result['reply']) && !self::reallySent($result['tools'] ?? [])) {
            FlowLog::write('staff.false_sent_claim', ['reply' => mb_substr((string) $result['reply'], 0, 300)]);
            try {
                $retry = (new FlowiseService())->ask($config, $this->systemMessage($config),
                    '(Note from the system: you said the email was sent, but you did not call send_email_reply or send_email, '
                    . 'so NOTHING was sent. The staff member confirmed. Call the send tool now with the last draft you showed, '
                    . 'and only say it was sent if the tool answers sent=true.) Staff message: ' . $message,
                    self::CHANNEL . ':' . $userId . ':' . $chatId, $context + ['_retry' => '1']);
            } catch (Throwable $e) {
                $retry = ['ok' => false];
            }
            if (!empty($retry['ok']) && self::reallySent($retry['tools'] ?? [])) {
                $result = $retry;
            } else {
                $result['reply'] = "⚠️ **Not sent.** The assistant did not actually send this email (no send tool was used).\n\n"
                    . 'Please say **"send"** again to send the draft above.';
            }
        }

        if ($result['ok']) {
            $cards = self::cardsFrom($result['tools'] ?? []);
            $this->log($messages, $userId, $chatId, 'assistant', self::withCards((string) $result['reply'], $cards), 'ok');
            return ['history_saved' => $this->historySaved, 'status' => 'ok', 'reply' => $result['reply'], 'cards' => $cards,
                    'error' => null, 'problems' => $config['problems']];
        }

        $fallback = 'Sorry, I could not answer just now. Please try again in a moment.';
        $reason   = trim((string) $result['error'] . ($config['problems'] !== [] ? ' | Setup: ' . implode(' ', $config['problems']) : ''));
        $this->log($messages, $userId, $chatId, 'assistant', $fallback, 'error');
        $messages->setError($userId, self::CHANNEL, $chatId, $reason);
        return ['history_saved' => $this->historySaved, 'status' => 'error', 'reply' => $fallback, 'error' => $result['error'], 'problems' => $config['problems'],
                'problem' => AssistantErrorAdvice::explain($reason)];
    }

    /**
     * Cards for the chat from the tools the AI used in this turn:
     *   emails  the check_email list (last one)    email  the read_email result (last one)
     *   sent    each email really sent
     *
     * @return list<array>
     */
    public static function cardsFrom(array $tools): array
    {
        $list = $open = $receipt = null;
        $sent = [];
        foreach ($tools as $tool) {
            $name = (string) ($tool['tool'] ?? '');
            $out  = $tool['toolOutput'] ?? '';
            $data = is_string($out) ? json_decode($out, true) : $out;
            if (!is_array($data) || empty($data['success'])) {
                continue;
            }
            if ($name === 'check_email') {
                $list = [
                    'type'   => 'emails',
                    'folder' => (string) ($data['folder'] ?? 'inbox'),
                    'search' => $data['search'] ?? null,
                    'period' => (string) ($data['period'] ?? ''),
                    'items'  => array_map(static fn (array $e): array => [
                        'no'        => (int) ($e['no'] ?? 0),
                        'from'      => (string) ($e['from'] ?? ''),
                        'to'        => (string) ($e['to'] ?? ''),
                        'subject'   => (string) ($e['subject'] ?? ''),
                        'date'      => (string) ($e['date'] ?? ''),
                        'snippet'   => mb_substr((string) ($e['snippet'] ?? ''), 0, 160),
                        'unread'    => !empty($e['unread']),
                        'important' => !empty($e['important']),
                    ], array_slice((array) ($data['emails'] ?? []), 0, 25)),
                ];
            } elseif ($name === 'read_email') {
                $open = [
                    'type'    => 'email',
                    'from'    => (string) ($data['from'] ?? ''),
                    'to'      => (string) ($data['to'] ?? ''),
                    'subject' => (string) ($data['subject'] ?? ''),
                    'date'    => (string) ($data['date'] ?? ''),
                    'body'    => mb_substr((string) ($data['body'] ?? ''), 0, 4000),
                ];
            } elseif ($name === 'review_receipt') {
                $receipt = ['type' => 'receipt', 'status' => 'payment_submitted', 'status_label' => 'Bank ref. to review']
                    + array_intersect_key($data, array_flip([
                    'order_id', 'order_no', 'customer', 'phone', 'total', 'items', 'ordered_at', 'receipt_sent_at', 'receipt_url',
                    'receipt_type', 'official_receipt_url', 'others_waiting']));
            } elseif (in_array($name, ['send_email_reply', 'send_email'], true) && !empty($data['sent'])) {
                $sent[] = ['type' => 'sent', 'to' => (string) ($data['to'] ?? ''), 'subject' => (string) ($data['subject'] ?? '')];
            }
        }
        return array_values(array_filter(array_merge([$list, $open, $receipt], $sent)));
    }

    public static function withCards(string $reply, array $cards): string
    {
        return $cards === [] ? $reply
            : $reply . "\n\n" . self::CARDS_MARK . base64_encode((string) json_encode($cards, JSON_UNESCAPED_UNICODE)) . '-->';
    }

    /** @return array{0: string, 1: list<array>} the reply text and its cards */
    public static function splitCards(string $content): array
    {
        $at = strrpos($content, self::CARDS_MARK);
        if ($at === false) {
            return [$content, []];
        }
        $json  = base64_decode(rtrim(substr($content, $at + strlen(self::CARDS_MARK)), "->\n "), true);
        $cards = $json !== false ? json_decode($json, true) : null;
        return [rtrim(substr($content, 0, $at)), is_array($cards) ? $cards : []];
    }

    /** A note for the AI with the email list / opened email the chat is showing (from the last cards). */
    private function emailContext(ChatMessage $messages, int $userId, string $chatId): string
    {
        try {
            $rows = $messages->recent($userId, self::CHANNEL, $chatId, 12);
        } catch (Throwable $e) {
            return '';
        }
        $list = $open = $receipt = null;
        for ($i = count($rows) - 1; $i >= 0 && ($list === null || $open === null); $i--) {
            if ($rows[$i]['role'] !== 'assistant') {
                continue;
            }
            foreach (self::splitCards((string) $rows[$i]['content'])[1] as $card) {
                if (($card['type'] ?? '') === 'emails' && $list === null) {
                    $list = $card;
                } elseif (($card['type'] ?? '') === 'email' && $open === null) {
                    $open = $card;
                }
                if (($card['type'] ?? '') === 'receipt' && $receipt === null && $i >= count($rows) - 4) {
                    $receipt = $card;
                }
            }
        }
        if ($list === null && $open === null && $receipt === null) {
            return '';
        }
        $lines = [];
        if ($receipt !== null) {
            $lines[] = 'Receipt card shown: order ' . ($receipt['order_no'] ?? '?') . ' (' . ($receipt['customer'] ?? '') . ', '
                . ($receipt['total'] ?? '') . '). Staff approve or reject it themselves with the card buttons.';
        }
        if ($list !== null) {
            $lines[] = 'Email list shown in the chat (' . $list['folder'] . '; use the number as the id for read_email):';
            foreach ($list['items'] as $e) {
                $lines[] = '#' . $e['no'] . ' ' . $e['from'] . ' | ' . $e['subject'] . ' | ' . $e['date'] . ($e['unread'] ? ' | unread' : '');
            }
        }
        if ($open !== null) {
            $lines[] = 'Email opened last: ' . $open['from'] . ' | ' . $open['subject'] . ' | ' . $open['date'];
        }
        return "\n\n(Context from the chat screen, not typed by the staff member:\n" . implode("\n", array_slice($lines, 0, 30)) . ')';
    }

    /** Staff said to send, and the reply claims an email was sent? */
    private function claimsSent(string $message, string $reply): bool
    {
        $askedToSend = preg_match('/\b(send|yes|ok|okay|confirm|go ahead|hantar|ya)\b/iu', $message) || preg_match('/(发送|发|好的|可以)/u', $message);
        $saysSent = preg_match('/(✓|✅)\s*\**\s*sent|\b(has|have|was|were|been)\s+sent\b|\b(email|reply|message)\s+sent\b|\bsent\s+to\b|gmail\s*>\s*sent|telah dihantar|sudah dihantar|已发送|已寄出/iu', $reply);
        return $askedToSend && $saysSent;
    }

    /** Did a send tool answer sent=true in this turn? */
    private static function reallySent(array $tools): bool
    {
        foreach ($tools as $tool) {
            $name = (string) ($tool['tool'] ?? '');
            $out  = $tool['toolOutput'] ?? '';
            $data = is_string($out) ? json_decode($out, true) : $out;
            if (in_array($name, ['send_email_reply', 'send_email'], true) && is_array($data) && !empty($data['sent'])) {
                return true;
            }
        }
        return false;
    }

    /** The staff system message for this business (also shown on the chat page). */
    public function systemMessage(array $config): string
    {
        $zone = new DateTimeZone(date_default_timezone_get());
        $now  = new DateTimeImmutable('now', $zone);

        $on = $off = [];
        foreach (AiSettingsController::STAFF_FUNCTIONS as $key => [$label]) {
            $tools = self::TOOLS[$key] ?? [];
            if ($tools === []) {
                continue;
            }
            if (in_array($key, $config['functions'], true)) {
                foreach ($tools as [$name, $about]) {
                    $on[] = '- `' . $name . '`: ' . $about . '.';
                }
            } else {
                $off[] = $label;
            }
        }
        $email = in_array('check_email', $config['functions'], true);

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
                ? 'Not available to you: ' . implode(', ', $off) . '. If staff ask about these, say you cannot look that up (switch functions on in Staff Assistant > Setup).'
                : '',
            'order_rules'             => in_array('check_orders', $config['functions'], true)
                ? (string) @file_get_contents(SRC_ROOT . '/Prompts/staff_order_rules.md') : '',
            'email_rules'             => $email ? (string) @file_get_contents(SRC_ROOT . '/Prompts/staff_email_rules.md') : '',
            'additional_instructions' => trim((string) ($config['general'] ?? '')) !== ''
                ? "## 5. Additional instructions\n" . trim((string) $config['general']) : '',
        ];

        $template = (string) file_get_contents(SRC_ROOT . '/Prompts/staff_system_message.md');
        $text = (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/u',
            static fn (array $m): string => (string) ($values[$m[1]] ?? ''), $template);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text)) . "\n";
    }

    /**
     * The staff chatflow for this provider: FLOWISE_STAFF_CHATFLOW_ID_<PROVIDER>
     * (e.g. _ANTHROPIC for Claude), else FLOWISE_STAFF_CHATFLOW_ID. Empty when
     * neither is set (the customer chatflow is used then).
     */
    public static function chatflowId(?string $provider = null): string
    {
        $suffix = strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) $provider));
        $id = $suffix !== '' ? trim((string) env('FLOWISE_STAFF_CHATFLOW_ID_' . $suffix, '')) : '';
        return $id !== '' ? $id : trim((string) env('FLOWISE_STAFF_CHATFLOW_ID', ''));
    }

    private function log(ChatMessage $messages, int $userId, string $chatId, string $role, string $content, string $status, ?string $name = null): void
    {
        try {
            $messages->add($userId, self::CHANNEL, $chatId, $role, $content, $status, $name);
            return;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        // Migration 017 not run yet: the database only allows 'telegram' and 'web'
        // chats, so staff messages were never saved (and vanished on reload).
        // Allow the 'staff' channel (same SQL as 017, safe to rerun) and try again.
        if (str_contains($error, 'chat_messages_channel_check')) {
            try {
                $messages->allowStaffChannel();
                $messages->add($userId, self::CHANNEL, $chatId, $role, $content, $status, $name);
                FlowLog::write('staff.history_fixed', ['note' => 'Added the staff channel to chat_messages (migration 017).']);
                return;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $this->historySaved = false;
        FlowLog::write('staff.history_not_saved', ['error' => mb_substr($error, 0, 300)]);
        error_log('[staff-assistant] chat history not saved: ' . $error);
    }
}
