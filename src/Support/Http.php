<?php
/**
 * Http.php - tiny JSON-over-HTTPS client for the Telegram and Google APIs.
 *
 * Uses cURL (enabled in XAMPP) and the CA bundle from ca_bundle_path(), so
 * HTTPS works on Windows too. Never logs request bodies or headers: they can
 * contain bot tokens and OAuth secrets.
 */
declare(strict_types=1);

namespace Src\Support;

use RuntimeException;

final class Http
{
    /**
     * @param array<string, string>        $headers
     * @param array<string, mixed>|string|null $body  array = form-encoded, string = sent as-is
     * @return array{status: int, json: mixed, raw: string}
     * @throws RuntimeException on a network failure (no HTTP response at all)
     */
    public static function request(string $method, string $url, array $headers = [], array|string|null $body = null, int $timeout = 15): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is not enabled (php.ini: extension=curl).');
        }

        $lines = [];
        foreach ($headers + ['Accept' => 'application/json'] as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ];

        if ($body !== null) {
            // An array with a file (CURLFile) is sent as multipart/form-data (e.g. a photo to Telegram).
            $hasFile = is_array($body) && array_filter($body, static fn ($v) => $v instanceof \CURLFile) !== [];
            $options[CURLOPT_POSTFIELDS] = is_array($body) && !$hasFile ? http_build_query($body) : $body;
        }

        $ca = ca_bundle_path();
        if ($ca !== null) {
            $options[CURLOPT_CAINFO] = $ca;
        }

        curl_setopt_array($ch, $options);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Could not reach ' . (string) parse_url($url, PHP_URL_HOST) . ': ' . $error);
        }

        return [
            'status' => $status,
            'json'   => json_decode((string) $raw, true),
            'raw'    => (string) $raw,
        ];
    }

    /** @return array{status: int, json: mixed, raw: string} */
    public static function postJson(string $url, array $payload, array $headers = []): array
    {
        return self::request('POST', $url, $headers + ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
