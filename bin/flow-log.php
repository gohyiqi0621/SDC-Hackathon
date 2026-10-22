<?php
/**
 * bin/flow-log.php - read the Telegram -> system -> Flowise -> Telegram log.
 *
 * Usage (from the project folder):
 *     C:\xampp\php\php.exe bin\flow-log.php            last 40 steps of today
 *     C:\xampp\php\php.exe bin\flow-log.php 100        last 100 steps
 *     C:\xampp\php\php.exe bin\flow-log.php 40 2026-10-21   another day
 *
 * The raw file is storage/logs/telegram-flow-YYYY-MM-DD.log (one JSON line
 * per step; secrets are already masked when written).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/flow-log.php is a command-line tool.\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

$count = max(1, (int) ($argv[1] ?? 40));
$day   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($argv[2] ?? '')) ? $argv[2] : date('Y-m-d');
$file  = APP_ROOT . '/storage/logs/telegram-flow-' . $day . '.log';

if (!is_file($file)) {
    fwrite(STDOUT, "No flow log for $day yet ($file).\n");
    fwrite(STDOUT, "If you messaged the bot and nothing appears here, Telegram never reached this app:\n");
    fwrite(STDOUT, "  run C:\\xampp\\php\\php.exe bin\\telegram-webhook.php status\n");
    exit(0);
}

$lines = array_slice(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -$count);
$last  = null;

foreach ($lines as $line) {
    $row = json_decode($line, true);
    if (!is_array($row)) {
        continue;
    }
    if ($row['req'] !== $last) {
        fwrite(STDOUT, PHP_EOL . '--- request ' . $row['req'] . ' ---' . PHP_EOL);
        $last = $row['req'];
    }

    $time = substr((string) $row['time'], 11, 8);
    $step = (string) $row['step'];
    unset($row['time'], $row['req'], $row['step']);

    $details = [];
    foreach ($row as $key => $value) {
        if ($value === null || $value === '' || $value === []) {
            continue;
        }
        $text = match (true) {
            is_bool($value)   => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default           => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
        $text = str_replace(["\r", "\n"], ' ', $text);
        if (mb_strlen($text) > 300) {
            $text = mb_substr($text, 0, 300) . '…';
        }
        $details[] = $key . '=' . $text;
    }

    fwrite(STDOUT, $time . '  ' . str_pad($step, 25) . implode('  ', $details) . PHP_EOL);
}
