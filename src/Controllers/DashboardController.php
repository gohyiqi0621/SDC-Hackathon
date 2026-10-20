<?php
/**
 * DashboardController.php - the home screen (the Smart SME AI email dashboard).
 *
 * The inbox is frontend mock data for now. The Brevo strip is real: it shows
 * whether password-reset email is configured and can send a test message.
 */
declare(strict_types=1);

namespace Src\Controllers;

use Src\Services\BrevoMailer;

final class DashboardController
{
    /** GET /?page=dashboard */
    public function index(): void
    {
        view('dashboard/index', [
            'user'       => require_login(),
            'brevoReady' => (new BrevoMailer())->isConfigured(),
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
