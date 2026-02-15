<?php

declare(strict_types=1);

namespace App\Services\Upload;

/**
 * Image processing service — EXIF rotation, size capping, WebP derivatives.
 *
 * Single Responsibility: pixel manipulation only. Upload lifecycle (validation,
 * storage, URL generation) stays in UploadService.
 *
 * All public methods return bool (true = action taken, false = no-op or failure).
 * They do NOT throw — the CLI processor handles errors per-image with And-Yet
 * logging so one failure doesn't halt the batch.
 */
final class ImageProcessor
{
    private const MAX_MEGAPIXELS = 10_000_000; // ~10MP cap for originals
    private const WEB_MAX_DIMENSION = 2000;    // longest side for detail pages
    private const WEB_QUALITY = 82;
    private const THUMB_MAX_DIMENSION = 400;   // longest side for gallery grids
    private const THUMB_QUALITY = 78;
    private const MASTER_QUALITY = 92;         // quality for in-place original rewrites

    /**
     * Check runtime capabilities for image processing.
     *
     * @return array{gd: bool, webp: bool, exif: bool}
     */
    public function checkCapabilities(): array
    {
        return [
            'gd'   => extension_loaded('gd'),
            'webp' => function_exists('imagewebp'),
            'exif' => function_exists('exif_read_data'),
        ];
    }

    /**
     * Apply EXIF orientation correction in-place (JPEG only).
     *
     * Handles orientations 3 (180°), 6 (90° CW), 8 (90° CCW).
     * Orientations 2/4/5/7 involve mirroring — extremely rare in real camera
     * output. Gracefully ignored (And-Yet: mirrored EXIF orientations not handled).
     *
     * @return bool True if the image was rotated and re-saved.
     */
    public function correctExifRotation(string $path, string $ext): bool
    {
        if ($ext !== 'jpg' || !function_exists('exif_read_data')) {
            return false;
        }

        $exif = @exif_read_data($path);
        if (!$exif || empty($exif['Orientation'])) {
            return false;
        }

        $orientation = (int) $exif['Orientation'];
        if ($orientation === 1) {
            return false; // Already correct
        }

        $image = @imagecreatefromjpeg($path);
        if ($image === false) {
            return false;
        }

        $angle = match ($orientation) {
            3 => 180,
            6 => -90,  // 270° CW = -90°
            8 => 90,
            default => null,
        };

        if ($angle === null) {
            imagedestroy($image);
            return false; // Mirrored orientations — skip gracefully
        }

        $rotated = imagerotate($image, $angle, 0);
        imagedestroy($image);

        if ($rotated === false) {
            return false;
        }

        // GD doesn't write EXIF, so the saved file has no orientation tag
        // — it will display correctly everywhere.
        $result = imagejpeg($rotated, $path, self::MASTER_QUALITY);
        imagedestroy($rotated);

        return $result;
    }

    /**
     * Cap original image at ~10MP in-place (same format, same path).
     *
     * @return bool True if the image was resized, false if already within cap.
     */
    public function capOriginal(string $path, string $ext): bool
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return false;
        }

        $w = $info[0];
        $h = $info[1];
        $megapixels = $w * $h;

        if ($megapixels <= self::MAX_MEGAPIXELS) {
            return false; // Already within cap
        }

        $scale = sqrt(self::MAX_MEGAPIXELS / $megapixels);
        $newW = (int) round($w * $scale);
        $newH = (int) round($h * $scale);

        $image = $this->loadImage($path, $ext);
        if ($image === false) {
            return false;
        }

        $resized = imagecreatetruecolor($newW, $newH);
        if ($ext === 'png') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($image);

        $result = $this->saveImage($resized, $path, $ext, self::MASTER_QUALITY);
        imagedestroy($resized);

        return $result;
    }

    /**
     * Generate web-display WebP at 2000px longest side.
     */
    public function createWebDisplay(string $source, string $dest): bool
    {
        return $this->resizeToWebp($source, $dest, self::WEB_MAX_DIMENSION, self::WEB_QUALITY);
    }

    /**
     * Generate thumbnail WebP at 400px longest side.
     */
    public function createThumbnail(string $source, string $dest): bool
    {
        return $this->resizeToWebp($source, $dest, self::THUMB_MAX_DIMENSION, self::THUMB_QUALITY);
    }

    /**
     * Determine file extension from filename.
     */
    public function extensionFromPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        return $ext;
    }

    // --- Private helpers ---

    private function loadImage(string $path, string $ext): \GdImage|false
    {
        return match ($ext) {
            'jpg'  => @imagecreatefromjpeg($path),
            'png'  => @imagecreatefrompng($path),
            'webp' => @imagecreatefromwebp($path),
            default => false,
        };
    }

    private function saveImage(\GdImage $image, string $path, string $ext, int $quality): bool
    {
        return match ($ext) {
            'jpg'  => imagejpeg($image, $path, $quality),
            'png'  => imagepng($image, $path, (int) round((100 - $quality) / 100 * 9)),
            'webp' => imagewebp($image, $path, $quality),
            default => false,
        };
    }

    /**
     * Resize source image to WebP at given max dimension (longest side).
     *
     * Detects source format from actual file content via getimagesize(),
     * not from the file extension — defence against mislabelled files.
     */
    private function resizeToWebp(string $source, string $dest, int $maxDim, int $quality): bool
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            return false;
        }

        $info = @getimagesize($source);
        if ($info === false) {
            return false;
        }

        $origW = $info[0];
        $origH = $info[1];

        $ext = match ($info[2]) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_WEBP => 'webp',
            default        => null,
        };
        if ($ext === null) {
            return false;
        }

        // Calculate proportional size based on longest side
        $longest = max($origW, $origH);
        if ($longest <= $maxDim) {
            $newW = $origW;
            $newH = $origH;
        } else {
            $ratio = $maxDim / $longest;
            $newW = (int) round($origW * $ratio);
            $newH = (int) round($origH * $ratio);
        }

        $image = $this->loadImage($source, $ext);
        if ($image === false) {
            return false;
        }

        if ($newW === $origW && $newH === $origH) {
            // No resize needed — just convert format to WebP
            $result = imagewebp($image, $dest, $quality);
            imagedestroy($image);
            return $result;
        }

        $resized = imagecreatetruecolor($newW, $newH);
        // WebP supports alpha
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($image);

        $result = imagewebp($resized, $dest, $quality);
        imagedestroy($resized);

        return $result;
    }
}
