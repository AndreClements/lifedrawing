<?php

/**
 * CLI: Merge unclaimed stub accounts into their real registered accounts.
 *
 * Usage: php tools/merge-stubs.php [--dry-run]
 *
 * Moves session participants, claims, comments, and provenance from stub → real.
 * Handles unique constraint conflicts by skipping duplicates.
 * Deletes stub after merge. Refreshes stats for affected users.
 *
 * The merge list is hardcoded below — verify matches before running.
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

$dryRun = in_array('--dry-run', $argv, true);

$config = require LDR_ROOT . '/config/database.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ──────────────────────────────────────────────
// Merge list: [real_id, stub_id, description]
// Verified via check-users.php on 2026-02-19
// ──────────────────────────────────────────────
$merges = [
    [227, 52,  'Heinrich Meyer ← Heinrich (31 sessions)'],
    [234, 32,  'Nicole Fabry ← Nicole (54 sessions)'],
    [239, 190, 'Michelle Laubscher-Jäger ← Michelle (7 sessions)'],
    [232, 205, 'Yamilah Jasmin ← Yamilah (3 sessions)'],
    [236, 53,  'Elana ← Elana (3 sessions)'],
    [238, 105, 'Luci ← Lucia (3 sessions)'],
    [235, 213, 'Berenice Egan ← Berenice (1 session)'],
    [237, 200, 'Bron Schultz ← Bron (1 session)'],
    [240, 92,  'Shane David Grace ← Shane (1 session)'],
];

echo ($dryRun ? "[DRY RUN] " : "") . "Merging " . count($merges) . " stub accounts...\n\n";

$affectedUserIds = [];

foreach ($merges as [$realId, $stubId, $desc]) {
    echo "--- {$desc} ---\n";
    echo "  Real: #{$realId}, Stub: #{$stubId}\n";

    // Verify both accounts exist and stub is actually a stub
    $real = $pdo->prepare("SELECT id, display_name, email FROM users WHERE id = ?");
    $real->execute([$realId]);
    $realUser = $real->fetch(PDO::FETCH_ASSOC);

    $stub = $pdo->prepare("SELECT id, display_name, email FROM users WHERE id = ? AND email LIKE '%.stub@local'");
    $stub->execute([$stubId]);
    $stubUser = $stub->fetch(PDO::FETCH_ASSOC);

    if (!$realUser) {
        echo "  SKIP: Real user #{$realId} not found!\n\n";
        continue;
    }
    if (!$stubUser) {
        echo "  SKIP: Stub #{$stubId} not found or already claimed!\n\n";
        continue;
    }

    echo "  Real: {$realUser['display_name']} ({$realUser['email']})\n";
    echo "  Stub: {$stubUser['display_name']} ({$stubUser['email']})\n";

    if (!$dryRun) {
        $pdo->beginTransaction();
    }

    try {
        // 1. Move session participants (skip duplicates via unique key)
        $parts = $pdo->prepare("SELECT id, session_id, role FROM ld_session_participants WHERE user_id = ?");
        $parts->execute([$stubId]);
        $participants = $parts->fetchAll(PDO::FETCH_ASSOC);
        $moved = 0;
        $skipped = 0;

        foreach ($participants as $p) {
            // Check if real user already has this session+role
            $exists = $pdo->prepare(
                "SELECT 1 FROM ld_session_participants WHERE session_id = ? AND user_id = ? AND role = ?"
            );
            $exists->execute([$p['session_id'], $realId, $p['role']]);

            if ($exists->fetch()) {
                $skipped++;
                if (!$dryRun) {
                    $pdo->prepare("DELETE FROM ld_session_participants WHERE id = ?")->execute([$p['id']]);
                }
            } else {
                $moved++;
                if (!$dryRun) {
                    $pdo->prepare("UPDATE ld_session_participants SET user_id = ? WHERE id = ?")->execute([$realId, $p['id']]);
                }
            }
        }
        echo "  Participants: {$moved} moved, {$skipped} duplicates\n";

        // 2. Move claims (skip duplicates via unique key)
        $claims = $pdo->prepare("SELECT id, artwork_id, claim_type FROM ld_claims WHERE claimant_id = ?");
        $claims->execute([$stubId]);
        $claimRows = $claims->fetchAll(PDO::FETCH_ASSOC);
        $claimMoved = 0;
        $claimSkipped = 0;

        foreach ($claimRows as $c) {
            $exists = $pdo->prepare(
                "SELECT 1 FROM ld_claims WHERE artwork_id = ? AND claimant_id = ? AND claim_type = ?"
            );
            $exists->execute([$c['artwork_id'], $realId, $c['claim_type']]);

            if ($exists->fetch()) {
                $claimSkipped++;
                if (!$dryRun) {
                    $pdo->prepare("DELETE FROM ld_claims WHERE id = ?")->execute([$c['id']]);
                }
            } else {
                $claimMoved++;
                if (!$dryRun) {
                    $pdo->prepare("UPDATE ld_claims SET claimant_id = ? WHERE id = ?")->execute([$realId, $c['id']]);
                }
            }
        }
        echo "  Claims: {$claimMoved} moved, {$claimSkipped} duplicates\n";

        // 3. Move comments
        if (!$dryRun) {
            $commentResult = $pdo->prepare("UPDATE ld_comments SET user_id = ? WHERE user_id = ?");
            $commentResult->execute([$realId, $stubId]);
            $commentCount = $commentResult->rowCount();
        } else {
            $commentCount = $pdo->prepare("SELECT COUNT(*) FROM ld_comments WHERE user_id = ?");
            $commentCount->execute([$stubId]);
            $commentCount = $commentCount->fetchColumn();
        }
        echo "  Comments: {$commentCount} moved\n";

        // 4. Move provenance log (informational, SET NULL on delete anyway)
        if (!$dryRun) {
            $provResult = $pdo->prepare("UPDATE provenance_log SET user_id = ? WHERE user_id = ?");
            $provResult->execute([$realId, $stubId]);
            $provCount = $provResult->rowCount();
        } else {
            $provCount = $pdo->prepare("SELECT COUNT(*) FROM provenance_log WHERE user_id = ?");
            $provCount->execute([$stubId]);
            $provCount = $provCount->fetchColumn();
        }
        echo "  Provenance: {$provCount} moved\n";

        // 5. Move artist_stats (if stub has stats but real doesn't)
        if (!$dryRun) {
            $hasRealStats = $pdo->prepare("SELECT 1 FROM ld_artist_stats WHERE user_id = ?");
            $hasRealStats->execute([$realId]);
            if (!$hasRealStats->fetch()) {
                $pdo->prepare("UPDATE ld_artist_stats SET user_id = ? WHERE user_id = ?")->execute([$realId, $stubId]);
                echo "  Stats: transferred\n";
            } else {
                $pdo->prepare("DELETE FROM ld_artist_stats WHERE user_id = ?")->execute([$stubId]);
                echo "  Stats: real user already has stats (will refresh)\n";
            }
        }

        // 6. Log the merge
        if (!$dryRun) {
            $pdo->prepare(
                "INSERT INTO provenance_log (user_id, action, entity_type, entity_id, context)
                 VALUES (?, 'user.merge_stub', 'user', ?, ?)"
            )->execute([
                $realId, $stubId,
                json_encode([
                    'stub_name' => $stubUser['display_name'],
                    'stub_email' => $stubUser['email'],
                    'participants_moved' => $moved,
                    'claims_moved' => $claimMoved,
                ])
            ]);
        }

        // 7. Delete stub
        if (!$dryRun) {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$stubId]);
            echo "  Stub #{$stubId} deleted.\n";
            $pdo->commit();
        } else {
            echo "  [DRY RUN — no changes made]\n";
        }

        $affectedUserIds[] = $realId;
        echo "\n";

    } catch (\Exception $e) {
        if (!$dryRun) {
            $pdo->rollBack();
        }
        echo "  ERROR: {$e->getMessage()}\n\n";
    }
}

// Refresh stats for all affected users
if (!$dryRun && !empty($affectedUserIds)) {
    echo "Refreshing stats...\n";
    $db = new App\Database\Connection(
        host: $config['host'],
        database: $config['database'],
        username: $config['username'],
        password: $config['password'],
    );
    $stats = new App\Services\StatsService($db);
    foreach ($affectedUserIds as $uid) {
        $stats->refreshUser($uid);
        echo "  Stats refreshed for #{$uid}\n";
    }
}

echo "\nDone.\n";
