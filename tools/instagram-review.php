<?php
/**
 * Render facilitator-facing review sheets for an Instagram curation round.
 *
 * Writes into storage/instagram/{session}/ (never a temp dir — these are for the
 * facilitator to open quickly):
 *
 *   review-all.jpg        every candidate, numbered, colour-framed by status + legend
 *   review-proposed.jpg   just the proposed carousel, in slide order
 *
 * Status comes from storage/instagram/{session}/review.json:
 *
 *   {
 *     "selected":  [8, 4, 1],     // in the proposed carousel      -> GREEN frame
 *     "dignity":   [13, 19],      // cut on the dignity gate       -> RED frame
 *     "alternate": [15, 17],      // close call / ready swap-in    -> BLUE frame
 *     "discuss":   [8, 4, 19],    // I have a question about this  -> AMBER inner ring
 *     "notes":     { "4": "signature in corner" }
 *   }
 *
 * Anything not listed is a plain cut and gets no frame. An image may be both
 * selected and discussed — it gets the green frame plus the amber inner ring.
 *
 * Usage:
 *   php tools/instagram-review.php --session=284
 *   php tools/instagram-review.php --session=284 --src=storage/photo-import/_backup/284
 *
 * Source dir must be FLAT (see INSTAGRAM.md) — image numbers are assigned by
 * sorted filename, matching contact-sheet.jpg from instagram-prep.php --scaffold.
 */

define('LDR_ROOT', dirname(__DIR__));

$opt = ['session' => null, 'src' => null];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--session=')) $opt['session'] = (int) substr($a, 10);
    elseif (str_starts_with($a, '--src=')) $opt['src'] = substr($a, 6);
    elseif ($a === '--help') { fwrite(STDOUT, "Usage: php tools/instagram-review.php --session=ID [--src=DIR]\n"); exit(0); }
}
if (!$opt['session']) { fwrite(STDERR, "Missing --session=ID\n"); exit(1); }

$session = $opt['session'];
$outDir  = LDR_ROOT . '/storage/instagram/' . $session;
$srcDir  = rtrim($opt['src'] ?: (LDR_ROOT . '/storage/photo-import/_backup/' . $session), '/\\');

if (!is_dir($srcDir)) { fwrite(STDERR, "Source dir not found: $srcDir\n"); exit(1); }
if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) { fwrite(STDERR, "Cannot create $outDir\n"); exit(1); }

$files = [];
foreach (glob($srcDir . '/*') ?: [] as $f) {
    if (is_file($f) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true)) $files[] = $f;
}
sort($files);
if (!$files) { fwrite(STDERR, "No images in $srcDir (is it flat, not per-pose subdirs?)\n"); exit(1); }

$reviewPath = $outDir . '/review.json';
$R = is_file($reviewPath) ? json_decode((string) file_get_contents($reviewPath), true) : [];
if (!is_array($R)) $R = [];
$sel  = array_flip($R['selected']  ?? []);
$dig  = array_flip($R['dignity']   ?? []);
$alt  = array_flip($R['alternate'] ?? []);
$disc = array_flip($R['discuss']   ?? []);
$notes = $R['notes'] ?? [];

function ld_load(string $p): \GdImage|false
{
    $i = @imagecreatefromjpeg($p);
    if (!$i) return false;
    $e = @exif_read_data($p);
    $o = $e['Orientation'] ?? 1;
    if ($o == 3)      $i = imagerotate($i, 180, 0);
    elseif ($o == 6)  $i = imagerotate($i, -90, 0);
    elseif ($o == 8)  $i = imagerotate($i, 90, 0);
    return $i;
}

function ld_place(\GdImage $dst, \GdImage $src, int $x, int $y, int $mw, int $mh): array
{
    $sw = imagesx($src); $sh = imagesy($src);
    $sc = min($mw / $sw, $mh / $sh);
    $nw = (int) ($sw * $sc); $nh = (int) ($sh * $sc);
    $px = $x + (int) (($mw - $nw) / 2);
    $py = $y + (int) (($mh - $nh) / 2);
    imagecopyresampled($dst, $src, $px, $py, 0, 0, $nw, $nh, $sw, $sh);
    return [$px, $py, $nw, $nh];
}

// ---------------------------------------------------------------- sheet 1: all
$cols = 5; $cw = 380; $chh = 300; $lbl = 26; $pad = 12; $legend = 92;
$rows = (int) ceil(count($files) / $cols);
$W = $cols * ($cw + $pad) + $pad;
$H = $legend + $rows * ($chh + $lbl + $pad) + $pad;

$img = imagecreatetruecolor($W, $H);
imagefill($img, 0, 0, imagecolorallocate($img, 24, 24, 26));
$white  = imagecolorallocate($img, 238, 238, 238);
$grey   = imagecolorallocate($img, 140, 140, 145);
$green  = imagecolorallocate($img, 74, 190, 130);
$red    = imagecolorallocate($img, 226, 86, 76);
$amber  = imagecolorallocate($img, 245, 182, 76);
$blue   = imagecolorallocate($img, 92, 168, 226);

imagestring($img, 5, $pad, 14, "SESSION $session  -  ALL " . count($files) . " CANDIDATES", $amber);
$lx = $pad; $ly = 44;
$key = [[$green, 'GREEN = in my proposed set'], [$red, 'RED = cut on dignity'],
        [$blue, 'BLUE = close call / alternate'], [$amber, 'AMBER inner ring = I have a question'],
        [$grey, 'no frame = cut (legibility or redundant)']];
foreach ($key as $k) {
    imagesetthickness($img, 5);
    imagerectangle($img, $lx, $ly, $lx + 26, $ly + 18, $k[0]);
    imagestring($img, 3, $lx + 34, $ly + 3, $k[1], $white);
    $lx += 34 + strlen($k[1]) * 7 + 26;
}

$y = $legend; $i = 0;
foreach ($files as $idx0 => $f) {
    $n = $idx0 + 1;
    $src = ld_load($f);
    if (!$src) continue;
    $col = $i % $cols;
    $x = $pad + $col * ($cw + $pad);
    [$px, $py, $nw, $nh] = ld_place($img, $src, $x, $y, $cw, $chh);
    imagedestroy($src);

    $frame = null;
    if (isset($sel[$n]))      $frame = $green;
    elseif (isset($dig[$n]))  $frame = $red;
    elseif (isset($alt[$n]))  $frame = $blue;
    if ($frame !== null) {
        imagesetthickness($img, 7);
        imagerectangle($img, $px - 5, $py - 5, $px + $nw + 4, $py + $nh + 4, $frame);
    }
    if (isset($disc[$n])) {
        imagesetthickness($img, 5);
        imagerectangle($img, $px + 3, $py + 3, $px + $nw - 4, $py + $nh - 4, $amber);
    }

    $tag = sprintf('%02d', $n);
    if (isset($sel[$n]))     $tag .= '  IN';
    elseif (isset($dig[$n])) $tag .= '  DIGNITY';
    elseif (isset($alt[$n])) $tag .= '  ALT';
    if (isset($notes[(string) $n])) $tag .= '  - ' . $notes[(string) $n];
    imagestring($img, 3, $x, $y + $chh + 5, $tag, isset($disc[$n]) ? $amber : $white);

    $i++;
    if ($i % $cols === 0) $y += $chh + $lbl + $pad;
}
if ($i % $cols !== 0) $y += $chh + $lbl + $pad;
imagejpeg($img, $outDir . '/review-all.jpg', 86);
imagedestroy($img);
echo "WROTE $outDir/review-all.jpg\n";

// ----------------------------------------------------------- sheet 2: proposed
$order = $R['selected'] ?? [];
if (!$order) { echo "No 'selected' list in review.json — skipped review-proposed.jpg\n"; exit(0); }

$cols2 = 4; $cw2 = 470; $ch2 = 390; $lbl2 = 30; $rows2 = (int) ceil(count($order) / $cols2);
$W2 = $cols2 * ($cw2 + $pad) + $pad;
$H2 = 46 + $rows2 * ($ch2 + $lbl2 + $pad) + $pad;
$im2 = imagecreatetruecolor($W2, $H2);
imagefill($im2, 0, 0, imagecolorallocate($im2, 24, 24, 26));
$w2 = imagecolorallocate($im2, 238, 238, 238);
$a2 = imagecolorallocate($im2, 245, 182, 76);
$g2 = imagecolorallocate($im2, 74, 190, 130);
imagestring($im2, 5, $pad, 14, "SESSION $session  -  PROPOSED CAROUSEL, IN ORDER  -  slide 1 is the feed thumbnail", $a2);

$y = 46; $i = 0;
foreach ($order as $slot => $n) {
    $f = $files[$n - 1] ?? null;
    if (!$f) continue;
    $src = ld_load($f);
    if (!$src) continue;
    $col = $i % $cols2;
    $x = $pad + $col * ($cw2 + $pad);
    [$px, $py, $nw, $nh] = ld_place($im2, $src, $x, $y, $cw2, $ch2);
    imagedestroy($src);
    imagesetthickness($im2, 6);
    imagerectangle($im2, $px - 4, $py - 4, $px + $nw + 3, $py + $nh + 3, $g2);
    $t = 'SLIDE ' . ($slot + 1) . '   (#' . sprintf('%02d', $n) . ')';
    if (isset($notes[(string) $n])) $t .= '  - ' . $notes[(string) $n];
    imagestring($im2, 4, $x, $y + $ch2 + 6, $t, isset($disc[$n]) ? $a2 : $w2);
    $i++;
    if ($i % $cols2 === 0) $y += $ch2 + $lbl2 + $pad;
}
imagejpeg($im2, $outDir . '/review-proposed.jpg', 86);
echo "WROTE $outDir/review-proposed.jpg\n";
