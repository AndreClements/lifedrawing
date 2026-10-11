<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use DomainException;

final class PoseService
{
    public function __construct(private readonly Connection $db) {}

    /** Preserve the gallery's existing consecutive groups; never infer new boundaries. */
    public function groups(int $sessionId): array
    {
        $rows = $this->db->fetchAll("SELECT * FROM ld_artworks WHERE session_id = ? ORDER BY pose_index, created_at, id", [$sessionId]);
        $groups = [];
        $lastKey = null;
        foreach ($rows as $row) {
            $key = $row['pose_number'] !== null ? 'n' . $row['pose_number'] : 'legacy:' . json_encode([$row['pose_duration'], $row['pose_label']]);
            if ($key !== $lastKey) $groups[] = [];
            $groups[count($groups) - 1][] = $row;
            $lastKey = $key;
        }
        return $groups;
    }

    /** Caller holds the session lock. Explicit review is required for unnumbered groups. */
    public function normalize(int $sessionId, bool $confirmed): void
    {
        $groups = $this->groups($sessionId);
        $hasLegacy = false;
        $max = (int) $this->db->fetchColumn("SELECT COALESCE(MAX(pose_number), 0) FROM ld_artworks WHERE session_id = ?", [$sessionId]);
        foreach ($groups as $group) {
            if ($group[0]['pose_number'] === null) $hasLegacy = true;
        }
        if ($hasLegacy && !$confirmed) throw new DomainException('Review and confirm the existing pose groups before adding or editing poses.', 409);
        foreach ($groups as $group) {
            if ($group[0]['pose_number'] !== null) continue;
            if (++$max > 65535) throw new DomainException('Too many poses in this session.', 422);
            foreach ($group as $row) {
                $this->db->execute("UPDATE ld_artworks SET pose_number = ? WHERE id = ? AND pose_number IS NULL", [$max, $row['id']]);
            }
        }
    }

    public function batch(int $sessionId, int $actorId, string $key, bool $confirmLegacy = false): array
    {
        SessionAccess::requireManager($this->db, $actorId);
        SessionNotesService::requestKey($key);
        return $this->db->transaction(function () use ($sessionId, $actorId, $key, $confirmLegacy) {
            SessionAccess::session($this->db, $sessionId, true);
            $existing = $this->db->fetch("SELECT * FROM ld_upload_batches WHERE session_id = ? AND request_key = ?", [$sessionId, $key]);
            if ($existing) return $existing;
            $this->normalize($sessionId, $confirmLegacy);
            $number = 1 + (int) $this->db->fetchColumn(
                "SELECT GREATEST(COALESCE((SELECT MAX(pose_number) FROM ld_artworks WHERE session_id = ?), 0),
                                 COALESCE((SELECT MAX(pose_number) FROM ld_upload_batches WHERE session_id = ?), 0))", [$sessionId, $sessionId]);
            if ($number > 65535) throw new DomainException('Too many poses in this session.', 422);
            $this->db->execute("INSERT INTO ld_upload_batches (session_id, request_key, pose_number) VALUES (?, ?, ?)", [$sessionId, $key, $number]);
            return $this->db->fetch("SELECT * FROM ld_upload_batches WHERE id = ?", [(int) $this->db->lastInsertId()]);
        });
    }

    public function saveFile(array $batch, int $actorId, array $file, array $metadata): bool
    {
        SessionAccess::requireManager($this->db, $actorId);
        $sessionId = (int) $batch['session_id'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new DomainException('The image upload was interrupted or exceeds the upload limit.', 422);
        }
        $fileKey = hash_file('sha256', $file['tmp_name']);
        $paths = null;
        try {
            return $this->db->transaction(function () use ($batch, $actorId, $file, $metadata, $sessionId, $fileKey, &$paths) {
                SessionAccess::session($this->db, $sessionId, true);
                if ($this->db->fetchColumn("SELECT id FROM ld_artworks WHERE upload_batch_id = ? AND upload_file_key = ?", [$batch['id'], $fileKey])) return false;
                $first = $this->db->fetch("SELECT pose_label, pose_duration FROM ld_artworks WHERE session_id = ? AND pose_number = ? ORDER BY pose_index, id LIMIT 1",
                    [$sessionId, $batch['pose_number']]);
                // Partial retries inherit the saved pose, including any subsequent correction.
                if ($first) $metadata = array_replace($metadata, $first);
                $paths = app('upload')->storeSessionArtwork($file, $sessionId);
                // A retry stays beside its pose, even if another batch arrived meanwhile.
                $last = $this->db->fetchColumn("SELECT MAX(pose_index) FROM ld_artworks WHERE session_id = ? AND pose_number = ?", [$sessionId, $batch['pose_number']]);
                $next = 1 + (int) ($last ?? $this->db->fetchColumn("SELECT COALESCE(MAX(pose_index), 0) FROM ld_artworks WHERE session_id = ?", [$sessionId]));
                $this->db->execute("UPDATE ld_artworks SET pose_index = pose_index + 1 WHERE session_id = ? AND pose_index >= ?", [$sessionId, $next]);
                $this->db->execute(
                    "INSERT INTO ld_artworks (session_id, uploaded_by, file_path, caption, pose_index, pose_number, pose_duration, pose_label, visibility, upload_batch_id, upload_file_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'public', ?, ?)",
                    [$sessionId, $actorId, $paths['file_path'], $metadata['caption'] ?: null, $next, $batch['pose_number'],
                     $metadata['pose_duration'] ?: null, $metadata['pose_label'] ?: null, $batch['id'], $fileKey]
                );
                (new ProvenanceService($this->db))->record($actorId, 'artwork.upload', 'artwork', (int) $this->db->lastInsertId(),
                    ['session_id' => $sessionId, 'orig' => basename($file['name']), 'pose_number' => $batch['pose_number']]);
                return true;
            });
        } catch (\Throwable $e) {
            if ($paths !== null) @unlink(app('upload')->uploadDir . '/' . $paths['file_path']);
            throw $e;
        }
    }
}
