<?php

declare(strict_types=1);

namespace App;

use App\Database\Connection;
use App\Database\Migration;
use App\Exceptions\AppException;
use App\Exceptions\Handler;
use App\View\Template;

/**
 * Application Kernel.
 *
 * The single entry point. Boots config, wires the DI container,
 * loads module routes, runs the middleware pipeline, and dispatches.
 *
 * Architecture: try { as-if } catch { if-not } // and-yet
 */
final class Kernel
{
    private Container $container;
    private Router $router;
    private Handler $handler;

    /** @var string[] Global middleware class names (applied to every request) */
    private array $globalMiddleware = [
        \App\Middleware\SecurityHeadersMiddleware::class,
        \App\Middleware\CsrfMiddleware::class,
    ];

    public function __construct()
    {
        $this->boot();
    }

    /** Handle an HTTP request and return a Response. */
    public function handle(Request $request): Response
    {
        try {
            // Match route
            $match = $this->router->match($request);

            if ($match === null) {
                return $this->notFound($request);
            }

            // Inject route params into request
            $request = $request->withParams($match['params']);

            // Build middleware pipeline: global + route-specific
            $middleware = array_merge($this->globalMiddleware, $match['middleware']);

            // Execute through middleware pipeline
            $response = $this->pipeline($request, $middleware, function (Request $req) use ($match) {
                return $this->dispatch($req, $match['handler']);
            });

            return $response;

        } catch (\Throwable $e) {
            return $this->handler->handle($e);
        }
        // And-Yet: The kernel catches everything, but some errors (OOM, segfaults)
        // bypass PHP exceptions entirely. XAMPP error logs cover that gap.
    }

    // --- Boot sequence ---

    private function boot(): void
    {
        // Timezone
        date_default_timezone_set(config('app.timezone', 'Africa/Johannesburg'));

        // Session
        if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
            $sessionName = config('auth.session_name', 'ldr_session');
            $isProduction = config('app.env') === 'production';
            session_name($sessionName);
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => $isProduction,
                'httponly'  => true,
                'samesite'  => 'Lax',
            ]);
            session_start();

            // Generate CSRF token if not present
            if (empty($_SESSION['_csrf_token'])) {
                $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
            }
        }

        // Container
        $this->container = Container::getInstance();

        // Exception handler
        $this->handler = new Handler(
            environment: config('app.env', 'production'),
            logPath: LDR_ROOT . '/storage/logs/app.log',
        );

        // Wire services
        $this->wireServices();

        // Remember-me: restore session from cookie if not logged in
        if (empty($_SESSION['user_id']) && !empty($_COOKIE['ldr_remember'])) {
            $this->container->get('auth')->attemptRememberLogin($_COOKIE['ldr_remember']);
        }

        // Router
        $this->router = new Router();
        $this->container->instance('router', $this->router);

        // Load module routes
        $this->loadModules();

        // Core routes (health check, etc.)
        $this->registerCoreRoutes();
    }

    private function wireServices(): void
    {
        // Database connection (singleton — one connection per request)
        $this->container->singleton('db', function () {
            $cfg = config('database');
            return new Connection(
                host: $cfg['host'],
                database: $cfg['database'],
                username: $cfg['username'],
                password: $cfg['password'],
                port: $cfg['port'] ?? 3306,
                charset: $cfg['charset'] ?? 'utf8mb4',
            );
        });

        // Migration runner
        $this->container->singleton('migrator', function (Container $c) {
            return new Migration($c->get('db'));
        });

        // Template engine
        $this->container->singleton('view', function () {
            return new Template(LDR_ROOT . '/modules/lifedrawing/Views');
        });

        // Auth service
        $this->container->singleton('auth', function (Container $c) {
            return new \App\Services\Auth\AuthService($c->get('db'));
        });

        // Provenance service
        $this->container->singleton('provenance', function (Container $c) {
            return new \App\Services\ProvenanceService($c->get('db'));
        });

        // Upload service
        $this->container->singleton('upload', function () {
            return new \App\Services\Upload\UploadService();
        });

        // Stats service (Strava-for-artistry)
        $this->container->singleton('stats', function (Container $c) {
            return new \App\Services\StatsService($c->get('db'));
        });

        // Mail service (SMTP via PHPMailer)
        $this->container->singleton('mail', function () {
            $cfg = config('mail');
            return new \App\Services\MailService(
                host: $cfg['host'],
                port: $cfg['port'],
                username: $cfg['username'],
                password: $cfg['password'],
                fromAddress: $cfg['from_address'],
                fromName: $cfg['from_name'],
                encryption: $cfg['encryption'],
            );
        });
    }

    private function loadModules(): void
    {
        $modules = config('app.modules', []);

        foreach ($modules as $moduleName) {
            $manifestPath = LDR_ROOT . "/modules/{$moduleName}/module.php";
            if (!file_exists($manifestPath)) {
                continue;
            }

            $manifest = require $manifestPath;
            $slug = $manifest['slug'] ?? $moduleName;
            $prefix = $slug !== '' ? '/' . $slug : '';

            // Load module routes inside a group with the module's prefix
            $routesFile = $manifest['routes'] ?? null;
            if ($routesFile && file_exists($routesFile)) {
                $router = $this->router;
                $middleware = $manifest['middleware'] ?? [];
                $router->group($prefix, $middleware, function (Router $router) use ($routesFile) {
                    require $routesFile;
                });
            }
        }
    }

    private function registerCoreRoutes(): void
    {
        // Health check
        $this->router->get('/_health', function () {
            return Response::json(['status' => 'ok', 'time' => date('c')]);
        }, '_health');

        // Auth routes — delegated to AuthController (extracted from Kernel closures)
        $auth = \Modules\Lifedrawing\Controllers\AuthController::class;

        $this->router->get('/login',           [$auth, 'loginForm'], 'auth.login');
        $this->router->get('/register',        [$auth, 'registerForm'], 'auth.register');
        $this->router->get('/register/search-stub', [$auth, 'searchStubs'], 'auth.register.search_stubs');
        $this->router->get('/consent',         [$auth, 'consentForm'], 'auth.consent');
        $this->router->post('/consent',        [$auth, 'consent'], 'auth.consent.post');
        $this->router->get('/logout',          [$auth, 'logout'], 'auth.logout');
        $this->router->get('/forgot-password', [$auth, 'forgotPasswordForm'], 'auth.forgot_password');
        $this->router->get('/reset-password',  [$auth, 'resetPasswordForm'], 'auth.reset_password');

        // Rate-limited auth POST routes (5 attempts per 15 minutes)
        $this->router->group('', [\App\Middleware\RateLimitAuth::class], function (Router $router) use ($auth) {
            $router->post('/login',           [$auth, 'login'], 'auth.login.post');
            $router->post('/register',        [$auth, 'register'], 'auth.register.post');
            $router->post('/forgot-password', [$auth, 'forgotPassword'], 'auth.forgot_password.post');
            $router->post('/reset-password',  [$auth, 'resetPassword'], 'auth.reset_password.post');
        });
    }

    // --- Middleware pipeline ---

    /**
     * Run request through middleware stack, then the final handler.
     *
     * @param string[]                    $middleware Class names
     * @param callable(Request): Response $final     The route handler
     */
    private function pipeline(Request $request, array $middleware, callable $final): Response
    {
        // Build the pipeline from inside out
        $next = $final;

        foreach (array_reverse($middleware) as $class) {
            $instance = new $class();
            $next = fn(Request $req) => $instance->handle($req, $next);
        }

        return $next($request);
    }

    // --- Dispatch ---

    /**
     * Call the matched route handler.
     *
     * Supports:
     * - Closures: fn(Request $req): Response
     * - Array [ControllerClass, 'method']
     */
    private function dispatch(Request $request, callable|array $handler): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class($this->container);
            $result = $controller->$method($request);
        } else {
            $result = $handler($request);
        }

        // Allow handlers to return strings (auto-wrap in HTML response)
        if (is_string($result)) {
            return Response::html($result);
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        return $result;
    }

    private function notFound(Request $request): Response
    {
        if ($request->wantsJson() || $request->isHtmx()) {
            return Response::json(['error' => 'Not Found'], 404);
        }

        return Response::notFound(
            $this->handler->handle(
                new AppException('Page not found', 'Router matched nothing for: ' . $request->path)
            )->getBody()
        );
    }
}
