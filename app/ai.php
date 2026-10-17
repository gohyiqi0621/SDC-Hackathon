<?php
/**
 * ai.php - the only place that talks to the Python service.
 *
 * callAiService() never throws and never returns a broken shape: if Python is
 * down, slow, or returns nonsense, you get ok=false plus a message that is safe
 * to show in the UI. The panel therefore cannot crash because of this file.
 */
declare(strict_types=1);

/**
 * @param string $endpoint e.g. 'predict' or 'health'
 * @param array  $payload  JSON body for POST requests
 * @param string $method   'POST' or 'GET'
 *
 * @return array{ok:bool,status:int,data:array<string,mixed>,error:string,via:string}
 */
function callAiService(string $endpoint, array $payload = [], string $method = 'POST'): array
{
    $url    = AI_SERVICE_URL . '/' . ltrim($endpoint, '/');
    $method = strtoupper($method) === 'GET' ? 'GET' : 'POST';

    if ($method === 'GET' && $payload !== []) {
        $url .= '?' . http_build_query($payload);
    }

    // shortcut: cURL is the spec's choice but is not loaded inside Apache on
    // this machine, so fall back to a stream context. Same timeout, same
    // error handling. Drop the fallback once curl is fixed.
    $result = function_exists('curl_init')
        ? aiRequestViaCurl($url, $payload, $method)
        : aiRequestViaStream($url, $payload, $method);

    if ($result['body'] === null) {
        // The technical detail goes to the log, not to the visitor: the message
        // below is rendered in the AI panel, and AI_SERVICE_URL is an internal
        // hostname nobody outside the deployment should ever see.
        error_log('[ai] service unreachable at ' . AI_SERVICE_URL . ' (via ' . $result['via'] . ')');

        return [
            'ok'     => false,
            'status' => 0,
            'data'   => [],
            'error'  => 'The AI service is not reachable right now. Please try again in a moment.',
            'via'    => $result['via'],
        ];
    }

    $data = json_decode($result['body'], true);
    if (!is_array($data)) {
        return [
            'ok'     => false,
            'status' => $result['status'],
            'data'   => [],
            'error'  => 'The AI service returned a response that was not JSON.',
            'via'    => $result['via'],
        ];
    }

    if ($result['status'] >= 400) {
        $message = is_string($data['error'] ?? null)
            ? (string) $data['error']
            : 'The AI service returned HTTP ' . $result['status'] . '.';

        return ['ok' => false, 'status' => $result['status'], 'data' => $data, 'error' => $message, 'via' => $result['via']];
    }

    return ['ok' => true, 'status' => $result['status'], 'data' => $data, 'error' => '', 'via' => $result['via']];
}

/**
 * @return array{body:?string,status:int,via:string}
 */
function aiRequestViaCurl(string $url, array $payload, string $method): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(5, AI_TIMEOUT),
        CURLOPT_TIMEOUT        => AI_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    ]);

    if ($method === 'POST') {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $body   = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $failed = $body === false;
    if ($failed) {
        error_log('[ai] cURL error: ' . curl_error($curl));
    }
    curl_close($curl);

    return ['body' => $failed ? null : (string) $body, 'status' => $status, 'via' => 'curl'];
}

/**
 * @return array{body:?string,status:int,via:string}
 */
function aiRequestViaStream(string $url, array $payload, string $method): array
{
    $context = stream_context_create([
        'http' => [
            'method'        => $method,
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content'       => $method === 'POST' ? json_encode($payload) : '',
            'timeout'       => AI_TIMEOUT,
            'ignore_errors' => true,   // still read the body of a 4xx/5xx reply
        ],
    ]);

    // $http_response_header is filled in by file_get_contents in this scope.
    $body   = @file_get_contents($url, false, $context);
    $status = 0;

    if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $match)) {
        $status = (int) $match[1];
    }

    if ($body === false) {
        error_log('[ai] stream request to ' . $url . ' failed');
    }

    return ['body' => $body === false ? null : (string) $body, 'status' => $status, 'via' => 'stream'];
}
