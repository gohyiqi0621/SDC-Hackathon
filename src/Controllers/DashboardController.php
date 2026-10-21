<?php
/**
 * DashboardController.php - the home screen (the SME Smart AI email dashboard).
 *
 * The inbox shows the user's real Gmail when connected (GmailController feeds
 * it as JSON), otherwise demo emails. The Brevo strip shows whether
 * password-reset email is configured and can send a test message.
 */
declare(strict_types=1);

namespace Src\Controllers;

use Src\Models\GmailConnection;
use Src\Services\BrevoMailer;
use Src\Services\GmailService;

final class DashboardController
{
    /** GET /?page=dashboard */
    public function index(): void
    {
        $user        = require_login();
        $connections = new GmailConnection();
        $tableReady  = $connections->tableExists();
        $connection  = $tableReady ? $connections->find((int) $user['id']) : null;

        view('dashboard/index', [
            'user'        => $user,
            'brevoReady'  => (new BrevoMailer())->isConfigured(),
            'gmailReady'  => (new GmailService())->isConfigured() && $tableReady,
            'gmailTable'  => $tableReady,
            'gmailEmail'  => $connection['google_email'] ?? null,
        ]);
    }

    /** POST /?page=dashboard&action=test_email - send a test email to the signed-in user. */
    public function testEmail(): void
    {
        $user = require_login();
        $sent = (new BrevoMailer())->sendTest($user);

        flash(
            $sent['ok'] ? 'success' : 'error',
            $sent['ok']
                ? 'Test email sent to ' . $user['email'] . '. Check the inbox.'
                : 'The test email could not be sent. Check the Brevo key, sender and storage/logs/mail.log.',
            $sent['ok'] ? 'Email sent' : 'Email not sent'
        );

        redirect(url('dashboard'));
    }
}
