<?php

declare(strict_types=1);

/**
 * CLI Migration Tool.
 *
 * Usage:
 *   php tools/migrate.php              — Run all pending migrations
 *   php tools/migrate.php status       — Show migration status
 *   php tools/migrate.php create-db    — Create the database (run once)
 */

define('LDR_ROOT', dirname(__DIR__));
require LDR_ROOT . '/vendor/autoload.php';

use App\Database\Connection;
use App\Database\Migration;

$command = $argv[1] ?? 'run';

echo "Life Drawing Randburg — Migration Tool\n";
echo str_repeat('=', 40) . "\n\n";

// Handle database creation first (before we try to connect to it)
if ($command === 'create-db') {
    $cfg = config('database');
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']}",
        $cfg['username'],
        $cfg['password'],
    );
    $db = $cfg['database'];
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database '{$db}' created (or already exists).\n";
    exit(0);
}

// Connect to the database
try {
    $cfg = config('database');
    $db = new Connection(
        host: $cfg['host'],
        database: $cfg['database'],
        username: $cfg['username'],
        password: $cfg['password'],
        port: $cfg['port'] ?? 3306,
    );
} catch (\Throwable $e) {
    echo "ERROR: Cannot connect to database.\n";
    echo "  " . $e->getMessage() . "\n\n";
    echo "Have you created the database? Try:\n";
    echo "  php tools/migrate.php create-db\n";
    exit(1);
}

$migrator = new Migration($db);

// Core migrations
$coreMigrationPath = LDR_ROOT . '/database/migrations';

// Gather all migration paths: core + modules
$migrationSets = [];

if (is_dir($coreMigrationPath)) {
    $migrationSets[] = ['path' => $coreMigrationPath, 'module' => 'core'];
}

$modules = config('app.modules', []);
foreach ($modules as $moduleName) {
    $moduleMigrationPath = LDR_ROOT . "/modules/{$moduleName}/migrations";
    if (is_dir($moduleMigrationPath)) {
        $migrationSets[] = ['path' => $moduleMigrationPath, 'module' => $moduleName];
    }
}

match ($command) {
    'run' => runMigrations($migrator, $migrationSets),
    'status' => showStatus($migrator, $migrationSets),
    default => printUsage(),
};

function runMigrations(Migration $migrator, array $sets): void
{
    $total = 0;
    foreach ($sets as $set) {
        $applied = $migrator->run($set['path'], $set['module']);
        foreach ($applied as $name) {
            echo "  Applied: {$name}\n";
            $total++;
        }
    }

    if ($total === 0) {
        echo "Nothing to migrate. All up to date.\n";
    } else {
        echo "\nApplied {$total} migration(s).\n";
    }
}

function showStatus(Migration $migrator, array $sets): void
{
    foreach ($sets as $set) {
        $status = $migrator->status($set['path'], $set['module']);
        if (empty($status)) {
            continue;
        }
        echo "[{$set['module']}]\n";
        foreach ($status as $row) {
            $mark = $row['applied'] ? 'Y' : 'N';
            echo "  [{$mark}] {$row['migration']}\n";
        }
        echo "\n";
    }
}

function printUsage(): void
{
    echo "Usage:\n";
    echo "  php tools/migrate.php              Run pending migrations\n";
    echo "  php tools/migrate.php status       Show migration status\n";
    echo "  php tools/migrate.php create-db    Create the database\n";
}
