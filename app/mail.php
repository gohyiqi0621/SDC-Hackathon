<?php
/**
 * mail.php - transactional email through the Brevo (ex-Sendinblue) API.
 *
 * Hand-rolled to match app/google.php and app/ai.php: no Composer, no SMTP
 * sockets, no PHPMailer. One HTTPS POST to Brevo's /v3/smtp/email endpoint with
 * a JSON body, authenticated by the `api-key` header.
 *
 * Transport: cURL when the extension is present, otherwise an HTTPS stream
 * context. On this machine the curl extension is NOT loaded inside Apache, so
 * the stream path is what actually runs locally - exactly like google.php.
 *
 * Credentials come from the Key page (the `settings` table), never from .env:
 *   BREVO_API_KEY       secret, sealed with AES-256-GCM
 *   BREVO_SENDER_EMAIL  plain, must be a verified sender in Brevo
 *   BREVO_SENDER_NAME   plain, optional display name
 *
 * Nothing in this file throws. A mail failure must never take a page down; the
 * caller gets ['ok' => false, 'error' => ...] and decides what to tell the user.
 */
declare(strict_types=1);

/** Brevo's single transactional-send endpoint. */
function brevo_endpoint(): string
{
    return 'https://api.brevo.com/v3/smtp/email';
}

/**
 * Is there enough configuration to attempt a send?
 *
 * The API key and a sender address are both mandatory: Brevo rejects a message
 * whose sender is not a verified sender on the account, so an empty sender is
 * a configuration error rather than something to try.
 */
function brevo_configured(): bool
{
    return BREVO_API_KEY !== '' && BREVO_SENDER_EMAIL !== '';
}

/**
 * Non-secret diagnostics for logs. Never contains the key itself.
 *
 * @return array<string,mixed>
 */
function brevo_config_state(): array
{
    return [
        'source'            => 'database',
        'api_key_set'       => BREVO_API_KEY !== '',
        'secret_shape'      => secret_shape(BREVO_API_KEY),
        'sender_email_set'  => BREVO_SENDER_EMAIL !== '',
        'sender_name_set'   => BREVO_SENDER_NAME !== '',
    ];
}

/** Append a line to storage/logs/mail.log. Never throws, never blocks. */
function mail_log(string $message, array $context = []): void
{
    $dir = APP_ROOT . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    @file_put_contents($dir . '/mail.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * One JSON POST. Returns [status, decodedBody|null]; status 0 means the
 * request never reached Brevo. Never throws.
 *
 * The URL is a parameter (not read from a constant) so the transport can be
 * exercised against a local stub without touching the real endpoint.
 *
 * @return array{status:int,body:?array<string,mixed>}
 */
function brevo_request(string $url, array $payload, string $apiKey): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        error_log('[brevo] could not encode the request body');
        return ['status' => 0, 'body' => null];
    }

    $headers = [
        'accept: application/json',
        'content-type: application/json',
        'api-key: ' . $apiKey,
    ];

    // Windows has no CA store; without this every call dies at the TLS
    // handshake. Null on Linux, where the default store is correct.
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
            error_log('[brevo] cURL error: ' . curl_error($ch));
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
        if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($raw === false) {
            // The suppressed warning is the only clue a TLS/DNS failure leaves.
            $why = error_get_last()['message'] ?? 'unknown';
            error_log('[brevo] stream request to ' . $url . ' failed: ' . $why);
            return ['status' => 0, 'body' => null];
        }
    }

    $decoded = json_decode((string) $raw, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null];
}

/**
 * Send one transactional email.
 *
 * @return array{ok:bool,status:int,error:?string,message_id:?string}
 *         `error` is a short machine-ish code for the caller ('not_configured',
 *         'transport', Brevo's own error code) - never shown to a visitor.
 */
function brevo_send(string $toEmail, string $toName, string $subject, string $html, string $text = ''): array
{
    if (!brevo_configured()) {
        mail_log('send refused: not configured', ['to' => $toEmail]);
        return ['ok' => false, 'status' => 0, 'error' => 'not_configured', 'message_id' => null];
    }

    $payload = [
        'sender' => [
            'name'  => BREVO_SENDER_NAME !== '' ? BREVO_SENDER_NAME : APP_NAME,
            'email' => BREVO_SENDER_EMAIL,
        ],
        'to'          => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
        'subject'     => $subject,
        'htmlContent' => $html,
    ];
    if ($text !== '') {
        $payload['textContent'] = $text;
    }

    $res    = brevo_request(brevo_endpoint(), $payload, BREVO_API_KEY);
    $status = $res['status'];
    $body   = $res['body'];

    // status 0 means the request never got an HTTP reply (DNS, TLS, socket).
    // One retry is worth it and cannot duplicate a message: nothing reached
    // Brevo, so there is nothing to send twice.
    if ($status === 0) {
        mail_log('transport failed, retrying once', ['to' => $toEmail]);
        $res    = brevo_request(brevo_endpoint(), $payload, BREVO_API_KEY);
        $status = $res['status'];
        $body   = $res['body'];
    }

    $messageId = is_array($body) && isset($body['messageId']) ? (string) $body['messageId'] : null;

    if ($status >= 200 && $status < 300 && $messageId !== null) {
        mail_log('sent', ['to' => $toEmail, 'status' => $status, 'message_id' => $messageId]);
        return ['ok' => true, 'status' => $status, 'error' => null, 'message_id' => $messageId];
    }

    // Brevo returns {code, message}; log it, but never forward it to a visitor -
    // it can name the account, the key or the rejected sender.
    $error = is_array($body) && isset($body['code'])
        ? (string) $body['code']
        : ($status === 0 ? 'transport' : 'http_' . $status);

    mail_log('send failed', [
        'to'      => $toEmail,
        'status'  => $status,
        'error'   => $error,
        'message' => is_array($body) ? ($body['message'] ?? null) : null,
    ]);

    return ['ok' => false, 'status' => $status, 'error' => $error, 'message_id' => null];
}

/* ------------------------------------------------------------- templates --- */

/**
 * The one-time-code email.
 *
 * Inline styles and a table-free block layout on purpose: Gmail strips <style>
 * blocks in some clients, and a plain block stack degrades gracefully. Colours
 * are hard-coded (an email cannot read our CSS variables).
 */
function otp_email_html(string $name, string $code, int $minutes): string
{
    $greeting = $name !== '' ? 'Hi ' . e($name) . ',' : 'Hi,';
    $app      = e(APP_NAME);

    return <<<HTML
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Your verification code</title></head>
<body style="margin:0;padding:0;background:#f4f6f5;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">Your {$app} code is {$code}. It expires in {$minutes} minutes.</div>
  <div style="padding:32px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:18px;padding:32px 28px;
                box-shadow:0 1px 3px rgba(16,24,40,.08);">
      <p style="margin:0 0 4px;font-size:13px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#2E7D32;">
        {$app}
      </p>
      <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#14181c;">Reset your password</h1>
      <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#4a5259;">
        {$greeting}<br>
        Use the code below to choose a new password. It works once and expires in {$minutes} minutes.
      </p>
      <div style="margin:0 0 20px;padding:18px 12px;background:#f2f7f3;border-radius:14px;text-align:center;">
        <span style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:34px;
                     font-weight:700;letter-spacing:10px;color:#14181c;">{$code}</span>
      </div>
      <p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#4a5259;">
        Enter it on the reset page, then set your new password.
      </p>
      <p style="margin:0;font-size:13px;line-height:1.6;color:#7b848c;">
        If you did not ask to reset your password, you can ignore this email &mdash;
        your current password still works.
      </p>
    </div>
    <p style="max-width:480px;margin:16px auto 0;text-align:center;font-size:12px;color:#98a1a9;">
      Sent by {$app}. This address does not accept replies.
    </p>
  </div>
</body>
</html>
HTML;
}

/** Plain-text alternative, so the message is not HTML-only. */
function otp_email_text(string $name, string $code, int $minutes): string
{
    $greeting = $name !== '' ? 'Hi ' . $name . ',' : 'Hi,';

    return $greeting . "\n\n"
        . 'Your ' . APP_NAME . ' verification code is ' . $code . ".\n"
        . 'It works once and expires in ' . $minutes . " minutes.\n\n"
        . "If you did not ask to reset your password, ignore this email.\n";
}
