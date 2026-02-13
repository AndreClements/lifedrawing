<?php

declare(strict_types=1);

/**
 * Life Drawing Randburg — Routes.
 *
 * $router is available via the group() closure in Kernel::loadModules().
 * All paths here are relative to the module's group prefix (root / for now).
 *
 * Convention: [ControllerClass, 'method'] — the Kernel resolves via DI.
 */

use Modules\Lifedrawing\Controllers\LandingController;
use Modules\Lifedrawing\Controllers\SessionController;
use Modules\Lifedrawing\Controllers\GalleryController;
use Modules\Lifedrawing\Controllers\ClaimController;
use Modules\Lifedrawing\Controllers\ProfileController;
use Modules\Lifedrawing\Controllers\DashboardController;

// --- Landing page ---
$router->get('/',                [LandingController::class, 'index'], 'home');
$router->get('/sessions',       [SessionController::class, 'index'], 'sessions.index');
$router->get('/sessions/create',[SessionController::class, 'create'], 'sessions.create');
$router->post('/sessions',      [SessionController::class, 'store'], 'sessions.store');
$router->get('/sessions/{id}',  [SessionController::class, 'show'], 'sessions.show');
$router->post('/sessions/{id}/join', [SessionController::class, 'join'], 'sessions.join');

// --- Gallery (public browsing, gated uploads) ---
$router->get('/gallery',                    [GalleryController::class, 'index'], 'gallery.index');
$router->get('/sessions/{id}/upload',       [GalleryController::class, 'uploadForm'], 'gallery.upload');
$router->post('/sessions/{id}/upload',      [GalleryController::class, 'upload'], 'gallery.upload.post');

// --- Claims (authenticated + consent) ---
$router->post('/artworks/{id}/claim',       [ClaimController::class, 'claim'], 'claims.claim');
$router->post('/claims/{id}/resolve',       [ClaimController::class, 'resolve'], 'claims.resolve');
$router->get('/claims/pending',             [ClaimController::class, 'pending'], 'claims.pending');

// --- Profiles (public browsing, authenticated editing) ---
$router->get('/artists',                    [ProfileController::class, 'artists'], 'profiles.artists');
$router->get('/profile/edit',               [ProfileController::class, 'edit'], 'profiles.edit');
$router->post('/profile/edit',              [ProfileController::class, 'update'], 'profiles.update');
$router->get('/profile/{id}',               [ProfileController::class, 'show'], 'profiles.show');

// --- Dashboard (authenticated — Strava-for-artistry) ---
$router->get('/dashboard',                  [DashboardController::class, 'index'], 'dashboard');
$router->post('/dashboard/refresh',         [DashboardController::class, 'refresh'], 'dashboard.refresh');
