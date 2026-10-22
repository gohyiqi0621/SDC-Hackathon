<?php
/**
 * ChatbotController.php - AI Chatbot for two audiences.
 *
 *   Customer: Setup, Product & Service (ProductController), Chat Interface
 *   Staff:    Setup, Chat Interface
 *
 * Customer settings use the AI Settings & Integration page (ai_settings/*).
 * Staff settings and both chat screens share chatbot/settings.php and
 * chatbot/chat.php; this controller only supplies the wording.
 */
declare(strict_types=1);

namespace Src\Controllers;

use Src\Models\ChatMessage;
use Src\Models\GmailConnection;
use Src\Models\TelegramIntegration;
use Src\Models\Order;
use Src\Models\Product;
use Src\Services\AssistantConfigService;
use Src\Services\AssistantReplyService;
use Src\Services\BrevoMailer;
use Src\Services\FlowiseService;
use Src\Services\GmailService;
use Src\Services\SetupStatus;
use Src\Services\OrderService;
use Src\Services\SystemMessageBuilder;
use Src\Services\StaffAssistantService;
use Src\Support\AssistantErrorAdvice;
use Throwable;

final class ChatbotController
{
    /** Options for company_profiles.business_category (the value is stored as-is). */
    public const BUSINESS_CATEGORIES = [
        'Food & Beverage',
        'Retail & E-commerce',
        'Beauty & Wellness',
        'Health & Clinic',
        'Education & Training',
        'Professional Services',
        'Home Services',
        'Automotive',
        'Travel & Hospitality',
        'Property & Real Estate',
        'Technology & IT',
        'Events & Entertainment',
        'Other',
    ];

    /**
     * GET /?page=customer&action=settings
     *
     * AI Settings & Integration: company information, AI assistant, Telegram
     * and Google Calendar. The page itself only renders the shell; each section
     * loads and saves through the JSON endpoints in aiSettingsEndpoints().
     * The signed-in user is always taken from the session on the server - the
     * browser never sends a user id for authorization.
     */
    public function customerSettings(): void
    {
        $user = require_login();

        view('ai_settings/index', [
            'user'           => $user,
            'userId'         => (int) $user['id'],
            'endpoints'      => self::aiSettingsEndpoints(),
            'aiModels'       => AiSettingsController::AI_MODELS,
            'chatUrl'        => url('customer', ['action' => 'chat']),
            'steps'          => SetupStatus::customer((int) $user['id']),
            'companyUrl'     => url('company'),
            'connectionsUrl' => url('customer', ['action' => 'connections']),
        ]);
    }

    /** GET /?page=customer&action=connections - Telegram bot and Google Calendar. */
    public function customerConnections(): void
    {
        $user = require_login();

        view('ai_settings/connections', [
            'user'                => $user,
            'userId'              => (int) $user['id'],
            'endpoints'           => self::aiSettingsEndpoints(),
            'timezones'           => \DateTimeZone::listIdentifiers(),
            'setupUrl'            => url('customer', ['action' => 'settings']),
            'staffConnectionsUrl' => url('staff', ['action' => 'connections']),
        ]);
    }

    /** GET /?page=company - Settings > Company Profile (used by both assistants). */
    public function companyProfile(): void
    {
        $user = require_login();

        view('ai_settings/company', [
            'user'               => $user,
            'userId'             => (int) $user['id'],
            'endpoints'          => self::aiSettingsEndpoints(),
            'businessCategories' => self::BUSINESS_CATEGORIES,
            'customerSetupUrl'   => url('customer', ['action' => 'settings']),
            'staffSetupUrl'      => url('staff', ['action' => 'settings']),
        ]);
    }

    /** GET /?page=staff&action=connections - Gmail (Check email) and your Telegram for staff alerts. */
    public function staffConnections(): void
    {
        $user   = require_login();
        $userId = (int) $user['id'];
        $gmail  = new GmailService();

        try {
            $mail = (new GmailConnection())->find($userId);
        } catch (Throwable $e) {
            $mail = null;
        }
        try {
            $tg = (new TelegramIntegration())->find($userId) ?? [];
        } catch (Throwable $e) {
            $tg = [];
        }
        $functions = (new AssistantConfigService())->forStaff($userId)['functions'];

        view('chatbot/staff_connections', [
            'user'     => $user,
            'gmail'    => [
                'configured'   => $gmail->isConfigured(),
                'email'        => $mail !== null ? (string) $mail['google_email'] : null,
                'connected_at' => $mail !== null && !empty($mail['created_at']) ? date('j M Y', strtotime((string) $mail['created_at'])) : null,
                'needed'       => in_array('check_email', $functions, true),
            ],
            'telegram' => [
                'bot_connected' => ($tg['connection_status'] ?? '') === 'connected' && !empty($tg['bot_username']),
                'bot_username'  => (string) ($tg['bot_username'] ?? ''),
                'staff_linked'  => !empty($tg['staff_telegram_chat_id']),
                'staff_name'    => (string) ($tg['staff_linked_name'] ?? ''),
            ],
            'connectUrl'             => url('gmail', ['action' => 'connect', 'return' => 'staff']),
            'disconnectUrl'          => url('gmail', ['action' => 'disconnect']),
            'linkUrl'                => url('ai_settings', ['action' => 'telegram_staff_link']),
            'unlinkUrl'              => url('ai_settings', ['action' => 'telegram_staff_unlink']),
            'customerConnectionsUrl' => url('customer', ['action' => 'connections']),
            'setupUrl'               => url('staff', ['action' => 'settings']),
            'dashboardUrl'           => url('dashboard'),
        ]);
    }

    /**
     * The JSON endpoints the AI Settings page calls, in one place.
     *
     * The proposed REST paths (/api/smes/:userId/...) do not fit this app's
     * Router (GET/POST only, addressed as ?page=&action=), so the same
     * operations are mapped onto that convention. PUT becomes POST. They are
     * registered in routes.php and handled by AiSettingsController. If one is
     * ever missing (404), the page shows an error for that section.
     *
     * @return array<string, string>
     */
    private static function aiSettingsEndpoints(): array
    {
        $to = static fn (string $action): string => url('ai_settings', ['action' => $action]);

        return [
            // Company information (company_profiles)
            'companyGet'          => $to('company_profile'),            // GET
            'companySave'         => $to('company_profile_save'),       // POST multipart
            // AI assistant (ai_assistant_settings)
            'assistantGet'        => $to('assistant'),                  // GET
            'assistantSave'       => $to('assistant_save'),             // POST
            'assistantGenerateSops' => $to('generate_sops'),            // POST -> {sops: [...]}
            // Telegram (telegram_integrations)
            'telegramGet'         => $to('telegram'),                   // GET
            'telegramConnect'     => $to('telegram_connect'),           // POST
            'telegramDisconnect'  => $to('telegram_disconnect'),        // POST
            'telegramForget'      => $to('telegram_forget'),            // POST
            'telegramStaffLink'   => $to('telegram_staff_link'),        // POST -> {link}
            'telegramStaffUnlink' => $to('telegram_staff_unlink'),      // POST
            // Google Calendar (google_calendar_integrations)
            'calendarGet'         => $to('google_calendar'),            // GET
            'calendarOauthStart'  => $to('google_calendar_oauth_start'),// GET -> {auth_url}
            'calendarCalendars'   => $to('google_calendar_calendars'),  // GET
            'calendarSave'        => $to('google_calendar_save'),       // POST
            'calendarDisconnect'  => $to('google_calendar_disconnect'), // POST
        ];
    }

    /** GET /?page=customer&action=chat - live, view-only customer conversations. */
    public function customerChat(): void
    {
        $user   = require_login();
        $userId = (int) $user['id'];

        view('chatbot/conversations', [
            'user'        => $user,
            'title'       => 'Customer Chat Interface',
            'feed'        => $this->feed($userId, (string) ($_GET['chat'] ?? ''), 0),
            'feedUrl'     => url('customer', ['action' => 'chat_feed']),
            'ordersUrl'   => url('orders'),
            'settingsUrl' => url('customer', ['action' => 'settings']),
        ]);
    }

    /**
     * GET /?page=customer&action=chat_feed&chat=telegram:123&after=456  (JSON)
     *
     * Polled by the Chat Interface every few seconds: the conversation list,
     * the selected chat's messages newer than "after", and its orders today.
     */
    public function customerChatFeed(): void
    {
        $user = require_login();
        json_response(true, $this->feed((int) $user['id'], (string) ($_GET['chat'] ?? ''), max(0, (int) ($_GET['after'] ?? 0))));
    }

    /** Everything the live Chat Interface shows, as plain arrays (also embedded in the first page load). */
    private function feed(int $userId, string $selected, int $after): array
    {
        $model = new ChatMessage();
        try {
            $rows = $model->conversations($userId);
        } catch (Throwable $e) {
            $rows = []; // migration 007 not run yet
        }

        $label = static function (array $c): string {
            if ($c['channel'] === 'web') {
                return 'Website test chat';
            }
            $name = trim((string) ($c['customer_name'] ?? ''));
            return $name !== '' ? $name : 'Telegram ' . $c['chat_id'];
        };

        $conversations = [];
        $current = null;
        foreach ($rows as $c) {
            $key  = $c['channel'] . ':' . $c['chat_id'];
            $item = [
                'key'          => $key,
                'channel'      => $c['channel'],
                'chat_id'      => (string) $c['chat_id'],
                'label'        => $label($c),
                'username'     => $c['customer_username'] ?? null,
                'last_message' => mb_strimwidth(preg_replace('/\s+/u', ' ', (string) $c['last_message']) ?? '', 0, 90, '…'),
                'last_role'    => $c['last_role'],
                'last_at'      => date(DATE_ATOM, strtotime((string) $c['last_at'])),
                'messages'     => (int) $c['messages'],
            ];
            $conversations[] = $item;
            if ($key === $selected) {
                $current = $item;
            }
        }
        if ($current === null && $conversations !== [] && $selected === '') {
            $current = $conversations[0];
        }

        $messages = [];
        $orders   = [];
        if ($current !== null) {
            $list = $after > 0
                ? $model->after($userId, $current['channel'], $current['chat_id'], $after)
                : $model->recent($userId, $current['channel'], $current['chat_id'], 200);
            foreach ($list as $m) {
                $messages[] = [
                    'id'      => (int) $m['id'],
                    'role'    => $m['role'],
                    'content' => (string) $m['content'],
                    'status'  => $m['status'],
                    'at'      => date(DATE_ATOM, strtotime((string) $m['created_at'])),
                    'event'   => preg_match('/^(📎|🖼|🧾|✅|⚠️|📦|📅)/u', (string) $m['content']) === 1,
                    // Why the AI did not answer, and how to fix it (staff only).
                    'problem' => $m['role'] === 'assistant' && $m['status'] !== 'ok'
                        ? AssistantErrorAdvice::explain((string) ($m['error_reason'] ?? ''), (string) $m['status']) : null,
                ];
            }

            try {
                foreach ((new Order())->todayForChat($userId, $current['channel'], $current['chat_id']) as $o) {
                    $orders[] = [
                        'order_no' => $o['order_no'],
                        'status'   => $o['status'],
                        'label'    => Order::STATUSES[$o['status']] ?? $o['status'],
                        'items'    => implode(', ', array_map(static fn (array $i): string => (int) $i['quantity'] . ' × ' . $i['product_name'], $o['items'])),
                        'total'    => format_money($o['total'], $o['currency']),
                        'at'       => date(DATE_ATOM, strtotime((string) $o['created_at'])),
                        'receipt'  => !empty($o['receipt_path']) ? url('orders', ['action' => 'receipt', 'id' => (int) $o['id']]) : null,
                    ];
                }
            } catch (Throwable $e) {
                $orders = []; // migration 009 not run yet
            }
        }

        return [
            'conversations' => $conversations,
            'current'       => $current,
            'messages'      => $messages,
            'orders_today'  => $orders,
            'reset'         => $after === 0,
            'now'           => date(DATE_ATOM),
        ];
    }

    /**
     * POST /?page=customer&action=chat_send  (JSON)
     *
     * Runs one message through the same pipeline as Telegram (configuration
     * from PostgreSQL -> system message -> Flowise), as conversation
     * "web-<user id>". Also tells the page why there was no AI answer
     * (e.g. Flowise not set up yet), which Telegram customers never see.
     */
    public function customerChatSend(): void
    {
        $user    = require_login();
        $message = trim((string) ($_POST['message'] ?? ''));

        if ($message === '') {
            json_response(false, ['errors' => ['message' => 'Type a message first.']], 'Type a message first.', 422);
        }
        if (mb_strlen($message) > 4000) {
            json_response(false, ['errors' => ['message' => 'Keep the message under 4,000 characters.']], 'Message is too long.', 422);
        }

        $result = (new AssistantReplyService())->reply((int) $user['id'], 'web', 'web-' . $user['id'], $message, [
            'customer_name' => (string) $user['name'],
        ]);

        json_response(true, [
            'status'   => $result['status'],
            'reply'    => $result['reply'],
            'error'    => $result['error'],
            'problems' => $result['problems'],
        ]);
    }

    /**
     * GET /?page=customer&action=system_message  (JSON)
     *
     * The exact system message and Flowise request this business would send,
     * with the provider key and tool token masked. For checking the setup.
     */
    public function customerSystemMessage(): void
    {
        $user    = require_login();
        $config  = (new AssistantConfigService())->forUser((int) $user['id']);
        $system  = (new SystemMessageBuilder())->build($config);
        $flowise = new FlowiseService();
        $payload = $flowise->payload($config, $system, '<customer message>', 'web:' . $user['id'] . ':web-' . $user['id']);

        if (isset($payload['overrideConfig']['vars']['provider_api_key'])) {
            $key = (string) $payload['overrideConfig']['vars']['provider_api_key'];
            $payload['overrideConfig']['vars']['provider_api_key'] = substr($key, 0, 7) . '…' . substr($key, -4);
        }
        if (!empty($payload['overrideConfig']['vars']['tool_token'])) {
            $payload['overrideConfig']['vars']['tool_token'] = '(signed, valid 30 minutes)';
        }
        $payload['overrideConfig']['systemMessage'] = '(the system message above)';

        json_response(true, [
            'system_message'     => $system,
            'payload'            => $payload,
            'enabled'            => $config['enabled'],
            'problems'           => $config['problems'],
            'flowise_configured' => $flowise->isConfigured($config['provider']),
            'model'              => $config['ai_model'],
            'provider'           => $config['provider'],
            'temperature'        => $config['temperature'],
        ]);
    }

    /** Last web test messages, so the chat keeps its history on reload. */
    /** A customer's latest orders, for the conversation view. */
    private function ordersFor(int $userId, string $channel, string $chatId): array
    {
        try {
            return (new Order())->recentForChat($userId, $channel, $chatId, 5);
        } catch (Throwable $e) {
            return []; // migration 009 not run yet
        }
    }

    private function webHistory(int $userId): array
    {
        try {
            return (new ChatMessage())->recent($userId, 'web', 'web-' . $userId, 20);
        } catch (Throwable $e) {
            return []; // migration 007 not run yet
        }
    }

    /** GET /?page=staff&action=settings */
    public function staffSettings(): void
    {
        $user = require_login();

        view('chatbot/settings', [
            'user'         => $user,
            'title'        => 'Staff Assistant Setup',
            'audience'     => 'Staff',
            'sub'          => 'Get the assistant your team chats with ready: model, personality and what it may look up.',
            'steps'          => SetupStatus::staff((int) $user['id']),
            'connectionsUrl' => url('staff', ['action' => 'connections']),
            'assistant'    => ['name' => APP_NAME . ' Staff Assistant', 'greeting' => 'Hi! What do you need to look up?'],
            'productCount' => null,
            'integrations' => [
                ['fa-brands fa-slack',          'Slack',            'Ask the assistant from your team workspace.', false],
                ['fa-brands fa-microsoft',      'Microsoft Teams',  'Ask the assistant inside Teams.',             false],
                ['fa-brands fa-google-drive',   'Google Drive',     'Let it read your shared documents.',          false],
                ['fa-regular fa-envelope',      'Email (Brevo)',    'Send emails such as password resets.',        (new BrevoMailer())->isConfigured() ?: 'setup'],
            ],
            'chatUrl'      => url('staff', ['action' => 'chat']),
            // AI Assistant Settings (same form as the customer page, own table)
            'aiModels'     => AiSettingsController::AI_MODELS,
            'aiEndpoints'  => [
                'assistantGet'          => url('ai_settings', ['action' => 'staff_assistant']),
                'assistantSave'         => url('ai_settings', ['action' => 'staff_assistant_save']),
                'assistantGenerateSops' => url('ai_settings', ['action' => 'staff_generate_sops']),
            ],
        ]);
    }

    /**
     * GET /?page=staff&action=chat[&session=<key>|new] - the staff assistant.
     * Each "New chat" is its own session (own history and Flowise memory);
     * old sessions stay in the list on the left and can be reopened.
     */
    public function staffChat(): void
    {
        $user   = require_login();
        $userId = (int) $user['id'];
        $config = (new AssistantConfigService())->forStaff($userId);
        $fns    = $config['functions'];

        $suggestions = array_values(array_filter([
            in_array('check_email', $fns, true) ? 'Show my important emails' : null,
            in_array('check_email', $fns, true) ? 'Any unread emails today that need a reply?' : null,
            in_array('check_orders', $fns, true) ? 'Check the receipts waiting for review' : null,
            in_array('check_orders', $fns, true) ? 'How many orders today and the total?' : null,
            in_array('check_invoice', $fns, true) ? 'Which invoices are overdue?' : null,
            in_array('check_claims', $fns, true) ? 'Show claims waiting for approval' : null,
            in_array('check_email', $fns, true) ? 'Show emails I sent this week' : null,
        ]));

        $functions = [];
        foreach (AiSettingsController::STAFF_FUNCTIONS as $key => [$label, $icon]) {
            $functions[] = ['label' => $label, 'icon' => $icon, 'state' => in_array($key, $fns, true) ? 'on' : 'off'];
        }

        $messages = new ChatMessage();
        try {
            $sessions = $messages->staffSessions($userId);
        } catch (Throwable $e) {
            $sessions = [];
        }

        // Which session: ?session=<key>, "new", or the latest one.
        $asked   = (string) ($_GET['session'] ?? '');
        $session = null;
        if ($asked !== '' && $asked !== 'new') {
            $session = preg_match('/^(?:main|[a-z0-9]{4,20})$/', $asked) ? ($asked === 'main' ? '' : $asked) : null;
        } elseif ($asked === '' && $sessions !== []) {
            $session = StaffAssistantService::sessionOf($userId, $sessions[0]['chat_id']);
        }
        $isNew = $session === null;
        if ($isNew) {
            $session = StaffAssistantService::newSession();
        }

        $history = [];
        if (!$isNew) {
            try {
                $history = $messages->recent($userId, StaffAssistantService::CHANNEL, StaffAssistantService::chatId($userId, $session), 200);
            } catch (Throwable $e) {
                $history = [];
            }
        }

        $chatUrl = static fn (string $key): string => url('staff', ['action' => 'chat', 'session' => $key === '' ? 'main' : $key]);
        $list = [];
        foreach ($sessions as $row) {
            $key = StaffAssistantService::sessionOf($userId, $row['chat_id']);
            if ($key === null) {
                continue;
            }
            $title  = trim((string) preg_replace('/\s+/u', ' ', $row['title']));
            $list[] = [
                'key'     => $key,
                'title'   => $title !== '' ? mb_strimwidth($title, 0, 60, '…') : 'Chat',
                'at'      => date(DATE_ATOM, strtotime($row['last_at'])),
                'count'   => $row['messages'],
                'url'     => $chatUrl($key),
                'current' => !$isNew && $key === $session,
            ];
        }

        view('chatbot/staff_chat', [
            'user'        => $user,
            'title'       => 'Staff Chat Interface',
            'assistant'   => $config['assistant_name'] !== 'Assistant' ? $config['assistant_name'] : 'Staff assistant',
            'model'       => AiSettingsController::AI_MODELS[$config['ai_model']][0] ?? $config['ai_model'],
            'enabled'     => $config['enabled'],
            'problems'    => $config['problems'],
            'functions'   => $functions,
            'suggestions' => $suggestions,
            'sessions'    => $list,
            'session'     => $session,
            'isNew'       => $isNew,
            'history'     => array_map(fn (array $m): array => [
                'role'    => $m['role'] === 'customer' ? 'me' : 'ai',
                'content' => StaffAssistantService::splitCards((string) $m['content'])[0],
                'cards'   => $m['role'] === 'customer' ? [] : $this->freshCards($userId, StaffAssistantService::splitCards((string) $m['content'])[1]),
                'status'  => (string) $m['status'],
                'at'      => date(DATE_ATOM, strtotime((string) $m['created_at'])),
                'problem' => $m['role'] !== 'customer' && $m['status'] !== 'ok'
                    ? AssistantErrorAdvice::explain((string) ($m['error_reason'] ?? ''), (string) $m['status']) : null,
            ], $history),
            'sendUrl'     => url('staff', ['action' => 'chat_send']),
            'decideUrl'   => url('staff', ['action' => 'order_decide']),
            'newUrl'      => url('staff', ['action' => 'chat', 'session' => 'new']),
            'sessionUrl'  => $chatUrl($session),
            'settingsUrl' => url('staff', ['action' => 'settings']),
            'chatflowSet' => StaffAssistantService::chatflowId($config['provider']) !== '',
        ]);
    }

    /** Receipt cards show the order's current status (it may have been decided since). */
    private function freshCards(int $userId, array $cards): array
    {
        foreach ($cards as &$card) {
            if (($card['type'] ?? '') === 'receipt' && !empty($card['order_id'])) {
                $order = (new Order())->find($userId, (int) $card['order_id']);
                if ($order !== null) {
                    $card['status']       = (string) $order['status'];
                    $card['status_label'] = Order::STATUSES[$order['status']] ?? $order['status'];
                }
            }
        }
        unset($card);
        return $cards;
    }

    /**
     * POST /?page=staff&action=order_decide (JSON) - the Approve / Reject
     * buttons on a receipt card in the staff chat. The staff member decides
     * (same as the Orders page); the customer is told on Telegram.
     */
    public function staffOrderDecide(): void
    {
        $user     = require_login();
        $userId   = (int) $user['id'];
        $decision = (string) ($_POST['decision'] ?? '');
        $reason   = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 300);
        $order    = (new Order())->find($userId, (int) ($_POST['id'] ?? 0));

        if ($order === null) {
            json_response(false, [], 'That order was not found.', 404);
        }
        if (!in_array($decision, ['approve', 'reject'], true)) {
            json_response(false, [], 'Choose approve or reject.', 422);
        }

        try {
            $result = $decision === 'approve'
                ? (new OrderService())->approve($userId, $order)
                : (new OrderService())->reject($userId, $order, $reason);
        } catch (\RuntimeException $e) {
            json_response(false, ['status' => $order['status'], 'status_label' => Order::STATUSES[$order['status']] ?? $order['status']],
                $e->getMessage(), 409);
        }

        $status = (string) $result['order']['status'];
        json_response(true, [
            'status'       => $status,
            'status_label' => Order::STATUSES[$status] ?? $status,
            'notified'     => $result['notified'],
        ], $decision === 'approve'
            ? ($result['notified'] ? 'Approved. The customer was told on Telegram.' : 'Approved. The customer could not be messaged on Telegram.')
            : ($result['notified'] ? 'Rejected. The customer was asked for a new receipt.' : 'Rejected. The customer could not be messaged on Telegram.'));
    }

    /** POST /?page=staff&action=chat_send (JSON) - one staff message through Flowise, in one chat session. */
    public function staffChatSend(): void
    {
        $user    = require_login();
        $message = trim((string) ($_POST['message'] ?? ''));
        $session = (string) ($_POST['session'] ?? '');

        if ($message === '') {
            json_response(false, ['errors' => ['message' => 'Type a message first.']], 'Type a message first.', 422);
        }
        if (mb_strlen($message) > 4000) {
            json_response(false, ['errors' => ['message' => 'Keep the message under 4,000 characters.']], 'Message is too long.', 422);
        }
        if ($session !== '' && $session !== 'main' && !preg_match('/^[a-z0-9]{4,20}$/', $session)) {
            json_response(false, [], 'Unknown chat session. Reload the page.', 422);
        }

        $result = (new StaffAssistantService())->reply((int) $user['id'], (string) $user['name'], $message,
            $session === 'main' ? '' : $session);
        json_response(true, $result);
    }
}
