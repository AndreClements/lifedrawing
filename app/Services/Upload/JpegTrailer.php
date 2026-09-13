<?php

declare(strict_types=1);

namespace App\Services\Upload;

/**
 * Find and remove anything appended after a JPEG's end-of-image marker.
 *
 * Phone cameras hide payloads there. Samsung writes an SEF trailer: usually a few
 * dozen bytes of harmless metadata (Image_UTC_Data), but Motion Photo appends a
 * short video WITH AUDIO, and Live Focus / DualShot appends a complete second
 * photograph of the room. All of it survives finfo (image/jpeg) and getimagesize(),
 * so nothing upstream of here notices.
 *
 * That matters because originals land in public/assets/uploads/, which Apache
 * serves statically, and the gallery templates fall back to file_path whenever the
 * WebP derivatives haven't been generated yet. A model consents to photographs of
 * the drawings, not to a video of the room riding along inside one.
 *
 * Truncating at EOI is lossless: no decoder reads past it, EXIF lives in APP1
 * before the scan, and the retained bytes are a byte-identical prefix.
 *
 * These methods do NOT throw — callers report per file and carry on, matching
 * ImageProcessor's contract.
 */
final class JpegTrailer
{
    /** A metadata block bigger than this is not metadata. */
    private const MAX_BENIGN_BLOCK = 4096;

    /** SEF block names that are known to carry imagery, video or audio. */
    private const CARRIER_NAMES = '/MotionPhoto|DualShot|SingleTake|Burst|Video|Audio|Sound|Depth|Bokeh/i';

    /** Byte signatures that mean a media payload regardless of how the SEF parses. */
    private const CARRIER_SIGNATURES = ['ftyp', 'moov', 'mdat', "\xFF\xD8\xFF"];

    /** Refuse to walk an implausible SEF directory rather than loop on garbage. */
    private const MAX_SEF_ENTRIES = 1000;

    /**
     * Locate the end of the JPEG image stream by walking markers from SOI.
     *
     * Deliberately NOT a search for the last FFD9. On a DualShot file the appended
     * second JPEG has its own EOI near the end, so strrpos() returns that one and
     * retains megabytes of payload. Measured on 269/20260606_161459.jpg: the marker
     * walk gives 2,249,284; strrpos gives 6,921,705.
     *
     * @return array{ok: bool, eoi: int, error: string|null} eoi is INCLUSIVE of FF D9
     */
    public static function findEndOfImage(string $d): array
    {
        $n = strlen($d);
        if ($n < 4 || $d[0] !== "\xFF" || $d[1] !== "\xD8") {
            return self::fail('no SOI (not a JPEG)');
        }

        $p = 2;
        while ($p < $n - 1) {
            if ($d[$p] !== "\xFF") {
                return self::fail("desync at offset {$p}");
            }

            // A run of FF is legal padding; the real marker is the byte after the last one.
            while ($p + 1 < $n && $d[$p + 1] === "\xFF") {
                $p++;
            }
            if ($p + 1 >= $n) {
                return self::fail('trailing FF run at EOF');
            }

            $m = ord($d[$p + 1]);

            if ($m === 0xD9) {
                return ['ok' => true, 'eoi' => $p + 2, 'error' => null];
            }
            if ($m === 0x00) {
                return self::fail("FF00 at marker position {$p}");
            }
            if ($m === 0x01 || ($m >= 0xD0 && $m <= 0xD7)) {
                $p += 2; // TEM / RSTn are standalone — no length field
                continue;
            }

            if ($p + 4 > $n) {
                return self::fail("truncated segment length at offset {$p}");
            }
            $len = (ord($d[$p + 2]) << 8) | ord($d[$p + 3]);
            if ($len < 2 || $p + 2 + $len > $n) {
                return self::fail(
                    'bad or overrunning segment (marker FF' . strtoupper(dechex($m)) . ", len {$len}) at offset {$p}"
                );
            }

            if ($m === 0xDA) {
                // Entropy-coded data follows the scan header. Skip to the next real marker.
                $q = $p + 2 + $len;
                while ($q < $n - 1) {
                    if ($d[$q] !== "\xFF")        { $q += 1; continue; }
                    $b = ord($d[$q + 1]);
                    if ($b === 0x00)              { $q += 2; continue; } // stuffed FF, consumes both
                    if ($b === 0xFF)              { $q += 1; continue; } // fill byte
                    if ($b >= 0xD0 && $b <= 0xD7) { $q += 2; continue; } // restart marker mid-scan
                    break;                                               // a real marker
                }
                if ($q >= $n - 1) {
                    return self::fail('scan ran off end without a following marker');
                }
                // Hand back to the main loop rather than returning: progressive JPEGs
                // have several scans, and DNL/DHT segments may sit between them.
                $p = $q;
                continue;
            }

            // Every other segment is skipped by its own length, which is why a JPEG
            // thumbnail nested inside APP1 can never be mistaken for the real EOI.
            $p += 2 + $len;
        }

        return self::fail('ran off end without EOI');
    }

    /**
     * Decode a Samsung SEF trailer so a report can name what it found.
     *
     * Layout, verified against real A34 files:
     *   last 4 bytes          'SEFT'
     *   LE u32 at n-8         footer length L; the directory starts at (n-8) - L
     *   at that offset        'SEFH' + LE u32 version + LE u32 count
     *                         then count x 12-byte entries
     *   entry                 4-byte type id + LE u32 offsetBack + LE u32 size
     *                         (block begins at sefhPos - offsetBack)
     *   block                 4-byte type id + LE u32 nameLen + name + payload
     *
     * @return array{ok: bool, blocks: list<array{name: string, size: int}>, error: string|null}
     */
    public static function parseSefDirectory(string $tail): array
    {
        $n = strlen($tail);
        if ($n < 12 || substr($tail, -4) !== 'SEFT') {
            return self::sefFail('no SEFT footer');
        }

        $footerLen = self::u32($tail, $n - 8);
        if ($footerLen === null) {
            return self::sefFail('short footer');
        }

        $sefh = $n - 8 - $footerLen;
        if ($sefh < 0 || $sefh + 12 > $n || substr($tail, $sefh, 4) !== 'SEFH') {
            return self::sefFail('SEFH not present at the offset the footer points to');
        }

        $count = self::u32($tail, $sefh + 8);
        if ($count === null || $count > self::MAX_SEF_ENTRIES) {
            return self::sefFail('implausible SEF entry count');
        }

        $blocks = [];
        for ($i = 0; $i < $count; $i++) {
            $e = $sefh + 12 + ($i * 12);
            if ($e + 12 > $n) {
                return self::sefFail("entry {$i} runs past the trailer");
            }

            $offsetBack = self::u32($tail, $e + 4);
            $size       = self::u32($tail, $e + 8);
            if ($offsetBack === null || $size === null || $size < 8) {
                return self::sefFail("entry {$i} has a bad offset or size");
            }

            $pos = $sefh - $offsetBack;
            if ($pos < 0 || $pos + $size > $n) {
                return self::sefFail("entry {$i} points outside the trailer");
            }

            $nameLen = self::u32($tail, $pos + 4);
            if ($nameLen === null || $nameLen < 0 || 8 + $nameLen > $size) {
                return self::sefFail("entry {$i} has a bad name length");
            }

            $blocks[] = [
                'name' => substr($tail, $pos + 8, $nameLen),
                'size' => $size - 8 - $nameLen,
            ];
        }

        return ['ok' => true, 'blocks' => $blocks, 'error' => null];
    }

    /**
     * Describe a trailer: none, benign metadata, or a media carrier.
     *
     * REPORTING ONLY — this never decides what gets removed. Callers strip every byte
     * past EOI regardless, so "no bytes past EOI" stays a post-condition we enforce
     * rather than one contingent on this heuristic being right. Its job is to make the
     * report actionable ("four files, DualShot_DepthMap_1, 4.7 MB each").
     *
     * Fails closed: an unparseable trailer is reported as a carrier, because unknown
     * structure is not a reason to assume the contents are harmless.
     *
     * @param array{ok: bool, blocks: list<array{name: string, size: int}>, error: string|null} $sef
     */
    public static function classify(array $sef, string $tail): string
    {
        if ($tail === '') {
            return 'none';
        }
        if (!$sef['ok']) {
            return 'carrier';
        }

        foreach ($sef['blocks'] as $b) {
            if (preg_match(self::CARRIER_NAMES, $b['name']) === 1) {
                return 'carrier';
            }
            if ($b['size'] > self::MAX_BENIGN_BLOCK) {
                return 'carrier';
            }
        }

        foreach (self::CARRIER_SIGNATURES as $sig) {
            if (str_contains($tail, $sig)) {
                return 'carrier';
            }
        }

        return 'benign';
    }

    /**
     * Inspect one file on disk. Shared by the stripper, the importer and the audit.
     *
     * @return array{ok: bool, error: string|null, size: int, eoi: int, tail_bytes: int,
     *               blocks: list<array{name: string, size: int}>, class: string}
     */
    public static function inspectFile(string $path): array
    {
        $d = @file_get_contents($path);
        if ($d === false) {
            return self::inspectFail('unreadable');
        }

        return self::inspect($d);
    }

    /**
     * Inspect bytes already in memory.
     *
     * @return array{ok: bool, error: string|null, size: int, eoi: int, tail_bytes: int,
     *               blocks: list<array{name: string, size: int}>, class: string}
     */
    public static function inspect(string $d): array
    {
        $size = strlen($d);
        $end  = self::findEndOfImage($d);
        if (!$end['ok']) {
            return self::inspectFail($end['error'], $size);
        }

        $tail = substr($d, $end['eoi']);
        $sef  = self::parseSefDirectory($tail);

        return [
            'ok'         => true,
            'error'      => null,
            'size'       => $size,
            'eoi'        => $end['eoi'],
            'tail_bytes' => strlen($tail),
            'blocks'     => $sef['blocks'],
            'class'      => self::classify($sef, $tail),
        ];
    }

    /**
     * Return the image bytes with any trailer removed, plus what was removed.
     *
     * @return array{ok: bool, error: string|null, bytes: string|null, tail_bytes: int,
     *               blocks: list<array{name: string, size: int}>, class: string}
     */
    public static function stripBytes(string $d): array
    {
        $info = self::inspect($d);
        if (!$info['ok']) {
            return [
                'ok'    => false, 'error' => $info['error'], 'bytes' => null,
                'tail_bytes' => 0, 'blocks' => [], 'class' => 'unknown',
            ];
        }

        return [
            'ok'         => true,
            'error'      => null,
            'bytes'      => $info['tail_bytes'] > 0 ? substr($d, 0, $info['eoi']) : $d,
            'tail_bytes' => $info['tail_bytes'],
            'blocks'     => $info['blocks'],
            'class'      => $info['class'],
        ];
    }

    /**
     * Strip a file in place, verifying before anything replaces the original.
     *
     * Writes a sibling temp file, runs every check against it, and only then renames.
     * A failed check leaves the source untouched. rename() is atomic, so a crash
     * mid-write can never leave a half-file where the artwork was.
     *
     * @param bool $deep Also compare decoded pixels — slow (~1s for a 12MP file), opt-in.
     * @return array{ok: bool, error: string|null, stripped: bool, tail_bytes: int,
     *               blocks: list<array{name: string, size: int}>, class: string,
     *               sha1_before: string|null, sha1_after: string|null}
     */
    public static function stripFile(string $path, bool $deep = false): array
    {
        $base = [
            'ok' => false, 'error' => null, 'stripped' => false, 'tail_bytes' => 0,
            'blocks' => [], 'class' => 'unknown', 'sha1_before' => null, 'sha1_after' => null,
        ];

        $d = @file_get_contents($path);
        if ($d === false) {
            return ['error' => 'unreadable'] + $base;
        }

        $strip = self::stripBytes($d);
        $base['tail_bytes'] = $strip['tail_bytes'];
        $base['blocks']     = $strip['blocks'];
        $base['class']      = $strip['class'];
        $base['sha1_before'] = sha1($d);

        if (!$strip['ok']) {
            return ['error' => $strip['error']] + $base;
        }
        if ($strip['tail_bytes'] === 0) {
            // Already clean — say so without rewriting the file.
            return ['ok' => true, 'sha1_after' => $base['sha1_before']] + $base;
        }

        $new = $strip['bytes'];

        $why = self::verifyStrip($d, $new, $strip['tail_bytes'], $deep);
        if ($why !== null) {
            return ['error' => 'verification failed: ' . $why] + $base;
        }

        $tmp = $path . '.strip-tmp';
        if (@file_put_contents($tmp, $new) !== strlen($new)) {
            @unlink($tmp);
            return ['error' => 'could not write temp file'] + $base;
        }

        // Check what actually landed on disk, not just what we meant to write.
        clearstatcache(true, $tmp);
        if (filesize($tmp) !== strlen($new) || sha1_file($tmp) !== sha1($new)) {
            @unlink($tmp);
            return ['error' => 'temp file does not match the verified bytes'] + $base;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return ['error' => 'could not replace the original'] + $base;
        }

        return ['ok' => true, 'stripped' => true, 'sha1_after' => sha1($new)] + $base;
    }

    /**
     * Every check that has to pass before a stripped file replaces an original.
     *
     * @return string|null Null when all checks pass, otherwise which one failed.
     */
    private static function verifyStrip(string $old, string $new, int $tailBytes, bool $deep): string|null
    {
        if (substr($old, 0, strlen($new)) !== $new) {
            return 'retained bytes are not a prefix of the original';
        }
        if (substr($new, -2) !== "\xFF\xD9") {
            return 'stripped file does not end in FF D9';
        }
        if (strlen($old) - strlen($new) !== $tailBytes) {
            return 'byte count removed does not match the reported trailer length';
        }

        $a = @getimagesizefromstring($old);
        $b = @getimagesizefromstring($new);
        if ($a === false || $b === false) {
            return 'one of the two no longer decodes as an image';
        }
        if ($a[0] !== $b[0] || $a[1] !== $b[1] || $a[2] !== $b[2]) {
            return "dimensions or type changed ({$a[0]}x{$a[1]} -> {$b[0]}x{$b[1]})";
        }

        $ea = self::exifOf($old);
        $eb = self::exifOf($new);
        if (($ea === null) !== ($eb === null)) {
            return 'EXIF present in one and not the other';
        }
        if ($ea !== null && $eb !== null) {
            if (($ea['Orientation'] ?? null) !== ($eb['Orientation'] ?? null)) {
                return 'EXIF Orientation changed';
            }
            if (count($ea) !== count($eb)) {
                return 'EXIF key count changed (' . count($ea) . ' -> ' . count($eb) . ')';
            }
        }

        if ($deep) {
            $ia = @imagecreatefromstring($old);
            $ib = @imagecreatefromstring($new);
            if ($ia === false || $ib === false) {
                return 'deep check: GD could not decode one of them';
            }
            $ha = self::pixelHash($ia);
            $hb = self::pixelHash($ib);
            imagedestroy($ia);
            imagedestroy($ib);
            if ($ha !== $hb) {
                return 'deep check: decoded pixels differ';
            }
        }

        return null;
    }

    private static function pixelHash(\GdImage $im): string
    {
        ob_start();
        imagepng($im, null, 0);
        return md5((string) ob_get_clean());
    }

    /**
     * Read EXIF from bytes without touching the filesystem.
     *
     * php://temp rather than php://memory so a 7MB DualShot file spills to disk
     * instead of being held twice over.
     */
    private static function exifOf(string $data): array|null
    {
        if (!function_exists('exif_read_data')) {
            return null;
        }

        $h = fopen('php://temp', 'r+');
        if ($h === false) {
            return null;
        }
        fwrite($h, $data);
        rewind($h);
        $e = @exif_read_data($h);
        fclose($h);

        return is_array($e) ? $e : null;
    }

    /** Little-endian uint32, or null if the buffer is too short. */
    private static function u32(string $s, int $at): int|null
    {
        if ($at < 0 || $at + 4 > strlen($s)) {
            return null;
        }
        $v = unpack('V', substr($s, $at, 4));

        return $v === false ? null : $v[1];
    }

    /** @return array{ok: false, eoi: int, error: string} */
    private static function fail(string $why): array
    {
        return ['ok' => false, 'eoi' => 0, 'error' => $why];
    }

    /** @return array{ok: false, blocks: list<array{name: string, size: int}>, error: string} */
    private static function sefFail(string $why): array
    {
        return ['ok' => false, 'blocks' => [], 'error' => $why];
    }

    /** @return array{ok: false, error: string, size: int, eoi: int, tail_bytes: int, blocks: array{}, class: string} */
    private static function inspectFail(string|null $why, int $size = 0): array
    {
        return [
            'ok'    => false,
            'error' => $why ?? 'unknown',
            'size'  => $size,
            'eoi'   => 0,
            'tail_bytes' => 0,
            'blocks'     => [],
            'class'      => 'unknown',
        ];
    }
}
