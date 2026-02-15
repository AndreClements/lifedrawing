<?php

declare(strict_types=1);

/**
 * Life Drawing Randburg — Front Controller
 *
 * All requests route through here. The Kernel handles the rest.
 */

define('LDR_START', microtime(true));
define('LDR_ROOT', dirname(__DIR__));

// Composer autoloader (PSR-4, no framework)
require LDR_ROOT . '/vendor/autoload.php';

// Boot and dispatch
$kernel = new App\Kernel();
$response = $kernel->handle(App\Request::capture());
$response->send();
