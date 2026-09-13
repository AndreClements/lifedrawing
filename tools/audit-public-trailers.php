<?php

declare(strict_types=1);

/**
 * Read-only audit: is anything in the public tree carrying data past its EOI marker?
 *
 * Walks public/assets/uploads/sessions/, marker-walks every JPEG, and joins each file
 * back to its ld_artworks row so a finding names an artwork rather than a path.
 *
 * Two distinct uses, and they should not be confused:
 *
 *   --session=291   A GATE. Those files went through the hardened importer, so zero
 *                   tails is a post-condition we control and can require.
 *
 *   (no --session)  A FINDING. Everything uploaded before JpegTrailer existed went
 *                   through the old path; whatever turns up is pre-existing state this
 *                   tool can observe but cannot promise anything about. Report it and
 *                   let André decide -- do not treat it as a release blocker.
 *
 * Only JPEGs are walked. PNG and WebP can carry appended data too, but the marker walk
 * is JPEG-specific and every phone import is JPEG; that gap is noted, not closed.
 *
 * Writes nothing. Exits 1 if any tail is found, so it can be used as a gate.
 *
 * Usage (run ON production, from the app root):
 *   php tools/audit-public-trailers.php --session=291
 *   php tools/audit-public-trailers.php
 *   php tools/audit-public-trailers.php --verbose
 */

define('LDR_ROOT', getcwd());

$envFile = LDR_ROOT . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = $_SERVER[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}

require LDR_ROOT . '/vendor/autoload.php';

use App\Services\Upload\JpegTrailer;

$sessionId = null; $verbose = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--session=')) $sessionId = (int) substr($arg, 10);
    elseif ($arg === '--verbose')            $verbose = true;
}

$root = LDR_ROOT . '/public/assets/uploads/sessions';
if (!is_dir($root)) {
    fwrite(STDERR, "No upload directory at {$root}\n");
    exit(1);
}

// --- Map relative paths to artwork rows so a finding names the artwork ---
$cfg = config('database');
$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4",
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$sql = "SELECT id, session_id, file_path, visibility FROM ld_artworks WHERE file_path IS NOT NULL";
if ($sessionId !== null) {
    $sql .= " AND session_id = " . $sessionId;
}
$byPath = [];
foreach ($pdo->query($sql) as $r) {
    $byPath[str_replace('\\', '/', (string) $r['file_path'])] = $r;
}

$scanDir = $sessionId !== null ? $root . '/' . $sessionId : $root;

// A gate has to assert something positive. "Nothing to check" is not a pass: it is the
// signature of an import that silently did nothing (wrong session id, empty --dir), and
// the runbook chains `&& rm -rf ~/photo-import/{id}` off this exit code — so a false
// pass deletes the staged copy while nothing has been published.
if ($sessionId !== null) {
    if ($byPath === []) {
        echo "FAIL: session {$sessionId} has no ld_artworks rows with a file_path — nothing was imported.\n";
        exit(1);
    }
    if (!is_dir($scanDir)) {
        echo 'FAIL: session ' . $sessionId . ' has ' . count($byPath)
           . " artwork row(s), but {$scanDir} does not exist.\n";
        exit(1);
    }
}

if (!is_dir($scanDir)) {
    echo "Nothing uploaded yet ({$scanDir} does not exist).\n";
    exit(0);
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && preg_match('/\.jpe?g$/i', $f->getFilename())) {
        $files[] = str_replace('\\', '/', $f->getPathname());
    }
}
sort($files);

$scope = $sessionId !== null ? "session {$sessionId}" : 'all sessions';
echo "Auditing {$scope}: " . count($files) . " JPEG(s) under " . str_replace(LDR_ROOT . '/', '', $scanDir) . "\n\n";

$clean = 0; $withTail = 0; $unparseable = 0; $orphans = 0; $bytes = 0;
$bySession = []; $seenPaths = [];

foreach ($files as $path) {
    $rel  = ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');
    $row  = $byPath['sessions/' . $rel] ?? null;
    $seenPaths['sessions/' . $rel] = true;
    $info = JpegTrailer::inspectFile($path);

    $who = $row !== null
        ? "artwork {$row['id']} (session {$row['session_id']}, {$row['visibility']})"
        : 'NO ARTWORK ROW';
    if ($row === null) {
        $orphans++;
    }

    if (!$info['ok']) {
        echo "  UNPARSEABLE  {$rel}  — {$info['error']}  [{$who}]\n";
        $unparseable++;
        continue;
    }

    if ($info['tail_bytes'] === 0) {
        $clean++;
        if ($verbose) {
            echo "  clean        {$rel}  [{$who}]\n";
        }
        continue;
    }

    $withTail++;
    $bytes += $info['tail_bytes'];
    $sid = $row['session_id'] ?? 0;
    $bySession[$sid] = ($bySession[$sid] ?? 0) + 1;

    $names = implode(', ', array_map(
        fn(array $b): string => $b['name'] . ($b['size'] > 0 ? " ({$b['size']}B)" : ''),
        $info['blocks']
    ));
    printf("  TAIL %-9s %s  %s B  [%s]  [%s]\n",
        $info['class'], $rel, number_format($info['tail_bytes']), $names, $who);
}

// The inverse of an orphan: a row pointing at a file that is not there. Only JPEG rows
// are walked, so ignore any row whose original is a PNG or WebP.
$missingFiles = [];
foreach ($byPath as $rel => $row) {
    if (preg_match('/\.jpe?g$/i', $rel) !== 1) {
        continue;
    }
    if (!isset($seenPaths[$rel])) {
        $missingFiles[] = "artwork {$row['id']} -> {$rel}";
    }
}

echo "\nclean={$clean}  with-tail={$withTail}  unparseable={$unparseable}";
if ($orphans > 0) {
    echo "  files-with-no-row={$orphans}";
}
if ($missingFiles !== []) {
    echo '  rows-with-no-file=' . count($missingFiles);
}
echo "\n";

foreach ($missingFiles as $m) {
    echo "  MISSING FILE  {$m}\n";
}

if ($withTail > 0) {
    echo 'bytes past EOI in the public tree: ' . number_format($bytes) . "\n";
    ksort($bySession);
    foreach ($bySession as $sid => $n) {
        echo '  session ' . ($sid ?: '(unknown)') . ": {$n} file(s)\n";
    }
}

if ($sessionId !== null) {
    $problems = [];
    if (count($files) === 0)  $problems[] = 'no JPEGs on disk to check';
    if ($withTail > 0)        $problems[] = "{$withTail} file(s) with data past EOI";
    if ($unparseable > 0)     $problems[] = "{$unparseable} unparseable file(s)";
    if ($missingFiles !== []) $problems[] = count($missingFiles) . ' row(s) whose file is missing';

    echo $problems === []
        ? "\nPASS: " . count($files) . " file(s) checked for session {$sessionId}, none carrying data past EOI.\n"
        : "\nFAIL: session {$sessionId} — " . implode('; ', $problems)
          . ". These went through the trailer-stripping importer, so investigate before publishing.\n";

    exit($problems === [] ? 0 : 1);
}

if ($withTail > 0) {
    echo "\nThese predate the trailer-stripping importer. Reported as a finding, not a gate.\n";
    echo "To fix, strip them in place and rebuild the derivatives for those rows specifically.\n";
    echo "NOT process_images.php --reprocess: it resets every eligible row rather than the ones\n";
    echo "listed, and it only rewrites an original when EXIF rotation or the 10MP cap fires — so a\n";
    echo "file needing neither keeps its trailer and the run reports success.\n";
}

exit($withTail > 0 || $unparseable > 0 || $missingFiles !== [] ? 1 : 0);
