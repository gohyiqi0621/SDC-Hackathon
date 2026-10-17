<?php
/**
 * public/index.php - the single entry point. Everything is routed through here.
 *
 * URL styles, both work:
 *   http://localhost/sdc-project/public/?page=items&action=edit&id=5
 *   http://localhost/sdc-project/public/items/edit/5     (via .htaccess)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once APP_ROOT . '/db.php';

$page   = (string) ($_GET['page'] ?? 'dashboard');
$action = (string) ($_GET['action'] ?? 'index');

// Whitelist characters so the value can never escape app/controllers/.
$page   = preg_replace('/[^a-z0-9_]/i', '', $page) ?: 'dashboard';
$action = preg_replace('/[^a-z0-9_]/i', '', $action) ?: 'index';

// Action aliases: snake/kebab route names that map to camelCase methods.
// Kept as an explicit map so the character whitelist above never loosens.
$actionAliases = [
    'google_callback' => 'googleCallback',
    'google-callback' => 'googleCallback',
    'set_password'    => 'setPassword',
    'save_setting'    => 'saveSetting',
    'save_policy'     => 'savePolicy',
];
$action = $actionAliases[strtolower($action)] ?? $action;

// One CSRF gate for every POST - forms and fetch() calls both land here.
if (is_post() && !csrf_valid()) {
    abort(419, 'Your session expired. Refresh the page and try again.');
}

$class = ucfirst($page) . 'Controller';
$file  = APP_ROOT . '/app/controllers/' . $class . '.php';

if (!is_file($file)) {
    abort(404);
}

// class_exists() triggers the autoloader registered in config.php.
if (!class_exists($class)) {
    abort(500, "Controller file found but class {$class} is missing.");
}

$controller = new $class();

if (!method_exists($controller, $action) || str_starts_with($action, '_')) {
    abort(404);
}

// method_exists() also sees private/protected methods, so check visibility.
$method = new ReflectionMethod($controller, $action);
if (!$method->isPublic() || $method->isStatic()) {
    abort(404);
}

try {
    $controller->{$action}();
} catch (Throwable $e) {
    error_log('[app] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    abort(500, APP_DEBUG ? $e->getMessage() : '');
}
