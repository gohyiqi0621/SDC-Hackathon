<?php
/**
 * CsrfMiddleware.php - rejects any POST without a valid session token.
 *
 * The Router installs this for every POST route, so a new controller cannot
 * accidentally ship an unprotected form.
 */
declare(strict_types=1);

namespace Src\Middleware;

final class CsrfMiddleware implements Middleware
{
    public function handle(callable $next): void
    {
        if (!csrf_valid()) {
            abort(419, 'Your session expired. Refresh the page and try again.');
        }

        $next();
    }
}
