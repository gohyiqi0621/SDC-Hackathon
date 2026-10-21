<?php
/**
 * Router.php - explicit route table + middleware pipeline.
 *
 * Routes are addressed with ?page=...&action=... (and the same parameters are
 * produced by the clean-URL rewrites in public/.htaccess):
 *
 *   /financial/invoice  ->  page=financial, action=invoice  ->  "financial/invoice"
 *   /profile            ->  page=profile                     ->  "profile"
 *
 * CSRF is enforced centrally for every POST, so a controller can never forget
 * it. Route-specific guards (AuthMiddleware, GuestMiddleware) run after that.
 *
 * The only exception is a route registered with $csrf = false: calls from
 * other servers (Telegram webhook, Flowise tools) that cannot carry a browser
 * CSRF token. Those controllers verify their own secret instead.
 */
declare(strict_types=1);

namespace Src\Support;

use Src\Middleware\CsrfMiddleware;

final class Router
{
    /** @var array<string, array<string, array{handler: callable|array{0:class-string,1:string}, middleware: array<int,class-string>, csrf: bool}>> */
    private array $routes = [];

    /** @param callable|array{0:class-string,1:string} $handler */
    public function get(string $route, callable|array $handler, array $middleware = []): self
    {
        return $this->add('GET', $route, $handler, $middleware);
    }

    /**
     * @param callable|array{0:class-string,1:string} $handler
     * @param bool $csrf false only for server-to-server endpoints that check their own secret
     */
    public function post(string $route, callable|array $handler, array $middleware = [], bool $csrf = true): self
    {
        return $this->add('POST', $route, $handler, $middleware, $csrf);
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    private function add(string $method, string $route, callable|array $handler, array $middleware, bool $csrf = true): self
    {
        $this->routes[$method][trim($route, '/')] = [
            'handler'    => $handler,
            'middleware' => $middleware,
            'csrf'       => $csrf,
        ];

        return $this;
    }

    /** Dispatch the current request, or stop with 404/405. */
    public function dispatch(): void
    {
        $method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $head    = $method === 'HEAD';
        $lookup  = $head ? 'GET' : $method;
        $route   = self::routeKey();

        if (!isset($this->routes[$lookup][$route])) {
            $other = $lookup === 'GET' ? 'POST' : 'GET';

            if (isset($this->routes[$other][$route])) {
                abort(405, 'This page does not accept that request method.');
            }

            abort(404);
        }

        $definition = $this->routes[$lookup][$route];
        $middleware = $definition['middleware'];

        // One CSRF gate for every POST - forms and fetch() calls both land here.
        if ($method === 'POST' && $definition['csrf'] && !in_array(CsrfMiddleware::class, $middleware, true)) {
            array_unshift($middleware, CsrfMiddleware::class);
        }

        $handler = function () use ($definition): void {
            $this->invoke($definition['handler']);
        };

        $pipeline = array_reduce(
            array_reverse($middleware),
            static function (callable $next, string $class): callable {
                return static function () use ($class, $next): void {
                    (new $class())->handle($next);
                };
            },
            $handler
        );

        $pipeline();
    }

    /** The canonical route key for the current query string. */
    public static function routeKey(): string
    {
        $page   = strtolower((string) ($_GET['page'] ?? 'dashboard'));
        $action = strtolower((string) ($_GET['action'] ?? ''));
        $page   = (string) preg_replace('/[^a-z0-9_]/', '', $page) ?: 'dashboard';
        $action = (string) preg_replace('/[^a-z0-9_]/', '', $action);

        return $action !== '' && $action !== 'index' ? $page . '/' . $action : $page;
    }

    /** @param callable|array{0:class-string,1:string}|null $handler */
    private function invoke(callable|array|null $handler): void
    {
        if ($handler === null) {
            abort(404);
        }

        if (is_callable($handler) && !is_array($handler)) {
            $handler();
            return;
        }

        [$class, $method] = $handler;

        if (!class_exists($class)) {
            abort(500, 'Controller is missing: ' . $class);
        }

        $controller = new $class();

        if (!method_exists($controller, $method)) {
            abort(404);
        }

        $reflection = new \ReflectionMethod($controller, $method);
        if (!$reflection->isPublic() || $reflection->isStatic()) {
            abort(404);
        }

        $controller->{$method}();
    }
}
