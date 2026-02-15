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
