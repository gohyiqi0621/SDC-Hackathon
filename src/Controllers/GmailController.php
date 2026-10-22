<?php
/**
 * GmailController.php - connect Gmail to the dashboard (read-only).
 *
 *   GET  /gmail/connect     -> redirect to Google's consent screen
 *   GET  /gmail/callback    <- Google returns here (must match GMAIL_REDIRECT_URI)
 *   GET  /gmail/inbox       JSON for assets/js/dashboard.js
 *   POST /gmail/disconnect  forget and revoke the connection
 */
declare(strict_types=1);

namespace Src\Controllers;

use Src\Services\GmailService;

final class GmailController
{
    public function connect(): void
    {
        require_login();
        $gmail = new GmailService();

        if (!$gmail->isConfigured()) {
            flash('error', 'Add GMAIL_CLIENT_ID and GMAIL_CLIENT_SECRET to the .env file first.', 'Gmail is not set up');
            redirect(url('dashboard'));
        }

        $_SESSION['gmail_oauth_state'] = bin2hex(random_bytes(32));

        redirect($gmail->authUrl($_SESSION['gmail_oauth_state']));
    }

    public function callback(): void
    {
        $user     = require_login();
        $expected = (string) ($_SESSION['gmail_oauth_state'] ?? '');
        $state    = (string) ($_GET['state'] ?? '');
        unset($_SESSION['gmail_oauth_state']);

        if (isset($_GET['error'])) {
            flash('error', 'Gmail was not connected because access was cancelled or denied.', 'Gmail not connected');
            redirect(url('dashboard'));
        }

        if ($expected === '' || $state === '' || !hash_equals($expected, $state)) {
            flash('error', 'The Gmail sign-in link expired or was not started here. Please connect again.', 'Gmail not connected');
            redirect(url('dashboard'));
        }

        $code  = (string) ($_GET['code'] ?? '');
        $email = $code !== '' ? (new GmailService())->connect((int) $user['id'], $code) : null;

        if ($email === null) {
            flash('error', 'Google did not accept the sign-in. Check the client ID, secret and redirect URI, then try again.', 'Gmail not connected');
            redirect(url('dashboard'));
        }

        flash('success', 'Gmail ' . $email . ' is connected. Your inbox is loading.', 'Gmail connected');
        redirect(url('dashboard'));
    }

    public function inbox(): void
    {
        $user   = require_login();
        $result = (new GmailService())->inbox((int) $user['id']);

        if (!$result['ok']) {
            json_response(false, ['reconnect' => $result['reconnect'] ?? false], $result['error'] ?? 'Gmail failed.', 502);
        }

        json_response(true, ['emails' => $result['emails']], count($result['emails']) . ' emails loaded.');
    }

    /**
 * Return the full body of a Gmail message.
 */
public function message(): void
{
    $user = require_login();

    $messageId = $_GET['id'] ?? '';

    if (!is_string($messageId) || !preg_match('/^[A-Za-z0-9]+$/', $messageId)) {
        json_response(
            false,
            null,
            'Invalid Gmail message ID.',
            400
        );
    }

    $result = (new GmailService())->message(
        (int) $user['id'],
        $messageId
    );

    if (!$result['ok']) {
        json_response(
            false,
            ['reconnect' => $result['reconnect'] ?? false],
            $result['error'] ?? 'Unable to load email.',
            502
        );
    }

    json_response(
        true,
        ['body' => $result['body']],
        'Email content loaded.'
    );
}

    /**
     * Send a reply using the connected Gmail account.
     */

public function send(): void
{
    $user = require_login();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(false, null, 'Method not allowed.', 405);
        return;
    }

    if (!csrf_valid()) {
        json_response(false, null, 'Invalid CSRF token.', 403);
        return;
    }

    $messageId = (string) ($_POST['messageId'] ?? '');
    $body = trim((string) ($_POST['body'] ?? ''));


        if (!preg_match('/^[A-Za-z0-9]+$/', $messageId)) {
            json_response(false, null, 'Invalid message ID.', 400);
            return;
        }

        if ($body === '') {
            json_response(false, null, 'Reply cannot be empty.', 422);
            return;
        }

        if (strlen($body) > 1000000) {
            json_response(false, null, 'Reply is too large.', 413);
            return;
        }

        $attachments = $_FILES['attachments'] ?? null;

        $result = (new GmailService())->sendReply(
            (int) $user['id'],
            $messageId,
            $body,
            $attachments
        );

        if (!$result['ok']) {
            json_response(
                false,
                null,
                $result['error'] ?? 'Unable to send reply.',
                502
            );
            return;
        }

        json_response(
            true,
            ['id' => $result['id'] ?? null],
            'Reply sent successfully.'
        );
    }

    /**
 * Get Gmail sent messages.
 * GET /gmail/sent
 */
public function sent(): void
{
    $user = require_login();

    $result = (new GmailService())->sent(
        (int) $user['id']
    );

    if (!$result['ok']) {
        json_response(
            false,
            ['reconnect' => $result['reconnect'] ?? false],
            $result['error'] ?? 'Unable to load sent emails.',
            502
        );
        return;
    }

    json_response(
        true,
        ['emails' => $result['emails']],
        'Sent emails loaded.'
    );
}

/**
 * Get the full Gmail conversation thread.
 * GET /gmail/thread?id=THREAD_ID
 */
public function thread(): void
{
    $user = require_login();

    $threadId = $_GET['id'] ?? '';

    if (
        !is_string($threadId) ||
        !preg_match('/^[A-Za-z0-9]+$/', $threadId)
    ) {
        json_response(
            false,
            null,
            'Invalid Gmail thread ID.',
            400
        );
        return;
    }

    $result = (new GmailService())->thread(
        (int) $user['id'],
        $threadId
    );

    if (!$result['ok']) {
        json_response(
            false,
            ['reconnect' => $result['reconnect'] ?? false],
            $result['error'] ?? 'Unable to load conversation.',
            502
        );
        return;
    }

    json_response(
        true,
        ['messages' => $result['messages']],
        'Conversation loaded.'
    );
}




    public function disconnect(): void
    {
        $user = require_login();
        (new GmailService())->disconnect((int) $user['id']);

        flash('success', 'Gmail was disconnected. The dashboard shows demo emails again.', 'Gmail disconnected');
        redirect(url('dashboard'));
    }


    /**
     * Get Gmail Spam messages.
     * GET /gmail/spam
     */
    public function spam(): void
    {
        $user = require_login();

        $result = (new GmailService())->spam(
            (int) $user['id']
        );

        if (!$result['ok']) {
            json_response(
                false,
                [
                    'reconnect' => $result['reconnect'] ?? false
                ],
                $result['error'] ?? 'Unable to load Spam.',
                502
            );
            return;
        }

        json_response(
            true,
            ['emails' => $result['emails'] ?? []],
            'Spam emails loaded.'
        );
    }

    /**
     * Get Gmail Trash messages.
     * GET /gmail/trash
     */
    public function trash(): void
    {
        $user = require_login();

        $result = (new GmailService())->trash(
            (int) $user['id']
        );

        if (!$result['ok']) {
            json_response(
                false,
                [
                    'reconnect' => $result['reconnect'] ?? false
                ],
                $result['error'] ?? 'Unable to load Trash.',
                502
            );
            return;
        }

        json_response(
            true,
            ['emails' => $result['emails'] ?? []],
            'Trash emails loaded.'
        );
    }

    /**
     * Perform Gmail message actions.
     * POST /gmail/action
     */
    public function action(): void
    {
        $user = require_login();

        if (!csrf_valid()) {
            json_response(
                false,
                null,
                'Invalid CSRF token.',
                403
            );
            return;
        }

        $messageId = (string) ($_POST['messageId'] ?? '');
        $action = (string) ($_POST['action'] ?? '');

        if (!preg_match('/^[A-Za-z0-9]+$/', $messageId)) {
            json_response(
                false,
                null,
                'Invalid Gmail message ID.',
                400
            );
            return;
        }

        $allowedActions = [
            'star',
            'unstar',
            'archive',
            'trash',
            'restore',
            'read',
            'unread',
            'spam',
            'not_spam',
            'important',
            'not_important'
        ];

        if (!in_array($action, $allowedActions, true)) {
            json_response(
                false,
                null,
                'Unsupported Gmail action.',
                422
            );
            return;
        }

        $result = (new GmailService())->action(
            (int) $user['id'],
            $messageId,
            $action
        );

        if (!$result['ok']) {
            json_response(
                false,
                [
                    'reconnect' => $result['reconnect'] ?? false
                ],
                $result['error'] ?? 'Gmail action failed.',
                502
            );
            return;
        }

        json_response(
            true,
            [
                'messageId' => $messageId,
                'action' => $action
            ],
            'Gmail action completed.'
        );
    }

    
}
