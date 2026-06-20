<?php

declare(strict_types=1);

/**
 * Import staged session photos into ld_artworks (facilitator bulk import).
 *
 * Mirrors the web upload path (GalleryController::upload + UploadService):
 *   - validates real MIME type (finfo, not extension) + image integrity (getimagesize)
 *   - inserts ld_artworks rows with derivatives left NULL so tools/process_images.php
 *     generates web/thumb WebP and stamps processed_at on its next run
 *   - writes an 'artwork.upload' provenance record per image
 *
 * Rerun-safe: skips any source file whose original filename was already imported
 * for this session (recorded in provenance), and as a secondary guard skips files
 * whose content hash matches an un-processed original already on disk.
 *
 * Orphan-safe: copies the file first, then inserts; if the insert throws, the
 * copied file is removed so we never leave a file without a row (or vice versa).
 *
 * Captions stay NULL. Pose duration/label are NULL unless passed via
 * --pose-duration / --pose-label (applied to every file in --dir, so stage
 * one pose per directory).
 *
 * Usage (run ON production):
 *   php tools/import-session-photos.php --session=267 --dir=~/photo-import/267 --dry-run
 *   php tools/import-session-photos.php --session=267 --dir=~/photo-import/267
 *   php tools/import-session-photos.php --session=267 --dir=~/photo-import/267 --uploader=1
 *   php tools/import-session-photos.php --session=272 --dir=~/photo-import/272/2 --pose-duration="20 min"
 */

define('LDR_ROOT', getcwd());

// --- Load .env (CLI tools bootstrap themselves) ---
$envFile = LDR_ROOT . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = $_SERVER[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}

require LDR_ROOT . '/vendor/autoload.php';

// --- Parse args ---
$sessionId = null; $dir = null; $dryRun = false; $uploader = null;
$poseLabel = null; $poseDuration = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--session='))           $sessionId = (int) substr($arg, 10);
    elseif (str_starts_with($arg, '--dir='))           $dir = substr($arg, 6);
    elseif ($arg === '--dry-run')                      $dryRun = true;
    elseif (str_starts_with($arg, '--uploader='))      $uploader = (int) substr($arg, 11);
    elseif (str_starts_with($arg, '--pose-label='))    $poseLabel = (substr($arg, 13) ?: null);
    elseif (str_starts_with($arg, '--pose-duration=')) $poseDuration = (substr($arg, 16) ?: null);
}

if (!$sessionId || !$dir) {
    fwrite(STDERR, "Usage: php tools/import-session-photos.php --session=ID --dir=PATH [--dry-run] [--uploader=ID]\n");
    exit(1);
}

// Expand a leading ~ (shells don't expand it inside --dir=~/...)
if (str_starts_with($dir, '~')) {
    $dir = (getenv('HOME') ?: '') . substr($dir, 1);
}
$dir = rtrim($dir, '/');
if (!is_dir($dir)) {
    fwrite(STDERR, "Source dir not found: $dir\n");
    exit(1);
}

// --- DB ---
$cfg = config('database');
$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4",
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// --- Validate session ---
$st = $pdo->prepare("SELECT id, facilitator_id FROM ld_sessions WHERE id = ?");
$st->execute([$sessionId]);
$session = $st->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    fwrite(STDERR, "Session $sessionId not found.\n");
    exit(1);
}
$uploader = $uploader ?: ((int) $session['facilitator_id'] ?: 1);

$uploadDir = LDR_ROOT . '/public/assets/uploads/sessions/' . $sessionId;
if (!$dryRun && !is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// --- Build dedup sets ---

// (a) original filenames already imported for this session (from provenance — survives reprocessing)
$importedOrig = [];
$pvSql = "SELECT pl.context FROM provenance_log pl
          JOIN ld_artworks a ON pl.entity_type = 'artwork' AND pl.entity_id = a.id
          WHERE a.session_id = ? AND pl.action = 'artwork.upload'";
$pv = $pdo->prepare($pvSql);
$pv->execute([$sessionId]);
foreach ($pv->fetchAll(PDO::FETCH_COLUMN) as $ctx) {
    $d = json_decode((string) $ctx, true);
    if (is_array($d) && !empty($d['orig'])) $importedOrig[$d['orig']] = true;
}

// (b) content hashes of un-processed originals currently on disk (catches reruns before process_images)
$existingHash = [];
foreach (glob($uploadDir . '/*') ?: [] as $f) {
    $b = basename($f);
    if (str_starts_with($b, 'web_') || str_starts_with($b, 'thumb_')) continue;
    if (is_file($f)) $existingHash[sha1_file($f)] = $b;
}

// --- Current max pose_index ---
$poseIndex = (int) $pdo->query(
    "SELECT COALESCE(MAX(pose_index), 0) AS m FROM ld_artworks WHERE session_id = " . $sessionId
)->fetch(PDO::FETCH_ASSOC)['m'];

// --- Walk source files (chronological by filename) ---
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$files = glob($dir . '/*') ?: [];
sort($files);

$finfo = new finfo(FILEINFO_MIME_TYPE);
$imported = 0; $skipDup = 0; $skipInvalid = 0; $failed = 0;
$seenHash = [];

foreach ($files as $src) {
    if (!is_file($src)) continue;
    $bn = basename($src);

    // Dedup by original filename (provenance) — robust across reprocessing
    if (isset($importedOrig[$bn])) { echo "SKIP dup (already imported): $bn\n"; $skipDup++; continue; }

    // Validate real MIME type
    $mime = $finfo->file($src);
    if (!isset($allowed[$mime])) { echo "SKIP invalid MIME ($mime): $bn\n"; $skipInvalid++; continue; }

    // Validate image integrity
    if (@getimagesize($src) === false) { echo "SKIP corrupt/not-an-image: $bn\n"; $skipInvalid++; continue; }

    // Secondary dedup by content hash
    $h = sha1_file($src);
    if (isset($existingHash[$h])) { echo "SKIP dup-content (on disk as {$existingHash[$h]}): $bn\n"; $skipDup++; continue; }
    if (isset($seenHash[$h]))     { echo "SKIP dup-content (same as {$seenHash[$h]} this run): $bn\n"; $skipDup++; continue; }

    $ext   = $allowed[$mime];
    $stamp = preg_match('/(\d{8}_\d{6})/', $bn, $mm) ? $mm[1] : date('Ymd_His');
    $name  = $stamp . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $rel   = 'sessions/' . $sessionId . '/' . $name;
    $dest  = $uploadDir . '/' . $name;
    $nextIndex = $poseIndex + 1;

    $poseInfo = ($poseDuration !== null || $poseLabel !== null)
        ? '  [' . trim(($poseLabel ?? '') . ' ' . ($poseDuration !== null ? "($poseDuration)" : '')) . ']'
        : '';

    if ($dryRun) {
        echo "DRY would import: $bn  ->  $rel  (pose_index=$nextIndex)$poseInfo\n";
        $poseIndex = $nextIndex; $seenHash[$h] = $bn; $imported++;
        continue;
    }

    // Copy first; only insert if the file is safely in place (orphan-safe).
    if (!@copy($src, $dest)) { echo "FAIL copy: $bn\n"; $failed++; continue; }

    try {
        $ins = $pdo->prepare(
            "INSERT INTO ld_artworks (session_id, uploaded_by, file_path, visibility, media_type, pose_index, pose_duration, pose_label, created_at)
             VALUES (?, ?, ?, 'public', 'photograph', ?, ?, ?, NOW())"
        );
        $ins->execute([$sessionId, $uploader, $rel, $nextIndex, $poseDuration, $poseLabel]);
        $artworkId = (int) $pdo->lastInsertId();

        // Provenance — mirror GalleryController's 'artwork.upload', plus orig name for rerun-safety
        $prov = $pdo->prepare(
            "INSERT INTO provenance_log (user_id, action, entity_type, entity_id, context, ip_address)
             VALUES (?, 'artwork.upload', 'artwork', ?, ?, 'cli-import')"
        );
        $prov->execute([
            $uploader,
            $artworkId,
            json_encode(['session_id' => $sessionId, 'file' => $rel, 'orig' => $bn, 'source' => 'phone-import']),
        ]);

        $poseIndex = $nextIndex;
        $seenHash[$h] = $bn;
        $existingHash[$h] = $name;
        $importedOrig[$bn] = true;
        $imported++;
        echo "OK  $bn  ->  $rel  (artwork_id=$artworkId, pose_index=$nextIndex)$poseInfo\n";
    } catch (\Throwable $e) {
        @unlink($dest); // roll back the copied file so no orphan remains
        $failed++;
        echo "FAIL insert ($bn): " . $e->getMessage() . "  [removed copied file]\n";
    }
}

echo "\nSession $sessionId: imported=$imported  dup-skipped=$skipDup  invalid-skipped=$skipInvalid  failed=$failed"
   . ($dryRun ? "  (DRY RUN — nothing written)" : "") . "\n";
echo $dryRun ? "" : "Next: run `php tools/process_images.php` to generate WebP derivatives.\n";
