<?php

declare(strict_types=1);

namespace App\Services\Upload;

use App\Exceptions\AppException;

/**
 * Image upload service — HTTP upload lifecycle only.
 *
 * Validates, stores, and generates URLs for uploaded images.
 * Pixel manipulation (EXIF, resize, WebP) is in ImageProcessor (SRP).
 * Safety facet: strict type/size validation. No arbitrary file execution.
 */
final class UploadService
{
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_SIZE = 10 * 1024 * 1024; // 10MB

    public string $uploadDir;

    public function __construct()
    {
        $this->uploadDir = LDR_ROOT . '/public/assets/uploads';
    }

    /**
     * Store an uploaded file for a session.
     *
     * @param array $file     The $_FILES entry
     * @param int   $sessionId
     * @return array{file_path: string, thumbnail_path: string|null}
     */
    public function storeSessionArtwork(array $file, int $sessionId): array
    {
        $this->validate($file);

        // Create session directory
        $dir = $this->uploadDir . '/sessions/' . $sessionId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Generate unique filename preserving extension
        $ext = $this->getExtension($file['type']);
        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destination = $dir . '/' . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new AppException(
                'Failed to store uploaded file.',
                andYet: 'move_uploaded_file failed — check directory permissions on uploads/sessions/'
            );
        }

        // Derivatives generated asynchronously by tools/process_images.php
        return [
            'file_path' => 'sessions/' . $sessionId . '/' . $filename,
            'thumbnail_path' => null,
        ];
    }

    /** Get the public URL for an upload path. */
    public function url(string $path): string
    {
        $basePath = config('app.base_path', '/lifedrawing/public');
        return rtrim($basePath, '/') . '/assets/uploads/' . ltrim($path, '/');
    }

    // --- Validation ---

    private function validate(array $file): void
    {
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = match ($file['error'] ?? -1) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large.',
                UPLOAD_ERR_PARTIAL => 'Upload was interrupted.',
                UPLOAD_ERR_NO_FILE => 'No file selected.',
                default => 'Upload failed.',
            };
            throw new AppException($errorMsg, andYet: 'Upload error code: ' . ($file['error'] ?? 'unknown'));
        }

        // Check actual MIME type (not the user-supplied one)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, self::ALLOWED_TYPES, true)) {
            throw new AppException(
                'Only JPEG, PNG, and WebP images are allowed.',
                andYet: "Rejected MIME type: {$mimeType}"
            );
        }

        if ($file['size'] > self::MAX_SIZE) {
            throw new AppException(
                'File is too large. Maximum size is 10MB.',
                andYet: 'File size: ' . round($file['size'] / 1024 / 1024, 2) . 'MB'
            );
        }

        // Verify it's actually an image
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            throw new AppException(
                'File does not appear to be a valid image.',
                andYet: 'getimagesize returned false — possible non-image or corrupt file'
            );
        }
    }

    private function getExtension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
    }

}
