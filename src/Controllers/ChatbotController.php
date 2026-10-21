<?php
/**
 * ChatbotController.php - AI Chatbot for two audiences.
 *
 *   Customer: Setting & Integration, Product & Service (ProductController), Chat Interface
 *   Staff:    Setting & Integration, Chat Interface
 *
 * Customer settings use the AI Settings & Integration page (ai_settings/*).
 * Staff settings and both chat screens share chatbot/settings.php and
 * chatbot/chat.php; this controller only supplies the wording.
 */
declare(strict_types=1);

namespace Src\Controllers;

use Src\Models\ChatMessage;
use Src\Models\Order;
use Src\Models\Product;
use Src\Services\AssistantConfigService;
use Src\Services\AssistantReplyService;
use Src\Services\BrevoMailer;
use Src\Services\FlowiseService;
use Src\Services\SystemMessageBuilder;
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
            'user'               => $user,
            'userId'             => (int) $user['id'],
            'endpoints'          => self::aiSettingsEndpoints(),
            'businessCategories' => self::BUSINESS_CATEGORIES,
            'timezones'          => \DateTimeZone::listIdentifiers(),
            'aiModels'           => AiSettingsController::AI_MODELS,
            'chatUrl'            => url('customer', ['action' => 'chat']),
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
            'title'        => 'Staff Setting & Integration',
            'audience'     => 'Staff',
            'sub'          => 'Set up how the staff assistant helps your team.',
            'assistant'    => ['name' => APP_NAME . ' Staff Assistant', 'greeting' => 'Hi! What do you need to look up?'],
            'productCount' => null,
            'integrations' => [
                ['fa-brands fa-slack',          'Slack',            'Ask the assistant from your team workspace.', false],
                ['fa-brands fa-microsoft',      'Microsoft Teams',  'Ask the assistant inside Teams.',             false],
                ['fa-brands fa-google-drive',   'Google Drive',     'Let it read your shared documents.',          false],
                ['fa-regular fa-envelope',      'Email (Brevo)',    'Send emails such as password resets.',        (new BrevoMailer())->isConfigured() ?: 'setup'],
            ],
            'chatUrl'      => url('staff', ['action' => 'chat']),
        ]);
    }

    /** GET /?page=staff&action=chat */
    public function staffChat(): void
    {
        view('chatbot/chat', [
            'user'        => require_login(),
            'title'       => 'Staff Chat Interface',
            'heading'     => 'Staff assistant',
            'sub'         => 'Give your team a faster way to look things up.',
            'icon'        => 'fa-solid fa-user-tie',
            'intro'       => 'Hi! Ask me across invoices, claims, settlements and your product list.',
            'suggestions' => ['Which invoices are overdue?', 'Show unmatched payments', 'Summarise this month\'s claims'],
            'notes'       => [
                ['fa-solid fa-chart-pie', 'Finance-wide view', 'Answers drawn from the workspace'],
                ['fa-solid fa-eye', 'Read-only first', 'Look-ups before any write action'],
                ['fa-solid fa-clipboard-check', 'Auditable', 'Every request stays in the app session'],
            ],
            'settingsUrl' => url('staff', ['action' => 'settings']),
        ]);
    }
}
