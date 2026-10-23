<?php
/**
 * bin/migrate.php - create / update the PostgreSQL tables.
 *
 * Usage (from the project folder):
 *     C:\xampp\php\php.exe bin\migrate.php
 *
 * Runs, in order:
 *   1. database/schema.sql              users + password_resets
 *   2. database/migrations/NNN_*.sql    numbered migrations, lowest first
 *      (003 adds the AI Settings & Integration tables)
 *
 * Every file is idempotent (CREATE ... IF NOT EXISTS), so running this again
 * never drops, resets or duplicates anything - including the users table.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/migrate.php is a command-line tool.\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Src\Support\Database;

$files = [APP_ROOT . '/database/schema.sql'];

$migrations = glob(APP_ROOT . '/database/migrations/[0-9][0-9][0-9]_*.sql') ?: [];
sort($migrations, SORT_STRING);
$files = array_merge($files, $migrations);

try {
    $pdo = Database::connection();

    fwrite(STDOUT, 'Database: ' . DB_NAME . ' on ' . DB_HOST . ':' . DB_PORT . PHP_EOL);

    foreach ($files as $file) {
        if (!is_file($file)) {
            fwrite(STDERR, 'Missing file: ' . $file . PHP_EOL);
            exit(1);
        }

        $pdo->exec((string) file_get_contents($file));
        fwrite(STDOUT, '  applied  ' . str_replace(APP_ROOT . '/', '', $file) . PHP_EOL);
    }

    $missing = false;
    foreach (['users', 'password_resets', 'product_categories', 'products', 'company_profiles', 'ai_assistant_settings', 'staff_ai_assistant_settings', 'telegram_integrations', 'google_calendar_integrations', 'gmail_connections', 'invoices', 'invoice_items', 'receipts', 'document_counters', 'finance_settings', 'file_backups', 'app_sessions', 'claims', 'telegram_updates', 'chat_messages'] as $table) {
        $stmt = $pdo->prepare('SELECT to_regclass(?)');
        $stmt->execute(['public.' . $table]);
        $exists = $stmt->fetchColumn() !== null;
        $missing = $missing || !$exists;

        fwrite(STDOUT, '  table    ' . str_pad($table, 30) . ($exists ? 'ok' : 'MISSING') . PHP_EOL);
    }

    exit($missing ? 1 : 0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
