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
 *
 * Reads the authoritative state from the database, not from the browser session.
 * Withdrawal can only update the session that performed it, so a second browser
 * already logged in would otherwise keep a cached 'granted' until its session
 * lapsed — and keep participating after the person had withdrawn.
 * BaseController::requireAuth() already re-reads the user row for exactly this
 * class of staleness, so the precedent and the per-request cost are established.
 */
final class ConsentGate implements MiddlewareInterface
{
    /** @var array<int,string> per-request cache, keyed by user id */
    private static array $cache = [];

    public function handle(Request $request, callable $next): Response
    {
        $state = self::currentState();

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

    /** Authoritative consent state for the logged-in user, cached per request. */
    public static function currentState(): ConsentState
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($userId === 0) {
            return ConsentState::from($_SESSION['consent_state'] ?? 'pending');
        }

        if (!isset(self::$cache[$userId])) {
            try {
                $row = app('db')->fetch("SELECT consent_state FROM users WHERE id = ?", [$userId]);
                // A missing row means the account is gone; requireAuth() handles
                // that, and 'pending' is the safe reading in the meantime.
                self::$cache[$userId] = $row['consent_state'] ?? 'pending';
            } catch (\Throwable $e) {
                // Never lock everyone out because the database hiccuped —
                // fall back to the session value, which was authoritative at login.
                error_log('ConsentGate state lookup failed: ' . $e->getMessage());
                self::$cache[$userId] = $_SESSION['consent_state'] ?? 'pending';
            }
            // Keep the session in step so views reading it agree with the gate.
            $_SESSION['consent_state'] = self::$cache[$userId];
        }

        return ConsentState::from(self::$cache[$userId]);
    }
}
