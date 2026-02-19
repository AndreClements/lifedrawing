<?php

/**
 * CLI: Check user account state — real users, unclaimed stubs, orphan matches.
 *
 * Usage: php tools/check-users.php [--stubs-only] [--name "David Forbes"]
 *
 * Shows: real accounts, stub accounts with matching real names, totals.
 * Useful for verifying stub claiming worked and spotting orphaned stubs.
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
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Parse args
$stubsOnly = in_array('--stubs-only', $argv, true);
$nameFilter = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--name' && isset($argv[$i + 1])) {
        $nameFilter = $argv[$i + 1];
    }
}

// ──────────────────────────────────────────────
// If filtering by name, show just that person
// ──────────────────────────────────────────────
if ($nameFilter) {
    echo "=== SEARCHING FOR: {$nameFilter} ===\n\n";
    $stmt = $pdo->prepare("
        SELECT u.id, u.display_name, u.email, u.role, u.created_at,
               u.password_hash,
               (SELECT COUNT(*) FROM ld_session_participants sp WHERE sp.user_id = u.id) as sessions,
               (SELECT COUNT(*) FROM ld_claims c WHERE c.claimant_id = u.id) as claims
        FROM users u
        WHERE u.display_name LIKE ?
        ORDER BY u.id
    ");
    $stmt->execute(["%{$nameFilter}%"]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($matches)) {
        echo "  No users found matching '{$nameFilter}'.\n";
    }
    foreach ($matches as $r) {
        $isStub = str_ends_with($r['email'], '.stub@local');
        $deadHash = str_contains($r['password_hash'], 'DEAD.HASH');
        echo sprintf("  #%-4d | %-25s | %-40s | %s\n", $r['id'], $r['display_name'], $r['email'], $r['role']);
        echo sprintf("         stub: %s | dead_hash: %s | %d sessions | %d claims | created: %s\n",
            $isStub ? 'YES' : 'no', $deadHash ? 'YES' : 'no',
            $r['sessions'], $r['claims'], $r['created_at']);

        // Check provenance
        $prov = $pdo->prepare("SELECT action, details, created_at FROM provenance_log WHERE target_id = ? ORDER BY created_at DESC LIMIT 5");
        $prov->execute([$r['id']]);
        $logs = $prov->fetchAll(PDO::FETCH_ASSOC);
        if ($logs) {
            echo "         provenance:\n";
            foreach ($logs as $log) {
                echo "           {$log['created_at']} | {$log['action']} | {$log['details']}\n";
            }
        }
        echo "\n";
    }
    exit(0);
}

// ──────────────────────────────────────────────
// Full report
// ──────────────────────────────────────────────
if (!$stubsOnly) {
    echo "=== REAL REGISTERED USERS ===\n\n";
    $real = $pdo->query("
        SELECT u.id, u.display_name, u.email, u.role, u.created_at,
               (SELECT COUNT(*) FROM ld_session_participants sp WHERE sp.user_id = u.id) as sessions,
               (SELECT COUNT(*) FROM ld_claims c WHERE c.claimant_id = u.id) as claims
        FROM users u
        WHERE u.email NOT LIKE '%.stub@local'
        ORDER BY u.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($real as $r) {
        echo sprintf("  #%-4d | %-25s | %-40s | %s | %d sessions | %d claims\n",
            $r['id'], $r['display_name'], $r['email'], $r['role'],
            $r['sessions'], $r['claims']);
    }
    echo "\n";
}

echo "=== POTENTIAL UNCLAIMED STUBS (name matches real users) ===\n\n";
$real = $pdo->query("
    SELECT id, display_name FROM users WHERE email NOT LIKE '%.stub@local'
")->fetchAll(PDO::FETCH_ASSOC);

$found = false;
foreach ($real as $r) {
    $parts = explode(' ', $r['display_name']);
    $firstName = $parts[0];

    // Match stubs by first name or full name
    $stmt = $pdo->prepare("
        SELECT id, display_name, email,
               (SELECT COUNT(*) FROM ld_session_participants sp WHERE sp.user_id = users.id) as sessions
        FROM users
        WHERE email LIKE '%.stub@local'
          AND (display_name LIKE ? OR display_name LIKE ?)
        ORDER BY id
    ");
    $stmt->execute(["{$firstName}%", "%{$r['display_name']}%"]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($matches) {
        $found = true;
        echo "  Real: #{$r['id']} {$r['display_name']}\n";
        foreach ($matches as $m) {
            echo "    Stub: #{$m['id']} {$m['display_name']} ({$m['email']}) — {$m['sessions']} sessions\n";
        }
        echo "\n";
    }
}
if (!$found) {
    echo "  None found — all stubs appear to be for unregistered participants.\n\n";
}

echo "=== PENDING CLAIMS ===\n\n";
$pending = $pdo->query("
    SELECT c.id, c.claim_type, c.claimed_at, a.session_id,
           u.display_name as claimant, s.session_date,
           s.facilitator_id
    FROM ld_claims c
    JOIN ld_artworks a ON c.artwork_id = a.id
    JOIN ld_sessions s ON a.session_id = s.id
    JOIN users u ON c.claimant_id = u.id
    WHERE c.status = 'pending'
    ORDER BY c.claimed_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($pending)) {
    echo "  No pending claims.\n\n";
} else {
    foreach ($pending as $p) {
        echo sprintf("  Claim #%d | %s | %s | session %d (%s) | facilitator: %s\n",
            $p['id'], $p['claimant'], $p['claim_type'], $p['session_id'],
            $p['session_date'], $p['facilitator_id'] ?? 'NULL');
    }
    echo "\n";
}

echo "=== TOTALS ===\n\n";
$total = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$stubs = $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE '%.stub@local'")->fetchColumn();
echo "  Total users: {$total}\n";
echo "  Stub accounts: {$stubs}\n";
echo "  Real accounts: " . ($total - $stubs) . "\n";
