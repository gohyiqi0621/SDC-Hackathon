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

    public function disconnect(): void
    {
        $user = require_login();
        (new GmailService())->disconnect((int) $user['id']);

        flash('success', 'Gmail was disconnected. The dashboard shows demo emails again.', 'Gmail disconnected');
        redirect(url('dashboard'));
    }
}
