<?php
/**
 * src/Views/emails/password_reset_text.php - plain-text alternative.
 *
 * Expects: $name, $resetUrl, $ttlMinutes, $appName
 */
$greeting = trim((string) $name) !== '' ? 'Hi ' . $name . ',' : 'Hi,';
?>
<?= $greeting ?>

We received a request to reset the password for your <?= $appName ?> account.

Choose a new password here:
<?= $resetUrl ?>

The link works once and expires in <?= (int) $ttlMinutes ?> minutes.

If you did not ask to reset your password, ignore this email. Your current
password still works and the link will expire on its own.
