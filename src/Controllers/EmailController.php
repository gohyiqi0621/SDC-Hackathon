<?php
/**
 * EmailController.php - the SME Smart AI email dashboard.
 *
 * Formerly the standalone email/index.php page; it now runs through the
 * router like every other page, so AuthMiddleware and the central CSRF check
 * apply. The Brevo panel proves the connection used by password-reset email.
 */
declare(strict_types=1);

namespace Src\Controllers;

use Src\Services\BrevoMailer;

final class EmailController
{
    /** GET /?page=email */
    public function index(): void
    {
        $user = require_login();

        view('email/index', [
            'workerName' => (string) $user['name'],
            'workerRole' => 'Member',
            'brevoReady' => (new BrevoMailer())->isConfigured(),
        ]);
    }

    /** POST /?page=email&action=test - send a test message to the signed-in user. */
    public function test(): void
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

        redirect(url('email'));
    }
}
