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
use Modules\Lifedrawing\Controllers\PageController;

// --- Public routes (no auth required) ---
$router->get('/',                   [LandingController::class, 'index'], 'home');
$router->get('/faq',               [PageController::class, 'faq'], 'pages.faq');
$router->get('/sessions',          [SessionController::class, 'index'], 'sessions.index');
$router->get('/sessions/create',   [SessionController::class, 'create'], 'sessions.create');
$router->get('/sessions/{id}',     [SessionController::class, 'show'], 'sessions.show');
$router->get('/gallery',           [GalleryController::class, 'index'], 'gallery.index');
$router->get('/artworks/{id}',    [GalleryController::class, 'show'], 'artworks.show');
$router->get('/claims/pending',    [ClaimController::class, 'pending'], 'claims.pending');
$router->get('/artists',           [ProfileController::class, 'artists'], 'profiles.artists');
$router->get('/sitters',           [ProfileController::class, 'sitters'], 'profiles.sitters');
$router->get('/profile/edit',      [ProfileController::class, 'edit'], 'profiles.edit');
$router->get('/profile/{id}',      [ProfileController::class, 'show'], 'profiles.show');
$router->get('/dashboard',         [DashboardController::class, 'index'], 'dashboard');

// --- Facilitator routes (auth + role enforced in controllers) ---
$router->post('/sessions',                 [SessionController::class, 'store'], 'sessions.store');
$router->get('/sessions/{id}/upload',      [GalleryController::class, 'uploadForm'], 'gallery.upload');
$router->get('/sessions/{id}/participants/search', [SessionController::class, 'searchParticipants'], 'sessions.participants.search');
$router->post('/sessions/{id}/participants/add',   [SessionController::class, 'addParticipant'], 'sessions.participants.add');
$router->post('/sessions/{id}/participants/remove', [SessionController::class, 'removeParticipant'], 'sessions.participants.remove');
$router->post('/sessions/{id}/participants/tentative', [SessionController::class, 'toggleTentative'], 'sessions.participants.tentative');
$router->post('/sessions/{id}/cancel',     [SessionController::class, 'cancel'], 'sessions.cancel');
$router->get('/schedule/whatsapp',         [SessionController::class, 'whatsappSchedule'], 'schedule.whatsapp');
$router->post('/claims/{id}/resolve',      [ClaimController::class, 'resolve'], 'claims.resolve');
$router->post('/artworks/{id}/delete',     [GalleryController::class, 'destroy'], 'artworks.destroy');

// Rate-limited upload (10 per hour)
$router->group('', [\App\Middleware\RateLimitUpload::class], function ($router) {
    $router->post('/sessions/{id}/upload', [GalleryController::class, 'upload'], 'gallery.upload.post');
});

// --- Consent-gated routes (auth + consent via middleware) ---
// These routes involve the user's identity in relation to others' work.
// ConsentGate redirects unconsented users to /consent instead of throwing.
$router->group('', [\App\Middleware\AuthMiddleware::class, \App\Middleware\ConsentGate::class], function ($router) {
    $router->post('/sessions/{id}/join',   [SessionController::class, 'join'], 'sessions.join');
    $router->post('/artworks/{id}/claim',    [ClaimController::class, 'claim'], 'claims.claim');
    $router->post('/artworks/{id}/comment', [GalleryController::class, 'comment'], 'artworks.comment');
    $router->post('/profile/edit',           [ProfileController::class, 'update'], 'profiles.update');
    $router->post('/dashboard/refresh',      [DashboardController::class, 'refresh'], 'dashboard.refresh');
});
