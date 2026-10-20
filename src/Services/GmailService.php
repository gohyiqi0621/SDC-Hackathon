<?php
/**
 * GmailService.php - connect a user's Gmail (OAuth 2.0) and read their inbox.
 *
 * Talks to Google's REST endpoints directly, so no Composer package is needed.
 * Scope is read-only (gmail.readonly). Tokens are stored per user in the
 * gmail_connections table and refreshed automatically when they expire.
 */
declare(strict_types=1);

namespace Src\Services;

use Src\Models\GmailConnection;
use Src\Support\HttpClient;

final class GmailService
{
    private const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL  = 'https://oauth2.googleapis.com/revoke';
    private const API_URL     = 'https://gmail.googleapis.com/gmail/v1/users/me';
    private const SCOPE       = 'https://www.googleapis.com/auth/gmail.readonly';

    public function isConfigured(): bool
    {
        return GMAIL_CLIENT_ID !== '' && GMAIL_CLIENT_SECRET !== '';
    }

    /** The Google consent screen URL. $state protects the callback (CSRF). */
    public function authUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'              => GMAIL_CLIENT_ID,
            'redirect_uri'           => GMAIL_REDIRECT_URI,
            'response_type'          => 'code',
            'scope'                  => self::SCOPE,
            'access_type'            => 'offline',   // ask for a refresh token
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ]);
    }

    /**
     * Swap the callback code for tokens and save them for the user.
     *
     * @return string|null the connected Gmail address, or null on failure
     */
    public function connect(int $userId, string $code): ?string
    {
        $token = HttpClient::postForm(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => GMAIL_CLIENT_ID,
            'client_secret' => GMAIL_CLIENT_SECRET,
            'redirect_uri'  => GMAIL_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]);

        $body = $token['body'];
        if ($token['status'] !== 200 || empty($body['access_token'])) {
            error_log('[gmail] token exchange failed: HTTP ' . $token['status'] . ' ' . ($body['error'] ?? ''));
            return null;
        }

        $profile = HttpClient::get(self::API_URL . '/profile', $this->auth((string) $body['access_token']));
        $email   = (string) ($profile['body']['emailAddress'] ?? '');

        if ($profile['status'] !== 200 || $email === '') {
            error_log('[gmail] profile lookup failed: HTTP ' . $profile['status']);
            return null;
        }

        (new GmailConnection())->save(
            $userId,
            $email,
            (string) $body['access_token'],
            isset($body['refresh_token']) ? (string) $body['refresh_token'] : null,
            (int) ($body['expires_in'] ?? 3600),
            isset($body['scope']) ? (string) $body['scope'] : null
        );

        return $email;
    }

    /**
     * Latest inbox + spam messages (metadata and snippet only).
     *
     * @return array{ok:bool, emails?:array<int, array<string, mixed>>, error?:string, reconnect?:bool}
     */
    public function inbox(int $userId, int $max = GMAIL_MAX_RESULTS): array
    {
        $token = $this->accessToken($userId);
        if ($token === null) {
            return ['ok' => false, 'error' => 'Gmail is not connected, or the connection expired. Please connect again.', 'reconnect' => true];
        }

        $list = HttpClient::get(self::API_URL . '/messages?' . http_build_query([
            'maxResults'       => $max,
            'includeSpamTrash' => 'true',
            'q'                => 'in:inbox OR in:spam',
        ]), $this->auth($token));

        if ($list['status'] === 401) {
            return ['ok' => false, 'error' => 'Gmail access was revoked. Please connect again.', 'reconnect' => true];
        }
        if ($list['status'] !== 200) {
            error_log('[gmail] list failed: HTTP ' . $list['status']);
            return ['ok' => false, 'error' => 'Could not load Gmail right now. Please try again.'];
        }

        $urls = [];
        foreach ($list['body']['messages'] ?? [] as $message) {
            $id = (string) ($message['id'] ?? '');
            if ($id !== '' && preg_match('/^[A-Za-z0-9]+$/', $id)) {
                $urls[$id] = self::API_URL . '/messages/' . $id
                    . '?format=metadata&metadataHeaders=From&metadataHeaders=Subject&metadataHeaders=Date';
            }
        }

        $emails = [];
        foreach (HttpClient::getMany($urls, $this->auth($token)) as $id => $detail) {
            if ($detail['status'] !== 200 || !is_array($detail['body'])) {
                continue;
            }

            $emails[] = $this->toEmail((string) $id, $detail['body']);
        }

        return ['ok' => true, 'emails' => $emails];
    }

    /** Forget the connection here and revoke it at Google. */
    public function disconnect(int $userId): void
    {
        $model      = new GmailConnection();
        $connection = $model->find($userId);

        if ($connection !== null) {
            $token = $connection['refresh_token'] ?: $connection['access_token'];
            HttpClient::postForm(self::REVOKE_URL, ['token' => $token]);
            $model->delete($userId);
        }
    }

    /* ---------------------------------------------------------- private ---- */

    /** A valid access token, refreshed if needed; null when not connected. */
    private function accessToken(int $userId): ?string
    {
        $model      = new GmailConnection();
        $connection = $model->find($userId);

        if ($connection === null) {
            return null;
        }

        if (!$model->isExpired($connection)) {
            return (string) $connection['access_token'];
        }

        if (empty($connection['refresh_token'])) {
            return null;
        }

        $refresh = HttpClient::postForm(self::TOKEN_URL, [
            'client_id'     => GMAIL_CLIENT_ID,
            'client_secret' => GMAIL_CLIENT_SECRET,
            'refresh_token' => $connection['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]);

        if ($refresh['status'] !== 200 || empty($refresh['body']['access_token'])) {
            error_log('[gmail] refresh failed: HTTP ' . $refresh['status'] . ' ' . ($refresh['body']['error'] ?? ''));

            // invalid_grant = the user revoked access or the token is dead.
            if (($refresh['body']['error'] ?? '') === 'invalid_grant') {
                $model->delete($userId);
            }

            return null;
        }

        $model->updateAccessToken($userId, (string) $refresh['body']['access_token'], (int) ($refresh['body']['expires_in'] ?? 3600));

        return (string) $refresh['body']['access_token'];
    }

    private function auth(string $token): array
    {
        return ['Authorization: Bearer ' . $token];
    }

    /** Shape one Gmail message the way the dashboard script expects. */
    private function toEmail(string $id, array $message): array
    {
        $headers = [];
        foreach ($message['payload']['headers'] ?? [] as $header) {
            $headers[strtolower((string) ($header['name'] ?? ''))] = (string) ($header['value'] ?? '');
        }

        $labels = $message['labelIds'] ?? [];
        $millis = (int) ($message['internalDate'] ?? 0);

        return [
            'id'        => $id,
            'sender'    => $headers['from'] ?? '',
            'subject'   => $headers['subject'] ?? '',
            'preview'   => html_entity_decode((string) ($message['snippet'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'timestamp' => $millis > 0 ? intdiv($millis, 1000) : null,
            'unread'    => in_array('UNREAD', $labels, true),
            'important' => in_array('IMPORTANT', $labels, true),
            'starred'   => in_array('STARRED', $labels, true),
            'isSpam'    => in_array('SPAM', $labels, true),
        ];
    }
}
