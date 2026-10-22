<?php
/**
 * bin/check.php - quick health check for the PostgreSQL connection.
 *
 * Usage:
 *     php bin/check.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/check.php is a command-line tool.\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Src\Support\Database;

// Which settings this process can see (values are never printed).
$keys = ['APP_URL', 'PUBLIC_APP_URL', 'APP_ENV', 'APP_DEBUG', 'APP_ENCRYPTION_KEY',
         'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_SSLMODE',
         'FLOWISE_API_URL', 'FLOWISE_CHATFLOW_ID', 'FLOWISE_API_KEY',
         'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GMAIL_CLIENT_ID', 'GMAIL_CLIENT_SECRET',
         'AGNES_API_KEY', 'BREVO_API_KEY', 'BREVO_SENDER_EMAIL'];
fwrite(STDOUT, '.env file: ' . (is_file(APP_ROOT . '/.env') ? 'present (only fills settings the environment does not set)' : 'not present') . PHP_EOL);
foreach ($keys as $key) {
    $fromEnv = (getenv($key) !== false && getenv($key) !== '') || !empty($_SERVER[$key]);
    $value   = (string) ($_ENV[$key] ?? '');
    fwrite(STDOUT, str_pad($key, 22) . ($value === '' ? 'MISSING' : 'set' . ($fromEnv ? ' (environment / Replit Secret)' : ' (.env file)')) . PHP_EOL);
}
fwrite(STDOUT, PHP_EOL);

try {
    $pdo = Database::connection();

    $version = (string) $pdo->query('SELECT version()')->fetchColumn();
    fwrite(STDOUT, 'Connected to ' . DB_NAME . ' on ' . DB_HOST . ':' . DB_PORT . PHP_EOL);
    fwrite(STDOUT, $version . PHP_EOL);

    foreach (['users', 'password_resets', 'product_categories', 'products', 'company_profiles', 'ai_assistant_settings', 'telegram_integrations', 'google_calendar_integrations', 'gmail_connections', 'telegram_updates', 'chat_messages'] as $table) {
        $stmt = $pdo->prepare("SELECT to_regclass('public." . $table . "')");
        $stmt->execute();
        $exists = $stmt->fetchColumn() !== null;
        fwrite(STDOUT, str_pad($table, 30) . ($exists ? 'ok' : 'MISSING - run php bin/migrate.php') . PHP_EOL);
    }

    $stmt = $pdo->query('SELECT COUNT(*) FROM users');
    fwrite(STDOUT, str_pad('users rows', 18) . (string) $stmt->fetchColumn() . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, 'Check failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
