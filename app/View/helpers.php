<?php

declare(strict_types=1);

/**
 * View helper functions.
 *
 * These are available in all templates. They handle the repetitive
 * security and rendering concerns so templates stay clean.
 */

/** Escape HTML entities — the default output filter. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Generate a CSRF token hidden input. */
function csrf_field(): string
{
    $token = $_SESSION['_csrf_token'] ?? '';
    return '<input type="hidden" name="_csrf_token" value="' . e($token) . '">';
}

/** Generate a method spoofing hidden input for PUT/DELETE. */
function method_field(string $method): string
{
    return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
}

/** Generate an asset URL with cache-busting. */
function asset(string $path): string
{
    $basePath = config('app.base_path', '/lifedrawing/public');
    $fullPath = LDR_ROOT . '/public/assets/' . ltrim($path, '/');
    $version = file_exists($fullPath) ? filemtime($fullPath) : 0;
    return rtrim($basePath, '/') . '/assets/' . ltrim($path, '/') . '?v=' . $version;
}

/** Get the URL for a named route. */
function route(string $name, array $params = []): string
{
    return app('router')->url($name, $params);
}

/** Format a date nicely. */
function format_date(string $date, string $format = 'j M Y'): string
{
    return date($format, strtotime($date));
}

/** Output an active class if the current path matches. */
function active_if(string $path): string
{
    $current = $_SERVER['REQUEST_URI'] ?? '';
    return str_contains($current, $path) ? ' class="active"' : '';
}

/** Truncate text with ellipsis. */
function excerpt(string $text, int $length = 150): string
{
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length) . '...';
}
