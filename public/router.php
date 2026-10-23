<?php
/**
 * public/router.php - URL rules for PHP's built-in web server (Replit).
 *
 *     php -S 0.0.0.0:8080 -t public public/router.php
 *
 * Does what public/.htaccess does under Apache/XAMPP:
 *   - real files (css, js, images) are served as they are;
 *   - dotfiles are never served;
 *   - /financial/invoice -> ?page=financial&action=invoice, /dashboard -> ?page=dashboard.
 */
declare(strict_types=1);

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Never serve dotfiles or anything with "..".
if (preg_match('#(^|/)\.#', $path) || str_contains($path, '..')) {
    http_response_code(404);
    exit;
}

// Real files are served by the built-in server itself.
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

if (str_starts_with($path, '/uploads/')) {
    // An uploaded image that is not on disk (fresh deployment): restored from the database.
    $_GET = ['page' => 'files', 'action' => 'public', 'path' => substr($path, 1)];
} elseif (preg_match('#^/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)/?$#', $path, $m)) {
    $_GET['page']   = $m[1];
    $_GET['action'] = $m[2];
} elseif (preg_match('#^/([A-Za-z0-9_]+)/?$#', $path, $m) && $m[1] !== 'index') {
    $_GET['page'] = $m[1];
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
require __DIR__ . '/index.php';
