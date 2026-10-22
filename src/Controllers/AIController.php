<?php

declare(strict_types=1);

namespace Src\Controllers;

use Src\Services\AIService;
use Throwable;

final class AIController
{
    public function summarize(): void
    {
        require_login();

        if (!csrf_valid()) {
            json_response(false, null, 'Invalid CSRF token.', 403);
            return;
        }

        $input = json_decode(
            file_get_contents('php://input'),
            true
        );

        if (!is_array($input)) {
            json_response(false, null, 'Invalid JSON request.', 400);
            return;
        }

        $subject = $input['subject'] ?? '';
        $body = $input['body'] ?? '';

        if (!is_string($subject) || !is_string($body)) {
            json_response(false, null, 'Invalid email content.', 400);
            return;
        }

        if (strlen($subject) > 1000 || strlen($body) > 100000) {
            json_response(false, null, 'Email content is too large.', 413);
            return;
        }

        try {
            $result = (new AIService())->summarize(
                $subject,
                $body
            );

            json_response(true, $result, 'Summary generated.');
        } catch (Throwable $e) {
            error_log('[AI summarize] ' . $e->getMessage());

            json_response(
                false,
                null,
                'AI summary failed. Please try again.',
                502
            );
        }
    }

    public function regenerate(): void
    {
        require_login();

        if (!csrf_valid()) {
            json_response(false, null, 'Invalid CSRF token.', 403);
            return;
        }

        $input = json_decode(
            file_get_contents('php://input'),
            true
        );

        if (!is_array($input)) {
            json_response(false, null, 'Invalid JSON request.', 400);
            return;
        }

        $subject = $input['subject'] ?? '';
        $body = $input['body'] ?? '';
        $tone = $input['tone'] ?? 'formal';

        if (
            !is_string($subject) ||
            !is_string($body) ||
            !is_string($tone)
        ) {
            json_response(false, null, 'Invalid input.', 400);
            return;
        }

        if (strlen($subject) > 1000 || strlen($body) > 100000) {
            json_response(false, null, 'Email content is too large.', 413);
            return;
        }

        if (trim($body) === '') {
            json_response(false, null, 'Email body is empty.', 400);
            return;
        }

        try {
            $result = (new AIService())->generateReply(
                $subject,
                $body,
                $tone
            );

            json_response(true, $result, 'Reply generated.');
        } catch (Throwable $e) {
            error_log('[AI regenerate] ' . $e->getMessage());

            json_response(
                false,
                null,
                'AI reply generation failed. Please try again.',
                502
            );
        }
    }
}
