<?php

declare(strict_types=1);

namespace Src\Controllers;

use Src\Services\AIService;

final class AIController
{
    public function summarize(): void
    {
        require_login();

        $input = json_decode(
            file_get_contents('php://input'),
            true
        );

        if (!is_array($input)) {
            json_response(
                false,
                null,
                'Invalid JSON request.',
                400
            );
        }

        $subject = $input['subject'] ?? '';
        $body = $input['body'] ?? '';

        if (!is_string($subject) || !is_string($body)) {
            json_response(
                false,
                null,
                'Invalid email content.',
                400
            );
        }

        if (strlen($subject) > 1000 || strlen($body) > 100000) {
            json_response(
                false,
                null,
                'Email content is too large.',
                413
            );
        }

        $result = (new AIService())->summarize(
            $subject,
            $body
        );

        json_response(
            true,
            $result,
            'Summary generated.'
        );
    }
}