<?php

declare(strict_types=1);

namespace Modules\Lifedrawing\Controllers;

use App\Container;
use App\Request;
use App\Response;
use App\Services\StatsService;

/**
 * Dashboard Controller — Strava-for-Artistry.
 *
 * The personal dashboard: a mirror for practice, not a scoreboard.
 * Tracks engagement (sessions attended, streaks maintained, media explored)
 * rather than quality metrics. The slope rewards showing up.
 *
 * Octagon: Continuity — stats accumulate, practice becomes visible over time.
 */
final class DashboardController extends BaseController
{
    private StatsService $stats;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->stats = $container->get('stats');
    }

    /** Personal dashboard (authenticated). */
    public function index(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;

        $userId = $this->userId();

        // Refresh stats on dashboard load (lightweight, keeps data current)
        $this->stats->refreshUser($userId);

        $data = $this->stats->getDashboardData($userId);
        $data['user'] = $this->auth->currentUser();

        return $this->render('dashboard.index', $data, 'My Dashboard');
    }

    /** API endpoint: refresh stats and return JSON (for HTMX partial updates). */
    public function refresh(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;

        $userId = $this->userId();
        $this->stats->refreshUser($userId);
        $data = $this->stats->getDashboardData($userId);

        if ($request->isHtmx() || $request->wantsJson()) {
            return Response::json([
                'stats' => $data['stats'],
                'milestones' => array_filter($data['milestones'], fn($m) => $m['achieved']),
            ]);
        }

        return Response::redirect(route('dashboard'));
    }
}
