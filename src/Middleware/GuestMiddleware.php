<?php
/**
 * GuestMiddleware.php - keeps an authenticated user off the auth screens.
 *
 * This is what makes /auth/login redirect straight to the dashboard when a
 * session already exists.
 */
declare(strict_types=1);

namespace Src\Middleware;

use Src\Services\AuthService;

final class GuestMiddleware implements Middleware
{
    public function handle(callable $next): void
    {
        if (AuthService::user() !== null) {
            // An expired session must not keep bouncing between login and
            // dashboard: clear it first, then let the auth screen render.
            if (AuthService::expiryReason() !== null) {
                AuthService::logout();
                flash('error', 'Your session ended. Please sign in again.', 'Session expired');
            } else {
                redirect(url('dashboard'));
            }
        }

        $next();
    }
}
