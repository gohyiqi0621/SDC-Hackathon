<?php
/**
 * bootstrap.php - the one file every entry point loads first.
 *
 * Responsibilities, in order:
 *   1. define the paths the rest of the app uses
 *   2. load .env (and real environment variables)
 *   3. register the PSR-4 autoloader for Src\*
 *   4. define app / database / mail constants
 *   5. configure a hardened PHP session
 *   6. send baseline security headers
 *
 * Nothing here talks to the database. That happens lazily, on first use, so a
 * page that only renders HTML (an error page, for example) still works when
 * PostgreSQL is down.
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

define('SRC_ROOT', __DIR__);
define('PUBLIC_ROOT', APP_ROOT . '/public');
define('STORAGE_ROOT', APP_ROOT . '/storage');

require_once SRC_ROOT . '/Support/helpers.php';

/* ------------------------------------------------------------- autoload --- */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Src\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = SRC_ROOT . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

/* ---------------------------------------------------------- environment --- */
$__envPath = APP_ROOT . '/.env';
$__envFile = [];

// Hand-rolled so XAMPP needs no Composer and a value may contain any character.
// Handles KEY=value, export KEY=value, # comments, and matching quotes.
if (is_file($__envPath)) {
    foreach ((array) file($__envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__line) {
        $__line = trim((string) $__line);
        if ($__line === '' || $__line[0] === '#' || !str_contains($__line, '=')) {
            continue;
        }

        $__line = (string) preg_replace('/^export\s+/', '', $__line);
        [$__key, $__value] = explode('=', $__line, 2);
        $__key   = trim($__key);
        $__value = trim($__value);

        if (strlen($__value) > 1
            && ($__value[0] === '"' || $__value[0] === "'")
            && str_ends_with($__value, $__value[0])) {
            $__value = substr($__value, 1, -1);
        }

        if ($__key !== '' && preg_match('/^[A-Z][A-Z0-9_]*$/', $__key)) {
            $__envFile[$__key] = $__value;
        }
    }
}

// Real environment variables (Replit Secrets, Docker, Railway) win. A .env
// file only fills in what the environment does not set, so XAMPP works with
// .env alone and a stray .env on a server can never blank out a Secret.
// $_SERVER is read too: some PHP setups (php-fpm, Apache) only put the
// environment there, and getenv() alone then returns nothing.
foreach ([(getenv() ?: []), $_SERVER] as $__source) {
    foreach ($__source as $__key => $__value) {
        if (is_string($__value) && $__value !== '' && preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $__key)
            && !str_starts_with((string) $__key, 'HTTP_') && !isset($_ENV[$__key])) {
            $_ENV[$__key] = $__value;
        }
    }
}
foreach ($__envFile as $__key => $__value) {
    if (!isset($_ENV[$__key]) || $_ENV[$__key] === '') {
        $_ENV[(string) $__key] = $__value;
    }
}
unset($__source);
unset($__envPath, $__envFile, $__key, $__value, $__line);

/* --------------------------------------------------------------- config --- */
define('APP_NAME', (string) env('APP_NAME', 'SME Smart'));
define('APP_ENV', (string) env('APP_ENV', 'production'));
define('APP_DEBUG', filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL));
define('APP_TIMEZONE', (string) env('APP_TIMEZONE', 'Asia/Kuala_Lumpur'));

/**
 * Base URL.
 *
 * Pinning APP_URL in .env is supported and is the reliable option on XAMPP,
 * where the app lives in a subdirectory. When it is empty the URL is derived
 * from the incoming request so a tunnel or a move to another folder keeps
 * working without an edit.
 */
$__appUrl = rtrim((string) env('APP_URL', ''), '/');
if ($__appUrl === '') {
    $__scheme = is_https() ? 'https' : 'http';
    $__host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $__script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $__base   = rtrim(dirname($__script), '/');

    if ($__base === '.' || $__base === '/') {
        $__base = '';
    }

    $__appUrl = $__scheme . '://' . $__host . $__base;
}

// Opened through the public tunnel (e.g. ngrok)? Then build links and
// redirects on that address, so the browser is not sent back to localhost.
$__public = rtrim((string) env('PUBLIC_APP_URL', ''), '/');
if ($__public !== '' && PHP_SAPI !== 'cli') {
    $__publicHost = strtolower((string) parse_url($__public, PHP_URL_HOST));
    $__reqHost    = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($__publicHost !== '' && $__publicHost === $__reqHost) {
        $__appUrl = $__public;
    }
}
define('APP_URL', rtrim($__appUrl, '/'));
unset($__appUrl, $__scheme, $__host, $__script, $__base, $__public, $__publicHost, $__reqHost);


/* ------------------------------------------------------------- database --- */
// PostgreSQL only. These are the same DB_* names the rest of the project uses;
// the DSN itself is built in Src\Support\Database.
define('DB_HOST', (string) env('DB_HOST', env('PGHOST', '127.0.0.1')));
define('DB_PORT', (string) env('DB_PORT', env('PGPORT', '5432')));
define('DB_NAME', (string) env('DB_NAME', env('PGDATABASE', 'sme_assistant')));
define('DB_USER', (string) env('DB_USER', env('PGUSER', 'postgres')));
define('DB_PASS', (string) env('DB_PASS', env('PGPASSWORD', '')));
define('DB_SSLMODE', (string) env('DB_SSLMODE', 'prefer'));

/* ----------------------------------------------------------------- mail --- */
define('BREVO_API_KEY', (string) env('BREVO_API_KEY', ''));
define('BREVO_SENDER_EMAIL', (string) env('BREVO_SENDER_EMAIL', ''));
define('BREVO_SENDER_NAME', (string) env('BREVO_SENDER_NAME', APP_NAME));
define('BREVO_API_URL', (string) env('BREVO_API_URL', 'https://api.brevo.com/v3/smtp/email'));
define('MAIL_CA_BUNDLE', (string) env('MAIL_CA_BUNDLE', ''));

/* ---------------------------------------------------------------- gmail --- */
// Google OAuth client (Google Cloud Console > APIs & Services > Credentials).
// The redirect URI must be listed there exactly as below.
define('GMAIL_CLIENT_ID', (string) env('GMAIL_CLIENT_ID', ''));
define('GMAIL_CLIENT_SECRET', (string) env('GMAIL_CLIENT_SECRET', ''));
define('GMAIL_REDIRECT_URI', (string) (env('GMAIL_REDIRECT_URI', '') ?: APP_URL . '/gmail/callback'));
define('GMAIL_MAX_RESULTS', max(1, min(50, (int) env('GMAIL_MAX_RESULTS', '20'))));

/* -------------------------------------------- telegram + flowise (AI) --- */
// Public HTTPS address of this app (the same place APP_URL points to), used
// for the Telegram webhook and for Flowise tools calling back into the app.
// On XAMPP use a tunnel, e.g. https://abc123.ngrok-free.app/SDC-Hackathon/public
define('PUBLIC_APP_URL', rtrim((string) (env('PUBLIC_APP_URL', '') ?: APP_URL), '/'));

// Flowise prediction API: POST {FLOWISE_API_URL}/api/v1/prediction/{chatflow id}
define('FLOWISE_API_URL', rtrim((string) env('FLOWISE_API_URL', ''), '/'));
define('FLOWISE_CHATFLOW_ID', (string) env('FLOWISE_CHATFLOW_ID', ''));
define('FLOWISE_API_KEY', (string) env('FLOWISE_API_KEY', ''));
define('FLOWISE_TIMEOUT', max(5, min(300, (int) env('FLOWISE_TIMEOUT', '90'))));

/* -------------------------------------------------------------- session --- */
define('SESSION_NAME', (string) env('SESSION_NAME', 'sme_session'));
define('SESSION_IDLE_MINUTES', max(1, (int) env('SESSION_IDLE_MINUTES', '60')));
define('SESSION_LIFETIME_MINUTES', max(1, (int) env('SESSION_LIFETIME_MINUTES', '480')));
define('PASSWORD_MIN_LENGTH', max(8, min(72, (int) env('PASSWORD_MIN_LENGTH', '8'))));

date_default_timezone_set(APP_TIMEZONE);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

/* --------------------------------------------------------------- session --- */
// CLI scripts (bin/create-user.php) have no session and must not start one.
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    // PHP's own clean-up must not delete a session before our timeouts end
    // (its default is 24 minutes); AuthService enforces the idle limit.
    ini_set('session.gc_maxlifetime', (string) (SESSION_LIFETIME_MINUTES * 60));
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');

    // Sessions live in PostgreSQL, so a restart or redeploy (Replit) does not sign everyone out.
    session_set_save_handler(new \Src\Support\DatabaseSessionHandler(SESSION_LIFETIME_MINUTES * 60), true);

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,                 // browser-session cookie; the server owns the timeout
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ------------------------------------------------------- security heads --- */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    if (APP_ENV === 'development') {
        // Permit the Replit preview while keeping unrelated sites from framing it.
        header("Content-Security-Policy: frame-ancestors 'self' https://replit.com https://*.replit.com https://*.replit.dev");
    } else {
        header('X-Frame-Options: DENY');
    }
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cache-Control: no-store, private');
}
