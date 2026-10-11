<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use DomainException;

/** Small, re-encoded portraits; independent of session artwork and pose imports. */
final class ProfilePictureService
{
    public function __construct(private readonly Connection $db) {}

    public function upload(int $userId, array $file, bool $nonNude): ?string
    {
        if (!$nonNude) throw new DomainException('Please confirm that this is a non-nude profile photo.', 422);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new DomainException('Choose a JPEG, PNG or WebP photo up to 10MB.', 422);
        }
        if (!ImageLock::acquire()) throw new DomainException('Image processing is busy. Please retry shortly.', 409);
        $path = null;
        $committed = false;
        try {
            $this->db->transaction(function () use ($userId, $file, &$path) {
                $user = $this->db->fetch("SELECT avatar_path, consent_state FROM users WHERE id = ? FOR UPDATE", [$userId]);
                if (!$user || $user['consent_state'] !== 'granted') throw new DomainException('Consent is required before publishing a profile photo.', 403);
                $path = 'avatars/' . $userId . '/' . bin2hex(random_bytes(16)) . '.jpg';
                $target = LDR_ROOT . '/public/assets/uploads/' . $path;
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) throw new \RuntimeException('Could not prepare picture directory.');
                $this->encode($file['tmp_name'], $target);
                $this->db->execute("UPDATE users SET avatar_path = ? WHERE id = ?", [$path, $userId]);
                (new ProvenanceService($this->db))->record($userId, 'profile.picture.update', 'user', $userId);
                // Remove the old file after the successful DB commit below.
                $this->oldPath = $user['avatar_path'];
            });
            $committed = true;
            try { $this->deleteOwned($userId, $this->oldPath); }
            catch (\Throwable $e) {
                error_log('Previous profile photo cleanup failed: ' . $e->getMessage());
                return 'Photo saved. Previous-photo cleanup needs attention.';
            }
            return null;
        } catch (\Throwable $e) {
            if (!$committed && $path) $this->deleteOwned($userId, $path);
            throw $e;
        } finally { ImageLock::release(); }
    }

    private ?string $oldPath = null;

    public function remove(int $userId, int $actorId): void
    {
        if ($actorId !== $userId) SessionAccess::requireManager($this->db, $actorId);
        if (!ImageLock::acquire()) throw new DomainException('Image processing is busy. Please retry shortly.', 409);
        $original = $temporary = null;
        $committed = false;
        try {
            $this->db->transaction(function () use ($userId, $actorId, &$original, &$temporary) {
                $user = $this->db->fetch("SELECT avatar_path FROM users WHERE id = ? FOR UPDATE", [$userId]);
                if (!$user) throw new DomainException('User not found.', 404);
                $this->db->execute("UPDATE users SET avatar_path = NULL WHERE id = ?", [$userId]);
                (new ProvenanceService($this->db))->record($actorId, 'profile.picture.remove', 'user', $userId);
                if ($user['avatar_path'] && preg_match('~\Aavatars/' . $userId . '/[a-f0-9]{32}\.jpg\z~', $user['avatar_path'])) {
                    $original = LDR_ROOT . '/public/assets/uploads/' . $user['avatar_path'];
                    if (is_file($original)) {
                        $temporary = LDR_ROOT . '/storage/cache/picture-removal-' . bin2hex(random_bytes(16));
                        if (!rename($original, $temporary)) throw new \RuntimeException('Could not remove the profile photo.');
                    }
                }
            });
            $committed = true;
        } catch (\Throwable $e) {
            if ($temporary && is_file($temporary) && !rename($temporary, $original)) {
                error_log('Could not restore profile photo after failed removal: ' . $temporary);
            }
            throw $e;
        } finally {
            if ($committed && $temporary && is_file($temporary) && !unlink($temporary)) {
                error_log('Could not clean up removed profile photo: ' . $temporary);
            }
            ImageLock::release();
        }
    }

    private function deleteOwned(int $userId, ?string $path): void
    {
        if (!$path || !preg_match('~\Aavatars/' . $userId . '/[a-f0-9]{32}\.jpg\z~', $path)) return;
        $absolute = LDR_ROOT . '/public/assets/uploads/' . $path;
        if (is_file($absolute) && !unlink($absolute)) throw new \RuntimeException('Could not remove the previous profile photo.');
    }

    /** Decode and re-encode; metadata and trailing non-image bytes are discarded. */
    public function encode(string $source, string $target): void
    {
        $info = @getimagesize($source);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if (!$info || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new DomainException('Use a JPEG, PNG or WebP photo.', 422);
        if (filesize($source) > 10 * 1024 * 1024 || $info[0] * $info[1] > 16000000 || max($info[0], $info[1]) > 8000) {
            throw new DomainException('Use a photo up to 10MB and 16 megapixels. Choose a smaller copy of this photo.', 422);
        }
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/webp' => @imagecreatefromwebp($source),
        };
        if (!$image) throw new DomainException('That photo could not be read.', 422);
        $square = null;
        try {
            $orientation = $mime === 'image/jpeg' && function_exists('exif_read_data') ? (int) (@exif_read_data($source)['Orientation'] ?? 1) : 1;
            if (in_array($orientation, [2, 4, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
            $angle = match ($orientation) { 3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 };
            if ($angle) {
                $rotated = imagerotate($image, $angle, 0);
                if (!$rotated) throw new DomainException('Could not rotate that photo.', 422);
                imagedestroy($image);
                $image = $rotated;
            }
            $width = imagesx($image);
            $height = imagesy($image);
            $side = min($width, $height);
            $square = imagecreatetruecolor(512, 512);
            imagefill($square, 0, 0, imagecolorallocate($square, 255, 255, 255));
            imagecopyresampled($square, $image, 0, 0, (int) (($width - $side) / 2), (int) (($height - $side) / 2), 512, 512, $side, $side);
            if (!imagejpeg($square, $target, 88)) throw new \RuntimeException('Could not save the profile photo.');
        } finally {
            imagedestroy($image);
            if ($square) imagedestroy($square);
        }
    }
}
