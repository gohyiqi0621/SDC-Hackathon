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

try {
    $pdo = Database::connection();

    $version = (string) $pdo->query('SELECT version()')->fetchColumn();
    fwrite(STDOUT, 'Connected to ' . DB_NAME . ' on ' . DB_HOST . ':' . DB_PORT . PHP_EOL);
    fwrite(STDOUT, $version . PHP_EOL);

    foreach (['users', 'password_resets', 'product_categories', 'products', 'company_profiles', 'ai_assistant_settings', 'telegram_integrations', 'google_calendar_integrations', 'gmail_connections'] as $table) {
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
