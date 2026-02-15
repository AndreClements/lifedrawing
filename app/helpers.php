<?php

declare(strict_types=1);

/**
 * Global helper functions.
 *
 * Thin wrappers around the Container for ergonomic access.
 * Keep this file minimal — it loads on every request.
 */

use App\Container;

/** Resolve from the DI container, or return the container itself. */
function app(?string $id = null): mixed
{
    $container = Container::getInstance();
    if ($id === null) {
        return $container;
    }
    return $container->get($id);
}

/** Get a config value using dot notation. */
function config(string $key, mixed $default = null): mixed
{
    static $configs = [];

    $parts = explode('.', $key);
    $file = array_shift($parts);

    if (!isset($configs[$file])) {
        $path = LDR_ROOT . '/config/' . $file . '.php';
        $configs[$file] = file_exists($path) ? require $path : [];
    }

    $value = $configs[$file];
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

/** Get an environment variable with default. */
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false) {
        return $default;
    }

    // Cast string booleans and nulls
    return match (strtolower((string) $value)) {
        'true', '(true)' => true,
        'false', '(false)' => false,
        'null', '(null)' => null,
        'empty', '(empty)' => '',
        default => $value,
    };
}

/** Dump and die — development only. */
function dd(mixed ...$vars): never
{
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<pre style="background:#1a1a2e;color:#e0e0e0;padding:1rem;font-size:0.9rem;overflow:auto;">';
    }

    foreach ($vars as $var) {
        var_dump($var);
    }

    if (php_sapi_name() !== 'cli') {
        echo '</pre>';
    }

    exit(1);
}

/** Redirect and halt. */
function redirect(string $url, int $status = 302): never
{
    header("Location: {$url}", true, $status);
    exit;
}

/** Get the database query builder for a table. */
function db(string $table = ''): App\Database\QueryBuilder
{
    $qb = new App\Database\QueryBuilder(app('db'));
    if ($table !== '') {
        $qb = $qb->table($table);
    }
    return $qb;
}
