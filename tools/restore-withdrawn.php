<?php

/**
 * CLI: Restore artwork hidden by a consent withdrawal.
 *
 * Usage:
 *   php tools/restore-withdrawn.php --user=ID              # dry run, all their work
 *   php tools/restore-withdrawn.php --user=ID --artwork=ID # dry run, one piece
 *   php tools/restore-withdrawn.php --user=ID --execute    # apply
 *
 * Re-granting consent deliberately does NOT restore hidden images. Some of what
 * a person withdrew was probably meant to stay withdrawn, and republishing it
 * all on one click would expose exactly the work they wanted held back. So
 * restoration is a separate, explicit act, run when someone asks for it.
 *
 * Two states that look alike must not be conflated:
 *   - 'private'  — archived by withdrawal. Restorable.
 *   - 'removed'  — deleted. Stays deleted; its files were unlinked, not moved.
 *
 * Only files actually present in storage/withdrawn/ are restored, and only for
 * rows still marked 'private'.
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

// --- Args ---

$userId    = 0;
$artworkId = 0;
$execute   = in_array('--execute', $argv, true);

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--user='))    $userId    = (int) substr($arg, 7);
    if (str_starts_with($arg, '--artwork=')) $artworkId = (int) substr($arg, 10);
}

if ($userId <= 0) {
    fwrite(STDERR, "Usage: php tools/restore-withdrawn.php --user=ID [--artwork=ID] [--execute]\n");
    exit(1);
}

$user = $pdo->prepare("SELECT id, display_name, consent_state FROM users WHERE id = ?");
$user->execute([$userId]);
$user = $user->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    fwrite(STDERR, "No such user: {$userId}\n");
    exit(1);
}

echo "Restoring for: {$user['display_name']} (#{$userId}, consent: {$user['consent_state']})\n";
if (!$execute) {
    echo "DRY RUN — nothing will be changed. Add --execute to apply.\n";
}
echo str_repeat('-', 68) . "\n";

// --- Candidates ---

$sql = "SELECT id, file_path, web_path, thumbnail_path, visibility
        FROM ld_artworks
        WHERE uploaded_by = ? AND visibility = 'private'";
$params = [$userId];

if ($artworkId > 0) {
    $sql .= " AND id = ?";
    $params[] = $artworkId;
}

$uploadDir    = LDR_ROOT . '/public/assets/uploads';
$withdrawnDir = LDR_ROOT . '/storage/withdrawn/' . $userId;

// Same lock the image worker holds, for the same reason withdrawal takes it:
// a run in flight writes into the public tree at four separate points.
//
// Acquired BEFORE reading the candidates. Reading first and locking second
// leaves a window where an artwork selected as 'private' is deleted while we
// wait - and we would then move its archived files back into public view. The
// conditional UPDATE afterwards cannot undo that exposure, because the files
// are already being served.
$locked = \App\Services\ImageLock::acquire();
if (!$locked) {
    fwrite(STDERR, "Could not acquire the image lock; a process_images run may be active. Try again shortly.\n");
    exit(1);
}

$stmt = $pdo->prepare($sql . " ORDER BY id ASC");
$stmt->execute($params);
$artworks = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($artworks)) {
    \App\Services\ImageLock::release();
    echo "Nothing to restore (no 'private' artwork found for this user).\n";
    exit(0);
}

$restored = 0;
$missing  = 0;
$failed   = 0;

try {
    foreach ($artworks as $artwork) {
        $id    = (int) $artwork['id'];
        $paths = [];
        foreach (['file_path', 'web_path', 'thumbnail_path'] as $col) {
            if (!empty($artwork[$col])) $paths[] = $artwork[$col];
        }

        // Re-check under the lock. 'private' means archived and restorable;
        // 'removed' means deleted and must stay deleted. The two must never be
        // conflated, and the state can have changed since the list was built.
        $current = $pdo->prepare("SELECT visibility FROM ld_artworks WHERE id = ?");
        $current->execute([$id]);
        if ($current->fetchColumn() !== 'private') {
            echo "  SKIP  #{$id}: no longer private - leaving it alone\n";
            $missing++;
            continue;
        }

        $movable = [];
        foreach ($paths as $rel) {
            if (is_file($withdrawnDir . '/' . $rel)) {
                $movable[] = $rel;
            }
        }

        if (empty($movable)) {
            echo "  SKIP  #{$id}: no archived files found under storage/withdrawn/{$userId}/\n";
            $missing++;
            continue;
        }

        echo ($execute ? "  MOVE  " : "  WOULD ") . "#{$id}: " . count($movable) . " file(s)\n";

        if (!$execute) {
            $restored++;
            continue;
        }

        $ok = true;
        $restoredNow = [];
        foreach ($movable as $rel) {
            $src  = $withdrawnDir . '/' . $rel;
            $dest = $uploadDir . '/' . $rel;
            $destDir = dirname($dest);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);

            if (@rename($src, $dest) || (@copy($src, $dest) && @unlink($src))) {
                $restoredNow[] = $rel;
            } else {
                echo "        FAILED to restore {$rel}\n";
                $ok = false;
            }
        }

        // Roll back a partial restore. Otherwise the files that DID move are
        // sitting in the public tree, fetchable by direct URL, while the row
        // stays 'private' and the operator is told the restore failed — the
        // image is live and nothing says so.
        if (!$ok) {
            foreach ($restoredNow as $rel) {
                $back = $withdrawnDir . '/' . $rel;
                $from = $uploadDir . '/' . $rel;
                if (!is_dir(dirname($back))) @mkdir(dirname($back), 0755, true);
                if (!@rename($from, $back) && !(@copy($from, $back) && @unlink($from))) {
                    echo "        WARNING: {$rel} is now PUBLIC and could not be put back\n";
                }
            }
        }

        if ($ok) {
            // Only flip visibility once the files are actually back. A public
            // row pointing at a file that is not there is the wrong failure.
            $pdo->prepare("UPDATE ld_artworks SET visibility = 'public' WHERE id = ? AND visibility = 'private'")
                ->execute([$id]);
            $pdo->prepare(
                "INSERT INTO provenance_log (user_id, action, entity_type, entity_id, context, ip_address)
                 VALUES (?, 'artwork.restore', 'artwork', ?, ?, 'cli')"
            )->execute([$userId, $id, json_encode(['files' => count($movable)])]);
            $restored++;
        } else {
            $failed++;
        }
    }
} finally {
    \App\Services\ImageLock::release();
}

echo str_repeat('-', 68) . "\n";
echo ($execute ? "Restored" : "Would restore") . ": {$restored}   no archived files: {$missing}   failed: {$failed}\n";

exit($failed > 0 ? 1 : 0);
