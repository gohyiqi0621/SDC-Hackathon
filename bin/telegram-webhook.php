<?php
/**
 * bin/telegram-webhook.php - link every connected bot to this app again.
 *
 * Usage (from the project folder):
 *     C:\xampp\php\php.exe bin\telegram-webhook.php          register all connected bots
 *     C:\xampp\php\php.exe bin\telegram-webhook.php status   show what Telegram has
 *
 * Run it after changing PUBLIC_APP_URL in .env (for example a new ngrok
 * address): Telegram keeps calling the old address until told otherwise.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/telegram-webhook.php is a command-line tool.\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Src\Models\TelegramIntegration;
use Src\Services\TelegramService;
use Src\Services\TelegramWebhookService;
use Src\Support\Crypto;

$statusOnly = ($argv[1] ?? '') === 'status';

fwrite(STDOUT, 'PUBLIC_APP_URL: ' . PUBLIC_APP_URL . PHP_EOL);
if (!$statusOnly && !str_starts_with(PUBLIC_APP_URL, 'https://')) {
    fwrite(STDERR, "PUBLIC_APP_URL must be a public https:// address (e.g. your ngrok URL + /SDC-Hackathon/public).\n");
    exit(1);
}

$bots = (new TelegramIntegration())->allConnected();
if ($bots === []) {
    fwrite(STDOUT, "No connected Telegram bots. Connect one in AI Settings & Integration first.\n");
    exit(0);
}

$failed = false;

foreach ($bots as $bot) {
    $label = '@' . ($bot['bot_username'] ?? '?') . ' (user ' . $bot['user_id'] . ')';

    try {
        $token = Crypto::decrypt((string) $bot['bot_token_encrypted']);
    } catch (RuntimeException $e) {
        fwrite(STDOUT, "  $label  cannot decrypt the token - reconnect it in AI Settings\n");
        $failed = true;
        continue;
    }

    if ($statusOnly) {
        $info = (new TelegramService())->webhookInfo($token);
        fwrite(STDOUT, "  $label\n");
        fwrite(STDOUT, '      url:     ' . ($info['url'] ?? '(none)') . PHP_EOL);
        fwrite(STDOUT, '      pending: ' . ($info['pending_update_count'] ?? '?') . PHP_EOL);
        if (!empty($info['last_error_message'])) {
            fwrite(STDOUT, '      error:   ' . $info['last_error_message'] . PHP_EOL);
        }
        continue;
    }

    $result = (new TelegramWebhookService())->register($bot, $token);
    fwrite(STDOUT, '  ' . ($result['ok'] ? 'ok    ' : 'FAILED') . "  $label  " . ($result['ok'] ? $result['url'] : $result['message']) . PHP_EOL);
    $failed = $failed || !$result['ok'];
}

exit($failed ? 1 : 0);
