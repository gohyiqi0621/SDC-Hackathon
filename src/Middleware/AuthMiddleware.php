<?php
/**
 * AuthMiddleware.php - pages that require a signed-in user.
 *
 * Delegates to AuthService::requireUser(), which also enforces session timeouts.
 */
declare(strict_types=1);

namespace Src\Middleware;

use Src\Services\AuthService;

final class AuthMiddleware implements Middleware
{
    public function handle(callable $next): void
    {
        AuthService::requireUser();
        $next();
    }
}
