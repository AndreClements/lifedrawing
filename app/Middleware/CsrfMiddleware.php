<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Request;
use App\Response;

/**
 * CSRF protection middleware.
 *
 * Validates the _csrf_token on all state-changing requests (POST, PUT, DELETE).
 * GET requests pass through. HTMX requests are also validated.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // Only check state-changing methods
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $sessionToken = $_SESSION['_csrf_token'] ?? '';
        $requestToken = $request->post['_csrf_token']
            ?? $request->server['HTTP_X_CSRF_TOKEN']  // HTMX sends via header
            ?? '';

        if (!hash_equals($sessionToken, $requestToken)) {
            return Response::html(
                '<h1>403 — Invalid CSRF Token</h1><p>Your session may have expired. Please go back and try again.</p>',
                403
            );
        }

        return $next($request);
    }
}
