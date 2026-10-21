<?php
/**
 * config.php - loads .env, defines constants, starts the session.
 * Every entry point requires this file first.
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/app/security.php';
require_once __DIR__ . '/app/google.php';
require_once __DIR__ . '/app/ai.php';
require_once __DIR__ . '/app/onesignal.php';
require_once __DIR__ . '/app/mail.php';

define('APP_ROOT', __DIR__);
// The served directory (where index.php, css/ and js/ live). asset_versioned()
// uses this to stat files; it is NOT a URL.
define('PUBLIC_ROOT', APP_ROOT . '/public');

// --- Environment loader (hand-rolled so XAMPP needs no Composer) ------------
// 1) Real environment variables (Railway, Docker) are read first.
// 2) A local .env file, if present, overrides them (XAMPP development).
$envPath = APP_ROOT . '/.env';

foreach ((getenv() ?: []) as $k => $v) {
    if (is_string($v) && preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $k)) {
        $_ENV[$k] = $v;
    }
}

if (!is_file($envPath) && !isset($_ENV['DB_HOST'])) {
    // A fresh clone lands here: .env is git-ignored on purpose (it holds the DB
    // password and APP_KEY), so the file has to be created locally once.
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "Missing .env file or DB_HOST variable.\n"
        . "\n"
        . "This project keeps its database credentials and encryption key out of Git,\n"
        . "so a fresh clone has no .env. Create one from the project root:\n"
        . "\n"
        . "    php setup.php\n"
        . "\n"
        . "It writes .env, generates APP_KEY and checks the database connection.\n"
        . "Prefer to do it by hand? Copy .env.example to .env and fill in DB_HOST,\n"
        . "DB_NAME, DB_USER and DB_PASS (plus a generated APP_KEY).\n"
    );
}

$__envLines = is_file($envPath)
    ? (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
    : [];

foreach ($__envLines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $key   = trim($key);
    $value = trim($value);
    // Strip matching surrounding quotes
    if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
        $value = substr($value, 1, -1);
    }
    $_ENV[$key] = $value;
}
unset($__envLines);

// --- App --------------------------------------------------------------------
define('APP_NAME', (string) env('APP_NAME', 'SME Smart'));
define('APP_ENV', (string) env('APP_ENV', 'production'));
define('APP_DEBUG', filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL));

/**
 * Base URL.
 *
 * `APP_URL` in .env is an OPTIONAL pin. Leave it empty and the base is derived
 * from the incoming request, which is what makes the app work unchanged behind
 * a tunnel (the host changes every restart) as well as on localhost.
 *
 * Pinning it is still supported: set APP_URL when the app must always emit one
 * canonical origin (e.g. a fixed production domain, or when serving from CLI).
 * Note the Google OAuth redirect URI is built from this value, so a pin must
 * match an authorised redirect URI in the Google console.
 */
$__appUrl = rtrim((string) env('APP_URL', ''), '/');
if ($__appUrl === '') {
    // Derive scheme + host from the request, then the app's own directory.
    $__https  = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $__scheme = $__https ? 'https' : 'http';
    $__host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    // The URL path the entry script runs under, e.g. /sdc-project/public.
    // SCRIPT_NAME is the reliable source: DOCUMENT_ROOT is the XAMPP htdocs
    // dir, whereas this file lives one level above the served directory.
    $__script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $__base   = rtrim(dirname($__script), '/');
    if ($__base === '.' || $__base === '/') {
        $__base = '';
    }

    $__appUrl = $__scheme . '://' . $__host . $__base;
}
define('APP_URL', rtrim($__appUrl, '/'));
unset($__appUrl, $__https, $__scheme, $__host, $__script, $__base);

// --- Database ---------------------------------------------------------------
define('DB_HOST', (string) env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (string) env('DB_PORT', '3306'));
define('DB_NAME', (string) env('DB_NAME', 'sdc_app'));
define('DB_USER', (string) env('DB_USER', 'root'));
define('DB_PASS', (string) env('DB_PASS', ''));

// --- Python AI service ------------------------------------------------------
define('AI_SERVICE_URL', rtrim((string) env('AI_SERVICE_URL', 'http://127.0.0.1:5000'), '/'));
define('AI_TIMEOUT', (int) env('AI_TIMEOUT', 15));

// --- Encryption (API key storage) -------------------------------------------
// A 32-byte key (base64). Without it, storing a key is refused rather than
// stored in the clear.
define('APP_KEY', (string) env('APP_KEY', ''));

// --- Google OAuth (optional) ------------------------------------------------
// Google rejects redirect URIs that contain a query string, so the callback is
// a clean path (rewritten to ?page=auth&action=googleCallback by public/.htaccess).
// The client id/secret are defined below, after the autoloader (setting() needs it).
define('GOOGLE_REDIRECT_URI', APP_URL . '/auth/google-callback');

// --- Runtime ----------------------------------------------------------------
date_default_timezone_set((string) env('APP_TIMEZONE', 'Asia/Kuala_Lumpur'));

// Resolve app/models/*.php and app/controllers/*.php by class name,
// so nothing has to hand-require them.
spl_autoload_register(static function (string $class): void {
    foreach (['models', 'controllers'] as $dir) {
        $file = APP_ROOT . "/app/{$dir}/{$class}.php";
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// --- Integration credentials (database only) ---------------------------------
// Google and OneSignal are configured by an admin on the Key page and stored in
// the `settings` table. They are deliberately NOT read from .env: the database
// is the single source of truth, so a key can be rotated from the UI without a
// redeploy, and no integration secret is left lying around in environment
// variables. An empty value means "not configured yet".
//
// Public identifiers (ODIC client id, OneSignal App ID) are handed to the
// browser; the secrets (client secret, REST key) stay server-side.
define('GOOGLE_CLIENT_ID', setting('google_client_id'));
define('GOOGLE_CLIENT_SECRET', setting('google_secret'));
define('ONESIGNAL_APP_ID', setting('onesignal_app_id'));
define('ONESIGNAL_API_KEY', setting('onesignal_api_key'));

// Agnes AI powers the chat replies. The Python service receives this per
// request and holds it for that call only.
define('AGNES_API_KEY', setting('agnes_api_key'));

// Brevo sends the "Forgot password" one-time codes. The API key is a secret;
// the sender address must be a verified sender on the Brevo account.
define('BREVO_API_KEY', setting('brevo_api_key'));
define('BREVO_SENDER_EMAIL', setting('brevo_sender_email'));
define('BREVO_SENDER_NAME', setting('brevo_sender_name'));

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}