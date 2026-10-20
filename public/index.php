<?php
/**
 * public/index.php - the single HTTP entry point.
 *
 * public/ is the only folder the web server serves. All application code
 * lives in src/; the URL table is src/routes.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Src\Support\Router;

$router = new Router();
(require SRC_ROOT . '/routes.php')($router);

try {
    $router->dispatch();
} catch (Throwable $e) {
    error_log('[app] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    abort(500, APP_DEBUG ? $e->getMessage() : '');
}
