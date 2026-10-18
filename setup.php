<?php
/**
 * setup.php - one-command bootstrap for a fresh clone.
 *
 * The repository deliberately ships no .env: that file holds the database
 * password and the encryption key, so it is git-ignored (`.env` in .gitignore).
 * A clone therefore cannot boot until someone copies .env.example to .env and
 * fills it in - which is exactly what this script does, plus a generated
 * APP_KEY, plus a connection test that says what is still missing.
 *
 * Run it from the project root:
 *
 *     php setup.php
 *
 * It only ever writes .env. It does NOT touch the database: importing
 * database.sql drops every table, so that stays a separate, deliberate command
 * (the script prints the exact one).
 */
declare(strict_types=1);

// A web request must never reach this file - it would write a .env over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("setup.php is a command-line tool. Run: php setup.php\n");
}

$root       = __DIR__;
$envExample = $root . '/.env.example';
$envPath    = $root . '/.env';
$force      = in_array('--force', $argv ?? [], true);

/* ------------------------------------------------------------- helpers --- */

function line(string $text = ''): void
{
    echo $text, PHP_EOL;
}

/** Prompt with a default; a closed stdin (piped input ran out) keeps the default. */
function ask(string $label, string $default = ''): string
{
    echo $label, ($default !== '' ? ' [' . $default . ']' : ''), ': ';
    $answer = fgets(STDIN);
    if ($answer === false) {
        line($default);
        return $default;
    }
    $answer = trim($answer);
    return $answer === '' ? $default : $answer;
}

function confirm(string $label, bool $default = false): bool
{
    $answer = strtolower(ask($label . ' (y/n)', $default ? 'y' : 'n'));
    return $answer === 'y' || $answer === 'yes';
}

/** Read a .env file into an array, using the same rules as config.php. */
function parse_env(string $content): array
{
    $values = [];
    foreach (preg_split('/\R/', $content) ?: [] as $raw) {
        $raw = trim($raw);
        if ($raw === '' || $raw[0] === '#' || !str_contains($raw, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $raw, 2);
        $value = trim($value);
        if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }
        $values[trim($key)] = $value;
    }
    return $values;
}

/**
 * Set one key in a .env file's text, keeping every comment and every other line
 * exactly as it is. A missing key is appended.
 *
 * preg_replace_callback, not preg_replace: a password may contain `$` or `\`,
 * which preg_replace would read as a back-reference.
 */
function env_set(string $content, string $key, string $value): string
{
    $lineText = $key . '=' . $value;
    $pattern  = '/^' . preg_quote($key, '/') . '=.*$/m';

    if (preg_match($pattern, $content) === 1) {
        return (string) preg_replace_callback($pattern, static fn (): string => $lineText, $content, 1);
    }

    return rtrim($content, "\r\n") . PHP_EOL . $lineText . PHP_EOL;
}

function fail(string $message, string $hint = ''): never
{
    line();
    line('  ✗ ' . $message);
    if ($hint !== '') {
        line();
        foreach (explode("\n", $hint) as $hintLine) {
            line('    ' . $hintLine);
        }
    }
    line();
    exit(1);
}

/* ----------------------------------------------------------- pre-flight --- */

line();
line('  SDC App - local setup');
line('  ' . str_repeat('-', 50));
line();

if (!is_file($envExample)) {
    fail('Cannot find .env.example next to setup.php.',
        'Run this from the project root: php setup.php');
}

foreach (['pdo_mysql' => 'PDO MySQL driver', 'openssl' => 'OpenSSL (for APP_KEY)'] as $ext => $label) {
    if (!extension_loaded($ext)) {
        fail('PHP is missing the ' . $ext . ' extension (' . $label . ').',
            'Enable it in php.ini, then run this again.');
    }
}

/* --------------------------------------------------------- 1. the .env ---- */

$values = [];

if (is_file($envPath) && !$force) {
    $existing = (string) file_get_contents($envPath);
    $values   = parse_env($existing);

    if (($values['APP_KEY'] ?? '') !== '') {
        line('  .env already exists with an APP_KEY - leaving it alone.');
        line('  (php setup.php --force rebuilds it from .env.example)');
        line();
    } else {
        // A half-written .env (copied by hand, APP_KEY still blank) is worth
        // completing rather than overwriting, so the user keeps their DB details.
        line('  .env exists but APP_KEY is empty - generating one.');
        $existing = env_set($existing, 'APP_KEY', base64_encode(random_bytes(32)));
        if (file_put_contents($envPath, $existing) === false) {
            fail('Could not write .env. Check the folder permissions.');
        }
        $values = parse_env($existing);
        line('  ✓ APP_KEY generated');
        line();
    }
}

if ($values === []) {
    line('  This creates .env with your local database details.');
    line('  Press Enter to accept the default shown in [brackets].');
    line();

    $host = ask('MySQL host', '127.0.0.1');
    $port = ask('MySQL port', '3306');
    $name = ask('Database name', 'sdc_app');
    $user = ask('MySQL user', 'root');
    $pass = ask('MySQL password (blank for the XAMPP default)', '');

    if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
        fail('The database name may only contain letters, digits and underscores.');
    }

    $content = (string) file_get_contents($envExample);
    $content = env_set($content, 'DB_HOST', $host);
    $content = env_set($content, 'DB_PORT', $port);
    $content = env_set($content, 'DB_NAME', $name);
    $content = env_set($content, 'DB_USER', $user);
    $content = env_set($content, 'DB_PASS', $pass);
    // 32 bytes, base64. Losing this key makes every stored secret unreadable,
    // so it is generated once and never regenerated behind the user's back.
    $content = env_set($content, 'APP_KEY', base64_encode(random_bytes(32)));

    if (is_file($envPath)) {
        $backup = $envPath . '.bak.' . date('Ymd-His');
        if (!copy($envPath, $backup)) {
            fail('Could not back up the existing .env to ' . basename($backup) . '.');
        }
        line('  Old .env saved as ' . basename($backup));
    }

    if (file_put_contents($envPath, $content) === false) {
        fail('Could not write .env. Check the folder permissions.');
    }
    @chmod($envPath, 0600);

    $values = parse_env($content);

    line();
    line('  ✓ .env created');
    line('  ✓ APP_KEY generated (keep this file - it is git-ignored)');
    line();
}

/* -------------------------------------------------- 2. can we connect? ---- */

$host = $values['DB_HOST'] ?? '127.0.0.1';
$port = $values['DB_PORT'] ?? '3306';
$name = $values['DB_NAME'] ?? 'sdc_app';
$user = $values['DB_USER'] ?? 'root';
$pass = $values['DB_PASS'] ?? '';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
} catch (PDOException $e) {
    fail('Cannot reach MySQL at ' . $host . ':' . $port . ' as "' . $user . '".',
        "Start MySQL in the XAMPP Control Panel, then run this again.\n"
        . "If your password is wrong, edit .env (DB_PASS) or re-run: php setup.php --force\n"
        . 'Driver said: ' . $e->getMessage());
}

line('  ✓ MySQL reachable at ' . $host . ':' . $port);

$stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$stmt->execute([$name]);
$hasDatabase = (bool) $stmt->fetchColumn();

$hasTables = false;
if ($hasDatabase) {
    $pdo->exec('USE `' . $name . '`');
    $hasTables = (bool) $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
}

if (!$hasDatabase || !$hasTables) {
    line('  · database "' . $name . '" is not imported yet');
} else {
    $tables = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $pdo->quote($name)
    )->fetchColumn();
    line('  ✓ database "' . $name . '" found (' . $tables . ' tables)');
}

/* ------------------------------------------------------- 3. next steps ---- */

$folder = basename($root);
$url    = 'http://localhost/' . $folder . '/public';

line();
line('  ' . str_repeat('-', 50));

if (!$hasDatabase || !$hasTables) {
    line();
    line('  Next: import the schema (this creates every table):');
    line();
    line('      C:\\xampp\\mysql\\bin\\mysql.exe -u root < database.sql');
    line();
    line('  No MySQL on your PATH? Paste database.sql into phpMyAdmin > SQL.');
    line('  database.sql is destructive: it DROPs and recreates the tables.');
    line();
}

line('  Open the app:');
line('      ' . $url);
line();
line('  Notes:');
line('  · The site is served from public/, not the project root.');
line('  · The first account you register is a normal user. Promote it once:');
line("        UPDATE users SET role = 'admin' WHERE email = 'you@example.com';");
line('  · API keys (Google, OneSignal, Agnes, Brevo) are NOT in .env - sign in as');
line('    an admin, open Key, and paste them there.');
line('  · AI Chat also needs the Python service: cd ai-service && python app.py');
line();
