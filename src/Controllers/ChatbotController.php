<?php
/**
 * ChatbotController.php - AI Chatbot for two audiences.
 *
 *   Customer: Setting & Integration, Product & Service (ProductController), Chat Interface
 *   Staff:    Setting & Integration, Chat Interface
 *
 * Both audiences share the same two views (chatbot/settings.php and
 * chatbot/chat.php); this controller only supplies the wording.
 * The AI service and channel integrations are the next integration step.
 */
declare(strict_types=1);

namespace Src\Controllers;

use Src\Models\Product;
use Src\Services\BrevoMailer;

final class ChatbotController
{
    /** GET /?page=customer&action=settings */
    public function customerSettings(): void
    {
        $user = require_login();

        view('chatbot/settings', [
            'user'         => $user,
            'title'        => 'Customer Setting & Integration',
            'audience'     => 'Customer',
            'sub'          => 'Set up how the customer assistant talks and where customers can reach it.',
            'assistant'    => ['name' => APP_NAME . ' Assistant', 'greeting' => 'Hi! How can I help you today?'],
            'productCount' => (new Product())->countForUser((int) $user['id']),
            'integrations' => [
                ['fa-solid fa-globe',         'Website chat widget', 'Add the assistant to your website.',            false],
                ['fa-brands fa-whatsapp',     'WhatsApp Business',   'Answer customers on WhatsApp.',                 false],
                ['fa-brands fa-telegram',     'Telegram',            'Answer customers on Telegram.',                 false],
                ['fa-brands fa-facebook-messenger', 'Messenger',     'Answer customers on Facebook Messenger.',       false],
                ['fa-regular fa-envelope',    'Email (Brevo)',       'Send emails such as password resets.',          (new BrevoMailer())->isConfigured() ?: 'setup'],
            ],
            'chatUrl'      => url('customer', ['action' => 'chat']),
        ]);
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
