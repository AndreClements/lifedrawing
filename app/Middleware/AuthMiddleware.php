<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Request;
use App\Response;

/**
 * Authentication middleware.
 *
 * Redirects unauthenticated users to login.
 * API requests get a 401 JSON response instead.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // Check session auth
        if (isset($_SESSION['user_id'])) {
            return $next($request);
        }

        // Check bearer token for API requests
        $token = $request->bearerToken();
        if ($token !== null) {
            $auth = app('auth');
            $user = $auth->authenticateByToken($token);
            if ($user) {
                // Attach user to request as attribute
                $request = $request->withAttribute('api_user', $user);
                return $next($request);
            }
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        // Web request — redirect to login
        if ($request->wantsJson() || $request->isHtmx()) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $loginUrl = route('auth.login');
        return Response::redirect($loginUrl);
    }
}
