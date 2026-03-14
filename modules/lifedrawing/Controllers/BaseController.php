<?php

declare(strict_types=1);

namespace Modules\Lifedrawing\Controllers;

use App\Container;
use App\Database\Connection;
use App\Database\QueryBuilder;
use App\Request;
use App\Response;
use App\Services\Auth\AuthService;
use App\Services\ProvenanceService;
use App\View\Template;

/**
 * Base controller for the Life Drawing module.
 *
 * Provides access to common services. Controllers are thin —
 * they validate input, call services/repositories, and return responses.
 */
abstract class BaseController
{
    protected Connection $db;
    protected AuthService $auth;
    protected Template $view;
    protected ProvenanceService $provenance;

    public function __construct(Container $container)
    {
        $this->db = $container->get('db');
        $this->auth = $container->get('auth');
        $this->view = $container->get('view');
        $this->provenance = $container->get('provenance');
    }

    /** Render a module view inside the main layout. */
    protected function render(string $view, array $data = [], ?string $title = null, array $meta = []): Response
    {
        $content = $this->view->render($view, $data);

        // Auto-generate canonical URL if not explicitly set
        if (!isset($meta['canonical_url'])) {
            $meta['canonical_url'] = self::currentCanonicalUrl();
        }

        return Response::html($this->view->render('layouts.main', array_merge([
            'title' => ($title ? $title . ' — ' : '') . 'Life Drawing Randburg',
            'content' => $content,
        ], $meta)));
    }

    /** Build canonical URL for the current request (path only, no query params). */
    protected static function currentCanonicalUrl(): string
    {
        $appUrl = rtrim(config('app.url', ''), '/');
        $basePath = config('app.base_path', '');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // Strip base path to get the route path, then rebuild with the full app URL
        if ($basePath && str_starts_with($path, $basePath)) {
            $routePath = substr($path, strlen($basePath)) ?: '/';
        } else {
            $routePath = $path;
        }

        return $appUrl . '/' . ltrim($routePath, '/');
    }

    /** Build BreadcrumbList JSON-LD script tag. Each crumb is [label, url]. */
    protected static function breadcrumbJsonLd(array $crumbs): string
    {
        $appUrl = rtrim(config('app.url', ''), '/');
        $items = [];
        foreach ($crumbs as $i => [$label, $path]) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $label,
                'item' => $appUrl . $path,
            ];
        }
        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /** Get a query builder for a table. */
    protected function table(string $name): QueryBuilder
    {
        return (new QueryBuilder($this->db))->table($name);
    }

    /** Get the current authenticated user ID or null. */
    protected function userId(): ?int
    {
        return $this->auth->currentUserId();
    }

    /**
     * Determine whether a session has a known model and whether the current user is one.
     *
     * Old sessions may have no sitter queue data; in that case model claims stay open.
     *
     * @return array{sessionHasKnownModel: bool, isSessionModel: bool}
     */
    protected function sessionModelClaimContext(int $sessionId): array
    {
        $userId = $this->userId() ?? 0;
        $row = $this->db->fetch(
            "SELECT
                COUNT(*) AS known_count,
                SUM(model_user_id = ?) AS current_user_count
             FROM (
                 SELECT DISTINCT sp.user_id AS model_user_id
                 FROM ld_session_participants sp
                 WHERE sp.session_id = ?
                   AND sp.role = 'model'

                 UNION

                 SELECT DISTINCT q.user_id AS model_user_id
                 FROM ld_sitter_queue q
                 WHERE q.scheduled_session_id = ?
                   AND q.status IN ('scheduled', 'completed')
             ) session_models",
            [$userId, $sessionId, $sessionId]
        ) ?? ['known_count' => 0, 'current_user_count' => 0];

        $sessionHasKnownModel = (int) ($row['known_count'] ?? 0) > 0;
        $isSessionModel = $this->auth->isLoggedIn()
            && (int) ($row['current_user_count'] ?? 0) > 0;

        return [
            'sessionHasKnownModel' => $sessionHasKnownModel,
            'isSessionModel' => $isSessionModel,
        ];
    }

    /** Require authentication — redirect to login if not logged in or session is stale. */
    protected function requireAuth(): ?Response
    {
        if (!$this->auth->isLoggedIn()) {
            return Response::redirect(route('auth.login'));
        }
        // Guard against stale sessions (user_id in session but user deleted from DB)
        if ($this->auth->currentUser() === null) {
            $this->auth->logout();
            return Response::redirect(route('auth.login'));
        }
        return null;
    }

    /** Require a specific role — return 403 if not authorized. */
    protected function requireRole(string ...$roles): ?Response
    {
        if (!$this->auth->hasRole(...$roles)) {
            return Response::forbidden('You do not have permission for this action.');
        }
        return null;
    }
}
