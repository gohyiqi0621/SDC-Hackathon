<?php
/**
 * GmailService.php - connect a user's Gmail (OAuth 2.0)
 * and read their inbox and full email content.
 *
 * Uses Google's REST API directly.
 * Gmail permission: read-only.
 */
declare(strict_types=1);

namespace Src\Services;

use Src\Models\GmailConnection;
use Src\Support\HttpClient;

final class GmailService
{
    private const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const API_URL    = 'https://gmail.googleapis.com/gmail/v1/users/me';
    private const SCOPE      = 'https://www.googleapis.com/auth/gmail.readonly';

    public function isConfigured(): bool
    {
        return GMAIL_CLIENT_ID !== '' && GMAIL_CLIENT_SECRET !== '';
    }

    /**
     * Generate Google OAuth authorization URL.
     */
    public function authUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'              => GMAIL_CLIENT_ID,
            'redirect_uri'           => GMAIL_REDIRECT_URI,
            'response_type'          => 'code',
            'scope'                  => self::SCOPE,
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ]);
    }

    /**
     * Exchange authorization code for tokens.
     *
     * @return string|null Connected Gmail address.
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

        if (
            $token['status'] !== 200 ||
            empty($body['access_token'])
        ) {
            error_log(
                '[gmail] token exchange failed: HTTP ' .
                $token['status'] . ' ' .
                ($body['error'] ?? '')
            );

            return null;
        }

        $profile = HttpClient::get(
            self::API_URL . '/profile',
            $this->auth((string) $body['access_token'])
        );

        $email = (string) (
            $profile['body']['emailAddress'] ?? ''
        );

        if (
            $profile['status'] !== 200 ||
            $email === ''
        ) {
            error_log(
                '[gmail] profile lookup failed: HTTP ' .
                $profile['status']
            );

            return null;
        }

        (new GmailConnection())->save(
            $userId,
            $email,
            (string) $body['access_token'],
            isset($body['refresh_token'])
                ? (string) $body['refresh_token']
                : null,
            (int) ($body['expires_in'] ?? 3600),
            isset($body['scope'])
                ? (string) $body['scope']
                : null
        );

        return $email;
    }

    /**
     * Get latest inbox and spam emails.
     *
     * Only metadata and snippets are loaded here.
     */
    public function inbox(
        int $userId,
        int $max = GMAIL_MAX_RESULTS
    ): array {
        $token = $this->accessToken($userId);

        if ($token === null) {
            return [
                'ok' => false,
                'error' => 'Gmail is not connected, or the connection expired. Please connect again.',
                'reconnect' => true
            ];
        }

        $list = HttpClient::get(
            self::API_URL . '/messages?' . http_build_query([
                'maxResults'       => $max,
                'includeSpamTrash' => 'true',
                'q'                => 'in:inbox OR in:spam',
            ]),
            $this->auth($token)
        );

        if ($list['status'] === 401) {
            return [
                'ok' => false,
                'error' => 'Gmail access was revoked. Please connect again.',
                'reconnect' => true
            ];
        }

        if ($list['status'] !== 200) {
            error_log(
                '[gmail] list failed: HTTP ' .
                $list['status']
            );

            return [
                'ok' => false,
                'error' => 'Could not load Gmail right now. Please try again.'
            ];
        }

        $urls = [];

        foreach (
            $list['body']['messages'] ?? [] as $message
        ) {
            $id = (string) ($message['id'] ?? '');

            if (
                $id !== '' &&
                preg_match('/^[A-Za-z0-9]+$/', $id)
            ) {
                $urls[$id] =
                    self::API_URL . '/messages/' . $id .
                    '?format=metadata' .
                    '&metadataHeaders=From' .
                    '&metadataHeaders=Subject' .
                    '&metadataHeaders=Date';
            }
        }

        $emails = [];

        foreach (
            HttpClient::getMany(
                $urls,
                $this->auth($token)
            ) as $id => $detail
        ) {
            if (
                $detail['status'] !== 200 ||
                !is_array($detail['body'])
            ) {
                continue;
            }

            $emails[] = $this->toEmail(
                (string) $id,
                $detail['body']
            );
        }

        return [
            'ok' => true,
            'emails' => $emails
        ];
    }

    /**
     * Get the full body of one Gmail message.
     *
     * The message must belong to the connected
     * Gmail account of the authenticated user.
     */
    public function message(
        int $userId,
        string $messageId
    ): array {
        if (
            !preg_match(
                '/^[A-Za-z0-9]+$/',
                $messageId
            )
        ) {
            return [
                'ok' => false,
                'error' => 'Invalid Gmail message ID.'
            ];
        }

        $token = $this->accessToken($userId);

        if ($token === null) {
            return [
                'ok' => false,
                'error' => 'Gmail is not connected.',
                'reconnect' => true
            ];
        }

        $response = HttpClient::get(
            self::API_URL .
                '/messages/' .
                $messageId .
                '?format=full',
            $this->auth($token)
        );

        if ($response['status'] === 401) {
            return [
                'ok' => false,
                'error' => 'Gmail connection expired. Please reconnect.',
                'reconnect' => true
            ];
        }

        if (
            $response['status'] !== 200 ||
            !is_array($response['body'])
        ) {
            error_log(
                '[gmail] message failed: HTTP ' .
                $response['status']
            );

            return [
                'ok' => false,
                'error' => 'Unable to load Gmail message.'
            ];
        }

        $message = $response['body'];

        $payload = $message['payload'] ?? [];

        $body = is_array($payload)
            ? $this->extractBody($payload)
            : '';

        if ($body === '') {
            $body = html_entity_decode(
                (string) ($message['snippet'] ?? ''),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
        }

        return [
            'ok' => true,
            'body' => $body
        ];
    }

    /**
     * Disconnect Gmail and revoke the token.
     */
    public function disconnect(int $userId): void
    {
        $model = new GmailConnection();

        $connection = $model->find($userId);

        if ($connection !== null) {
            $token = $connection['refresh_token']
                ?: $connection['access_token'];

            HttpClient::postForm(
                self::REVOKE_URL,
                ['token' => $token]
            );

            $model->delete($userId);
        }
    }

    /* -------------------- PRIVATE METHODS -------------------- */

    /**
     * Extract readable content from MIME parts.
     *
     * Prefer text/plain.
     * Fall back to text/html when needed.
     */
    private function extractBody(array $payload): string
    {
        $mimeType = strtolower(
            (string) ($payload['mimeType'] ?? '')
        );

        if ($mimeType === 'text/plain') {
            return $this->decodeGmailBody(
                (string) (
                    $payload['body']['data'] ?? ''
                )
            );
        }

        $htmlFallback = '';

        foreach ($payload['parts'] ?? [] as $part) {
            if (!is_array($part)) {
                continue;
            }

            $text = $this->extractBody($part);

            if ($text === '') {
                continue;
            }

            // Prefer plain text in multipart/alternative.
            if (
                strtolower(
                    (string) ($part['mimeType'] ?? '')
                ) === 'text/plain'
            ) {
                return $text;
            }

            if ($htmlFallback === '') {
                $htmlFallback = $text;
            }
        }

        if ($htmlFallback !== '') {
            return $htmlFallback;
        }

        if ($mimeType === 'text/html') {
            $html = $this->decodeGmailBody(
                (string) (
                    $payload['body']['data'] ?? ''
                )
            );

            return $this->htmlToText($html);
        }

        return '';
    }

    /**
     * Decode Gmail base64url data.
     */
    private function decodeGmailBody(
        string $encoded
    ): string {
        if ($encoded === '') {
            return '';
        }

        $encoded = strtr(
            $encoded,
            '-_',
            '+/'
        );

        $encoded .= str_repeat(
            '=',
            (4 - strlen($encoded) % 4) % 4
        );

        $decoded = base64_decode(
            $encoded,
            true
        );

        return $decoded === false
            ? ''
            : $decoded;
    }

    /**
     * Convert basic HTML emails into readable text.
     */
    private function htmlToText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Remove scripts and styles.
        $html = preg_replace(
            '~<(script|style)\b[^>]*>.*?</\1>~is',
            '',
            $html
        ) ?? $html;

        // Preserve common line breaks.
        $html = preg_replace(
            '~<br\s*/?>|</p>|</div>|</li>|</tr>~i',
            "\n",
            $html
        ) ?? $html;

        $text = strip_tags($html);

        $text = html_entity_decode(
            $text,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = preg_replace(
            "/[ \t]+/",
            ' ',
            $text
        ) ?? $text;

        $text = preg_replace(
            "/\n{3,}/",
            "\n\n",
            $text
        ) ?? $text;

        return trim($text);
    }

    /**
     * Get a valid access token.
     * Refresh it automatically when expired.
     */
    private function accessToken(int $userId): ?string
    {
        $model = new GmailConnection();

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

        $refresh = HttpClient::postForm(
            self::TOKEN_URL,
            [
                'client_id'     => GMAIL_CLIENT_ID,
                'client_secret' => GMAIL_CLIENT_SECRET,
                'refresh_token' => $connection['refresh_token'],
                'grant_type'    => 'refresh_token',
            ]
        );

        if (
            $refresh['status'] !== 200 ||
            empty($refresh['body']['access_token'])
        ) {
            error_log(
                '[gmail] refresh failed: HTTP ' .
                $refresh['status'] . ' ' .
                ($refresh['body']['error'] ?? '')
            );

            if (
                ($refresh['body']['error'] ?? '') ===
                'invalid_grant'
            ) {
                $model->delete($userId);
            }

            return null;
        }

        $model->updateAccessToken(
            $userId,
            (string) $refresh['body']['access_token'],
            (int) (
                $refresh['body']['expires_in'] ?? 3600
            )
        );

        return (string) $refresh['body']['access_token'];
    }

    /**
     * Generate Gmail Authorization header.
     */
    private function auth(string $token): array
    {
        return [
            'Authorization: Bearer ' . $token
        ];
    }

    /**
     * Convert Gmail metadata into dashboard email format.
     */
    private function toEmail(
        string $id,
        array $message
    ): array {
        $headers = [];

        foreach (
            $message['payload']['headers'] ?? []
            as $header
        ) {
            $headers[
                strtolower(
                    (string) ($header['name'] ?? '')
                )
            ] = (string) (
                $header['value'] ?? ''
            );
        }

        $labels = $message['labelIds'] ?? [];

        $millis = (int) (
            $message['internalDate'] ?? 0
        );

        return [
            'id'        => $id,
            'sender'    => $headers['from'] ?? '',
            'subject'   => $headers['subject'] ?? '',
            'preview'   => html_entity_decode(
                (string) (
                    $message['snippet'] ?? ''
                ),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ),
            'timestamp' => $millis > 0
                ? intdiv($millis, 1000)
                : null,
            'unread'    => in_array(
                'UNREAD',
                $labels,
                true
            ),
            'important' => in_array(
                'IMPORTANT',
                $labels,
                true
            ),
            'starred'   => in_array(
                'STARRED',
                $labels,
                true
            ),
            'isSpam'    => in_array(
                'SPAM',
                $labels,
                true
            ),
        ];
    }
}
