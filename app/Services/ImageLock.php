<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cross-process lock over the public uploads tree.
 *
 * tools/process_images.php writes into public/assets/uploads/ at four separate
 * points in a single run: it rewrites the ORIGINAL in place for EXIF rotation
 * and the 10MP cap, then writes the web and thumbnail derivatives beside it.
 *
 * That makes a check-before-write useless for anything that moves or deletes
 * those files. A worker can read an artwork as public, wait while consent
 * withdrawal moves the file away and verifies it gone, and then recreate it at
 * the public path. The interleaving has to be made impossible, not unlikely.
 *
 * So withdrawal, artwork deletion and restoration take the same lock the image
 * worker already holds — storage/process_images.lock — for the whole of
 * read-paths, move, and verify.
 *
 * The worker acquires non-blocking and exits when busy, which is right for a
 * cron: it simply runs again two minutes later. Callers here must instead wait,
 * because silently giving up is the one outcome a privacy operation cannot have.
 */
final class ImageLock
{
    /** @var resource|null */
    private static $handle = null;

    public static function path(): string
    {
        return LDR_ROOT . '/storage/process_images.lock';
    }

    /**
     * Acquire the lock, waiting up to $timeoutSeconds for the image worker to
     * finish its batch. Returns false if it could not be taken in time.
     */
    public static function acquire(int $timeoutSeconds = 60): bool
    {
        if (self::$handle !== null) {
            return true; // already held by this request
        }

        $dir = dirname(self::path());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = @fopen(self::path(), 'c');
        if ($handle === false) {
            return false;
        }

        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                self::$handle = $handle;
                return true;
            }
            usleep(200_000); // 200ms
        } while (microtime(true) < $deadline);

        fclose($handle);
        return false;
    }

    public static function release(): void
    {
        if (self::$handle === null) {
            return;
        }
        flock(self::$handle, LOCK_UN);
        fclose(self::$handle);
        self::$handle = null;
    }
}
