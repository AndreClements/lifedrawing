<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Request;
use App\Response;
use App\Services\Auth\ConsentState;

/**
 * Consent Gate middleware.
 *
 * Octagon facet 7: operations on user data require granted consent.
 * Users with pending or withdrawn consent are redirected to the consent page.
 */
final class ConsentGate implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $state = ConsentState::from($_SESSION['consent_state'] ?? 'pending');

        if ($state->canParticipate()) {
            return $next($request);
        }

        if ($request->wantsJson() || $request->isHtmx()) {
            return Response::json([
                'error' => 'Consent required',
                'consent_state' => $state->value,
            ], 403);
        }

        return Response::redirect(route('auth.consent'));
    }
}
