<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Request;
use App\Response;

/**
 * Rate limiting middleware — file-based sliding window.
 *
 * Throttles state-changing requests by IP address.
 * Subclasses set the category via constructor (preserving the
 * no-args-constructor convention used by the middleware pipeline).
 *
 * Categories:
 *   auth    — 5 attempts per 15 minutes
 *   upload  — 10 per hour
 *   general — 30 per 15 minutes
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private const CACHE_DIR = 'ratelimit';

    /** @var array<string, array{limit: int, window: int}> */
    private const LIMITS = [
        'auth'    => ['limit' => 5,  'window' => 900],
        'upload'  => ['limit' => 10, 'window' => 3600],
        'general' => ['limit' => 30, 'window' => 900],
    ];

    protected string $category = 'general';

    public function handle(Request $request, callable $next): Response
    {
        // Only rate-limit state-changing methods
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $ip = $request->ip();
        $config = self::LIMITS[$this->category] ?? self::LIMITS['general'];

        if ($this->isLimited($ip, $config['limit'], $config['window'])) {
            if ($request->wantsJson() || $request->isHtmx()) {
                return Response::json(['error' => 'Too many requests. Please wait.'], 429)
                    ->withHeader('Retry-After', (string) $config['window']);
            }

            return Response::html(
                '<h1>Too Many Requests</h1><p>Please wait a few minutes and try again.</p>',
                429
            )->withHeader('Retry-After', (string) $config['window']);
        }

        $this->record($ip);

        return $next($request);
    }

    private function cacheDir(): string
    {
        $dir = LDR_ROOT . '/storage/cache/' . self::CACHE_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private function cacheFile(string $ip): string
    {
        return $this->cacheDir() . '/' . md5($ip . '|' . $this->category) . '.json';
    }

    private function isLimited(string $ip, int $limit, int $window): bool
    {
        $file = $this->cacheFile($ip);
        if (!file_exists($file)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($file), true) ?: [];
        $cutoff = time() - $window;
        $recent = array_filter($data, fn(int $ts) => $ts > $cutoff);

        return count($recent) >= $limit;
    }

    private function record(string $ip): void
    {
        $file = $this->cacheFile($ip);
        $data = [];

        if (file_exists($file)) {
            $data = json_decode((string) file_get_contents($file), true) ?: [];
        }

        $data[] = time();

        // Prune entries older than 1 hour
        $cutoff = time() - 3600;
        $data = array_values(array_filter($data, fn(int $ts) => $ts > $cutoff));

        file_put_contents($file, json_encode($data), LOCK_EX);
    }
}
