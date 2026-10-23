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
    private const SCOPE =
    'https://www.googleapis.com/auth/gmail.modify ' .
    'https://www.googleapis.com/auth/gmail.send';

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
            self::logProblem('connect: token exchange', $token);

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
            self::logProblem('connect: profile lookup', $profile);

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
     * Full MIME payloads are inspected for keyword and calendar classification.
     * Only summaries and classification flags are returned to the browser.
     */
    public function inbox(
        int $userId,
        int $max = GMAIL_MAX_RESULTS,
        string $pageToken = ''
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
                'maxResults' => $max,
                'labelIds' => 'INBOX',
                'includeSpamTrash' => 'false',
                'pageToken' => $pageToken,
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
            self::logProblem('inbox list', $list);

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
                    '?format=full';
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
            'emails' => $emails,
            'nextPageToken' => $list['body']['nextPageToken'] ?? null
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
            self::logProblem('open message', $response);

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
     * Send a Gmail reply in the original conversation.
     *
     * @param array|null $attachments Files from $_FILES['attachments']
     */
    public function sendReply(
        int $userId,
        string $messageId,
        string $body,
        ?array $attachments = null
    ): array {
        if (!preg_match('/^[A-Za-z0-9]+$/', $messageId)) {
            return ['ok' => false, 'error' => 'Invalid message ID.'];
        }

        $token = $this->accessToken($userId);

        if ($token === null) {
            return [
                'ok' => false,
                'reconnect' => true,
                'error' => 'Please reconnect Gmail.'
            ];
        }

        // Fetch the original message to verify the recipient and thread.
        $original = HttpClient::get(
            self::API_URL . '/messages/' . $messageId . '?format=metadata',
            $this->auth($token)
        );

        if ($original['status'] !== 200 || !is_array($original['body'])) {
            return [
                'ok' => false,
                'error' => 'Could not load the original email.'
            ];
        }

        $message = $original['body'];
        $headers = [];

        foreach ($message['payload']['headers'] ?? [] as $header) {
            $headers[strtolower((string) ($header['name'] ?? ''))] =
                (string) ($header['value'] ?? '');
        }

        $connection = (new GmailConnection())->find($userId);
        $from = (string) ($connection['google_email'] ?? '');

        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid connected Gmail address.'];
        }

        // Prefer Reply-To when present.
        $recipientHeader = $headers['reply-to'] ?? $headers['from'] ?? '';
        $addresses = [];

        if (preg_match_all(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            $recipientHeader,
            $matches
        )) {
            $addresses = $matches[0];
        }

        $to = $addresses[0] ?? '';

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid recipient address.'];
        }

        $subject = trim((string) ($headers['subject'] ?? ''));
        if (!preg_match('/^Re:/i', $subject)) {
            $subject = 'Re: ' . $subject;
        }

        $threadId = (string) ($message['threadId'] ?? '');
        $originalMessageId = trim((string) ($headers['message-id'] ?? ''));

        if ($threadId === '' || $originalMessageId === '') {
            return [
                'ok' => false,
                'error' => 'Original email thread information is missing.'
            ];
        }

        // Prevent header injection.
        $originalMessageId = str_replace(["\r", "\n"], '', $originalMessageId);
        $subject = str_replace(["\r", "\n"], ' ', $subject);

        $boundary = 'smart_sme_' . bin2hex(random_bytes(16));

        $mime = [
            'From: ' . $from,
            'To: ' . $to,
            'Subject: ' . '=?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'In-Reply-To: ' . $originalMessageId,
            'References: ' . trim(
                ($headers['references'] ?? '') . ' ' . $originalMessageId
            ),
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($body))
        ];

    if ($attachments !== null && isset($attachments['size'])) {
        $totalSize = 0;

        foreach ((array) $attachments['size'] as $size) {
            $totalSize += (int) $size;
        }

        // Leave room for MIME/base64 encoding overhead.
        if ($totalSize > 15 * 1024 * 1024) {
            return [
                'ok' => false,
                'error' => 'Total attachments must be 15 MB or smaller.'
            ];
        }
    }


    if ($attachments !== null && isset($attachments['name'])) {
        $names = (array) $attachments['name'];
        $tmpNames = (array) $attachments['tmp_name'];
        $errors = (array) $attachments['error'];
        $sizes = (array) $attachments['size'];

        foreach ($names as $index => $name) {
            $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                return ['ok' => false, 'error' => 'Attachment upload failed.'];
            }

            $tmp = (string) ($tmpNames[$index] ?? '');
            $size = (int) ($sizes[$index] ?? 0);

            if ($size > 10 * 1024 * 1024) {
                return [
                    'ok' => false,
                    'error' => 'Each attachment must be 10 MB or smaller.'
                ];
            }

            if (!is_uploaded_file($tmp)) {
                return ['ok' => false, 'error' => 'Invalid attachment.'];
            }

            $content = file_get_contents($tmp);

            if ($content === false) {
                return ['ok' => false, 'error' => 'Unable to read attachment.'];
            }

            $filename = basename(str_replace('\\', '/', (string) $name));
            $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp)
                ?: 'application/octet-stream';

            $mime[] = '--' . $boundary;
            $mime[] = 'Content-Type: ' . $mimeType;
            $mime[] = 'Content-Disposition: attachment; filename*=UTF-8\'\'' .
                rawurlencode($filename);
            $mime[] = 'Content-Transfer-Encoding: base64';
            $mime[] = '';
            $mime[] = chunk_split(base64_encode($content));
        }
    }

    $mime[] = '--' . $boundary . '--';
    $mime[] = '';

    $raw = implode("\r\n", $mime);

    $response = HttpClient::postJson(
        self::API_URL . '/messages/send',
        [
            'raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '='),
            'threadId' => $threadId
        ],
        $this->auth($token)
    );

    
if ($response['status'] !== 200) {
    $googleError = $response['body']['error'] ?? [];
    $googleMessage = is_array($googleError)
        ? ($googleError['message'] ?? 'Unknown Gmail API error')
        : (string) $googleError;

    error_log(
        '[gmail] Send failed HTTP ' .
        $response['status'] . ': ' . $googleMessage
    );
    self::logProblem('send reply', $response);

    return [
        'ok' => false,
        'error' => 'Gmail API Error (' .
            $response['status'] . '): ' . $googleMessage
    ];
}


    return [
        'ok' => true,
        'id' => $response['body']['id'] ?? null
    ];
}

    /**
 * Get sent emails from Gmail.
 */
public function sent(int $userId): array
{
    $token = $this->accessToken($userId);

    if ($token === null) {
        return [
            'ok' => false,
            'reconnect' => true,
            'error' => 'Please reconnect Gmail.'
        ];
    }

    $response = HttpClient::get(
        self::API_URL . '/messages?' . http_build_query([
            'labelIds' => 'SENT',
            'maxResults' => 50
        ]),
        $this->auth($token)
    );

    if ($response['status'] === 401) {
        self::logProblem('sent list', $response);
        return [
            'ok' => false,
            'reconnect' => true,
            'error' => 'Gmail connection expired.'
        ];
    }

    if ($response['status'] !== 200) {
        self::logProblem('sent list', $response);
        return [
            'ok' => false,
            'error' => 'Unable to load sent emails.'
        ];
    }

    $urls = [];

    foreach ($response['body']['messages'] ?? [] as $message) {
        $id = (string) ($message['id'] ?? '');

        if (preg_match('/^[A-Za-z0-9]+$/', $id)) {
            $urls[$id] =
                self::API_URL . '/messages/' . $id .
                '?format=metadata' .
                '&metadataHeaders=From' .
                '&metadataHeaders=To' .
                '&metadataHeaders=Subject' .
                '&metadataHeaders=Date';
        }
    }

    $emails = [];

    foreach (
        HttpClient::getMany($urls, $this->auth($token))
        as $id => $detail
    ) {
        if (
            $detail['status'] !== 200 ||
            !is_array($detail['body'])
        ) {
            continue;
        }

        $email = $this->toEmail((string) $id, $detail['body']);

        $headers = [];

        foreach ($detail['body']['payload']['headers'] ?? [] as $header) {
            $headers[strtolower((string) ($header['name'] ?? ''))] =
                (string) ($header['value'] ?? '');
        }

        $email['sender'] = $headers['to'] ?? '';
        $email['senderEmail'] = $headers['to'] ?? '';
        $email['from'] = $headers['from'] ?? '';
        $email['to'] = $headers['to'] ?? '';
        $email['threadId'] =
            (string) ($detail['body']['threadId'] ?? '');

        $email['sent'] = true;
        $email['unread'] = false;
        $email['needsReply'] = false;

        $emails[] = $email;
    }

    usort($emails, static function ($a, $b) {
        return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
    });

    return [
        'ok' => true,
        'emails' => $emails
    ];
}

    /**
 * Get all messages in a Gmail conversation.
 */
public function thread(int $userId, string $threadId): array
{
    if (!preg_match('/^[A-Za-z0-9]+$/', $threadId)) {
        return [
            'ok' => false,
            'error' => 'Invalid thread ID.'
        ];
    }

    $token = $this->accessToken($userId);

    if ($token === null) {
        return [
            'ok' => false,
            'reconnect' => true,
            'error' => 'Please reconnect Gmail.'
        ];
    }

    $response = HttpClient::get(
        self::API_URL . '/threads/' . $threadId . '?format=full',
        $this->auth($token)
    );

    if ($response['status'] === 401) {
        return [
            'ok' => false,
            'reconnect' => true,
            'error' => 'Gmail connection expired.'
        ];
    }

    if (
        $response['status'] !== 200 ||
        !is_array($response['body'])
    ) {
        return [
            'ok' => false,
            'error' => 'Unable to load conversation.'
        ];
    }

    $messages = [];

    foreach ($response['body']['messages'] ?? [] as $message) {
        $headers = [];

        foreach ($message['payload']['headers'] ?? [] as $header) {
            $headers[strtolower((string) ($header['name'] ?? ''))] =
                (string) ($header['value'] ?? '');
        }

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

        $messages[] = [
            'id' => (string) ($message['id'] ?? ''),
            'threadId' => $threadId,
            'from' => $headers['from'] ?? '',
            'to' => $headers['to'] ?? '',
            'subject' => $headers['subject'] ?? '',
            'body' => $body,
            'timestamp' => isset($message['internalDate'])
                ? intdiv((int) $message['internalDate'], 1000)
                : null,
            'sent' => in_array(
                'SENT',
                $message['labelIds'] ?? [],
                true
            )
        ];
    }

    usort($messages, static function ($a, $b) {
        return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
    });

    return [
        'ok' => true,
        'messages' => $messages
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


    /**
     * Get Gmail Spam messages.
     */
    public function spam(int $userId): array
    {
        return $this->folder($userId, 'SPAM');
    }

    /**
     * Get Gmail Trash messages.
     */
    public function trash(int $userId): array
    {
        return $this->folder($userId, 'TRASH');
    }

    /**
     * Load messages from a Gmail system folder.
     */
    private function folder(int $userId, string $label): array
    {
        if (!in_array($label, ['SPAM', 'TRASH'], true)) {
            return [
                'ok' => false,
                'error' => 'Invalid Gmail folder.'
            ];
        }

        $token = $this->accessToken($userId);

        if ($token === null) {
            return [
                'ok' => false,
                'reconnect' => true,
                'error' => 'Please reconnect Gmail.'
            ];
        }

        $response = HttpClient::get(
            self::API_URL . '/messages?' . http_build_query([
                'labelIds' => $label,
                'maxResults' => 50,
                'includeSpamTrash' => 'true'
            ]),
            $this->auth($token)
        );

        if ($response['status'] === 401) {
            return [
                'ok' => false,
                'reconnect' => true,
                'error' => 'Gmail authorization expired.'
            ];
        }

        if ($response['status'] !== 200) {
            return [
                'ok' => false,
                'error' => 'Unable to load Gmail folder.'
            ];
        }

        $urls = [];

        foreach ($response['body']['messages'] ?? [] as $message) {
            $id = (string) ($message['id'] ?? '');

            if (preg_match('/^[A-Za-z0-9]+$/', $id)) {
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
            HttpClient::getMany($urls, $this->auth($token))
            as $id => $detail
        ) {
            if (
                $detail['status'] !== 200 ||
                !is_array($detail['body'])
            ) {
                continue;
            }

            $email = $this->toEmail(
                (string) $id,
                $detail['body']
            );

            $email['folder'] = strtolower($label);

            $emails[] = $email;
        }

        usort($emails, static function ($a, $b) {
            return ($b['timestamp'] ?? 0)
                <=> ($a['timestamp'] ?? 0);
        });

        return [
            'ok' => true,
            'emails' => $emails
        ];
    }


    /**
     * Perform Gmail actions on a message.
     *
     * Supported actions:
     * star, unstar, archive, trash, restore,
     * read, unread, spam, not_spam,
     * important, not_important
     */
    public function action(
        int $userId,
        string $messageId,
        string $action
    ): array {
        if (!preg_match('/^[A-Za-z0-9]+$/', $messageId)) {
            return [
                'ok' => false,
                'error' => 'Invalid Gmail message ID.'
            ];
        }

        $token = $this->accessToken($userId);

        if ($token === null) {
            return [
                'ok' => false,
                'reconnect' => true,
                'error' => 'Please reconnect Gmail.'
            ];
        }

        $add = [];
        $remove = [];

        switch ($action) {
            case 'star':
                $add = ['STARRED'];
                break;

            case 'unstar':
                $remove = ['STARRED'];
                break;

            case 'archive':
                $remove = ['INBOX'];
                break;

            case 'trash':
                $add = ['TRASH'];
                $remove = ['INBOX'];
                break;

            case 'restore':
                $add = ['INBOX'];
                $remove = ['TRASH'];
                break;

            case 'read':
                $remove = ['UNREAD'];
                break;

            case 'unread':
                $add = ['UNREAD'];
                break;

            case 'spam':
                $add = ['SPAM'];
                $remove = ['INBOX'];
                break;

            case 'not_spam':
                $add = ['INBOX'];
                $remove = ['SPAM'];
                break;

            case 'important':
                $add = ['IMPORTANT'];
                break;

            case 'not_important':
                $remove = ['IMPORTANT'];
                break;

            default:
                return [
                    'ok' => false,
                    'error' => 'Unsupported Gmail action.'
                ];
        }

        $response = HttpClient::postJson(
            self::API_URL .
                '/messages/' .
                $messageId .
                '/modify',
            [
                'addLabelIds' => $add,
                'removeLabelIds' => $remove
            ],
            $this->auth($token)
        );

        if ($response['status'] === 401) {
            return [
                'ok' => false,
                'reconnect' => true,
                'error' => 'Gmail authorization expired. Please reconnect.'
            ];
        }

        if ($response['status'] !== 200) {
            $googleError = $response['body']['error'] ?? [];

            $googleMessage = is_array($googleError)
                ? (string) ($googleError['message'] ?? 'Gmail action failed.')
                : (string) $googleError;

            error_log(
                '[gmail] Action failed HTTP ' .
                $response['status'] .
                ': ' .
                $googleMessage
            );
            self::logProblem('email action', $response);

            return [
                'ok' => false,
                'error' => $googleMessage
            ];
        }

        return [
            'ok' => true,
            'messageId' => $messageId,
            'action' => $action
        ];
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
            self::logProblem('refresh access (uses GMAIL_CLIENT_ID / GMAIL_CLIENT_SECRET)', $refresh);

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
     * Staff assistant (tools/check_email): messages with these Gmail labels
     * and/or matching a Gmail search ("from:ali invoice", "after:2026/10/01").
     * Metadata and snippet only, newest first.
     *
     * @param list<string> $labels INBOX, IMPORTANT, UNREAD, STARRED, SENT, SPAM, TRASH, CATEGORY_*
     * @return array{ok: bool, emails?: list<array>, estimate?: int, error?: string, reconnect?: bool}
     */
    public function search(int $userId, array $labels, string $query = '', int $max = 10): array
    {
        $token = $this->accessToken($userId);
        if ($token === null) {
            return ['ok' => false, 'reconnect' => true,
                    'error' => 'Gmail is not connected, or the connection expired. Connect Gmail on the Dashboard first.'];
        }

        $url = self::API_URL . '/messages?maxResults=' . max(1, min(50, $max));
        foreach ($labels as $label) {
            if (preg_match('/^[A-Z_]+$/', $label)) {
                $url .= '&labelIds=' . rawurlencode($label);
            }
        }
        $query = trim(str_replace(["\r", "\n"], ' ', $query));
        if ($query !== '') {
            $url .= '&q=' . rawurlencode(mb_substr($query, 0, 300));
        }
        if (array_intersect($labels, ['SPAM', 'TRASH']) !== []) {
            $url .= '&includeSpamTrash=true';
        }

        $list = HttpClient::get($url, $this->auth($token));
        if ($list['status'] === 401) {
            return ['ok' => false, 'reconnect' => true, 'error' => 'Gmail access expired. Please connect Gmail again on the Dashboard.'];
        }
        if ($list['status'] !== 200 || !is_array($list['body'])) {
            error_log('[gmail] search failed: HTTP ' . $list['status']);
            self::logProblem('search', $list);
            return ['ok' => false, 'error' => 'Could not load Gmail right now (HTTP ' . $list['status'] . ').'];
        }

        $urls = [];
        foreach ($list['body']['messages'] ?? [] as $message) {
            $id = (string) ($message['id'] ?? '');
            if (preg_match('/^[A-Za-z0-9]+$/', $id)) {
                $urls[$id] = self::API_URL . '/messages/' . $id . '?format=metadata'
                    . '&metadataHeaders=From&metadataHeaders=To&metadataHeaders=Subject&metadataHeaders=Date';
            }
        }

        $emails = [];
        foreach (HttpClient::getMany($urls, $this->auth($token)) as $id => $detail) {
            if ($detail['status'] !== 200 || !is_array($detail['body'])) {
                continue;
            }
            $email = $this->toEmail((string) $id, $detail['body']);
            $email['to'] = '';
            foreach ($detail['body']['payload']['headers'] ?? [] as $header) {
                if (strtolower((string) ($header['name'] ?? '')) === 'to') {
                    $email['to'] = (string) ($header['value'] ?? '');
                }
            }
            $emails[] = $email;
        }
        usort($emails, static fn ($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

        return ['ok' => true, 'emails' => $emails, 'estimate' => (int) ($list['body']['resultSizeEstimate'] ?? count($emails))];
    }

    /**
     * Staff assistant (tools/send_email): a new email (not a reply) from the
     * connected Gmail. $to / $cc: addresses separated by commas ("Ali <a@b.my>"
     * is fine). Plain text.
     *
     * @return array{ok: bool, id?: ?string, to?: list<string>, error?: string, reconnect?: bool}
     */
    public function sendNew(int $userId, string $to, string $subject, string $body, string $cc = ''): array
    {
        $parse = static function (string $list): ?array {
            $out = [];
            foreach (preg_split('/[,;]+/', $list) ?: [] as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $email = preg_match('/<([^<>]+)>/', $part, $m) ? trim($m[1]) : $part;
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return null;
                }
                $out[strtolower($email)] = $email;
            }
            return array_values($out);
        };
        $toList = $parse($to);
        $ccList = $parse($cc);
        if ($toList === null || $toList === [] || $ccList === null) {
            return ['ok' => false, 'error' => 'Invalid email address. Use addresses like name@example.com.'];
        }
        if (count($toList) + count($ccList) > 10) {
            return ['ok' => false, 'error' => 'Too many recipients (10 at most).'];
        }
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
        if ($subject === '' || trim($body) === '') {
            return ['ok' => false, 'error' => 'The email needs a subject and a message.'];
        }

        $token = $this->accessToken($userId);
        if ($token === null) {
            return ['ok' => false, 'reconnect' => true,
                    'error' => 'Gmail is not connected, or the connection expired. Connect Gmail on the Dashboard first.'];
        }
        $from = (string) ((new GmailConnection())->find($userId)['google_email'] ?? '');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid connected Gmail address.'];
        }

        $mime = [
            'From: ' . $from,
            'To: ' . implode(', ', $toList),
        ];
        if ($ccList !== []) {
            $mime[] = 'Cc: ' . implode(', ', $ccList);
        }
        $mime = array_merge($mime, [
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode(str_replace("\r\n", "\n", $body))),
        ]);

        $response = HttpClient::postJson(
            self::API_URL . '/messages/send',
            ['raw' => rtrim(strtr(base64_encode(implode("\r\n", $mime)), '+/', '-_'), '=')],
            $this->auth($token)
        );
        if ($response['status'] === 401) {
            return ['ok' => false, 'reconnect' => true, 'error' => 'Gmail access expired. Please connect Gmail again on the Dashboard.'];
        }
        if ($response['status'] !== 200) {
            $err = $response['body']['error'] ?? [];
            $msg = is_array($err) ? (string) ($err['message'] ?? 'Unknown Gmail API error') : (string) $err;
            error_log('[gmail] send new failed HTTP ' . $response['status'] . ': ' . $msg);
            self::logProblem('send new email', $response);
            return ['ok' => false, 'error' => 'Gmail API Error (' . $response['status'] . '): ' . $msg];
        }

        return ['ok' => true, 'id' => $response['body']['id'] ?? null, 'to' => $toList, 'cc' => $ccList];
    }

    /**
     * Staff assistant (tools/read_email): one message with its headers and
     * plain-text body. Read only; nothing is changed in Gmail.
     */
    public function read(int $userId, string $messageId): array
    {
        if (!preg_match('/^[A-Za-z0-9]{6,64}$/', $messageId)) {
            return ['ok' => false, 'error' => 'Invalid Gmail message id.'];
        }
        $token = $this->accessToken($userId);
        if ($token === null) {
            return ['ok' => false, 'reconnect' => true,
                    'error' => 'Gmail is not connected, or the connection expired. Connect Gmail on the Dashboard first.'];
        }

        $response = HttpClient::get(self::API_URL . '/messages/' . $messageId . '?format=full', $this->auth($token));
        if ($response['status'] === 401) {
            return ['ok' => false, 'reconnect' => true, 'error' => 'Gmail access expired. Please connect Gmail again on the Dashboard.'];
        }
        if ($response['status'] === 404) {
            return ['ok' => false, 'error' => 'No email with that id in this mailbox.'];
        }
        if ($response['status'] !== 200 || !is_array($response['body'])) {
            return ['ok' => false, 'error' => 'Unable to load the email (HTTP ' . $response['status'] . ').'];
        }

        $message = $response['body'];
        $headers = [];
        foreach ($message['payload']['headers'] ?? [] as $header) {
            $headers[strtolower((string) ($header['name'] ?? ''))] = (string) ($header['value'] ?? '');
        }
        $body = is_array($message['payload'] ?? null) ? $this->extractBody($message['payload']) : '';
        if (trim($body) === '') {
            $body = html_entity_decode((string) ($message['snippet'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return ['ok' => true, 'email' => $this->toEmail($messageId, $message) + [
            'to'       => $headers['to'] ?? '',
            'cc'       => $headers['cc'] ?? '',
            'reply_to' => $headers['reply-to'] ?? '',
            'body'     => $body,
        ]];
    }

    /**
     * Detect calendar invitations, including nested MIME attachments.
     */
    private function hasCalendarPart(array $payload): bool
    {
        if (strtolower((string) ($payload['mimeType'] ?? '')) === 'text/calendar'
            || preg_match('/\.ics$/i', (string) ($payload['filename'] ?? ''))) {
            return true;
        }
        foreach ($payload['parts'] ?? [] as $part) {
            if (is_array($part) && $this->hasCalendarPart($part)) return true;
        }
        return false;
    }

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

        $payload = $message['payload'] ?? [];
        $classificationText = ($headers['subject'] ?? '') . ' '
            . $this->extractBody($payload) . ' ' . ($message['snippet'] ?? '');

        $millis = (int) (
            $message['internalDate'] ?? 0
        );

        return [
            'id'        => $id,
            'meeting' => $this->hasCalendarPart($payload)
                || (bool) preg_match('/\b(?:meeting invitation|meeting request|schedule a meeting)\b/i', $classificationText),
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


            'isTrash' => in_array(
                'TRASH',
                $labels,
                true
            ),

            'isInbox' => in_array(
                'INBOX',
                $labels,
                true
            ),

            'threadId' => (string) (
                $message['threadId'] ?? ''
            ),

        ];
    }

    /** Google's own error text from an API response (never contains tokens). */
    private static function googleError(array $response): string
    {
        $body  = is_array($response['body'] ?? null) ? $response['body'] : [];
        $error = $body['error'] ?? null;
        if (is_array($error)) {
            $text = trim((string) ($error['message'] ?? '') . ' ' . (string) ($error['status'] ?? ''));
        } else {
            $text = trim((string) $error . ' ' . (string) ($body['error_description'] ?? ''));
        }
        return mb_substr($text, 0, 400);
    }

    /** A Gmail problem, also shown in System > Flow Log so it can be traced on the live site. */
    private static function logProblem(string $step, array $response): void
    {
        \Src\Support\FlowLog::write('gmail.error', [
            'step'          => $step,
            'status'        => (int) ($response['status'] ?? 0),
            'google'        => self::googleError($response),
            'client_id_set' => GMAIL_CLIENT_ID !== '',
            'redirect_uri'  => GMAIL_REDIRECT_URI,
        ]);
    }
}
