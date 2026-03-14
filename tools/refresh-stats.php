<?php

/**
 * CLI: Refresh all artist stats.
 *
 * Usage: php tools/refresh-stats.php
 *
 * Run this periodically (e.g. daily cron) to keep stats current.
 * Stats also refresh on dashboard load and key actions (join, claim approval).
 */

declare(strict_types=1);

define('LDR_ROOT', dirname(__DIR__));

// Load .env file if present
$envFile = LDR_ROOT . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = $_SERVER[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

require LDR_ROOT . '/vendor/autoload.php';

$config = require LDR_ROOT . '/config/database.php';

$db = new App\Database\Connection(
    host: $config['host'],
    database: $config['database'],
    username: $config['username'],
    password: $config['password'],
);

$stats = new App\Services\StatsService($db);

echo "Refreshing all artist stats...\n";
$stats->refreshAll();
echo "Done.\n";
