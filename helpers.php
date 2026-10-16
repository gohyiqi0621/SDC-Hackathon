<?php
/**
 * helpers.php - small shared functions. No dependencies, safe to load first.
 */
declare(strict_types=1);

/** Read a value from the loaded .env (see config.php). */
function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? $default;
}

/**
 * An admin-managed config value, falling back to .env.
 *
 * Secrets live encrypted in the settings table (never in .env once saved); the
 * OneSignal App ID and Google client id are public and stored in the clear.
 * Wrapped in try/catch so a missing table on a fresh checkout still boots to
 * .env instead of fatalling.
 */
function setting(string $key, string $default = ''): string
{
    try {
        $meta = Setting::SCHEMA[$key] ?? null;
        if ($meta === null) {
            return $default;
        }
        // config.php can run before db.php is loaded, so pull it in on demand.
        require_once APP_ROOT . '/db.php';
        $s = new Setting();
        $value = $meta['secret'] ? $s->secret($key) : $s->plain($key);
        return $value !== null && $value !== '' ? $value : $default;
    } catch (Throwable $e) {
        error_log('[setting] ' . $e->getMessage());
        return $default;
    }
}

/** Escape any value for safe HTML output. Use this on every echo in a view. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * The password policy admins configure on the Admin page, stored as plain
 * values in the settings table. Defaults keep the old behaviour (min 8, no
 * special symbol, no case rules) until an admin changes them.
 *
 * @return array{min: int, special: bool, case: bool}
 */
function password_policy(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $policy = ['min' => 8, 'special' => false, 'case' => false];
    try {
        // config.php can run before db.php is loaded, so pull it in on demand.
        require_once APP_ROOT . '/db.php';
        $rows = db()->query(
            "SELECT `key`, value_plain FROM settings
             WHERE `key` IN ('password_min_chars', 'password_require_special', 'password_require_case')"
        )->fetchAll();
        foreach ($rows as $row) {
            if ($row['key'] === 'password_min_chars') {
                $min = (int) $row['value_plain'];
                $policy['min'] = max(4, min(64, $min > 0 ? $min : 8));
            } elseif ($row['key'] === 'password_require_special') {
                $policy['special'] = $row['value_plain'] === '1';
            } elseif ($row['key'] === 'password_require_case') {
                $policy['case'] = $row['value_plain'] === '1';
            }
        }
    } catch (Throwable $e) {
        // no table yet: fall through to the defaults
    }
    return $cached = $policy;
}

/** Human hint for form labels: "At least 8 characters, upper/lower case…". */
function password_hint(): string
{
    $policy = password_policy();
    $parts  = ['at least ' . $policy['min'] . ' characters'];
    if ($policy['case']) {
        $parts[] = 'upper and lower case letters';
    }
    if ($policy['special']) {
        $parts[] = 'one special character';
    }
    if (count($parts) === 1) {
        return 'At least ' . $policy['min'] . ' characters.';
    }
    return 'Use ' . implode(', ', array_slice($parts, 0, -1)) . ' and ' . $parts[count($parts) - 1] . '.';
}

/** '' when the password satisfies the policy, otherwise the error message. */
function validate_password(string $password): string
{
    $policy = password_policy();
    if (mb_strlen($password) < $policy['min']) {
        return 'Use at least ' . $policy['min'] . ' characters.';
    }
    if ($policy['case'] && (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password))) {
        return 'Mix uppercase and lowercase letters.';
    }
    if ($policy['special'] && !preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Add at least one special character.';
    }
    return '';
}

/** Build an internal URL: url('items', ['action' => 'edit', 'id' => 5]). */
function url(string $page = '', array $params = []): string
{
    if ($page !== '') {
        $params = ['page' => $page] + $params;
    }
    return APP_URL . '/' . ($params ? '?' . http_build_query($params) : '');
}

/** Build a URL to a file inside public/ (css, js, img). */
function asset(string $path): string
{
    return APP_URL . '/' . ltrim($path, '/');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Render a view file from app/views/, e.g. view('auth/login', ['errors' => $e]). */
function view(string $path, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require APP_ROOT . '/app/views/' . $path . '.php';
}

/**
 * Inline SVG icons live in app/views/partials/icons.php (one inline sprite per
 * page, names drawn from the v2 design system). icon() is defined there.
 */

/**
 * Set the response status line.
 *
 * http_response_code() makes Apache reply 500 for any code missing from its own
 * table - 419 among them. Sending the raw status line works for those.
 */
function set_status(int $code): void
{
    $reasons = [
        200 => 'OK',
        400 => 'Bad Request',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        419 => 'Page Expired',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    if (isset($reasons[$code])) {
        header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' ' . $code . ' ' . $reasons[$code]);
        return;
    }

    http_response_code($code);
}

/** Send the standard JSON envelope and stop. Every fetch endpoint uses this. */
function json_response(bool $success, mixed $data = null, string $message = '', int $code = 200): void
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

/** Read raw JSON body (for fetch calls that post JSON instead of a form). */
function json_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * True when the caller is fetch() rather than a browser form post.
 * Controllers use this to pick JSON or a redirect; abort() uses it too.
 */
function wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}

/**
 * A form post succeeded: JSON with a redirect target for fetch() callers,
 * flash + redirect for a plain browser submit. Always exits.
 */
function respond_ok(string $message, string $redirect): void
{
    if (wants_json()) {
        json_response(true, ['redirect' => $redirect], $message);
    }

    flash('success', $message);
    redirect($redirect);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/* ---------------------------------------------------------------- CSRF ---- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
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

/* --------------------------------------------------------------- flash ---- */

/**
 * Queue a one-shot message for the next page load.
 *
 * $title is optional and overrides the generic heading picked from $type. Use
 * it when the message is not actually a failure - e.g. a sign-in redirect is
 * type 'error' (so it is announced assertively) but must NOT be headed
 * "Something went wrong".
 */
function flash(string $type, string $message, string $title = ''): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message, 'title' => $title];
}

/** Pull and clear queued flash messages. */
function flashes(): array
{
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

/* ----------------------------------------------------------------- time ---- */

/**
 * Time zones, in one place.
 *
 * Every datetime column in this schema holds the APP's wall-clock time - MySQL
 * runs with `time_zone = SYSTEM`, `NOW()` returns the same clock PHP does, and
 * `created_at` / `read_at` / `scheduled_at` are all written that way. Keep it
 * that way: a single convention is the whole point.
 *
 * `scheduled_at` used to be stored as UTC while `created_at` was local. Reading
 * the UTC value back with a bare strtotime() (which assumes the app timezone)
 * made a push scheduled for 23:02 local render as 15:02, and it made the two
 * columns disagree by eight hours in the same table. These helpers keep the
 * storage local and convert to UTC exactly once, at the OneSignal API boundary.
 */

/** Browser `datetime-local` value -> the `Y-m-d H:i:s` a DATETIME column wants. */
function schedule_to_db(string $local): ?string
{
    $local = trim($local);
    if ($local === '') {
        return null;
    }
    $ts = strtotime($local);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

/** Has this stored moment already passed? Used to retire 'scheduled' rows. */
function schedule_passed(?string $dbTime): bool
{
    $dbTime = trim((string) $dbTime);
    if ($dbTime === '') {
        return false;
    }
    $ts = strtotime($dbTime);
    return $ts !== false && $ts <= time();
}

/** Stored wall-clock -> ISO-8601 UTC, which is what OneSignal's send_after wants. */
function schedule_to_iso(?string $dbTime): ?string
{
    $dbTime = trim((string) $dbTime);
    if ($dbTime === '') {
        return null;
    }
    $ts = strtotime($dbTime);
    return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
}

/** A stored datetime -> the string a human should read. */
function db_time(?string $value, string $format = 'j M Y, H:i'): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts === false ? '—' : date($format, $ts);
}

/* ---------------------------------------------------------------- auth ---- */

/**
 * The signed-in user row, or null. Re-read from the DB once per request so
 * role changes and admin-disabled accounts take effect immediately.
 */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }

    $user = null;
    if (!empty($_SESSION['user_id'])) {
        $row = (new User())->find((int) $_SESSION['user_id']);
        if ($row && $row['status'] === 'active') {
            $user = $row;
        } else {
            unset($_SESSION['user_id']);
        }
    }

    return $user;
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'admin';
}

/** The first letter of a name, for the avatar circle. */
function initial_of(?array $user): string
{
    $name = (string) ($user['name'] ?? '?');
    return strtoupper(mb_substr(trim($name) !== '' ? $name : '?', 0, 1));
}

/**
 * The primary navigation entries, shared by the side nav AND the floating tab
 * bar so the two never drift apart.
 *
 * Regular users get the AI chat; admins get the Key page in its place (admins
 * have no run-AI function), plus the Admin page. Icons are v2 sprite names.
 * 'group' = 'main' | 'admin' | 'account' drives the side-nav group labels.
 *
 * @return array<int, array{page:string,label:string,icon:string,url:string,group:string}>
 */
function nav_links(?array $user = null): array
{
    $isAdmin = $user !== null && ($user['role'] ?? null) === 'admin';

    $links = [
        ['page' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home',     'url' => url('dashboard'), 'group' => 'main'],
        ['page' => 'items',     'label' => 'Items',     'icon' => 'items',    'url' => url('items'),     'group' => 'main'],
    ];

    if ($isAdmin) {
        $links[] = ['page' => 'key',   'label' => 'Key',   'icon' => 'key',     'url' => url('key'),   'group' => 'main'];
        $links[] = ['page' => 'admin', 'label' => 'Admin', 'icon' => 'shield',  'url' => url('admin'), 'group' => 'admin'];
    } else {
        $links[] = ['page' => 'ai',    'label' => 'AI Chat', 'icon' => 'sparkle', 'url' => url('ai'),  'group' => 'main'];
    }

    $links[] = ['page' => 'notification', 'label' => 'Notification', 'icon' => 'bell', 'url' => url('notification'), 'group' => 'main'];

    // Everyone needs the profile page to change their details.
    $links[] = ['page' => 'profile', 'label' => 'Profile', 'icon' => 'user', 'url' => url('profile'), 'group' => 'account'];

    return $links;
}

/** The sanitized ?page= value that marks the current nav item as active. */
function current_page_key(): string
{
    $page = preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['page'] ?? ''));
    return $page === '' ? 'dashboard' : $page;
}

/** Page guard: send guests to the login screen. */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        // Type stays 'error' so the notice is announced assertively, but the
        // heading is a sign-in prompt - not "Something went wrong".
        flash('error', 'Please sign in to continue.', 'Sign in to continue');
        redirect(url('auth', ['action' => 'login']));
    }
    return $user;
}

/** Page guard: admins only. */
function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        abort(403, 'This page is restricted to administrators.');
    }
    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true); // stop session fixation on privilege change
    $_SESSION['user_id'] = (int) $user['id'];
}

function logout_user(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}
/* --------------------------------------------------------------- abort ---- */

/**
 * Stop with an error page.
 * Renders app/views/errors/error.php inside the app shell for signed-in users,
 * and in the bare auth panel for guests.
 */
function abort(int $code, string $message = ''): never
{
    $titles = [403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 419 => 'Page Expired', 500 => 'Server Error'];
    $title  = $titles[$code] ?? 'Error';

    if ($message === '' && APP_DEBUG && $code === 404) {
        $message = 'No route matched page="' . ($_GET['page'] ?? 'dashboard') . '".';
    }

    // fetch() callers must never receive an HTML page.
    if (wants_json()) {
        json_response(false, null, $message !== '' ? $message : $title, $code);
    }

    set_status($code);
    header('Content-Type: text/html; charset=utf-8');

    // Buffer so a half-rendered error page can still be thrown away.
    ob_start();
    try {
        $bare = current_user() === null;
        view('layouts/header', ['title' => $title, 'bare' => $bare]);
        require APP_ROOT . '/app/views/errors/error.php';
        view('layouts/footer', ['bare' => $bare]);
        ob_end_flush();
    } catch (Throwable $e) {
        // An error page that itself errors is the worst outcome: drop whatever
        // was rendered and fall back to a page with no layout and no database.
        ob_end_clean();
        error_log('[abort] could not render the ' . $code . ' page: ' . $e->getMessage());
        abortFallback($code, $title, $message);
    }

    exit;
}

/** Last-resort error page. Must not touch the database, views or the session. */
function abortFallback(int $code, string $title, string $message): never
{
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= $code ?> <?= e($title) ?></title>
        <style>
            body{margin:0;height:100vh;display:grid;place-items:center;background:#f6f7f9;color:#1c2024;
                 font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
            .box{text-align:center;padding:2rem}
            h1{font-size:3.5rem;margin:0;color:#2E7D32}
            p{color:#5b6472;margin:.5rem 0 1.25rem}
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
