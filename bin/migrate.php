<?php
/**
 * bin/migrate.php - apply database/schema.sql to the PostgreSQL database.
 *
 * Usage:
 *     php bin/migrate.php
 *
 * The schema is idempotent (CREATE ... IF NOT EXISTS), so running it again
 * does not drop or duplicate anything. It is safe on an empty database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/migrate.php is a command-line tool.\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Src\Support\Database;

$schemaFile = APP_ROOT . '/database/schema.sql';

if (!is_file($schemaFile)) {
    fwrite(STDERR, "Cannot find database/schema.sql.\n");
    exit(1);
}

$sql = (string) file_get_contents($schemaFile);

try {
    $pdo = Database::connection();
    $pdo->exec($sql);

    $tables = [];
    foreach (['users', 'password_resets'] as $table) {
        $stmt = $pdo->prepare("SELECT to_regclass('public." . $table . "')");
        $stmt->execute();
        $tables[$table] = $stmt->fetchColumn() !== null;
    }

    fwrite(STDOUT, "Schema applied to " . DB_NAME . " on " . DB_HOST . ':' . DB_PORT . PHP_EOL);

    foreach ($tables as $table => $exists) {
        fwrite(STDOUT, '  - ' . $table . ': ' . ($exists ? 'ok' : 'MISSING') . PHP_EOL);
    }

    if (in_array(false, $tables, true)) {
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
