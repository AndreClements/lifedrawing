<?php

declare(strict_types=1);

/**
 * CLI Thumbnail Generator.
 *
 * Finds artworks with NULL thumbnail_path and generates thumbnails.
 * Designed to run as a cron job or manually after uploads.
 *
 * Usage:
 *   php tools/thumbnails.php          — Generate all missing thumbnails
 *   php tools/thumbnails.php --limit=10  — Process at most 10 images
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

// Direct DB connection (same pattern as migrate.php — no Container needed)
$cfg = config('database');
$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4",
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$uploadService = new \App\Services\Upload\UploadService();

// Parse --limit flag
$limit = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, 8);
    }
}

$query = "SELECT id, file_path FROM ld_artworks WHERE thumbnail_path IS NULL AND file_path IS NOT NULL";
if ($limit > 0) {
    $query .= " LIMIT {$limit}";
}

$artworks = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

if (empty($artworks)) {
    echo "No thumbnails to generate.\n";
    exit(0);
}

echo count($artworks) . " artwork(s) need thumbnails.\n";

$generated = 0;
$failed = 0;

foreach ($artworks as $artwork) {
    $sourcePath = $uploadService->uploadDir . '/' . $artwork['file_path'];

    if (!file_exists($sourcePath)) {
        echo "  SKIP #{$artwork['id']}: source file missing ({$artwork['file_path']})\n";
        $failed++;
        continue;
    }

    // Derive thumbnail path
    $dir = dirname($sourcePath);
    $basename = basename($artwork['file_path']);
    $thumbFilename = 'thumb_' . $basename;
    $thumbDest = $dir . '/' . $thumbFilename;
    $thumbRelative = dirname($artwork['file_path']) . '/' . $thumbFilename;

    // Get extension from file
    $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';

    if ($uploadService->createThumbnail($sourcePath, $thumbDest, $ext)) {
        $stmt = $pdo->prepare("UPDATE ld_artworks SET thumbnail_path = ? WHERE id = ?");
        $stmt->execute([$thumbRelative, $artwork['id']]);
        echo "  OK #{$artwork['id']}: {$thumbRelative}\n";
        $generated++;
    } else {
        echo "  FAIL #{$artwork['id']}: thumbnail generation failed\n";
        $failed++;
    }
}

echo "\nDone: {$generated} generated, {$failed} failed.\n";
