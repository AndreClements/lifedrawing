<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Request;
use App\Response;

/**
 * Security headers middleware.
 *
 * Adds standard browser security headers to every response.
 * Applied globally — wraps the entire pipeline.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        $response = $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Content-Security-Policy',
                "default-src 'self'; "
                . "script-src 'self' https://unpkg.com; "
                . "style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data:; "
                . "frame-ancestors 'none'"
            );

        if (config('app.env') === 'production') {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
