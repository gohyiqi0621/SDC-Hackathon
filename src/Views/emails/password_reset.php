<?php
/**
 * src/Views/emails/password_reset.php - HTML body for the reset link.
 *
 * Expects: $name, $resetUrl, $ttlMinutes, $appName
 * Inline styles on purpose: Gmail strips some <style> blocks and an email
 * cannot read the app's CSS variables.
 */
$greeting = trim((string) $name) !== '' ? 'Hi ' . e($name) . ',' : 'Hi,';
$app      = e($appName);
$url      = e($resetUrl);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset your password</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f5;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">
    Your <?= $app ?> password reset link expires in <?= (int) $ttlMinutes ?> minutes.
  </div>
  <div style="padding:32px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:18px;padding:32px 28px;
                box-shadow:0 1px 3px rgba(16,24,40,.08);">
      <p style="margin:0 0 4px;font-size:13px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#2E7D32;">
        <?= $app ?>
      </p>
      <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#14181c;">Reset your password</h1>
      <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#4a5259;">
        <?= $greeting ?><br>
        We received a request to reset the password for your account. Use the button below
        to choose a new one. The link works once and expires in <?= (int) $ttlMinutes ?> minutes.
      </p>
      <p style="margin:0 0 24px;text-align:center;">
        <a href="<?= $url ?>"
           style="display:inline-block;padding:14px 24px;border-radius:12px;background:#14181c;color:#ffffff;
                  font-size:15px;font-weight:600;text-decoration:none;">
          Choose a new password
        </a>
      </p>
      <p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#4a5259;">
        Button not working? Paste this link into your browser:
      </p>
      <p style="margin:0 0 20px;font-size:12px;line-height:1.5;color:#2E7D32;word-break:break-all;">
        <?= $url ?>
      </p>
      <p style="margin:0;font-size:13px;line-height:1.6;color:#7b848c;">
        If you did not ask to reset your password, you can ignore this email &mdash;
        your current password still works and the link will expire on its own.
      </p>
    </div>
    <p style="max-width:480px;margin:16px auto 0;text-align:center;font-size:12px;color:#98a1a9;">
      Sent by <?= $app ?>. This address does not accept replies.
    </p>
  </div>
</body>
</html>
