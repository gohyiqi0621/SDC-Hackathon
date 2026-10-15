<?php
/**
 * google.php - minimal Google OAuth 2.0 (authorization-code) client.
 *
 * Hand-rolled to match app/ai.php and keep the project Composer-free. The flow:
 * redirect to Google -> user consents -> Google calls back with a code -> we
 * exchange it for an access token -> we read the profile from userinfo.
 *
 * Transport: cURL when the extension is present, otherwise an HTTPS stream
 * context. On this machine the curl extension is not loaded inside Apache, so
 * the stream path is what actually runs - exactly like app/ai.php.
 */
declare(strict_types=1);

function google_configured(): bool
{
    return GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '';
}

/**
 * Append a line to storage/logs/google.log. Never throws, never blocks the flow.
 * Diagnostics only - it must never log the full client secret or access token.
 */
function google_log(string $message, array $context = []): void
{
    $dir = APP_ROOT . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    @file_put_contents($dir . '/google.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * One HTTP call. Returns [status, decodedBody|null]; status 0 means transport
 * failure. Never throws.
 *
 * @param array<string,string> $fields POST fields, or [] for a GET
 * @param array<int,string>    $headers extra request headers
 * @return array{0:int,1:?array<string,mixed>}
 */
function google_request(string $url, array $fields = [], array $headers = []): array
{
    $method = $fields !== [] ? 'POST' : 'GET';
    $body   = $fields !== [] ? http_build_query($fields) : null;

    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            error_log('[google] cURL error: ' . curl_error($ch));
            curl_close($ch);
            return [0, null];
        }
        curl_close($ch);
    } else {
        $headerLines = implode("\r\n", array_merge($headers, ['Accept: application/json'])) . "\r\n";
        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => $headerLines,
                'content'       => $body ?? '',
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $status   = 0;
        if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($response === false) {
            error_log('[google] stream request to ' . $url . ' failed');
            return [0, null];
        }
    }

    $decoded = json_decode((string) $response, true);
    return [$status, is_array($decoded) ? $decoded : null];
}

/** Swap the authorization code for an access token. Returns the token, or null. */
function google_exchange_code(string $code): ?string
{
    [$status, $data] = google_request('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);

    if ($status < 200 || $status >= 300 || $data === null) {
        return null;
    }

    $token = $data['access_token'] ?? null;
    return is_string($token) && $token !== '' ? $token : null;
}

/**
 * Read the profile for an access token.
 *
 * @return array{sub?:string,name?:string,email?:string,picture?:string}|null
 */
function google_userinfo(string $accessToken): ?array
{
    [$status, $data] = google_request(
        'https://openidconnect.googleapis.com/v1/userinfo',
        [],
        ['Authorization: Bearer ' . $accessToken]
    );

    if ($status < 200 || $status >= 300 || $data === null) {
        return null;
    }

    return $data;
}
