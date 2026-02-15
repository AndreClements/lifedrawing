<?php

/**
 * CLI: Seed the database with demo data.
 *
 * Usage: php tools/seed.php [--fresh]
 *
 * Options:
 *   --fresh   Truncate all tables before seeding (WARNING: destructive)
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

$fresh = in_array('--fresh', $argv ?? [], true);

if ($fresh) {
    echo "Truncating tables...\n";
    $db->execute("SET FOREIGN_KEY_CHECKS = 0");
    $tables = ['ld_artist_stats', 'ld_claims', 'ld_artworks', 'ld_session_participants', 'ld_sessions', 'provenance_log', 'users'];
    foreach ($tables as $table) {
        $db->execute("TRUNCATE TABLE {$table}");
        echo "  Truncated: {$table}\n";
    }
    $db->execute("SET FOREIGN_KEY_CHECKS = 1");
}

$seedFile = LDR_ROOT . '/database/seeds/demo.sql';
if (!file_exists($seedFile)) {
    echo "Seed file not found: {$seedFile}\n";
    exit(1);
}

$sql = file_get_contents($seedFile);

// Strip comment lines, then split on semicolons
$stripped = preg_replace('/^--.*$/m', '', $sql);
$statements = array_filter(
    array_map('trim', explode(';', $stripped)),
    fn($s) => $s !== ''
);

$count = 0;
foreach ($statements as $stmt) {
    try {
        $db->execute($stmt);
        $count++;
    } catch (\PDOException $e) {
        echo "Error: {$e->getMessage()}\n";
        echo "Statement: " . substr($stmt, 0, 120) . "...\n\n";
    }
}

echo "Executed {$count} statements.\n";

// Refresh stats
echo "Refreshing stats...\n";
$stats = new App\Services\StatsService($db);
$stats->refreshAll();

echo "Seeding complete.\n";
