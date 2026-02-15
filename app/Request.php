<?php

declare(strict_types=1);

namespace App;

/**
 * HTTP Request wrapper.
 * Immutable snapshot of the current request — no global state leakage.
 */
final class Request
{
    /** @param array<string, string> $params Route parameters extracted by Router */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
        public readonly array $cookies,
        public readonly array $files,
        private array $params = [],
        private array $attributes = [],
    ) {}

    /** Capture the current PHP request into an immutable object. */
    public static function capture(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Strip the base path for subdirectory installs.
        // Prefer APP_BASE_PATH (set in .env for production) over SCRIPT_NAME dirname
        // because htaccess rewrites make SCRIPT_NAME include /public which isn't in the URL.
        $basePath = $_ENV['APP_BASE_PATH'] ?? '';
        if ($basePath && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        } elseif (!$basePath) {
            $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
            if ($scriptDir !== '/' && str_starts_with($path, $scriptDir)) {
                $path = substr($path, strlen($scriptDir)) ?: '/';
            }
        }

        // Normalise: ensure leading slash, strip trailing slash (except root)
        $path = '/' . ltrim($path, '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            uri: $uri,
            path: $path,
            query: $_GET,
            post: $_POST,
            server: $_SERVER,
            cookies: $_COOKIE,
            files: $_FILES,
        );
    }

    /** Get a route parameter set by the Router. */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /** Set route parameters (called by Router after matching). Returns new instance. */
    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = $params;
        return $clone;
    }

    /** Get/set arbitrary request attributes (middleware can attach data). */
    public function attr(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;
        return $clone;
    }

    /** Get input from POST, then GET, then default. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /** Get all input (POST merged over GET). */
    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    /** Is this an AJAX / HTMX request? */
    public function isHtmx(): bool
    {
        return !empty($this->server['HTTP_HX_REQUEST']);
    }

    /** Does the client want JSON? */
    public function wantsJson(): bool
    {
        $accept = $this->server['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json');
    }

    /** Get the bearer token from Authorization header. */
    public function bearerToken(): ?string
    {
        $header = $this->server['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
