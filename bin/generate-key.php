<?php
/**
 * bin/generate-key.php - create APP_ENCRYPTION_KEY in .env (once).
 *
 * Usage (from the project folder):
 *     C:\xampp\php\php.exe bin\generate-key.php
 *
 * The key encrypts the Telegram bot token and Google tokens in PostgreSQL.
 * It refuses to replace an existing key, because that would make every stored
 * token unreadable. Never commit .env.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/generate-key.php is a command-line tool.\n");
}

$envPath = dirname(__DIR__) . '/.env';

if (!is_file($envPath)) {
    fwrite(STDERR, "No .env file found. Copy .env.example to .env first.\n");
    exit(1);
}

$contents = (string) file_get_contents($envPath);

if (preg_match('/^\s*APP_ENCRYPTION_KEY\s*=\s*\S+/m', $contents)) {
    fwrite(STDOUT, "APP_ENCRYPTION_KEY already exists in .env - left unchanged.\n");
    exit(0);
}

$line = 'APP_ENCRYPTION_KEY=base64:' . base64_encode(random_bytes(32));

// Replace an empty "APP_ENCRYPTION_KEY=" line, or append a new one.
if (preg_match('/^\s*APP_ENCRYPTION_KEY\s*=\s*$/m', $contents)) {
    $contents = (string) preg_replace('/^\s*APP_ENCRYPTION_KEY\s*=\s*$/m', $line, $contents, 1);
} else {
    $contents = rtrim($contents) . PHP_EOL . PHP_EOL
        . '# Encrypts stored Telegram / Google tokens. Do not change or share it.' . PHP_EOL
        . $line . PHP_EOL;
}

file_put_contents($envPath, $contents);
fwrite(STDOUT, "APP_ENCRYPTION_KEY added to .env.\n");
