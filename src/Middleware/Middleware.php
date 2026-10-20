<?php
/**
 * Middleware.php - the contract every route guard implements.
 */
declare(strict_types=1);

namespace Src\Middleware;

interface Middleware
{
    /** Continue the pipeline by calling $next(), or stop with a redirect/abort. */
    public function handle(callable $next): void;
}
