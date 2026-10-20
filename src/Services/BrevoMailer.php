<?php
/**
 * BrevoMailer.php - transactional email through Brevo's HTTP API.
 *
 * Credentials come from .env (BREVO_API_KEY, BREVO_SENDER_EMAIL,
 * BREVO_SENDER_NAME). The sender must be verified in Brevo. Nothing here
 * throws: a mail failure returns ['ok' => false, 'error' => '...'] and the
 * caller decides what the visitor sees, so a mail outage never takes a page
 * down.
 *
 * Mail bodies live in src/Views/emails/. The dashboard's Brevo strip
 * (DashboardController::testEmail) uses sendTest() to check the connection.
 */
declare(strict_types=1);

namespace Src\Services;

final class BrevoMailer
{
    public function isConfigured(): bool
    {
        return BREVO_API_KEY !== '' && BREVO_SENDER_EMAIL !== '';
    }

    /** Non-secret state for a status line / log. */
    public function configState(): array
    {
        return [
            'api_key_set'      => BREVO_API_KEY !== '',
            'sender_email_set' => BREVO_SENDER_EMAIL !== '',
            'sender_name_set'  => BREVO_SENDER_NAME !== '',
        ];
    }

    /**
     * Send the password-reset link.
     *
     * @return array{ok:bool, status:int, error:?string, message_id:?string}
     */
    public function sendPasswordReset(array $user, string $resetUrl, int $ttlMinutes): array
    {
        $name = trim((string) ($user['name'] ?? ''));

        $html = $this->render('password_reset', [
            'name'         => $name,
            'email'        => (string) ($user['email'] ?? ''),
            'resetUrl'     => $resetUrl,
            'ttlMinutes'   => $ttlMinutes,
            'appName'      => APP_NAME,
        ]);

        $text = $this->render('password_reset_text', [
            'name'       => $name,
            'resetUrl'   => $resetUrl,
            'ttlMinutes' => $ttlMinutes,
            'appName'    => APP_NAME,
        ]);

        return $this->send(
            (string) $user['email'],
            $name,
            'Reset your ' . APP_NAME . ' password',
            $html,
            $text
        );
    }

    /**
     * A small self-test used by the email dashboard.
     *
     * @return array{ok:bool, status:int, error:?string, message_id:?string}
     */
    public function sendTest(array $user): array
    {
        $html = $this->render('test', [
            'name'    => (string) ($user['name'] ?? ''),
            'appName' => APP_NAME,
        ]);

        $text = 'This is a test email from ' . APP_NAME . ". Brevo delivery is working.\n";

        return $this->send(
            (string) $user['email'],
            (string) ($user['name'] ?? ''),
            APP_NAME . ' email test',
            $html,
            $text
        );
    }

    /**
     * One transactional send.
     *
     * @return array{ok:bool, status:int, error:?string, message_id:?string}
     */
    public function send(string $toEmail, string $toName, string $subject, string $html, string $text = ''): array
    {
        if (!$this->isConfigured()) {
            $this->log('send refused: not configured', ['to' => $toEmail]);

            return ['ok' => false, 'status' => 0, 'error' => 'not_configured', 'message_id' => null];
        }

        $payload = [
            'sender' => [
                'name'  => BREVO_SENDER_NAME !== '' ? BREVO_SENDER_NAME : APP_NAME,
                'email' => BREVO_SENDER_EMAIL,
            ],
            'to' => [[
                'email' => $toEmail,
                'name'  => $toName !== '' ? $toName : $toEmail,
            ]],
            'subject'     => $subject,
            'htmlContent' => $html,
        ];

        if ($text !== '') {
            $payload['textContent'] = $text;
        }

        $response = $this->request(BREVO_API_URL, $payload, BREVO_API_KEY);
        $status   = $response['status'];
        $body     = $response['body'];

        // Nothing reached Brevo: one retry cannot duplicate a message.
        if ($status === 0) {
            $this->log('transport failed, retrying once', ['to' => $toEmail]);
            $response = $this->request(BREVO_API_URL, $payload, BREVO_API_KEY);
            $status   = $response['status'];
            $body     = $response['body'];
        }

        $messageId = is_array($body) && isset($body['messageId'])
            ? (string) $body['messageId']
            : null;

        if ($status >= 200 && $status < 300 && $messageId !== null) {
            $this->log('sent', ['to' => $toEmail, 'status' => $status, 'message_id' => $messageId]);

            return ['ok' => true, 'status' => $status, 'error' => null, 'message_id' => $messageId];
        }

        $error = is_array($body) && isset($body['code'])
            ? (string) $body['code']
            : ($status === 0 ? 'transport' : 'http_' . $status);

        $this->log('send failed', [
            'to'      => $toEmail,
            'status'  => $status,
            'error'   => $error,
            'message' => is_array($body) ? ($body['message'] ?? null) : null,
        ]);

        return ['ok' => false, 'status' => $status, 'error' => $error, 'message_id' => null];
    }

    /**
     * JSON POST to Brevo. cURL when present, otherwise the HTTPS stream
     * wrapper. Never throws.
     *
     * @return array{status:int, body:?array<string,mixed>}
     */
    private function request(string $url, array $payload, string $apiKey): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $this->log('could not encode the request body');

            return ['status' => 0, 'body' => null];
        }

        $headers = [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $apiKey,
        ];

        $ca = ca_bundle_path();

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 20,
            ]);
            if ($ca !== null) {
                curl_setopt($ch, CURLOPT_CAINFO, $ca);
            }

            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($raw === false) {
                $this->log('cURL error: ' . curl_error($ch));
                curl_close($ch);

                return ['status' => 0, 'body' => null];
            }

            curl_close($ch);
        } else {
            $options = [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers) . "\r\n",
                'content'       => $json,
                'timeout'       => 20,
                'ignore_errors' => true,
            ];
            if ($ca !== null) {
                $options['ssl'] = ['cafile' => $ca, 'verify_peer' => true, 'verify_peer_name' => true];
            }

            $raw    = @file_get_contents($url, false, stream_context_create(['http' => $options]));
            $status = 0;

            if (isset($http_response_header[0])
                && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }

            if ($raw === false) {
                $this->log('stream request failed: ' . (error_get_last()['message'] ?? 'unknown'));

                return ['status' => 0, 'body' => null];
            }
        }

        $decoded = json_decode((string) $raw, true);

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null];
    }

    /** Render one template from src/Views/emails/ and return its output. */
    private function render(string $template, array $data): string
    {
        $file = APP_ROOT . '/src/Views/emails/' . $template . '.php';

        if (!is_file($file)) {
            $this->log('template missing', ['template' => $template]);
            return '';
        }

        ob_start();
        extract($data, EXTR_SKIP);
        require $file;

        return (string) ob_get_clean();
    }

    /** Append a line to storage/logs/mail.log. Never throws. */
    private function log(string $message, array $context = []): void
    {
        $dir = STORAGE_ROOT . '/logs';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;

        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        @file_put_contents($dir . '/mail.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
