<?php
/**
 * ChatbotController.php - the AI Chatbot section: Customer and Staff.
 *
 * Both audiences share one view (chatbot/index.php); this controller only
 * supplies the wording. The AI service itself is the next integration step.
 */
declare(strict_types=1);

namespace Src\Controllers;

final class ChatbotController
{
    /** GET /?page=chatbot&action=customer */
    public function customer(): void
    {
        $this->render([
            'title'       => 'Customer Chatbot',
            'heading'     => 'Customer assistant',
            'sub'         => 'Answer customer questions about invoices, claims and payments.',
            'icon'        => 'fa-solid fa-robot',
            'intro'       => 'Hi! I can help customers with questions about their invoices, claims and payments.',
            'suggestions' => ['Where is my invoice?', 'What does my claim status mean?', 'When will my payment settle?'],
            'notes'       => [
                ['fa-solid fa-lock', 'Signed-in only', 'Every answer is tied to your account'],
                ['fa-solid fa-user-shield', 'Own records', 'No other customer\'s data is reachable'],
                ['fa-solid fa-server', 'Server decides', 'The browser never chooses which records are read'],
            ],
            'switchLabel' => 'Staff assistant',
            'switchIcon'  => 'fa-solid fa-user-tie',
            'switchUrl'   => url('chatbot', ['action' => 'staff']),
        ]);
    }

    /** GET /?page=chatbot&action=staff */
    public function staff(): void
    {
        $this->render([
            'title'       => 'Staff Chatbot',
            'heading'     => 'Staff assistant',
            'sub'         => 'Give the finance team a faster way to look things up.',
            'icon'        => 'fa-solid fa-user-tie',
            'intro'       => 'Hi! Ask me across invoices, claims and settlements.',
            'suggestions' => ['Which invoices are overdue?', 'Show unmatched payments', 'Summarise this month\'s claims'],
            'notes'       => [
                ['fa-solid fa-chart-pie', 'Finance-wide view', 'Answers drawn from the workspace'],
                ['fa-solid fa-eye', 'Read-only first', 'Look-ups before any write action'],
                ['fa-solid fa-clipboard-check', 'Auditable', 'Every request stays in the app session'],
            ],
            'switchLabel' => 'Customer assistant',
            'switchIcon'  => 'fa-solid fa-user',
            'switchUrl'   => url('chatbot', ['action' => 'customer']),
        ]);
    }

    private function render(array $data): void
    {
        view('chatbot/index', $data + ['user' => require_login()]);
    }
}
