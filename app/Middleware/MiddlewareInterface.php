<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Request;
use App\Response;

/**
 * Middleware contract.
 *
 * Each middleware receives the request and a $next callable.
 * It can modify the request before passing it down, or short-circuit
 * by returning a Response directly without calling $next.
 */
interface MiddlewareInterface
{
    /**
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
