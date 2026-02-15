<?php

declare(strict_types=1);

namespace App;

/**
 * Lightweight pattern-matching router.
 *
 * Supports {param} extraction, HTTP method routing, route groups with prefix,
 * middleware attachment, and named routes for URL generation.
 */
final class Router
{
    /** @var array<string, array<array{pattern: string, handler: callable|array, middleware: string[], name: ?string}>> */
    private array $routes = [];

    /** @var array<string, string> Named route patterns for URL generation */
    private array $namedRoutes = [];

    private string $groupPrefix = '';

    /** @var string[] */
    private array $groupMiddleware = [];

    // --- Route registration ---

    public function get(string $path, callable|array $handler, string $name = null): self
    {
        return $this->addRoute('GET', $path, $handler, $name);
    }

    public function post(string $path, callable|array $handler, string $name = null): self
    {
        return $this->addRoute('POST', $path, $handler, $name);
    }

    public function put(string $path, callable|array $handler, string $name = null): self
    {
        return $this->addRoute('PUT', $path, $handler, $name);
    }

    public function delete(string $path, callable|array $handler, string $name = null): self
    {
        return $this->addRoute('DELETE', $path, $handler, $name);
    }

    /** Register a route group with shared prefix and/or middleware. */
    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = $previousPrefix . $prefix;
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    private function addRoute(string $method, string $path, callable|array $handler, ?string $name): self
    {
        $fullPath = $this->groupPrefix . $path;

        // Convert {param} to regex named groups
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $fullPath);
        $pattern = '#^' . $pattern . '$#';

        $route = [
            'pattern' => $pattern,
            'handler' => $handler,
            'middleware' => $this->groupMiddleware,
            'name' => $name,
        ];

        $this->routes[$method][] = $route;

        if ($name !== null) {
            $this->namedRoutes[$name] = $fullPath;
        }

        return $this;
    }

    // --- Dispatching ---

    /**
     * Match the request to a route.
     *
     * @return array{handler: callable|array, params: array<string, string>, middleware: string[]}|null
     */
    public function match(Request $request): ?array
    {
        $method = $request->method;
        $path = $request->path;

        // Support method override via _method POST field (for PUT/DELETE from HTML forms)
        if ($method === 'POST' && isset($request->post['_method'])) {
            $method = strtoupper($request->post['_method']);
        }

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                // Extract only named groups
                $params = array_filter($matches, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY);

                return [
                    'handler' => $route['handler'],
                    'params' => $params,
                    'middleware' => $route['middleware'],
                ];
            }
        }

        return null;
    }

    // --- URL generation ---

    /** Generate a URL for a named route with parameter substitution. */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \RuntimeException("Route [{$name}] not defined.");
        }

        $path = $this->namedRoutes[$name];
        foreach ($params as $key => $value) {
            $path = str_replace("{{$key}}", (string) $value, $path);
        }

        $basePath = config('app.base_path', '/lifedrawing/public');
        return rtrim($basePath, '/') . $path;
    }

    /** Get all registered route names (for debugging). */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }
}
