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

use Src\Models\Product;
use Src\Services\BrevoMailer;

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
     * ever missing (404), the page falls back to a clearly labelled demo mode.
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
            // Telegram (telegram_integrations)
            'telegramGet'         => $to('telegram'),                   // GET
            'telegramConnect'     => $to('telegram_connect'),           // POST
            'telegramTest'        => $to('telegram_test'),              // POST
            'telegramDisconnect'  => $to('telegram_disconnect'),        // POST
            // Google Calendar (google_calendar_integrations)
            'calendarGet'         => $to('google_calendar'),            // GET
            'calendarOauthStart'  => $to('google_calendar_oauth_start'),// GET -> {auth_url}
            'calendarCalendars'   => $to('google_calendar_calendars'),  // GET
            'calendarSave'        => $to('google_calendar_save'),       // POST
            'calendarTest'        => $to('google_calendar_test'),       // POST
            'calendarDisconnect'  => $to('google_calendar_disconnect'), // POST
        ];
    }

    /** GET /?page=customer&action=chat */
    public function customerChat(): void
    {
        view('chatbot/chat', [
            'user'        => require_login(),
            'title'       => 'Customer Chat Interface',
            'heading'     => 'Customer assistant',
            'sub'         => 'Answer customer questions about your products, services and orders.',
            'icon'        => 'fa-solid fa-robot',
            'intro'       => 'Hi! I can help customers with questions about your products, services, prices and orders.',
            'suggestions' => ['What products do you sell?', 'Is there any promotion now?', 'How much is delivery?'],
            'notes'       => [
                ['fa-solid fa-box-open', 'Uses your catalogue', 'Answers come from Product & Service'],
                ['fa-solid fa-user-shield', 'Your data only', 'No other business\'s data is reachable'],
                ['fa-solid fa-server', 'Server decides', 'The browser never chooses which records are read'],
            ],
            'settingsUrl' => url('customer', ['action' => 'settings']),
        ]);
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
