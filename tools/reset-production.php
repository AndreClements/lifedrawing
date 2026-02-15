<?php

/**
 * CLI: Reset production data while preserving specific user accounts.
 *
 * Usage: php tools/reset-production.php --confirm [--keep-users=1,2,3]
 *
 * Deletes all session data, artworks, claims, comments, stats, and stub users.
 * Preserves real user accounts specified by --keep-users (default: 1,2,3).
 */

declare(strict_types=1);

define('LDR_ROOT', dirname(__DIR__));

require LDR_ROOT . '/vendor/autoload.php';

// Load .env for CLI context
if (file_exists(LDR_ROOT . '/.env')) {
    $lines = file(LDR_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
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

$db = new App\Database\Connection(
    host: $config['host'],
    database: $config['database'],
    username: $config['username'],
    password: $config['password'],
);

// Parse args
$confirm = false;
$keepUsers = [1, 2, 3];

foreach ($argv as $arg) {
    if ($arg === '--confirm') {
        $confirm = true;
    }
    if (str_starts_with($arg, '--keep-users=')) {
        $keepUsers = array_map('intval', explode(',', substr($arg, 13)));
    }
}

if (!$confirm) {
    echo "DRY RUN — pass --confirm to execute.\n\n";
}

$keepList = implode(',', $keepUsers);
echo "Preserving user IDs: {$keepList}\n\n";

// Tables to clear in FK-safe order
$steps = [
    ['ld_comments',             "DELETE FROM ld_comments"],
    ['ld_claims',               "DELETE FROM ld_claims"],
    ['ld_artworks',             "DELETE FROM ld_artworks"],
    ['ld_artist_stats',         "DELETE FROM ld_artist_stats"],
    ['ld_session_participants', "DELETE FROM ld_session_participants"],
    ['ld_sessions',             "DELETE FROM ld_sessions"],
    ['stub users',              "DELETE FROM users WHERE id NOT IN ({$keepList})"],
    ['password_resets',         "DELETE FROM password_resets"],
    ['provenance_log',          "DELETE FROM provenance_log"],
];

if ($confirm) {
    $db->execute("SET FOREIGN_KEY_CHECKS = 0");
}

foreach ($steps as [$label, $sql]) {
    if ($confirm) {
        $db->execute($sql);
        // PDO rowCount after DELETE
        $pdo = (new ReflectionClass($db))->getProperty('pdo');
        $pdo->setAccessible(true);
        $count = $pdo->getValue($db)->query("SELECT ROW_COUNT()")->fetchColumn();
        echo "  {$label}: deleted {$count} rows\n";
    } else {
        // Count what would be deleted
        $countSql = preg_replace('/^DELETE FROM/', 'SELECT COUNT(*) FROM', $sql);
        $count = $db->fetchColumn($countSql);
        echo "  {$label}: would delete {$count} rows\n";
    }
}

if ($confirm) {
    $db->execute("SET FOREIGN_KEY_CHECKS = 1");
    echo "\nReset complete. Preserved users:\n";
    $kept = $db->fetchAll("SELECT id, display_name, email, role FROM users ORDER BY id");
    foreach ($kept as $u) {
        echo "  #{$u['id']} {$u['display_name']} ({$u['email']}) — {$u['role']}\n";
    }
} else {
    echo "\nNo changes made. Pass --confirm to execute.\n";
}
