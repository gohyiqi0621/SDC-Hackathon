<?php

declare(strict_types=1);

namespace Src\Services;

final class AIService
{
    public function summarize(string $subject, string $body): array
    {
        $text = trim($subject . "\n" . $body);

        if ($text === '') {
            return [
                'summary' => ['No email content available.'],
                'category' => 'General',
                'suggestedAction' => 'No action required.',
                'needsReply' => false,
                'mode' => 'placeholder',
            ];
        }

        // Temporary logic — replace with AI API later.
        $category = 'General';

        if (preg_match('/invoice|payment|receipt|refund/i', $text)) {
            $category = 'Finance';
        } elseif (preg_match('/meeting|appointment|schedule|call/i', $text)) {
            $category = 'Meeting';
        } elseif (preg_match('/order|delivery|shipping/i', $text)) {
            $category = 'Orders';
        } elseif (preg_match('/partnership|collaboration|proposal/i', $text)) {
            $category = 'Partnership';
        }

        $needsReply = str_contains($text, '?');

        return [
            'summary' => [
                'Subject: ' . $subject,
                'Email content: ' . mb_substr(trim($body), 0, 250),
                $needsReply
                    ? 'The email may require a response.'
                    : 'Review the email for any required action.',
            ],
            'category' => $category,
            'suggestedAction' => $needsReply
                ? 'Review and reply to this email.'
                : 'Review the email.',
            'needsReply' => $needsReply,
            'mode' => 'placeholder',
        ];
    }
}