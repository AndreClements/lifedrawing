<?php

declare(strict_types=1);

/**
 * Instagram prep — turn staged session photos into a manual-upload carousel.
 *
 * Parallel consumer of the same local staging pass as import-session-photos.php:
 * it reads the as-shot originals in storage/photo-import/{session}/ and writes
 * Instagram-ready assets to storage/instagram/{session}/out/. It NEVER mutates
 * the staged originals and never touches the website upload/import path.
 *
 * Modes:
 *   --scaffold     contact-sheet.jpg + starter manifest.json + blank curation.md
 *   (default)      render manifest -> out/{NN}.jpg + caption/hashtags/alt-text/preview
 *   --dry-run      print planned render ops, write nothing
 *   --mark-posted="<url>"   record posted hashes in the repost-safety ledger
 *
 * Usage (run locally on the staging machine):
 *   php tools/instagram-prep.php --session=273 --scaffold
 *   php tools/instagram-prep.php --session=273 --dry-run
 *   php tools/instagram-prep.php --session=273
 *   php tools/instagram-prep.php --session=273 --mark-posted="https://www.instagram.com/p/XXXX/"
 *
 * Optional: --src=PATH --out=PATH --manifest=PATH --date=YYYY-MM-DD
 *           --posted-at=ISO8601 --force (overwrite an existing manifest/curation on --scaffold)
 *
 * v1 levels are manual only (brightness/contrast/gamma). auto/white_point are a
 * documented v2 (see INSTAGRAM.md / the plan).
 */

ini_set('memory_limit', '512M'); // 48MP A34 frames need a few hundred MB through GD

define('LDR_ROOT', getcwd());
require LDR_ROOT . '/vendor/autoload.php';

use App\Services\Upload\ImageProcessor;

const MAX_CAROUSEL = 10;   // editorial cap (IG platform allows 20)
const JPEG_QUALITY = 90;
const DEFAULT_RATIO = '4:5';
const DEFAULT_BG = '#f4f2ec';
const DEFAULT_HASHTAGS =
    '#lifedrawing #lifedrawingrandburg #figuredrawing #figurestudy #drawing #randburg #johannesburg #artcommunity';

// ---------------------------------------------------------------- args

$opt = [
    'session' => null, 'src' => null, 'out' => null, 'manifest' => null,
    'date' => null, 'scaffold' => false, 'dry' => false,
    'markPosted' => null, 'postedAt' => null, 'force' => false,
];
foreach ($argv as $a) {
    if (str_starts_with($a, '--session='))          $opt['session'] = (int) substr($a, 10);
    elseif (str_starts_with($a, '--src='))          $opt['src'] = substr($a, 6);
    elseif (str_starts_with($a, '--out='))          $opt['out'] = substr($a, 6);
    elseif (str_starts_with($a, '--manifest='))     $opt['manifest'] = substr($a, 11);
    elseif (str_starts_with($a, '--date='))         $opt['date'] = substr($a, 7);
    elseif ($a === '--scaffold')                    $opt['scaffold'] = true;
    elseif ($a === '--dry-run')                     $opt['dry'] = true;
    elseif (str_starts_with($a, '--mark-posted='))  $opt['markPosted'] = trim(substr($a, 14), '"');
    elseif (str_starts_with($a, '--posted-at='))    $opt['postedAt'] = substr($a, 12);
    elseif ($a === '--force')                       $opt['force'] = true;
}

if (!$opt['session']) {
    fwrite(STDERR, "Usage: php tools/instagram-prep.php --session=ID [--scaffold|--dry-run|--mark-posted=URL]\n");
    exit(1);
}

$gd = (new ImageProcessor())->checkCapabilities();
if (!$gd['gd']) {
    fwrite(STDERR, "GD extension not available — cannot process images.\n");
    exit(1);
}

$session  = $opt['session'];
$srcDir   = rtrim($opt['src'] ?: (LDR_ROOT . '/storage/photo-import/' . $session), '/\\');
$outBase  = rtrim($opt['out'] ?: (LDR_ROOT . '/storage/instagram/' . $session), '/\\');
$manifest = $opt['manifest'] ?: ($outBase . '/manifest.json');
$ledger   = LDR_ROOT . '/storage/instagram/posted-ledger.json';

// Every path we touch must live inside the project storage/ tree.
foreach (['src' => $srcDir, 'out' => $outBase, 'manifest' => $manifest] as $label => $p) {
    if (!within_storage($p)) {
        fwrite(STDERR, "Refusing $label outside storage/: $p\n");
        exit(1);
    }
}

// ---------------------------------------------------------------- dispatch

try {
    if ($opt['markPosted'] !== null) {
        mark_posted($manifest, $srcDir, $ledger, $session, $opt['markPosted'], $opt['postedAt']);
    } elseif ($opt['scaffold']) {
        scaffold($srcDir, $outBase, $manifest, $session, $opt['date'], $opt['force']);
    } else {
        render($manifest, $srcDir, $outBase, $ledger, $opt['dry']);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

// ================================================================ modes

function scaffold(string $srcDir, string $outBase, string $manifest, int $session, ?string $date, bool $force): void
{
    $files = source_files($srcDir);
    if (!$files) {
        throw new \RuntimeException("No images found in $srcDir");
    }

    if (!is_dir($outBase) && !mkdir($outBase, 0775, true) && !is_dir($outBase)) {
        throw new \RuntimeException("Could not create $outBase");
    }

    $sessionDate = $date ?: infer_date($files[0]) ?? '';

    // --- starter manifest: every source listed, empty treatment, for pruning ---
    if (file_exists($manifest) && !$force) {
        echo "KEEP existing manifest (use --force to overwrite): $manifest\n";
    } else {
        $carousel = [];
        foreach ($files as $f) {
            $carousel[] = ['src' => basename($f), '_time' => file_time($f)];
        }
        $data = [
            'session_id' => $session,
            'session_date' => $sessionDate,
            'ratio' => DEFAULT_RATIO,
            'background' => DEFAULT_BG,
            'caption' => '',
            'hashtags' => DEFAULT_HASHTAGS,
            'carousel' => $carousel,
        ];
        file_put_contents($manifest, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        echo "WROTE manifest: $manifest (" . count($files) . " candidates — prune to <=" . MAX_CAROUSEL . ")\n";
    }

    // --- contact sheet ---
    $sheet = $outBase . '/contact-sheet.jpg';
    build_contact_sheet($files, $sheet);
    echo "WROTE contact sheet: $sheet\n";

    // --- curation worksheet ---
    $curation = $outBase . '/curation.md';
    if (file_exists($curation) && !$force) {
        echo "KEEP existing curation.md (use --force to overwrite): $curation\n";
    } else {
        file_put_contents($curation, curation_template($files, $session, $sessionDate));
        echo "WROTE curation worksheet: $curation\n";
    }
}

function render(string $manifest, string $srcDir, string $outBase, string $ledger, bool $dry): void
{
    if (!is_file($manifest)) {
        throw new \RuntimeException("Manifest not found: $manifest (run --scaffold first)");
    }
    $m = json_decode((string) file_get_contents($manifest), true);
    if (!is_array($m) || !isset($m['carousel']) || !is_array($m['carousel'])) {
        throw new \RuntimeException("Manifest has no 'carousel' array");
    }

    $carousel = $m['carousel'];
    $n = count($carousel);
    if ($n < 1 || $n > MAX_CAROUSEL) {
        throw new \RuntimeException("Carousel has $n entries — must be 1..." . MAX_CAROUSEL);
    }

    $topRatio = $m['ratio'] ?? DEFAULT_RATIO;
    $bg = parse_hex($m['background'] ?? DEFAULT_BG);
    $posted = load_ledger($ledger);

    $outDir = $outBase . '/out';
    if (!$dry) {
        clear_out($outDir);
        if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            throw new \RuntimeException("Could not create $outDir");
        }
    }

    $rendered = [];
    $altLines = [];
    $i = 0;
    foreach ($carousel as $entry) {
        $i++;
        $src = (string) ($entry['src'] ?? '');
        if ($src === '' || basename($src) !== $src) {
            throw new \RuntimeException("Entry $i: 'src' must be a bare filename, got: " . json_encode($src));
        }
        $srcPath = $srcDir . '/' . $src;
        if (!is_file($srcPath)) {
            throw new \RuntimeException("Entry $i: source not found: $srcPath");
        }

        $ratio = $entry['ratio'] ?? $topRatio;
        [$tw, $th] = ratio_dims($ratio);
        $nn = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $dest = $outDir . '/' . $nn . '.jpg';

        $hash = sha1_file($srcPath);
        $warn = isset($posted[$hash]) ? "  ⚠ ALREADY POSTED (" . ($posted[$hash]['ig_url'] ?? '?') . ")" : '';

        $ops = describe_ops($entry, $tw, $th);
        echo ($dry ? "DRY " : "    ") . "$nn  $src  ->  {$tw}x{$th}  [$ops]$warn\n";

        $altLines[] = "$nn.jpg\t" . trim((string) ($entry['alt'] ?? ''));

        if ($dry) {
            $rendered[] = ['nn' => $nn, 'src' => $src, 'hash' => $hash];
            continue;
        }

        $img = pipeline($srcPath, $entry, $tw, $th, $bg);
        imagejpeg($img, $dest, JPEG_QUALITY);
        imagedestroy($img);
        $rendered[] = ['nn' => $nn, 'src' => $src, 'hash' => $hash];
    }

    if ($dry) {
        echo "\nDRY RUN — nothing written. $n slide(s) planned.\n";
        return;
    }

    // sidecars
    file_put_contents($outDir . '/caption.txt', rtrim((string) ($m['caption'] ?? '')) . "\n");
    file_put_contents($outDir . '/hashtags.txt', rtrim((string) ($m['hashtags'] ?? DEFAULT_HASHTAGS)) . "\n");
    file_put_contents($outDir . '/alt-text.txt', implode("\n", $altLines) . "\n");
    build_preview($outDir, $rendered, $bg);

    echo "\nWROTE " . count($rendered) . " slide(s) + caption.txt, hashtags.txt, alt-text.txt, preview.jpg to:\n  $outDir\n";
    $hits = array_filter($rendered, fn($r) => isset($posted[$r['hash']]));
    if ($hits) {
        echo "NOTE: " . count($hits) . " selected image(s) are already in the ledger (possible repost).\n";
    }
    echo "Next: review preview.jpg, then post manually and optionally run --mark-posted=URL.\n";
}

function mark_posted(string $manifest, string $srcDir, string $ledger, int $session, string $url, ?string $postedAt): void
{
    if (!is_file($manifest)) {
        throw new \RuntimeException("Manifest not found: $manifest");
    }
    $m = json_decode((string) file_get_contents($manifest), true);
    $carousel = $m['carousel'] ?? [];
    if (!$carousel) {
        throw new \RuntimeException("Manifest carousel is empty");
    }
    $stamp = $postedAt ?: date('c');
    $book = load_ledger($ledger);
    $i = 0; $count = 0;
    foreach ($carousel as $entry) {
        $i++;
        $src = (string) ($entry['src'] ?? '');
        $srcPath = $srcDir . '/' . $src;
        if (basename($src) !== $src || !is_file($srcPath)) {
            echo "SKIP (missing source): $src\n";
            continue;
        }
        $hash = sha1_file($srcPath);
        $nn = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $book[$hash] = [
            'session_id' => $session,
            'out_file' => $nn . '.jpg',
            'src' => $src,
            'ig_url' => $url,
            'posted_at' => $stamp,
        ];
        $count++;
    }
    if (!is_dir(dirname($ledger))) {
        mkdir(dirname($ledger), 0775, true);
    }
    file_put_contents($ledger, json_encode($book, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "Ledger updated: $count image(s) marked posted at $stamp -> $url\n";
}

// ================================================================ pipeline

/** Build the final GD image for one carousel entry (non-destructive; source untouched). */
function pipeline(string $srcPath, array $entry, int $tw, int $th, array $bg): \GdImage
{
    $img = gd_load($srcPath);
    if ($img === false) {
        throw new \RuntimeException("Could not load image: $srcPath");
    }

    $img = exif_orient($img, $srcPath);          // 1. EXIF rotation (in memory)

    if (isset($entry['crop'])) {                 // 2. crop (coords in orientation-corrected image)
        $img = apply_crop($img, $entry['crop']);
    }
    if (!empty($entry['rotate'])) {              // 2b. small tilt straighten (cannot fix keystone)
        $img = apply_rotate($img, (float) $entry['rotate'], $bg);
    }
    if (isset($entry['levels'])) {               // 3. manual levels
        apply_levels($img, $entry['levels']);
    }

    $fitted = resize_fit($img, $tw, $th);        // 4. resize-to-fit
    if ($fitted !== $img) {
        imagedestroy($img);
    }
    $canvas = pad_to($fitted, $tw, $th, $bg);    // 5. pad to ratio
    if ($canvas !== $fitted) {
        imagedestroy($fitted);
    }
    return $canvas;                              // 6. caller encodes JPEG
}

function gd_load(string $path): \GdImage|false
{
    $info = @getimagesize($path);
    if ($info === false) {
        return false;
    }
    return match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        default        => false,
    };
}

/** Apply EXIF orientation 3/6/8 in memory (mirrored 2/4/5/7 left as-is — fine for the A34). */
function exif_orient(\GdImage $img, string $path): \GdImage
{
    if (!function_exists('exif_read_data')) {
        return $img;
    }
    $info = @getimagesize($path);
    if (!$info || $info[2] !== IMAGETYPE_JPEG) {
        return $img;
    }
    $exif = @exif_read_data($path);
    $o = (int) ($exif['Orientation'] ?? 1);
    $angle = match ($o) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
    if ($angle === 0) {
        return $img;
    }
    $rot = imagerotate($img, $angle, 0);
    if ($rot === false) {
        return $img;
    }
    imagedestroy($img);
    return $rot;
}

function apply_crop(\GdImage $img, array $crop): \GdImage
{
    $w = imagesx($img);
    $h = imagesy($img);
    $x = max(0, (int) ($crop['x'] ?? 0));
    $y = max(0, (int) ($crop['y'] ?? 0));
    $cw = (int) ($crop['w'] ?? ($w - $x));
    $ch = (int) ($crop['h'] ?? ($h - $y));
    $cw = max(1, min($cw, $w - $x));
    $ch = max(1, min($ch, $h - $y));
    $out = imagecrop($img, ['x' => $x, 'y' => $y, 'width' => $cw, 'height' => $ch]);
    if ($out === false) {
        return $img;
    }
    imagedestroy($img);
    return $out;
}

function apply_rotate(\GdImage $img, float $deg, array $bg): \GdImage
{
    if (abs($deg) < 0.01) {
        return $img;
    }
    $color = imagecolorallocate($img, $bg[0], $bg[1], $bg[2]);
    $out = imagerotate($img, $deg, $color);
    if ($out === false) {
        return $img;
    }
    imagedestroy($img);
    return $out;
}

/** Manual levels. brightness -255..255, contrast -100..100 (GD sign inverted), gamma >0. */
function apply_levels(\GdImage $img, array $levels): void
{
    if (isset($levels['brightness'])) {
        imagefilter($img, IMG_FILTER_BRIGHTNESS, (int) $levels['brightness']);
    }
    if (isset($levels['contrast'])) {
        // GD: negative = more contrast. Flip the sign so the manifest reads naturally.
        imagefilter($img, IMG_FILTER_CONTRAST, -(int) $levels['contrast']);
    }
    if (isset($levels['gamma']) && (float) $levels['gamma'] > 0) {
        imagegammacorrect($img, 1.0, (float) $levels['gamma']);
    }
}

/** Resize so the image fits within tw x th, preserving aspect. Never upscales past target. */
function resize_fit(\GdImage $img, int $tw, int $th): \GdImage
{
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min($tw / $w, $th / $h);
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    if ($nw === $w && $nh === $h) {
        return $img;
    }
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

/** Centre the (already fitted) image on a tw x th canvas filled with bg. */
function pad_to(\GdImage $img, int $tw, int $th, array $bg): \GdImage
{
    $w = imagesx($img);
    $h = imagesy($img);
    $canvas = imagecreatetruecolor($tw, $th);
    $fill = imagecolorallocate($canvas, $bg[0], $bg[1], $bg[2]);
    imagefilledrectangle($canvas, 0, 0, $tw, $th, $fill);
    imagecopy($canvas, $img, (int) (($tw - $w) / 2), (int) (($th - $h) / 2), 0, 0, $w, $h);
    return $canvas;
}

// ================================================================ contact sheet / preview

function build_contact_sheet(array $files, string $dest): void
{
    $cols = 4;
    $cell = 360;          // image area (square)
    $label = 34;          // label strip under each
    $pad = 10;
    $cw = $cell + $pad * 2;
    $chh = $cell + $label + $pad * 2;
    $rows = (int) ceil(count($files) / $cols);
    $W = $cols * $cw;
    $H = $rows * $chh;

    $sheet = imagecreatetruecolor($W, $H);
    imagefilledrectangle($sheet, 0, 0, $W, $H, imagecolorallocate($sheet, 30, 30, 30));
    $white = imagecolorallocate($sheet, 235, 235, 235);

    $i = 0;
    foreach ($files as $f) {
        $col = $i % $cols;
        $row = intdiv($i, $cols);
        $ox = $col * $cw + $pad;
        $oy = $row * $chh + $pad;

        $img = gd_load($f);
        if ($img !== false) {
            $img = exif_orient($img, $f);
            $thumb = resize_fit($img, $cell, $cell);
            $tw = imagesx($thumb);
            $thh = imagesy($thumb);
            imagecopy($sheet, $thumb, $ox + (int) (($cell - $tw) / 2), $oy + (int) (($cell - $thh) / 2), 0, 0, $tw, $thh);
            if ($thumb !== $img) {
                imagedestroy($thumb);
            }
            imagedestroy($img);
        }
        $nn = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
        $caption = $nn . '  ' . basename($f) . '  ' . file_time($f);
        imagestring($sheet, 4, $ox, $oy + $cell + 8, $caption, $white);
        $i++;
    }
    imagejpeg($sheet, $dest, 88);
    imagedestroy($sheet);
}

function build_preview(string $outDir, array $rendered, array $bg): void
{
    if (!$rendered) {
        return;
    }
    $tileH = 320;
    $tiles = [];
    $totalW = 0;
    foreach ($rendered as $r) {
        $img = gd_load($outDir . '/' . $r['nn'] . '.jpg');
        if ($img === false) {
            continue;
        }
        $t = resize_fit($img, 100000, $tileH); // fit by height only
        imagedestroy($img);
        $tiles[] = $t;
        $totalW += imagesx($t) + 8;
    }
    if (!$tiles) {
        return;
    }
    $strip = imagecreatetruecolor($totalW + 8, $tileH + 16);
    imagefilledrectangle($strip, 0, 0, imagesx($strip), imagesy($strip), imagecolorallocate($strip, $bg[0], $bg[1], $bg[2]));
    $x = 8;
    foreach ($tiles as $t) {
        imagecopy($strip, $t, $x, 8, 0, 0, imagesx($t), imagesy($t));
        $x += imagesx($t) + 8;
        imagedestroy($t);
    }
    imagejpeg($strip, $outDir . '/preview.jpg', 88);
    imagedestroy($strip);
}

// ================================================================ helpers

function source_files(string $dir): array
{
    if (!is_dir($dir)) {
        throw new \RuntimeException("Source dir not found: $dir");
    }
    $files = [];
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (!is_file($f)) {
            continue;
        }
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $files[] = $f;
        }
    }
    sort($files); // chronological (filenames are timestamps)
    return $files;
}

function ratio_dims(string $ratio): array
{
    return match ($ratio) {
        '1:1' => [1080, 1080],
        '4:5' => [1080, 1350],
        default => [1080, 1350],
    };
}

function parse_hex(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        return [244, 242, 236];
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function describe_ops(array $entry, int $tw, int $th): string
{
    $parts = [];
    if (isset($entry['crop'])) {
        $c = $entry['crop'];
        $parts[] = "crop {$c['w']}x{$c['h']}@{$c['x']},{$c['y']}";
    }
    if (!empty($entry['rotate'])) {
        $parts[] = "rotate {$entry['rotate']}deg";
    }
    if (isset($entry['levels'])) {
        $parts[] = 'levels ' . implode(',', array_map(
            fn($k, $v) => "$k=$v",
            array_keys($entry['levels']),
            array_values($entry['levels'])
        ));
    }
    $parts[] = 'pad';
    return implode(' + ', $parts);
}

function file_time(string $f): string
{
    if (preg_match('/(\d{8})_(\d{2})(\d{2})(\d{2})/', basename($f), $m)) {
        return "{$m[2]}:{$m[3]}:{$m[4]}";
    }
    return '';
}

function infer_date(string $f): ?string
{
    if (preg_match('/(\d{4})(\d{2})(\d{2})_/', basename($f), $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
    return null;
}

function within_storage(string $path): bool
{
    // Lexical normalization — works for paths that don't exist yet (out dirs).
    $storage = normalize_path(LDR_ROOT . '/storage');
    $norm = normalize_path($path);
    // Windows is case-insensitive; compare accordingly.
    return stripos($norm . '/', $storage . '/') === 0;
}

function normalize_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $isUnixAbs = isset($path[0]) && $path[0] === '/';
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
    return ($isUnixAbs ? '/' : '') . implode('/', $parts);
}

function clear_out(string $outDir): void
{
    if (!is_dir($outDir)) {
        return;
    }
    foreach (glob($outDir . '/*') ?: [] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

function load_ledger(string $ledger): array
{
    if (!is_file($ledger)) {
        return [];
    }
    $d = json_decode((string) file_get_contents($ledger), true);
    return is_array($d) ? $d : [];
}

function curation_template(array $files, int $session, string $date): string
{
    $rows = '';
    $i = 0;
    foreach ($files as $f) {
        $nn = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
        $rows .= "| $nn | " . basename($f) . " | " . file_time($f) . " |  |  |  |  |  |  |\n";
        $i++;
    }

    return <<<MD
# Curation worksheet — session $session ($date)

Collaborative curation: Claude offers an independent first reading; the facilitator
responds with lived knowledge of the room, the participants, consent, and their own
artistic judgment. We revise together. Neither view is neutral or final by default.
See `INSTAGRAM.md` for the full rubric. (Every selection responds; roles circulate.)

**Gates (any "no" cuts the image):** legible at thumbnail · clean enough (glare/tilt
fixable, keystone not) · holds the model with dignity · not a near-duplicate.

**Axes to span (coverage, not ranking):** pose arc (warm-up→sustained) · medium ·
approach (gestural/structural/tonal) · authorship (different hands).

**Tie-break (1–3, only if >10 pass):** presence · carries-small · adds-variety · cleanliness.

Reference the contact sheet (`contact-sheet.jpg`) by the numbers below.

| # | file | time | gate? | axis it covers | presence | carries | variety | in/out + why |
|---|------|------|-------|----------------|----------|---------|---------|--------------|
$rows

## Proposed set & order

_(carousel order — image 1 is the feed thumbnail / hook, then walk the arc)_

1.
2.
3.

## Caption (draft)

_(axiom → the room/session → site link on the last line; no names, never the model's body)_

MD;
}
