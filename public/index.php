<?php

declare(strict_types=1);

/**
 * Life Drawing Randburg — Front Controller
 *
 * All requests route through here. The Kernel handles the rest.
 */

define('LDR_START', microtime(true));
define('LDR_ROOT', dirname(__DIR__));

// Load .env file if present (production deploys, local overrides)
$envFile = LDR_ROOT . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = $_SERVER[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

// Composer autoloader (PSR-4, no framework)
require LDR_ROOT . '/vendor/autoload.php';

// Boot and dispatch
$kernel = new App\Kernel();
$response = $kernel->handle(App\Request::capture());
$response->send();
