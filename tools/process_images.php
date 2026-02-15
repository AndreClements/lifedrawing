<?php

declare(strict_types=1);

/**
 * CLI Image Processor.
 *
 * Finds artworks with NULL processed_at and applies the full pipeline:
 *   1. EXIF rotation correction (JPEG only, in-place)
 *   2. Cap original at ~10MP (in-place)
 *   3. Generate web-display WebP (2000px longest side)
 *   4. Generate thumbnail WebP (400px longest side)
 *
 * Designed to run as a cron job or manually after uploads.
 * Uses flock to prevent overlapping cron runs.
 *
 * Usage:
 *   php tools/process_images.php              — Process all unprocessed images
 *   php tools/process_images.php --limit=20   — Process at most 20 images
 *   php tools/process_images.php --reprocess  — Reset all and reprocess
 */

// Allow generous memory for large images (20MP JPEG ≈ 120MB in GD)
ini_set('memory_limit', '384M');

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

// --- Flock: prevent overlapping cron runs ---

$lockFile = LDR_ROOT . '/storage/process_images.lock';
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0755, true);
}
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // Another instance is running — normal for cron overlap
    exit(0);
}

// --- Capability checks ---

$processor = new \App\Services\Upload\ImageProcessor();
$caps = $processor->checkCapabilities();

if (!$caps['gd']) {
    logLine("FATAL: GD extension not available. Cannot process images.");
    logLine("  And-Yet: Install php-gd or enable it in php.ini.");
    exit(1);
}

if (!$caps['webp']) {
    logLine("FATAL: WebP support not available in GD. Cannot generate derivatives.");
    logLine("  And-Yet: PHP must be compiled with --with-webp (libwebp). Check phpinfo().");
    exit(1);
}

if (!$caps['exif']) {
    logLine("WARNING: EXIF extension not available — phone photos may remain rotated.");
    logLine("  And-Yet: Enable php-exif for automatic EXIF orientation correction.");
}

// --- DB connection (direct PDO — no Container needed for CLI) ---

$cfg = config('database');
$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4",
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$uploadDir = LDR_ROOT . '/public/assets/uploads';

// --- Parse CLI flags ---

$limit = 0;
$reprocess = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, 8);
    }
    if ($arg === '--reprocess') {
        $reprocess = true;
    }
}

if ($reprocess) {
    $pdo->exec("UPDATE ld_artworks SET processed_at = NULL, web_path = NULL, thumbnail_path = NULL");
    logLine("Reset all artworks for reprocessing.");
}

// --- Fetch unprocessed artworks ---

$query = "SELECT id, file_path FROM ld_artworks WHERE processed_at IS NULL AND file_path IS NOT NULL ORDER BY id ASC";
if ($limit > 0) {
    $query .= " LIMIT {$limit}";
}

$artworks = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

if (empty($artworks)) {
    logLine("No images to process.");
    exit(0);
}

logLine(count($artworks) . " artwork(s) to process.");

$processed = 0;
$failed = 0;
$skipped = 0;

$updateStmt = $pdo->prepare(
    "UPDATE ld_artworks SET web_path = ?, thumbnail_path = ?, processed_at = NOW() WHERE id = ?"
);

foreach ($artworks as $artwork) {
    $id = (int) $artwork['id'];
    $filePath = $artwork['file_path'];
    $sourcePath = $uploadDir . '/' . $filePath;

    // --- Source file check ---

    if (!file_exists($sourcePath)) {
        logLine("  SKIP #{$id}: source file missing ({$filePath})");
        $skipped++;
        continue;
    }

    try {
        $ext = $processor->extensionFromPath($filePath);
        $baseDir = dirname($sourcePath);
        $stem = pathinfo(basename($filePath), PATHINFO_FILENAME);
        $relDir = dirname($filePath);

        // --- Step 1: EXIF rotation (in-place) ---

        $rotated = $processor->correctExifRotation($sourcePath, $ext);
        if ($rotated) {
            logLine("  #{$id}: EXIF rotation corrected");
        }

        // --- Step 2: Cap original at ~10MP (in-place) ---

        $capped = $processor->capOriginal($sourcePath, $ext);
        if ($capped) {
            logLine("  #{$id}: Original capped at ~10MP");
        }

        // --- Step 3: Generate web-display WebP ---

        $webFilename = 'web_' . $stem . '.webp';
        $webDest = $baseDir . '/' . $webFilename;
        $webRelative = $relDir . '/' . $webFilename;
        $webOk = $processor->createWebDisplay($sourcePath, $webDest);

        if (!$webOk) {
            logLine("  FAIL #{$id}: web display generation failed");
            logLine("    And-Yet: Source exists and passed earlier checks — GD may have failed on this specific image format or dimensions.");
            $failed++;
            continue;
        }

        // --- Step 4: Generate thumbnail WebP (from web version for memory efficiency) ---

        $thumbFilename = 'thumb_' . $stem . '.webp';
        $thumbDest = $baseDir . '/' . $thumbFilename;
        $thumbRelative = $relDir . '/' . $thumbFilename;

        // Try from web version first (smaller, faster), fall back to original
        $thumbOk = $processor->createThumbnail($webDest, $thumbDest);
        if (!$thumbOk) {
            logLine("  #{$id}: Thumb from web failed, trying from original...");
            $thumbOk = $processor->createThumbnail($sourcePath, $thumbDest);
        }

        if (!$thumbOk) {
            logLine("  FAIL #{$id}: thumbnail generation failed from both web and original");
            logLine("    And-Yet: Web display was generated OK but thumbnail failed — unusual. Check GD memory or the generated WebP file.");
            $failed++;
            continue;
        }

        // --- Step 5: Update DB (only when ALL derivatives succeed) ---

        $updateStmt->execute([$webRelative, $thumbRelative, $id]);
        logLine("  OK #{$id}: web={$webRelative} thumb={$thumbRelative}");
        $processed++;

    } catch (\Throwable $e) {
        $failed++;
        logLine("  ERROR #{$id}: " . get_class($e) . ": " . $e->getMessage());
        logLine("    And-Yet: Unhandled exception during processing of {$filePath} — image left unprocessed for retry on next run.");
        logLine("    File: {$e->getFile()}:{$e->getLine()}");
    }

    // Encourage GC every 10 images
    if (($processed + $failed + $skipped) % 10 === 0) {
        gc_collect_cycles();
    }
}

logLine("\nDone: {$processed} processed, {$failed} failed, {$skipped} skipped.");

// Release lock
flock($lock, LOCK_UN);
fclose($lock);

// --- Helpers ---

function logLine(string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] {$message}\n";
}
