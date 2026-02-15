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

        // Auth routes (core — not module-specific)
        $this->router->get('/login', function () {
            if (app('auth')->isLoggedIn()) {
                return Response::redirect(route('home'));
            }
            return Response::html(app('view')->render('layouts.main', [
                'title' => 'Login — Life Drawing Randburg',
                'content' => (new Template(LDR_ROOT . '/modules/lifedrawing/Views'))->render('auth.login'),
            ]));
        }, 'auth.login');

        $this->router->get('/register', function () {
            if (app('auth')->isLoggedIn()) {
                return Response::redirect(route('home'));
            }
            return Response::html(app('view')->render('layouts.main', [
                'title' => 'Register — Life Drawing Randburg',
                'content' => (new Template(LDR_ROOT . '/modules/lifedrawing/Views'))->render('auth.register'),
            ]));
        }, 'auth.register');

        // Rate-limited auth POST routes (5 attempts per 15 minutes)
        $this->router->group('', [\App\Middleware\RateLimitAuth::class], function (Router $router) {
            $router->post('/login', function (Request $req) {
                $user = app('auth')->attempt(
                    $req->input('email', ''),
                    $req->input('password', ''),
                );
                if ($user === null) {
                    return Response::html(app('view')->render('layouts.main', [
                        'title' => 'Login — Life Drawing Randburg',
                        'content' => (new Template(LDR_ROOT . '/modules/lifedrawing/Views'))->render('auth.login', [
                            'error' => 'Invalid email or password.',
                            'email' => $req->input('email', ''),
                        ]),
                    ]));
                }

                // Set remember-me cookie if requested
                if ($req->input('remember', '') === '1') {
                    $auth = app('auth');
                    $token = $auth->createRememberToken((int) $user['id']);
                    $auth->setRememberCookie($token);
                }

                return Response::redirect(route('home'));
            }, 'auth.login.post');

            $router->post('/register', function (Request $req) {
                $name = trim($req->input('display_name', ''));
                $email = trim($req->input('email', ''));
                $password = $req->input('password', '');
                $confirm = $req->input('password_confirm', '');

                $errors = [];
                if ($name === '') $errors[] = 'Display name is required.';
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
                if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
                if ($password !== $confirm) $errors[] = 'Passwords do not match.';

                if (!empty($errors)) {
                    return Response::html(app('view')->render('layouts.main', [
                        'title' => 'Register — Life Drawing Randburg',
                        'content' => (new Template(LDR_ROOT . '/modules/lifedrawing/Views'))->render('auth.register', [
                            'errors' => $errors,
                            'name' => $name,
                            'email' => $email,
                        ]),
                    ]));
                }

                try {
                    $userId = app('auth')->register($name, $email, $password);
                    app('auth')->attempt($email, $password);
                    return Response::redirect(route('auth.consent'));
                } catch (\App\Exceptions\AppException $e) {
                    return Response::html(app('view')->render('layouts.main', [
                        'title' => 'Register — Life Drawing Randburg',
                        'content' => (new Template(LDR_ROOT . '/modules/lifedrawing/Views'))->render('auth.register', [
                            'errors' => [$e->getMessage()],
                            'name' => $name,
                            'email' => $email,
                        ]),
                    ]));
                }
            }, 'auth.register.post');
        });

        $this->router->get('/consent', function () {
            return Response::html(app('view')->render('layouts.main', [
                'title' => 'Consent — Life Drawing Randburg',
                'content' => (new Template(LDR_ROOT . '/modules/lifedrawing/Views'))->render('auth.consent'),
            ]));
        }, 'auth.consent');

        $this->router->post('/consent', function (Request $req) {
            $auth = app('auth');
            $userId = $auth->currentUserId();
            if ($userId === null) {
                return Response::redirect(route('auth.login'));
            }
            if ($req->input('grant') === 'yes') {
                $auth->grantConsent($userId);
            }
            return Response::redirect(route('home'));
        }, 'auth.consent.post');

        $this->router->get('/logout', function () {
            app('auth')->logout();
            return Response::redirect(route('auth.login'));
        }, 'auth.logout');

        // Password reset routes (rate-limited with auth category)
        $this->router->get('/forgot-password', function () {
            return Response::html(app('view')->render('layouts.main', [
                'title' => 'Forgot Password — Life Drawing Randburg',
                'content' => (new Template(LDR_ROOT . '/modules/lifedrawing/Views'))->render('auth.forgot-password'),
            ]));
        }, 'auth.forgot_password');

        $this->router->get('/reset-password', function (Request $req) {
            $token = $req->input('token', '');
            $email = app('auth')->verifyResetToken($token);

            if ($email === null) {
                return Response::html(app('view')->render('layouts.main', [
                    'title' => 'Invalid Link — Life Drawing Randburg',
                    'content' => (new Template(LDR_ROOT . '/modules/lifedrawing/Views'))->render('auth.reset-expired'),
                ]));
            }

            return Response::html(app('view')->render('layouts.main', [
                'title' => 'Reset Password — Life Drawing Randburg',
                'content' => (new Template(LDR_ROOT . '/modules/lifedrawing/Views'))->render('auth.reset-password', [
                    'token' => $token,
                ]),
            ]));
        }, 'auth.reset_password');

        $this->router->group('', [\App\Middleware\RateLimitAuth::class], function (Router $router) {
            $router->post('/forgot-password', function (Request $req) {
                $email = trim($req->input('email', ''));

                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $token = app('auth')->createPasswordResetToken($email);
                    if ($token !== null) {
                        $resetUrl = config('app.url', 'http://localhost/lifedrawing/public')
                            . route('auth.reset_password') . '?token=' . $token;
                        @mail(
                            $email,
                            'Password Reset — Life Drawing Randburg',
                            "You requested a password reset.\n\nClick here to reset your password:\n{$resetUrl}\n\nThis link expires in 1 hour.\n\nIf you did not request this, please ignore this email.",
                            "From: noreply@lifedrawingrandburg.co.za\r\nContent-Type: text/plain; charset=UTF-8"
                        );
                    }
                }

                // Always show success (anti-enumeration)
                return Response::html(app('view')->render('layouts.main', [
                    'title' => 'Check Your Email — Life Drawing Randburg',
                    'content' => (new Template(LDR_ROOT . '/modules/lifedrawing/Views'))->render('auth.forgot-password-sent'),
                ]));
            }, 'auth.forgot_password.post');

            $router->post('/reset-password', function (Request $req) {
                $token = $req->input('token', '');
                $password = $req->input('password', '');
                $confirm = $req->input('password_confirm', '');

                $errors = [];
                if (strlen($password) < 8) {
                    $errors[] = 'Password must be at least 8 characters.';
                }
                if ($password !== $confirm) {
                    $errors[] = 'Passwords do not match.';
                }

                if (!empty($errors)) {
                    return Response::html(app('view')->render('layouts.main', [
                        'title' => 'Reset Password — Life Drawing Randburg',
                        'content' => (new Template(LDR_ROOT . '/modules/lifedrawing/Views'))->render('auth.reset-password', [
                            'token' => $token,
                            'errors' => $errors,
                        ]),
                    ]));
                }

                $success = app('auth')->resetPassword($token, $password);
                if (!$success) {
                    return Response::html(app('view')->render('layouts.main', [
                        'title' => 'Invalid Link — Life Drawing Randburg',
                        'content' => (new Template(LDR_ROOT . '/modules/lifedrawing/Views'))->render('auth.reset-expired'),
                    ]));
                }

                return Response::redirect(route('auth.login'));
            }, 'auth.reset_password.post');
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
