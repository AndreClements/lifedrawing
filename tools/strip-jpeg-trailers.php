<?php

declare(strict_types=1);

/**
 * Report and remove data appended after a JPEG's end-of-image marker.
 *
 * Run this on a staging directory before the photos go anywhere near production.
 *
 * Phone cameras hide payloads past EOI. Samsung's SEF trailer is usually a few dozen
 * bytes of metadata, but Motion Photo appends a short video WITH AUDIO and Live Focus
 * appends a complete second photograph of the room. All of it passes finfo and
 * getimagesize, so the importer would carry it straight into public/assets/uploads/.
 *
 * Strips EVERY byte past EOI, not just the ones classified as carriers, so that
 * "no bytes past EOI" stays a post-condition that can be checked rather than one
 * contingent on the classifier being right. Classification is reporting only.
 *
 * Truncating at EOI is lossless: dimensions, EXIF and decoded pixels are all verified
 * identical before anything replaces an original (see JpegTrailer::stripFile).
 *
 * Usage:
 *   php tools/strip-jpeg-trailers.php --dir=storage/photo-import/291
 *   php tools/strip-jpeg-trailers.php --dir=storage/photo-import/291 --strip
 *   php tools/strip-jpeg-trailers.php --dir=storage/photo-import/291 --strip --deep
 *   php tools/strip-jpeg-trailers.php --dir=storage/photo-import/291 --strip --quarantine=storage/photo-import/_quarantine/291
 *
 * No image file is modified without --strip. Both modes write a timestamped manifest to
 * storage/photo-import/_trailers/ recording what was found, what was removed, and the
 * before/after hashes; manifests are never overwritten.
 */

define('LDR_ROOT', dirname(__DIR__));

require LDR_ROOT . '/vendor/autoload.php';

use App\Services\Upload\JpegTrailer;

$dir = null; $doStrip = false; $deep = false; $quarantine = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--dir='))             $dir = substr($arg, 6);
    elseif ($arg === '--strip')                      $doStrip = true;
    elseif ($arg === '--report')                     $doStrip = false;
    elseif ($arg === '--deep')                       $deep = true;
    elseif (str_starts_with($arg, '--quarantine='))  $quarantine = substr($arg, 13);
}

if ($dir === null) {
    fwrite(STDERR, "Usage: php tools/strip-jpeg-trailers.php --dir=PATH [--strip] [--deep] [--quarantine=PATH]\n");
    exit(1);
}

if (str_starts_with($dir, '~')) {
    $dir = (getenv('HOME') ?: '') . substr($dir, 1);
}
$dir = rtrim(str_replace('\\', '/', $dir), '/');

if (!is_dir($dir)) {
    fwrite(STDERR, "Directory not found: {$dir}\n");
    exit(1);
}

// This tool rewrites image files in place. Keep it inside storage/ so a mistyped
// path can never reach the public tree or a system directory.
if (!within_storage($dir)) {
    fwrite(STDERR, "Refusing to work outside storage/: {$dir}\n");
    exit(1);
}
if ($quarantine !== null && !within_storage($quarantine)) {
    fwrite(STDERR, "Refusing to quarantine outside storage/: {$quarantine}\n");
    exit(1);
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && preg_match('/\.jpe?g$/i', $f->getFilename())) {
        $files[] = str_replace('\\', '/', $f->getPathname());
    }
}
sort($files);

if ($files === []) {
    echo "No JPEGs under {$dir}\n";
    exit(0);
}

echo ($doStrip ? 'STRIPPING' : 'REPORT (nothing will be written)') . " — {$dir}\n";
echo count($files) . " JPEG(s)\n\n";

$manifest = [];
$clean = 0; $benign = 0; $carrier = 0; $stripped = 0; $failed = 0; $bytesRemoved = 0;

foreach ($files as $path) {
    $rel  = ltrim(substr($path, strlen($dir)), '/');
    $info = JpegTrailer::inspectFile($path);

    if (!$info['ok']) {
        // An unparseable JPEG is a different problem. Never silently reshape it.
        echo "  FAIL   {$rel}  — {$info['error']}\n";
        $failed++;
        continue;
    }

    $names = implode(', ', array_map(
        fn(array $b): string => $b['name'] . ($b['size'] > 0 ? " ({$b['size']}B)" : ''),
        $info['blocks']
    ));

    if ($info['tail_bytes'] === 0) {
        $clean++;
        $manifest[] = ['path' => $rel, 'class' => 'none', 'tail_bytes' => 0, 'stripped' => false];
        continue;
    }

    $label = str_pad($info['class'], 7);
    $size  = str_pad(number_format($info['tail_bytes']), 11, ' ', STR_PAD_LEFT);

    if (!$doStrip) {
        echo "  {$label} {$rel}  tail {$size} B  [{$names}]\n";
        $info['class'] === 'carrier' ? $carrier++ : $benign++;
        $manifest[] = [
            'path' => $rel, 'class' => $info['class'], 'tail_bytes' => $info['tail_bytes'],
            'blocks' => $info['blocks'], 'stripped' => false,
        ];
        continue;
    }

    if ($quarantine !== null) {
        $qPath = $quarantine . '/' . $rel;
        @mkdir(dirname($qPath), 0755, true);
        if (!@copy($path, $qPath)) {
            echo "  FAIL   {$rel}  — could not quarantine before stripping\n";
            $failed++;
            continue;
        }
    }

    $res = JpegTrailer::stripFile($path, $deep);
    if (!$res['ok']) {
        echo "  FAIL   {$rel}  — {$res['error']}  [original untouched]\n";
        $failed++;
        continue;
    }

    $stripped++;
    $bytesRemoved += $res['tail_bytes'];
    $res['class'] === 'carrier' ? $carrier++ : $benign++;
    echo "  STRIP  {$rel}  removed {$size} B  [{$names}]\n";

    $manifest[] = [
        'path'        => $rel,
        'class'       => $res['class'],
        'tail_bytes'  => $res['tail_bytes'],
        'blocks'      => $res['blocks'],
        'stripped'    => true,
        'sha1_before' => $res['sha1_before'],
        'sha1_after'  => $res['sha1_after'],
    ];
}

echo "\n";
echo "clean={$clean}  benign={$benign}  carrier={$carrier}  failed={$failed}\n";
if ($doStrip) {
    echo "stripped={$stripped}  bytes removed=" . number_format($bytesRemoved) . "\n";
} else {
    echo "No image file was modified. Re-run with --strip to remove these.\n";
}

// The manifest lives outside the staging directory on purpose: import-session-photos.php
// globs $dir/* and would report a stray .json as an invalid MIME type.
//
// Timestamped, never overwritten. A second --strip run finds everything already clean,
// so reusing one filename per session would replace the only record of what was removed
// and what the before/after hashes were — exactly the evidence the manifest exists for.
$manifestDir = LDR_ROOT . '/storage/photo-import/_trailers';
@mkdir($manifestDir, 0755, true);
$manifestFile = sprintf(
    '%s/%s-%s-%s.json',
    $manifestDir,
    basename($dir),
    $doStrip ? 'strip' : 'report',
    date('Ymd-His')
);
@file_put_contents($manifestFile, json_encode([
    'dir'       => $dir,
    'at'        => date('c'),
    'mode'      => $doStrip ? 'strip' : 'report',
    'deep'      => $deep,
    'totals'    => compact('clean', 'benign', 'carrier', 'stripped', 'failed', 'bytesRemoved'),
    'files'     => $manifest,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Manifest: " . str_replace(LDR_ROOT . '/', '', $manifestFile) . "\n";

exit($failed > 0 ? 1 : 0);

/** Lexical containment check — works for paths that do not exist yet. */
function within_storage(string $path): bool
{
    $storage = normalize_path(LDR_ROOT . '/storage');
    $norm    = normalize_path($path);

    // Windows is case-insensitive; compare accordingly.
    return stripos($norm . '/', $storage . '/') === 0;
}

function normalize_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    if (!preg_match('#^([a-zA-Z]:/|/)#', $path)) {
        $path = str_replace('\\', '/', LDR_ROOT) . '/' . $path;
    }
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $seg;
    }

    return (str_starts_with($path, '/') ? '/' : '') . implode('/', $parts);
}
