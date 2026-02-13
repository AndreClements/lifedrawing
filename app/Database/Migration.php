<?php

declare(strict_types=1);

namespace App\Database;

/**
 * Lightweight SQL migration runner.
 *
 * Migrations are plain .sql files in module migration directories.
 * A `migrations` table tracks which have been applied.
 * No rollback magic — write reversible migrations explicitly.
 */
final class Migration
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /** Ensure the migrations tracking table exists. */
    public function ensureTable(): void
    {
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                module VARCHAR(100) NOT NULL DEFAULT 'core',
                applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /** Get list of already-applied migrations. */
    public function applied(): array
    {
        return array_column(
            $this->db->fetchAll("SELECT migration FROM migrations ORDER BY id"),
            'migration'
        );
    }

    /**
     * Run all pending migrations from a directory.
     *
     * @param string $path   Absolute path to migrations directory
     * @param string $module Module name for provenance tracking
     * @return string[]      List of newly applied migration filenames
     */
    public function run(string $path, string $module = 'core'): array
    {
        $this->ensureTable();
        $applied = $this->applied();
        $newlyApplied = [];

        $files = glob($path . '/*.sql');
        if ($files === false) {
            return [];
        }
        sort($files); // Lexicographic order: 001_, 002_, ...

        foreach ($files as $file) {
            $name = basename($file);
            $key = $module . '/' . $name;

            if (in_array($key, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }

            // Execute the migration (may contain multiple statements)
            $this->db->getPdo()->exec($sql);

            // Record it
            $this->db->execute(
                "INSERT INTO migrations (migration, module) VALUES (?, ?)",
                [$key, $module]
            );

            $newlyApplied[] = $key;
        }

        return $newlyApplied;
    }

    /** Get status of all known migrations. */
    public function status(string $path, string $module = 'core'): array
    {
        $this->ensureTable();
        $applied = $this->applied();
        $status = [];

        $files = glob($path . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $name = basename($file);
            $key = $module . '/' . $name;
            $status[] = [
                'migration' => $key,
                'applied' => in_array($key, $applied, true),
            ];
        }

        return $status;
    }
}
