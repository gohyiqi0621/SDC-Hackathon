<?php
/**
 * Router for `php -S 0.0.0.0:5000 -t public bin/serve.php`.
 * Mirrors public/.htaccess without exposing files outside the web root.
 */
declare(strict_types=1);

$public = realpath(dirname(__DIR__) . '/public');
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$file = realpath($public . $path);

// Cloud Run probes GET / and expects a 200. Show the existing sign-in page
// directly for an unauthenticated bare-root request instead of redirecting.
if ($path === '/' && $_SERVER['REQUEST_METHOD'] === 'GET' && $_GET === []) {
    $_GET = ['page' => 'auth', 'action' => 'login'];
}

if (preg_match('#(?:^|/)\.#', $path)
    || preg_match('#^/uploads/.*\.(?:php[0-9]?|phtml|phar)(?:/|$)#i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}

if ($path !== '/' && $file !== false && str_starts_with($file, $public . '/')
    && is_file($file)) {
    return false;
}

// An uploaded image that is not on disk (fresh deployment): restored from the database.
if (str_starts_with($path, '/uploads/')) {
    $_GET = ['page' => 'files', 'action' => 'public', 'path' => substr($path, 1)];
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    require $public . '/index.php';
    exit;
}

if ($path !== '/' && $path !== '/index.php') {
    if (!preg_match('#^/([a-zA-Z0-9_]+)(?:/([a-zA-Z0-9_]+))?/?$#', $path, $parts)) {
        http_response_code(404);
        exit('Not found');
    }

    // Apache's QSA retains explicit query-string values when provided.
    $_GET += ['page' => $parts[1]];
    if (isset($parts[2])) {
        $_GET += ['action' => $parts[2]];
    }
}

// Clean URLs must not become a base directory in APP_URL detection.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require $public . '/index.php';
