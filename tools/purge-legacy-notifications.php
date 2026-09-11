<?php

/**
 * CLI: one-off cutover purge of unsent notifications that predate migration 020.
 *
 * Usage:
 *   php tools/purge-legacy-notifications.php            # dry run
 *   php tools/purge-legacy-notifications.php --execute  # apply
 *
 * Run this INSIDE the deploy window, while the notification cron is still
 * paused, so nothing is delivered between the purge and the restart.
 *
 * Why it exists: rows queued before migration 020 carry a NULL source_type, so
 * deleting an artwork or withdrawing a claim cannot identify their mail and
 * cancel it. Without clearing them, the cancellation guarantee is false for
 * exactly the rows most likely to matter — the ones queued minutes before the
 * deploy. Nor do they age out on their own: cleanup() only removes rows that
 * have already been sent, and a row that keeps failing is never sent.
 *
 * The cost is real: a handful of buffered notifications are lost. That is the
 * right trade against a privacy guarantee that cannot be honoured.
 */

declare(strict_types=1);

define('LDR_ROOT', dirname(__DIR__));

require LDR_ROOT . '/vendor/autoload.php';

if (file_exists(LDR_ROOT . '/.env')) {
    foreach (file(LDR_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (str_contains($line, '=')) {
            putenv($line);
            [$key, $val] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val, '"\'');
        }
    }
}

$config = require LDR_ROOT . '/config/database.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$execute = in_array('--execute', $argv, true);

$rows = $pdo->query(
    "SELECT notification_type, COUNT(*) AS n, MIN(created_at) AS oldest, MAX(created_at) AS newest
     FROM ld_notification_queue
     WHERE sent_at IS NULL AND source_type IS NULL
     GROUP BY notification_type
     ORDER BY n DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$total = array_sum(array_column($rows, 'n'));

if ($total === 0) {
    echo "Nothing to purge — every unsent row already carries a source.\n";
    exit(0);
}

echo ($execute ? "PURGING" : "DRY RUN") . " — unsent notifications with no source identifier\n";
echo str_repeat('-', 68) . "\n";
printf("%-22s %6s  %-19s %-19s\n", 'TYPE', 'COUNT', 'OLDEST', 'NEWEST');
foreach ($rows as $r) {
    printf("%-22s %6d  %-19s %-19s\n", $r['notification_type'], $r['n'], $r['oldest'], $r['newest']);
}
echo str_repeat('-', 68) . "\n";
echo "Total: {$total}\n";

if (!$execute) {
    echo "\nDry run. Re-run with --execute to delete these.\n";
    exit(0);
}

$deleted = $pdo->exec(
    "DELETE FROM ld_notification_queue WHERE sent_at IS NULL AND source_type IS NULL"
);

echo "\nDeleted {$deleted} row(s).\n";
echo "Every remaining queued row now carries a source and can be cancelled.\n";
