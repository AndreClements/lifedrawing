<?php

declare(strict_types=1);

/**
 * LDRBot Post — insert AI-generated feedback as comments.
 *
 * Reads a JSON file of artwork feedback and inserts each as a comment
 * by the LDRBot user. Enqueues notifications for the artist claimant
 * only (not the model). Logs provenance for each comment.
 *
 * Usage:
 *   php tools/ldrbot-post.php --file=feedback.json
 *   php tools/ldrbot-post.php --file=feedback.json --dry-run
 *
 * Input JSON format:
 *   [
 *     { "artwork_id": 42, "body": "The weight distribution here..." },
 *     { "artwork_id": 43, "body": "There is a confident economy..." }
 *   ]
 *
 * And-Yet: Notifications target only the artist claimant, not the model.
 * artworkCommented() in NotificationService notifies both roles — we bypass
 * it and enqueue directly to keep feedback notifications scoped to artists.
 */

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

// --- LDRBot user ID (set after creating the account) ---

const LDRBOT_USER_ID = 256;

// --- DB connection (Connection for provenance, PDO for direct queries) ---

$cfg = config('database');
$db = new \App\Database\Connection(
    host: $cfg['host'],
    database: $cfg['database'],
    username: $cfg['username'],
    password: $cfg['password'],
    port: (int) ($cfg['port'] ?? 3306),
);
$provenance = new \App\Services\ProvenanceService($db);

// Also need raw PDO for notification queue insert (Connection uses prepared statements only)
$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4",
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// --- Parse CLI flags ---

$filePath = null;
$dryRun = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $filePath = substr($arg, 7);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

if (!$filePath) {
    fwrite(STDERR, "Usage: php tools/ldrbot-post.php --file=feedback.json [--dry-run]\n");
    exit(1);
}

if (LDRBOT_USER_ID === 0) {
    fwrite(STDERR, "LDRBOT_USER_ID not set. Create the LDRBot user first.\n");
    exit(1);
}

// --- Load feedback JSON ---

if (!file_exists($filePath)) {
    // Try relative to LDR_ROOT
    $filePath = LDR_ROOT . '/' . $filePath;
}

if (!file_exists($filePath)) {
    fwrite(STDERR, "File not found: {$filePath}\n");
    exit(1);
}

$feedback = json_decode(file_get_contents($filePath), true);

if (!is_array($feedback) || empty($feedback)) {
    fwrite(STDERR, "Invalid or empty JSON in {$filePath}\n");
    exit(1);
}

// --- Build base URL for notification links ---

$parsed = parse_url(config('app.url', 'http://localhost'));
$origin = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? 'localhost')
    . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
$basePath = rtrim($parsed['path'] ?? '', '/');
$prefsLink = $origin . $basePath . '/profile/edit';

// --- Prepare statements ---

$insertComment = $pdo->prepare(
    "INSERT INTO ld_comments (artwork_id, user_id, body) VALUES (?, ?, ?)"
);

$checkExisting = $pdo->prepare(
    "SELECT COUNT(*) FROM ld_comments WHERE artwork_id = ? AND user_id = ?"
);

$findArtwork = $pdo->prepare(
    "SELECT a.id, a.session_id
     FROM ld_artworks a
     WHERE a.id = ?"
);

$findArtistClaim = $pdo->prepare(
    "SELECT u.id, u.display_name, u.email, u.notify_comment
     FROM ld_claims c
     JOIN users u ON c.claimant_id = u.id
     WHERE c.artwork_id = ? AND c.claim_type = 'artist' AND c.status = 'approved'
       AND u.email NOT LIKE '%.stub@local'
     LIMIT 1"
);

$enqueueNotification = $pdo->prepare(
    "INSERT INTO ld_notification_queue
     (recipient_id, recipient_name, recipient_email, notification_type, session_id, subject, summary, detail, footer)
     VALUES (?, ?, ?, 'artworkCommented', ?, ?, ?, ?, ?)"
);

// --- Process each feedback entry ---

logLine("Processing " . count($feedback) . " feedback entries" . ($dryRun ? " (DRY RUN)" : "") . ".");

$posted = 0;
$skipped = 0;
$errors = 0;

foreach ($feedback as $entry) {
    $artworkId = (int) ($entry['artwork_id'] ?? 0);
    $body = trim($entry['body'] ?? '');

    if ($artworkId === 0 || $body === '') {
        logLine("  SKIP: missing artwork_id or body");
        $skipped++;
        continue;
    }

    if (mb_strlen($body) > 2000) {
        logLine("  SKIP #{$artworkId}: body exceeds 2000 chars (" . mb_strlen($body) . ")");
        $skipped++;
        continue;
    }

    // Check artwork exists
    $findArtwork->execute([$artworkId]);
    $artwork = $findArtwork->fetch(PDO::FETCH_ASSOC);
    if (!$artwork) {
        logLine("  SKIP #{$artworkId}: artwork not found");
        $skipped++;
        continue;
    }

    // Check for existing bot comment (idempotent)
    $checkExisting->execute([$artworkId, LDRBOT_USER_ID]);
    if ((int) $checkExisting->fetchColumn() > 0) {
        logLine("  SKIP #{$artworkId}: bot comment already exists");
        $skipped++;
        continue;
    }

    // Check artist claim exists
    $findArtistClaim->execute([$artworkId]);
    $artist = $findArtistClaim->fetch(PDO::FETCH_ASSOC);
    if (!$artist) {
        logLine("  SKIP #{$artworkId}: no approved artist claim");
        $skipped++;
        continue;
    }

    if ($dryRun) {
        logLine("  DRY #{$artworkId}: would post " . mb_strlen($body) . " chars for {$artist['display_name']}");
        $posted++;
        continue;
    }

    try {
        // Insert comment
        $insertComment->execute([$artworkId, LDRBOT_USER_ID, $body]);
        $commentId = (int) $pdo->lastInsertId();

        // Provenance
        $provenance->log(
            LDRBOT_USER_ID,
            'artwork.bot_comment',
            'artwork',
            $artworkId,
            ['comment_id' => $commentId, 'source' => 'ldrbot']
        );

        // Enqueue notification for artist only (if they opted in)
        if ((int) $artist['notify_comment'] === 1) {
            $hexId = dechex($artworkId);
            $artworkLink = $origin . $basePath . "/artworks/{$hexId}#comments";
            $snippet = mb_strlen($body) > 100 ? mb_substr($body, 0, 100) . '...' : $body;
            $footer = "You're receiving this because you opted in to comment notifications.\n"
                . "Update your preferences: {$prefsLink}";

            $enqueueNotification->execute([
                (int) $artist['id'],
                $artist['display_name'],
                $artist['email'],
                (int) $artwork['session_id'],
                "New Comment on Your Artwork",
                "LDRBot commented on artwork you've claimed:\n\n\"{$snippet}\"",
                "View the conversation: {$artworkLink}",
                $footer,
            ]);
        }

        logLine("  OK #{$artworkId}: posted comment (id={$commentId}, " . mb_strlen($body) . " chars) → {$artist['display_name']}");
        $posted++;

        // Courteous pacing
        usleep(200_000);

    } catch (\Throwable $e) {
        $errors++;
        logLine("  ERROR #{$artworkId}: " . $e->getMessage());
    }
}

logLine("Done: {$posted} posted, {$skipped} skipped, {$errors} errors.");

// --- Helpers ---

function logLine(string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] {$message}\n";
}
