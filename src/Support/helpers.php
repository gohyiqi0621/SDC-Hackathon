<?php
/**
 * helpers.php - small shared functions with no dependencies.
 *
 * Loaded first by bootstrap.php. Anything that needs the database (or a model)
 * is called lazily, so this file is safe during early boot and on error pages.
 */
declare(strict_types=1);

/** Read a value loaded from .env / the real environment. */
function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? $default;
}

/** True when the request reached us over HTTPS (directly or via a proxy). */
function is_https(): bool
{
    $https = (string) ($_SERVER['HTTPS'] ?? '');
    if ($https !== '' && strtolower($https) !== 'off') {
        return true;
    }

    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Escape any value for safe HTML output. Use on every echo in a view. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Build an internal URL: url('financial', ['action' => 'invoice']).
 *
 * Query-string routing is deliberate: it works in a XAMPP subdirectory, at a
 * domain root, and behind a tunnel without a rewrite rule being correct.
 * public/.htaccess also maps clean paths onto the same parameters.
 */
function url(string $page = '', array $params = []): string
{
    if ($page === '' && $params === []) {
        return APP_URL . '/';
    }

    if ($page !== '') {
        $params = ['page' => $page] + $params;
    }

    return APP_URL . '/?' . http_build_query($params);
}

/** Build a URL to a file inside public/ (css, js, images). */
function asset(string $path): string
{
    return APP_URL . '/' . ltrim($path, '/');
}

/** asset() plus ?v=<filemtime>, so a changed CSS/JS file busts the cache. */
function asset_versioned(string $path): string
{
    $file  = PUBLIC_ROOT . '/' . ltrim($path, '/');
    $stamp = is_file($file) ? (string) filemtime($file) : '';

    return asset($path) . ($stamp !== '' ? '?v=' . $stamp : '');
}

function redirect(string $target): never
{
    header('Location: ' . $target);
    exit;
}

/** Render a view from src/Views/, e.g. view('auth/login', ['errors' => $errors]). */
function view(string $path, array $data = []): void
{
    \Src\Support\View::render($path, $data);
}

/** Set a response status line that Apache also understands. */
function set_status(int $code): void
{
    $reasons = [
        200 => 'OK',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        419 => 'Page Expired',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Server Error',
    ];

    if (isset($reasons[$code])) {
        header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' ' . $code . ' ' . $reasons[$code]);
        return;
    }

    http_response_code($code);
}

/** True when the caller is fetch() rather than a plain browser form post. */
function wants_json(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || ((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'XMLHttpRequest';
}

/** Standard JSON envelope. Always exits. */
function json_response(bool $success, mixed $data = null, string $message = '', int $code = 200): never
{
    set_status($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'data'    => $data ?? new stdClass(),
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * A successful form post: JSON with a redirect target for fetch() callers,
 * flash + redirect for a plain browser submit. Always exits.
 */
function respond_ok(string $message, string $redirectTo): never
{
    if (wants_json()) {
        json_response(true, ['redirect' => $redirectTo], $message);
    }

    flash('success', $message);
    redirect($redirectTo);
}

function is_post(): bool
{
    return (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Best-effort client address, capped to the column size in the schema. */
function client_ip(): ?string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip === '' ? null : substr($ip, 0, 45);
}

/* ---------------------------------------------------------------- CSRF ---- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/** Drop this inside every POST form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Accepts the token from a form field or an X-CSRF-Token header (fetch calls). */
function csrf_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

/** Rotate the token after a privilege change (sign-in, password change). */
function csrf_rotate(): void
{
    unset($_SESSION['csrf_token']);
    csrf_token();
}

/* --------------------------------------------------------------- flash ---- */

function flash(string $type, string $message, string $title = ''): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message, 'title' => $title];
}

/** Pull and clear queued flash messages. */
function flashes(): array
{
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($all) ? $all : [];
}

/* ---------------------------------------------------------------- auth ---- */

/** The signed-in user, re-read from PostgreSQL once per request. */
function current_user(): ?array
{
    return \Src\Services\AuthService::user();
}

/** Page guard used by controllers and views. */
function require_login(): array
{
    return \Src\Services\AuthService::requireUser();
}

/** Up to two initials for the avatar circle: "Goh Yi Qi" -> "GY". */
function initials_of(?array $user): string
{
    $words = preg_split('/\s+/', trim((string) ($user['name'] ?? ''))) ?: [];
    $words = array_values(array_filter($words, static fn (string $w): bool => $w !== ''));

    if ($words === []) {
        return '?';
    }

    $letters = mb_substr($words[0], 0, 1) . (isset($words[1]) ? mb_substr($words[1], 0, 1) : '');

    return mb_strtoupper($letters);
}

/** "MYR 1,234.50" from a NUMERIC string. Empty for null. */
function format_money(mixed $amount, string $currency = 'MYR'): string
{
    if ($amount === null || $amount === '') {
        return '';
    }

    return trim($currency) . ' ' . number_format((float) $amount, 2);
}

/** Display a stored timestamp in the app's timezone. */
function fmt_datetime(?string $value, string $format = 'j M Y, H:i'): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    $ts = strtotime($value);

    return $ts === false ? '—' : date($format, $ts);
}

/**
 * The sidebar navigation. Also drives the page-title lookup, so the two can
 * never drift apart. Icons are Font Awesome classes, matching the dashboard.
 *
 * Financial and AI Chatbot are collapsible groups; Profile and Logout live in
 * the account section. There is no admin portal anywhere in this app.
 *
 * @return array<int, array<string, mixed>>
 */
function nav_items(): array
{
    return [
        [
            'kind'  => 'link',
            'key'   => 'dashboard',
            'label' => 'Dashboard',
            'icon'  => 'fa-solid fa-house',
            'url'   => url('dashboard'),
            'group' => 'Workspace',
        ],
        [
            'kind'     => 'group',
            'key'      => 'financial',
            'label'    => 'Financial',
            'icon'     => 'fa-solid fa-wallet',
            'group'    => 'Workspace',
            'children' => [
                ['key' => 'financial/invoice',    'label' => 'Invoice',        'icon' => 'fa-solid fa-file-invoice',        'url' => url('financial', ['action' => 'invoice'])],
                ['key' => 'financial/claims',     'label' => 'Claims',         'icon' => 'fa-solid fa-receipt',             'url' => url('financial', ['action' => 'claims'])],
                ['key' => 'financial/settlement', 'label' => 'Settlement',     'icon' => 'fa-solid fa-scale-balanced',      'url' => url('financial', ['action' => 'settlement'])],
                ['key' => 'financial/upload',     'label' => 'Upload Invoice', 'icon' => 'fa-solid fa-cloud-arrow-up',      'url' => url('financial', ['action' => 'upload'])],
            ],
        ],
        [
            'kind'     => 'group',
            'key'      => 'chatbot',
            'label'    => 'AI Chatbot',
            'icon'     => 'fa-solid fa-robot',
            'group'    => 'Workspace',
            // Pages that keep this group open and highlighted.
            'match'    => ['customer', 'staff', 'products', 'categories'],
            'children' => [
                ['heading' => 'Customer'],
                ['key' => 'customer/settings', 'label' => 'Setting & Integration', 'icon' => 'fa-solid fa-sliders',       'url' => url('customer', ['action' => 'settings'])],
                ['key' => 'products',          'label' => 'Product & Service',     'icon' => 'fa-solid fa-box-open',      'url' => url('products'), 'match' => ['products', 'categories']],
                ['key' => 'customer/chat',     'label' => 'Chat Interface',        'icon' => 'fa-regular fa-comments',    'url' => url('customer', ['action' => 'chat'])],
                ['heading' => 'Staff'],
                ['key' => 'staff/settings',    'label' => 'Setting & Integration', 'icon' => 'fa-solid fa-sliders',       'url' => url('staff', ['action' => 'settings'])],
                ['key' => 'staff/chat',        'label' => 'Chat Interface',        'icon' => 'fa-regular fa-comments',    'url' => url('staff', ['action' => 'chat'])],
            ],
        ],
        [
            'kind'  => 'link',
            'key'   => 'profile',
            'label' => 'Profile',
            'icon'  => 'fa-solid fa-circle-user',
            'url'   => url('profile'),
            'group' => 'Account',
        ],
        [
            'kind'  => 'logout',
            'key'   => 'logout',
            'label' => 'Logout',
            'icon'  => 'fa-solid fa-arrow-right-from-bracket',
            'group' => 'Account',
        ],
    ];
}

/** The sanitized route key that marks the current nav item as active. */
function page_key(): string
{
    $page   = strtolower((string) ($_GET['page'] ?? 'dashboard'));
    $action = strtolower((string) ($_GET['action'] ?? ''));
    $page   = (string) preg_replace('/[^a-z0-9_]/', '', $page) ?: 'dashboard';
    $action = (string) preg_replace('/[^a-z0-9_]/', '', $action);

    return $action !== '' && $action !== 'index' ? $page . '/' . $action : $page;
}

/**
 * True when $key (or any of $item['match']) is the active route or a parent
 * of it: 'products' matches 'products' and 'products/edit'.
 *
 * @param string|array<string, mixed> $item a route key or a nav item
 */
function nav_is_active(string|array $item, ?string $current = null): bool
{
    $current = $current ?? page_key();
    $keys    = is_array($item) ? ($item['match'] ?? [$item['key']]) : [$item];

    foreach ($keys as $key) {
        if ($current === $key || str_starts_with($current, $key . '/')) {
            return true;
        }
    }

    return false;
}

/** Human label for the current page, for the top bar and breadcrumb. */
function page_title_for(string $key): string
{
    $titles = [
        'dashboard'           => 'Dashboard',
        'profile'             => 'Profile',
        'financial/invoice'   => 'Invoice',
        'financial/claims'    => 'Claims',
        'financial/settlement'=> 'Settlement',
        'financial/upload'    => 'Upload Invoice',
        'customer/settings'   => 'Customer Setting & Integration',
        'customer/chat'       => 'Customer Chat Interface',
        'staff/settings'      => 'Staff Setting & Integration',
        'staff/chat'          => 'Staff Chat Interface',
        'products'            => 'Product & Service',
        'products/create'     => 'Add Product / Service',
        'products/edit'       => 'Edit Product / Service',
        'categories'          => 'Categories',
        'auth/login'          => 'Sign in',
        'auth/forgot'         => 'Forgot password',
        'auth/reset'          => 'Reset password',
        'auth/register'       => 'Create account',
    ];

    return $titles[$key] ?? 'Dashboard';
}

/* ----------------------------------------------------------- passwords ---- */

/** The minimum length the app enforces (never more than bcrypt's 72 bytes). */
function password_min_length(): int
{
    return defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 8;
}

/** One sentence describing the password rule, for a field hint. */
function password_policy_hint(): string
{
    return 'At least ' . password_min_length() . ' characters.';
}

/** null when acceptable, otherwise a message naming what is missing. */
function password_problem(string $password): ?string
{
    $length = mb_strlen($password);

    if ($length < password_min_length()) {
        return 'Use at least ' . password_min_length() . ' characters.';
    }
    if (strlen($password) > 72) {
        return 'Keep your password under 72 characters.';
    }

    return null;
}

/* ------------------------------------------------------------- TLS/CA ----- */

/**
 * Path to a CA bundle, or null when the platform already has a trust store.
 *
 * PHP on Windows ships without one, so outbound HTTPS calls fail at the TLS
 * handshake unless a bundle is named. XAMPP ships one next to Apache.
 */
function ca_bundle_path(): ?string
{
    static $resolved = false;
    if ($resolved !== false) {
        return $resolved;
    }

    $candidates = [
        MAIL_CA_BUNDLE,
        (string) env('CA_BUNDLE', ''),
        APP_ROOT . '/storage/cacert.pem',
        'C:/xampp/apache/bin/curl-ca-bundle.crt',
        'C:/xampp/php/extras/ssl/cacert.pem',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
    ];

    foreach ($candidates as $path) {
        if ($path !== '' && is_file($path) && is_readable($path)) {
            return $resolved = $path;
        }
    }

    return $resolved = null;
}

/* ------------------------------------------------------------- errors ----- */

/**
 * Stop with an error page, rendered inside the app shell when possible.
 */
function abort(int $code, string $message = ''): never
{
    static $handling = false;

    $titles = [
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        419 => 'Page Expired',
        429 => 'Too Many Requests',
        500 => 'Server Error',
    ];
    $title = $titles[$code] ?? 'Error';

    if ($message === '' && APP_DEBUG && $code === 404) {
        $message = 'No route matched page="' . e($_GET['page'] ?? 'dashboard') . '".';
    }

    if (wants_json()) {
        json_response(false, null, $message !== '' ? $message : $title, $code);
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $title . ($message !== '' ? ': ' . $message : '') . PHP_EOL);
        exit(1);
    }

    // A database failure during error rendering must not recurse: the first
    // abort sets the guard, and a re-entrant one drops straight to the plain
    // fallback page.
    if ($handling) {
        abort_fallback($code, $title, $message);
    }
    $handling = true;

    set_status($code);
    header('Content-Type: text/html; charset=utf-8');

    ob_start();
    try {
        // Read the session flag, not current_user(): rendering an error page
        // must not require a working database connection.
        $bare     = empty($_SESSION['user_id']);
        $signedIn = !$bare;

        view('layouts/header', ['title' => $title, 'bare' => $bare]);
        view('errors/error', [
            'code'      => $code,
            'title'     => $title,
            'message'   => $message,
            'signedIn'  => $signedIn,
        ]);
        view('layouts/footer', ['bare' => $bare]);
        ob_end_flush();
    } catch (Throwable $e) {
        ob_end_clean();
        error_log('[abort] could not render the ' . $code . ' page: ' . $e->getMessage());
        abort_fallback($code, $title, $message);
    }

    exit;
}

/** Last-resort error page. Must not touch the database, views or the session. */
function abort_fallback(int $code, string $title, string $message): never
{
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= $code ?> <?= e($title) ?></title>
        <style>
            body{margin:0;height:100vh;display:grid;place-items:center;background:#f8f9ff;color:#171b45;
                 font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
            .box{text-align:center;padding:2rem;max-width:32rem}
            h1{font-size:3.5rem;margin:0;color:#6255f5}
            p{color:#737b9b;margin:.5rem 0 1.25rem;line-height:1.6}
        </style>
    </head>
    <body>
        <div class="box">
            <h1><?= $code ?></h1>
            <p><?= e($title) ?></p>
            <?php if ($message !== ''): ?><p><?= e($message) ?></p><?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}
