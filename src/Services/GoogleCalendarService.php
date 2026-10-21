<?php
/**
 * GoogleCalendarService.php - Google OAuth 2.0 + Calendar API (server side only).
 *
 * Setup (once): in Google Cloud Console create an OAuth client of type
 * "Web application", enable the Google Calendar API, and add this
 * Authorized redirect URI (see redirectUri()):
 *
 *     http://localhost/SDC-Hackathon/public/ai_settings/google_calendar_oauth_callback
 *
 * then put GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env.
 *
 * Tokens never leave the server: they are encrypted with Crypto before they
 * are stored, and the browser only ever sees the account email and status.
 */
declare(strict_types=1);

namespace Src\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Src\Support\Http;

final class GoogleCalendarService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/calendar.readonly',     // list calendars, free/busy
        'https://www.googleapis.com/auth/calendar.events',       // read and create bookings
    ];

    /** Opening hours used by the availability test (local time in the chosen timezone). */
    private const DAY_START = 9;
    private const DAY_END   = 18;

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function redirectUri(): string
    {
        $configured = trim((string) env('GOOGLE_REDIRECT_URI', ''));

        // Clean path (public/.htaccess maps it to ?page=ai_settings&action=...):
        // Google needs an exact, query-free redirect URI.
        return $configured !== '' ? $configured : APP_URL . '/ai_settings/google_calendar_oauth_callback';
    }

    /** The Google sign-in URL. $state and the PKCE verifier are kept in the session by the caller. */
    public function authUrl(string $state, string $codeVerifier): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'              => $this->clientId(),
            'redirect_uri'           => $this->redirectUri(),
            'response_type'          => 'code',
            'scope'                  => implode(' ', self::SCOPES),
            'access_type'            => 'offline',   // we need a refresh token
            'prompt'                 => 'consent',   // ...every time, even on reconnect
            'include_granted_scopes' => 'true',
            'state'                  => $state,
            'code_challenge'         => rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '='),
            'code_challenge_method'  => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{access_token: string, expires_in: int, refresh_token?: string}
     * @throws RuntimeException
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        return $this->tokenRequest([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri(),
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * @return array{access_token: string, expires_in: int}
     * @throws GoogleAuthRevoked when Google no longer accepts the refresh token
     * @throws RuntimeException  on any other failure
     */
    public function refresh(string $refreshToken): array
    {
        return $this->tokenRequest([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    public function revoke(string $token): void
    {
        try {
            Http::request('POST', $this->oauthBase() . '/revoke', ['Content-Type' => 'application/x-www-form-urlencoded'], ['token' => $token]);
        } catch (RuntimeException) {
            // Best effort: the tokens are deleted locally either way.
        }
    }

    public function accountEmail(string $accessToken): string
    {
        $response = $this->api('GET', '/oauth2/v3/userinfo', $accessToken);

        return (string) ($response['email'] ?? '');
    }

    /** @return array<int, array{id: string, summary: string, primary: bool}> */
    public function calendars(string $accessToken): array
    {
        $response = $this->api('GET', '/calendar/v3/users/me/calendarList?' . http_build_query(['minAccessRole' => 'writer']), $accessToken);
        $list = [];

        foreach ((array) ($response['items'] ?? []) as $item) {
            if (!isset($item['id'])) {
                continue;
            }
            $list[] = [
                'id'      => (string) $item['id'],
                'summary' => (string) ($item['summaryOverride'] ?? $item['summary'] ?? $item['id']),
                'primary' => !empty($item['primary']),
            ];
        }

        usort($list, static fn (array $a, array $b): int => [$b['primary'], $a['summary']] <=> [$a['primary'], $b['summary']]);

        return $list;
    }

    /**
     * Next free slots of $minutes within opening hours over the coming week.
     *
     * @return array<int, array{start: string, end: string}>
     */
    public function freeSlots(string $accessToken, string $calendarId, string $timezone, int $minutes, int $limit = 5): array
    {
        $zone = new DateTimeZone($timezone);
        $now  = new DateTimeImmutable('now', $zone);
        $end  = $now->add(new DateInterval('P7D'));

        $response = $this->api('POST', '/calendar/v3/freeBusy', $accessToken, [
            'timeMin'  => $now->format(DATE_RFC3339),
            'timeMax'  => $end->format(DATE_RFC3339),
            'timeZone' => $timezone,
            'items'    => [['id' => $calendarId]],
        ]);

        $calendar = $response['calendars'][$calendarId] ?? null;
        if (!is_array($calendar)) {
            throw new RuntimeException('Google did not return this calendar. Choose another calendar and save first.');
        }
        if (!empty($calendar['errors'])) {
            throw new RuntimeException('Google could not read this calendar (' . (string) ($calendar['errors'][0]['reason'] ?? 'unknown') . ').');
        }

        $busy = [];
        foreach ((array) ($calendar['busy'] ?? []) as $period) {
            $busy[] = [new DateTimeImmutable((string) $period['start']), new DateTimeImmutable((string) $period['end'])];
        }

        // Start at the next quarter hour.
        $minute = (int) $now->format('i');
        $cursor = $now->setTime((int) $now->format('H'), $minute - $minute % 15)->add(new DateInterval('PT15M'));
        $step   = new DateInterval('PT' . $minutes . 'M');
        $slots  = [];

        while ($cursor < $end && count($slots) < $limit) {
            $hour = (int) $cursor->format('G');

            if ($hour < self::DAY_START) {
                $cursor = $cursor->setTime(self::DAY_START, 0);
                continue;
            }

            $slotEnd = $cursor->add($step);
            $closing = $cursor->setTime(self::DAY_END, 0);

            if ($slotEnd > $closing) {
                $cursor = $cursor->add(new DateInterval('P1D'))->setTime(self::DAY_START, 0);
                continue;
            }

            $clash = null;
            foreach ($busy as [$busyStart, $busyEnd]) {
                if ($cursor < $busyEnd && $slotEnd > $busyStart) {
                    $clash = $busyEnd;
                    break;
                }
            }

            if ($clash !== null) {
                $cursor = $clash->setTimezone($zone);
                continue;
            }

            $slots[] = ['start' => $cursor->format(DATE_ATOM), 'end' => $slotEnd->format(DATE_ATOM)];
            $cursor  = $slotEnd;
        }

        return $slots;
    }

    /**
     * Events between two times (recurring events expanded), not cancelled.
     *
     * @return list<array> Google event resources
     */
    public function events(string $accessToken, string $calendarId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $items = [];
        $page  = null;
        do {
            $query = [
                'timeMin'      => $from->format(DATE_RFC3339),
                'timeMax'      => $to->format(DATE_RFC3339),
                'singleEvents' => 'true',
                'orderBy'      => 'startTime',
                'maxResults'   => 250,
            ] + ($page !== null ? ['pageToken' => $page] : []);
            $response = $this->api('GET', '/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events?' . http_build_query($query), $accessToken);
            foreach ((array) ($response['items'] ?? []) as $item) {
                if (($item['status'] ?? '') !== 'cancelled') {
                    $items[] = $item;
                }
            }
            $page = $response['nextPageToken'] ?? null;
        } while ($page !== null && count($items) < 2000);

        return $items;
    }

    /** Create an event; returns the created resource (with its id). */
    public function insertEvent(string $accessToken, string $calendarId, array $event): array
    {
        return $this->api('POST', '/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events', $accessToken, $event);
    }

    /** Delete an event. Returns true when deleted (or already gone). */
    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): bool
    {
        $response = Http::request('DELETE',
            $this->apiBase() . '/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
            ['Authorization' => 'Bearer ' . $accessToken]);

        if ($response['status'] === 401) {
            throw new GoogleAuthRevoked('Google no longer accepts the saved access. Connect the account again.');
        }
        return in_array($response['status'], [200, 204, 404, 410], true);
    }

    /* ------------------------------------------------------------ helpers --- */

    private function clientId(): string
    {
        return trim((string) env('GOOGLE_CLIENT_ID', ''));
    }

    private function clientSecret(): string
    {
        return trim((string) env('GOOGLE_CLIENT_SECRET', ''));
    }

    // The two *_BASE settings exist only for local testing against a fake server.
    private function oauthBase(): string
    {
        return rtrim((string) env('GOOGLE_OAUTH_BASE', 'https://oauth2.googleapis.com'), '/');
    }

    private function apiBase(): string
    {
        return rtrim((string) env('GOOGLE_API_BASE', 'https://www.googleapis.com'), '/');
    }

    private function tokenRequest(array $params): array
    {
        $response = Http::request('POST', $this->oauthBase() . '/token', ['Content-Type' => 'application/x-www-form-urlencoded'], $params + [
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]);

        $json = is_array($response['json']) ? $response['json'] : [];

        if ($response['status'] !== 200 || empty($json['access_token'])) {
            $error = (string) ($json['error'] ?? 'unknown_error');
            if ($error === 'invalid_grant' && ($params['grant_type'] ?? '') === 'refresh_token') {
                throw new GoogleAuthRevoked('Google no longer accepts the saved access. Connect the account again.');
            }
            throw new RuntimeException('Google refused the request (' . $error . ').');
        }

        return [
            'access_token'  => (string) $json['access_token'],
            'expires_in'    => (int) ($json['expires_in'] ?? 3600),
        ] + (isset($json['refresh_token']) ? ['refresh_token' => (string) $json['refresh_token']] : []);
    }

    /** @throws GoogleAuthRevoked on 401, RuntimeException on anything else */
    private function api(string $method, string $path, string $accessToken, ?array $json = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $accessToken];
        $response = $json === null
            ? Http::request($method, $this->apiBase() . $path, $headers)
            : Http::postJson($this->apiBase() . $path, $json, $headers);

        if ($response['status'] === 401) {
            throw new GoogleAuthRevoked('Google no longer accepts the saved access. Connect the account again.');
        }
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
            $message = is_array($response['json']) ? (string) ($response['json']['error']['message'] ?? '') : '';
            throw new RuntimeException('Google Calendar returned an error' . ($message !== '' ? ': ' . $message : ' (HTTP ' . $response['status'] . ').'));
        }

        return $response['json'];
    }
}
