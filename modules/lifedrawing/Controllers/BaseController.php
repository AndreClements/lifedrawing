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
    protected function render(string $view, array $data = [], ?string $title = null): Response
    {
        $content = $this->view->render($view, $data);
        return Response::html($this->view->render('layouts.main', [
            'title' => ($title ? $title . ' — ' : '') . 'Life Drawing Randburg',
            'content' => $content,
        ]));
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

    /** Require authentication — redirect to login if not logged in. */
    protected function requireAuth(): ?Response
    {
        if (!$this->auth->isLoggedIn()) {
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
