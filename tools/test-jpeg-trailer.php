<?php

declare(strict_types=1);

/**
 * Tests for App\Services\Upload\JpegTrailer.
 *
 * No database, no network, and no fixture files: every JPEG is synthesised in
 * memory with GD. That is deliberate — the case this parser exists for is a photo
 * of a life-drawing room with a second photo of the room hidden inside it, and
 * that is not something to commit to a repository to test against.
 *
 * Optionally re-walks the real staging corpus when it is present, since the strongest
 * regression signal available is that all of the staged photos parse today.
 *
 * Usage:
 *   php tools/test-jpeg-trailer.php
 *   php tools/test-jpeg-trailer.php --corpus=storage/photo-import
 */

define('LDR_ROOT', dirname(__DIR__));

require LDR_ROOT . '/vendor/autoload.php';

use App\Services\Upload\JpegTrailer;

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  {$name}\n";
    } else {
        $failed++;
        echo "  FAIL  {$name}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

/** A small real JPEG, with enough detail that the encoder emits a normal scan. */
function makeJpeg(int $w = 64, int $h = 48, int $quality = 90, bool $progressive = false): string
{
    $im = imagecreatetruecolor($w, $h);
    for ($x = 0; $x < $w; $x++) {
        for ($y = 0; $y < $h; $y++) {
            imagesetpixel($im, $x, $y, imagecolorallocate($im, ($x * 7) % 256, ($y * 11) % 256, ($x * $y) % 256));
        }
    }
    if ($progressive) {
        imageinterlace($im, true);
    }
    ob_start();
    imagejpeg($im, null, $quality);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);

    return $bytes;
}

/**
 * Run a callable with any PHP diagnostic turned into a failure.
 *
 * The bounds bugs this guards against did not crash — they emitted
 * "Uninitialized string offset" and carried on with a wrong answer. A test that
 * only checks the return value would have passed against the broken version.
 */
function withoutWarnings(callable $fn): array
{
    $noticed = [];
    set_error_handler(function (int $no, string $str) use (&$noticed): bool {
        $noticed[] = $str;
        return true;
    });
    try {
        $result = $fn();
    } finally {
        restore_error_handler();
    }

    return [$result, $noticed];
}

echo "JpegTrailer\n";

// --- The happy path -------------------------------------------------------

$plain = makeJpeg();
[$r, $warn] = withoutWarnings(fn() => JpegTrailer::findEndOfImage($plain));
check('clean JPEG: parses', $r['ok'], (string) $r['error']);
check('clean JPEG: EOI is the end of the file', $r['eoi'] === strlen($plain), "eoi={$r['eoi']} len=" . strlen($plain));
check('clean JPEG: no diagnostics', $warn === [], implode('; ', $warn));

$big = makeJpeg(512, 384, 95);
$r = JpegTrailer::findEndOfImage($big);
check('larger JPEG (byte stuffing likely): EOI is the end', $r['ok'] && $r['eoi'] === strlen($big), (string) $r['error']);

$prog = makeJpeg(256, 192, 90, true);
$r = JpegTrailer::findEndOfImage($prog);
check('progressive JPEG (multiple scans): EOI is the end', $r['ok'] && $r['eoi'] === strlen($prog), (string) $r['error']);

// EXIF thumbnail: a whole SOI..EOI JPEG nested inside APP1. Segments are skipped by
// length, so the nested EOI is never even looked at.
$thumb  = makeJpeg(16, 12, 70);
$app1   = "Exif\x00\x00" . $thumb;
$withEx = substr($plain, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($plain, 2);
$r = JpegTrailer::findEndOfImage($withEx);
check('JPEG carrying an EXIF thumbnail: EOI is the outer end', $r['ok'] && $r['eoi'] === strlen($withEx), (string) $r['error']);

// --- The case the parser exists for ---------------------------------------

$carrier = makeJpeg(96, 72, 85);
$hidden  = makeJpeg(80, 60, 85);
$dual    = $carrier . $hidden;

$r = JpegTrailer::findEndOfImage($dual);
check('concatenated JPEGs: returns the FIRST EOI', $r['ok'] && $r['eoi'] === strlen($carrier),
    "eoi={$r['eoi']} expected=" . strlen($carrier));
check('concatenated JPEGs: strrpos would have been wrong',
    strrpos($dual, "\xFF\xD9") !== strlen($carrier) - 2,
    'the fixture does not actually reproduce the DualShot shape');

$strip = JpegTrailer::stripBytes($dual);
check('concatenated JPEGs: stripped bytes equal the first image exactly',
    $strip['ok'] && $strip['bytes'] === $carrier);
check('concatenated JPEGs: reported tail length matches the hidden image',
    $strip['tail_bytes'] === strlen($hidden), "tail={$strip['tail_bytes']} expected=" . strlen($hidden));
check('concatenated JPEGs: classified as a carrier', $strip['class'] === 'carrier', $strip['class']);

// --- Bounds: both reproduced against the unguarded parser -----------------

[$r, $warn] = withoutWarnings(fn() => JpegTrailer::findEndOfImage("\xFF\xD8\xFF\xFF\xFF\xFF"));
check('trailing FF run: fails cleanly', $r['ok'] === false && $r['error'] === 'trailing FF run at EOF', (string) $r['error']);
check('trailing FF run: reads nothing out of bounds', $warn === [], implode('; ', $warn));

[$r, $warn] = withoutWarnings(fn() => JpegTrailer::findEndOfImage("\xFF\xD8\xFF\xC4\x00"));
check('truncated segment length: fails cleanly', $r['ok'] === false && $r['error'] === 'truncated segment length at offset 2', (string) $r['error']);
check('truncated segment length: reads nothing out of bounds', $warn === [], implode('; ', $warn));

// --- Other malformed input ------------------------------------------------

foreach ([
    'empty string'            => '',
    'not a JPEG'              => 'PNG-ish bytes, definitely not a JPEG',
    'SOI then nothing'        => "\xFF\xD8",
    'segment overruns file'   => "\xFF\xD8\xFF\xC4\xFF\xFF",
    'desync after SOI'        => "\xFF\xD8\x41\x42\x43\x44",
    'no EOI at all'           => "\xFF\xD8" . str_repeat("\xFF\xD0", 20),
] as $label => $bytes) {
    [$r, $warn] = withoutWarnings(fn() => JpegTrailer::findEndOfImage($bytes));
    check("malformed ({$label}): fails cleanly with no diagnostics",
        $r['ok'] === false && $warn === [], (string) $r['error'] . ' ' . implode('; ', $warn));
}

// --- stripBytes on already-clean input is a no-op --------------------------

$strip = JpegTrailer::stripBytes($plain);
check('clean JPEG: stripBytes reports no trailer', $strip['ok'] && $strip['tail_bytes'] === 0);
check('clean JPEG: stripBytes returns the bytes unchanged', $strip['bytes'] === $plain);
check('clean JPEG: classified as none', $strip['class'] === 'none', $strip['class']);

$strip = JpegTrailer::stripBytes('not a jpeg at all');
check('unparseable input: stripBytes refuses rather than reshaping', $strip['ok'] === false && $strip['bytes'] === null);

// --- classify ------------------------------------------------------------

check('classify: unparseable trailer fails closed to carrier',
    JpegTrailer::classify(['ok' => false, 'blocks' => [], 'error' => 'x'], 'some bytes') === 'carrier');
check('classify: empty trailer is none',
    JpegTrailer::classify(['ok' => true, 'blocks' => [], 'error' => null], '') === 'none');
check('classify: small named metadata blocks are benign',
    JpegTrailer::classify(['ok' => true, 'blocks' => [['name' => 'Image_UTC_Data', 'size' => 20]], 'error' => null], 'x') === 'benign');
check('classify: a DualShot block is a carrier',
    JpegTrailer::classify(['ok' => true, 'blocks' => [['name' => 'DualShot_DepthMap_1', 'size' => 20]], 'error' => null], 'x') === 'carrier');
check('classify: a MotionPhoto block is a carrier',
    JpegTrailer::classify(['ok' => true, 'blocks' => [['name' => 'MotionPhoto_Data', 'size' => 20]], 'error' => null], 'x') === 'carrier');
check('classify: an oversized block is a carrier whatever it is called',
    JpegTrailer::classify(['ok' => true, 'blocks' => [['name' => 'Harmless_Sounding', 'size' => 500000]], 'error' => null], 'x') === 'carrier');
check('classify: an mp4 signature is a carrier even if the SEF looked fine',
    JpegTrailer::classify(['ok' => true, 'blocks' => [['name' => 'Image_UTC_Data', 'size' => 20]], 'error' => null], "....ftypisom....") === 'carrier');

// --- stripFile round-trip on disk ----------------------------------------

$tmpDir = sys_get_temp_dir() . '/ldr-jpeg-trailer-' . bin2hex(random_bytes(4));
@mkdir($tmpDir, 0777, true);
$tmpFile = $tmpDir . '/probe.jpg';
file_put_contents($tmpFile, $dual);

$before = sha1_file($tmpFile);
$res    = JpegTrailer::stripFile($tmpFile);
check('stripFile: succeeded', $res['ok'], (string) $res['error']);
check('stripFile: reports it stripped', $res['stripped'] === true);
check('stripFile: file on disk is now exactly the first image', sha1_file($tmpFile) === sha1($carrier));
check('stripFile: sha1_before matches what was there', $res['sha1_before'] === $before);
check('stripFile: no temp file left behind', !file_exists($tmpFile . '.strip-tmp'));

$res = JpegTrailer::stripFile($tmpFile);
check('stripFile: second run is a no-op', $res['ok'] && $res['stripped'] === false && $res['tail_bytes'] === 0);

file_put_contents($tmpFile, $dual);
$res = JpegTrailer::stripFile($tmpFile, deep: true);
check('stripFile --deep: decoded pixels verified identical', $res['ok'] && $res['stripped'], (string) $res['error']);

file_put_contents($tmpFile, 'not a jpeg');
$res = JpegTrailer::stripFile($tmpFile);
check('stripFile: refuses an unparseable file', $res['ok'] === false);
check('stripFile: leaves the unparseable file untouched', file_get_contents($tmpFile) === 'not a jpeg');

@unlink($tmpFile);
@rmdir($tmpDir);

// --- Optional: the real staging corpus ------------------------------------

$corpus = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--corpus=')) {
        $corpus = substr($arg, 9);
    }
}

if ($corpus !== null && is_dir($corpus)) {
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($corpus, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && preg_match('/\.jpe?g$/i', $f->getFilename())) {
            $files[] = $f->getPathname();
        }
    }

    $bad = [];
    $tails = [];
    foreach ($files as $f) {
        $info = JpegTrailer::inspectFile($f);
        if (!$info['ok']) {
            $bad[] = basename($f) . ': ' . $info['error'];
            continue;
        }
        $tails[$info['tail_bytes']] = ($tails[$info['tail_bytes']] ?? 0) + 1;
    }

    echo "\nCorpus: " . count($files) . " JPEGs under {$corpus}\n";
    ksort($tails);
    foreach ($tails as $len => $n) {
        echo '  tail ' . str_pad(number_format($len), 12, ' ', STR_PAD_LEFT) . " bytes x {$n}\n";
    }
    check('corpus: every staged JPEG parses', $bad === [], implode(' | ', array_slice($bad, 0, 5)));
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
