<?php
/**
 * HttpClient.php - small HTTPS client for external APIs (Google / Gmail).
 *
 * Uses cURL when available (with parallel GETs via curl_multi), otherwise the
 * HTTPS stream wrapper. Uses the same CA bundle lookup as the Brevo mailer so
 * it works on Windows/XAMPP. Never throws: failures come back as status 0.
 */
declare(strict_types=1);

namespace Src\Support;

final class HttpClient
{
    private const TIMEOUT = 20;

    /** @return array{status:int, body:?array<string,mixed>} */
    public static function get(string $url, array $headers = []): array
    {
        return self::send('GET', $url, $headers, null);
    }

    /** POST application/x-www-form-urlencoded (what OAuth token endpoints expect). */
    public static function postForm(string $url, array $fields, array $headers = []): array
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';

        return self::send('POST', $url, $headers, http_build_query($fields));
    }

    /**
     * Several GETs at once (in parallel with cURL). Keys are preserved.
     *
     * @param array<string|int, string> $urls
     * @return array<string|int, array{status:int, body:?array<string,mixed>}>
     */
    public static function getMany(array $urls, array $headers = []): array
    {
        if (!function_exists('curl_multi_init')) {
            $results = [];
            foreach ($urls as $key => $url) {
                $results[$key] = self::get($url, $headers);
            }

            return $results;
        }

        $multi   = curl_multi_init();
        $handles = [];

        foreach ($urls as $key => $url) {
            $handles[$key] = self::curlHandle('GET', $url, $headers, null);
            curl_multi_add_handle($multi, $handles[$key]);
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $key => $ch) {
            $raw = curl_multi_getcontent($ch);
            $results[$key] = self::result((int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $raw);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);

        return $results;
    }

    /** @return array{status:int, body:?array<string,mixed>} */
    private static function send(string $method, string $url, array $headers, ?string $body): array
    {
        if (function_exists('curl_init')) {
            $ch  = self::curlHandle($method, $url, $headers, $body);
            $raw = curl_exec($ch);

            if ($raw === false) {
                error_log('[http] ' . $method . ' ' . parse_url($url, PHP_URL_HOST) . ' failed: ' . curl_error($ch));
                curl_close($ch);

                return ['status' => 0, 'body' => null];
            }

            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return self::result($status, $raw);
        }

        $options = [
            'method'        => $method,
            'header'        => implode("\r\n", $headers) . "\r\n",
            'timeout'       => self::TIMEOUT,
            'ignore_errors' => true,
        ];
        if ($body !== null) {
            $options['content'] = $body;
        }

        $ca  = ca_bundle_path();
        $ssl = $ca !== null ? ['cafile' => $ca, 'verify_peer' => true, 'verify_peer_name' => true] : [];

        $raw    = @file_get_contents($url, false, stream_context_create(['http' => $options, 'ssl' => $ssl]));
        $status = 0;

        if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        if ($raw === false) {
            error_log('[http] ' . $method . ' ' . parse_url($url, PHP_URL_HOST) . ' failed: ' . (error_get_last()['message'] ?? 'unknown'));

            return ['status' => 0, 'body' => null];
        }

        return self::result($status, $raw);
    }

    /** @return \CurlHandle */
    private static function curlHandle(string $method, string $url, array $headers, ?string $body)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $ca = ca_bundle_path();
        if ($ca !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }

        return $ch;
    }

    /** @return array{status:int, body:?array<string,mixed>} */
    private static function result(int $status, mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null];
    }
}
