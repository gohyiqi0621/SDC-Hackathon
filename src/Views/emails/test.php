<?php
/**
 * src/Views/emails/test.php - body of the "send test email" action.
 *
 * Expects: $name, $appName
 */
$greeting = trim((string) $name) !== '' ? 'Hi ' . e($name) . ',' : 'Hi,';
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Email test</title></head>
<body style="margin:0;padding:0;background:#f4f6f5;">
  <div style="padding:32px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:18px;padding:32px 28px;
                box-shadow:0 1px 3px rgba(16,24,40,.08);">
      <p style="margin:0 0 4px;font-size:13px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#2E7D32;">
        <?= e($appName) ?>
      </p>
      <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#14181c;">Brevo delivery is working</h1>
      <p style="margin:0;font-size:15px;line-height:1.6;color:#4a5259;">
        <?= $greeting ?><br>
        This is a test email from <?= e($appName) ?>. Password-reset links are sent
        through the same Brevo connection, so if this message arrived, reset mail is
        configured correctly.
      </p>
    </div>
  </div>
</body>
</html>
