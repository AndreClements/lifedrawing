<?php

declare(strict_types=1);

namespace Modules\Lifedrawing\Controllers;

use App\Request;
use App\Response;
use App\Services\Upload\UploadService;

/**
 * Gallery Controller — browse and upload session artwork snapshots.
 *
 * Public browsing, facilitator-gated uploads.
 * Parametric authorship: easy to browse, gated to upload.
 */
final class GalleryController extends BaseController
{
    /** View a single artwork (public). */
    public function show(Request $request): Response
    {
        $id = from_hex($request->param('id'));
        $artwork = $this->db->fetch(
            "SELECT a.*, s.title as session_title, s.session_date, s.venue,
                    u.display_name as uploader_name
             FROM ld_artworks a
             JOIN ld_sessions s ON a.session_id = s.id
             JOIN users u ON a.uploaded_by = u.id
             WHERE a.id = ? AND a.visibility IN ('claimed', 'public')",
            [$id]
        );

        if (!$artwork) {
            return Response::notFound('Artwork not found.');
        }

        // Get claims (artist + model) with consent check
        $claims = $this->db->fetchAll(
            "SELECT c.claim_type, c.status, u.display_name, u.id as user_id
             FROM ld_claims c
             JOIN users u ON c.claimant_id = u.id
             WHERE c.artwork_id = ? AND c.status = 'approved' AND u.consent_state = 'granted'
             ORDER BY c.claim_type ASC",
            [$id]
        );

        // Check if current user already has claims on this artwork
        $userClaims = [];
        if ($this->auth->isLoggedIn()) {
            $rows = $this->db->fetchAll(
                "SELECT claim_type, status FROM ld_claims
                 WHERE artwork_id = ? AND claimant_id = ? AND status IN ('pending', 'approved')",
                [$id, $this->userId()]
            );
            foreach ($rows as $row) {
                $userClaims[$row['claim_type']] = $row['status'];
            }
        }

        // Pose metadata
        $duration = $artwork['pose_duration'] ?: null;

        // Comments — artist and model comments float to top (equal priority)
        $comments = $this->db->fetchAll(
            "SELECT c.*, u.display_name,
                    (SELECT GROUP_CONCAT(cl.claim_type)
                     FROM ld_claims cl
                     WHERE cl.artwork_id = c.artwork_id
                       AND cl.claimant_id = c.user_id
                       AND cl.status = 'approved') as claim_roles
             FROM ld_comments c
             JOIN users u ON c.user_id = u.id
             WHERE c.artwork_id = ? AND u.consent_state = 'granted'
             ORDER BY
                 CASE WHEN EXISTS (
                     SELECT 1 FROM ld_claims cl
                     WHERE cl.artwork_id = c.artwork_id
                       AND cl.claimant_id = c.user_id
                       AND cl.status = 'approved'
                 ) THEN 0 ELSE 1 END,
                 c.created_at ASC",
            [$id]
        );

        // Build OG meta for social sharing
        $uploadService = app('upload');
        $imageUrl = rtrim(config('app.url', 'http://localhost/lifedrawing'), '/')
            . $uploadService->url($artwork['web_path'] ?? $artwork['file_path']);
        $pageUrl = full_url(route('artworks.show', ['id' => hex_id($id)]));
        $ogDesc = 'Artwork from ' . session_title($artwork)
            . ' (' . format_date($artwork['session_date']) . ')';

        return $this->render('gallery.show', [
            'artwork' => $artwork,
            'claims' => $claims,
            'userClaims' => $userClaims,
            'comments' => $comments,
            'duration' => $duration,
            'pageUrl' => $pageUrl,
        ], $artwork['caption'] ?: 'Artwork #' . $id, [
            'og_image' => $imageUrl,
            'og_description' => $ogDesc,
            'og_url' => $pageUrl,
            'og_type' => 'article',
        ]);
    }

    /** Browse all artworks across sessions (public). */
    public function index(Request $request): Response
    {
        $sessionId = $request->input('session');

        $query = $this->db->fetchAll(
            "SELECT a.*, s.title as session_title, s.session_date,
                    u.display_name as uploader_name
             FROM ld_artworks a
             JOIN ld_sessions s ON a.session_id = s.id
             JOIN users u ON a.uploaded_by = u.id
             WHERE a.visibility IN ('claimed', 'public')
             " . ($sessionId ? "AND a.session_id = ?" : "") . "
             ORDER BY s.session_date DESC, a.pose_index ASC
             LIMIT 60",
            $sessionId ? [(int) $sessionId] : []
        );

        // Get available sessions for filter
        $sessions = $this->table('ld_sessions')
            ->select('id', 'title', 'session_date')
            ->orderBy('session_date', 'DESC')
            ->limit(50)
            ->get();

        return $this->render('gallery.index', [
            'artworks' => $query,
            'sessions' => $sessions,
            'currentSession' => $sessionId,
        ], 'Gallery');
    }

    /** Show upload form for a session (facilitator+). */
    public function uploadForm(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $sessionId = from_hex($request->param('id'));
        $session = $this->table('ld_sessions')->where('id', '=', $sessionId)->first();

        if (!$session) {
            return Response::notFound('Session not found.');
        }

        // IDOR check: only this session's facilitator (or admin) can upload
        if (!$this->auth->hasRole('admin') && (int) ($session['facilitator_id'] ?? 0) !== $this->userId()) {
            return Response::forbidden('You can only upload to your own sessions.');
        }

        return $this->render('gallery.upload', [
            'session' => $session,
        ], 'Upload Artworks');
    }

    /** Debug log for upload instrumentation. */
    private function uploadLog(string $msg): void
    {
        $logFile = defined('LDR_ROOT') ? LDR_ROOT . '/storage/upload.log' : '/tmp/upload.log';
        $ts = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$ts}] {$msg}\n", FILE_APPEND | LOCK_EX);
    }

    /** Handle artwork upload (facilitator+). */
    public function upload(Request $request): Response
    {
        $this->uploadLog('=== Upload request started ===');
        $this->uploadLog('Memory: ' . (memory_get_usage(true) / 1024 / 1024) . 'MB');
        $this->uploadLog('POST size: ' . ($_SERVER['CONTENT_LENGTH'] ?? 'unknown') . ' bytes');

        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $sessionId = from_hex($request->param('id'));
        $session = $this->table('ld_sessions')->where('id', '=', $sessionId)->first();

        if (!$session) {
            return Response::notFound('Session not found.');
        }

        // IDOR check: only this session's facilitator (or admin) can upload
        if (!$this->auth->hasRole('admin') && (int) ($session['facilitator_id'] ?? 0) !== $this->userId()) {
            return Response::forbidden('You can only upload to your own sessions.');
        }

        $files = $request->files['artworks'] ?? [];
        $caption = trim($request->input('caption', ''));
        $poseLabel = trim($request->input('pose_label', '')) ?: null;
        $uploadService = app('upload');
        $uploaded = 0;

        $poseDuration = trim($request->input('pose_duration', '')) ?: null;

        $fileCount = is_array($files['name'] ?? null) ? count($files['name']) : (empty($files['name']) ? 0 : 1);
        $this->uploadLog("Files received: {$fileCount}");

        if ($fileCount > 0 && is_array($files['name'] ?? null)) {
            for ($i = 0; $i < $fileCount; $i++) {
                $this->uploadLog("File {$i}: {$files['name'][$i]} ({$files['size'][$i]} bytes, error={$files['error'][$i]})");
            }
        }

        // Get current max pose_index for this session (for sequential numbering across batches)
        $maxIndex = (int) ($this->db->fetch(
            "SELECT COALESCE(MAX(pose_index), 0) as max_idx FROM ld_artworks WHERE session_id = ?",
            [$sessionId]
        )['max_idx'] ?? 0);

        // Handle multiple file upload
        if (!empty($files['name']) && is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    $this->uploadLog("File {$i}: skipped, upload error code {$files['error'][$i]}");
                    continue;
                }

                $singleFile = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i],
                ];

                try {
                    $this->uploadLog("File {$i}: storing via UploadService...");
                    $paths = $uploadService->storeSessionArtwork($singleFile, $sessionId);
                    $this->uploadLog("File {$i}: stored OK → {$paths['file_path']}");

                    $this->uploadLog("File {$i}: inserting DB record...");
                    $artworkId = (int) $this->table('ld_artworks')->insert([
                        'session_id' => $sessionId,
                        'uploaded_by' => $this->userId(),
                        'file_path' => $paths['file_path'],
                        'thumbnail_path' => $paths['thumbnail_path'],
                        'caption' => $caption ?: null,
                        'pose_index' => $maxIndex + $uploaded + 1,
                        'pose_duration' => $poseDuration,
                        'pose_label' => $poseLabel,
                        'visibility' => 'public',
                    ]);
                    $this->uploadLog("File {$i}: DB OK, artwork_id={$artworkId}");

                    $this->provenance->log(
                        $this->userId(),
                        'artwork.upload',
                        'artwork',
                        $artworkId,
                        ['session_id' => $sessionId, 'file' => $paths['file_path'],
                         'pose_duration' => $poseDuration, 'pose_label' => $poseLabel]
                    );

                    $uploaded++;
                    $this->uploadLog("File {$i}: complete. Memory: " . (memory_get_usage(true) / 1024 / 1024) . 'MB');
                } catch (\App\Exceptions\AppException $e) {
                    $this->uploadLog("File {$i}: FAILED — " . $e->getMessage());
                    error_log("Upload failed for file {$i}: " . $e->getMessage());
                } catch (\Throwable $e) {
                    $this->uploadLog("File {$i}: EXCEPTION — " . get_class($e) . ': ' . $e->getMessage());
                    error_log("Upload exception for file {$i}: " . $e->getMessage());
                }
            }
        } elseif (!empty($files['name']) && is_string($files['name'])) {
            // Single file upload
            try {
                $this->uploadLog("Single file: storing via UploadService...");
                $paths = $uploadService->storeSessionArtwork($files, $sessionId);
                $this->uploadLog("Single file: stored OK → {$paths['file_path']}");

                $this->uploadLog("Single file: inserting DB record...");
                $artworkId = (int) $this->table('ld_artworks')->insert([
                    'session_id' => $sessionId,
                    'uploaded_by' => $this->userId(),
                    'file_path' => $paths['file_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'caption' => $caption ?: null,
                    'pose_index' => $maxIndex + 1,
                    'pose_duration' => $poseDuration,
                    'pose_label' => $poseLabel,
                    'visibility' => 'public',
                ]);
                $this->uploadLog("Single file: DB OK, artwork_id={$artworkId}");

                $this->provenance->log(
                    $this->userId(),
                    'artwork.upload',
                    'artwork',
                    $artworkId,
                    ['session_id' => $sessionId]
                );

                $uploaded++;
            } catch (\App\Exceptions\AppException $e) {
                $this->uploadLog("Single file: FAILED — " . $e->getMessage());
                return $this->render('gallery.upload', [
                    'session' => $session,
                    'error' => $e->getMessage(),
                ], 'Upload Artworks');
            } catch (\Throwable $e) {
                $this->uploadLog("Single file: EXCEPTION — " . get_class($e) . ': ' . $e->getMessage());
                return $this->render('gallery.upload', [
                    'session' => $session,
                    'error' => 'Upload failed: ' . $e->getMessage(),
                ], 'Upload Artworks');
            }
        } else {
            $this->uploadLog('No files in request. $_FILES keys: ' . implode(', ', array_keys($_FILES)));
            $this->uploadLog('$files structure: ' . json_encode(array_map(function($v) { return is_array($v) ? 'array(' . count($v) . ')' : gettype($v); }, $files)));
        }

        if ($uploaded === 0) {
            $this->uploadLog('Upload complete: 0 files uploaded');
            return $this->render('gallery.upload', [
                'session' => $session,
                'error' => 'No files were uploaded. Please select at least one image.',
            ], 'Upload Artworks');
        }

        $this->uploadLog("Upload complete: {$uploaded} file(s) uploaded successfully");

        // Redirect back to upload form for next batch (not to session view)
        return $this->render('gallery.upload', [
            'session' => $session,
            'success' => "{$uploaded} image(s) uploaded. Add another batch or go back to the session.",
        ], 'Upload Artworks');
    }

    /** Delete an artwork (facilitator+). Soft-deletes DB record, removes files from disk. */
    public function destroy(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $id = from_hex($request->param('id'));
        $artwork = $this->table('ld_artworks')->where('id', '=', $id)->first();

        if (!$artwork) {
            return Response::notFound('Artwork not found.');
        }

        // IDOR check: only this session's facilitator (or admin) can delete
        $session = $this->table('ld_sessions')->where('id', '=', (int) $artwork['session_id'])->first();
        if (!$this->auth->hasRole('admin') && (int) ($session['facilitator_id'] ?? 0) !== $this->userId()) {
            return Response::forbidden('You can only delete artworks from your own sessions.');
        }

        // Remove physical files
        $uploadDir = app('upload')->uploadDir;
        foreach (['file_path', 'web_path', 'thumbnail_path'] as $col) {
            if (!empty($artwork[$col])) {
                $fullPath = $uploadDir . '/' . $artwork[$col];
                if (is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }
        }

        // Soft-delete: hide from all queries
        $this->table('ld_artworks')
            ->where('id', '=', $id)
            ->update(['visibility' => 'removed']);

        $this->provenance->log(
            $this->userId(),
            'artwork.delete',
            'artwork',
            $id,
            [
                'session_id' => $artwork['session_id'],
                'file' => $artwork['file_path'],
            ]
        );

        return Response::redirect(route('sessions.show', ['id' => hex_id((int) $artwork['session_id'])]));
    }

    /** Post a comment on an artwork (auth + consent required via middleware). */
    public function comment(Request $request): Response
    {
        $artworkId = from_hex($request->param('id'));
        $body = trim($request->input('body', ''));

        if ($body === '' || mb_strlen($body) > 2000) {
            return Response::redirect(route('artworks.show', ['id' => hex_id($artworkId)]));
        }

        // Verify artwork exists and is visible
        $artwork = $this->db->fetch(
            "SELECT id FROM ld_artworks WHERE id = ? AND visibility IN ('claimed', 'public')",
            [$artworkId]
        );

        if (!$artwork) {
            return Response::notFound('Artwork not found.');
        }

        $commentId = (int) $this->table('ld_comments')->insert([
            'artwork_id' => $artworkId,
            'user_id' => $this->userId(),
            'body' => $body,
        ]);

        $this->provenance->log(
            $this->userId(),
            'artwork.comment',
            'artwork',
            $artworkId,
            ['comment_id' => $commentId]
        );

        // Notify claimed artists/models about the new comment
        app('notifications')->artworkCommented($artworkId, $this->userId(), $body);

        return Response::redirect(route('artworks.show', ['id' => hex_id($artworkId)]) . '#comments');
    }
}
