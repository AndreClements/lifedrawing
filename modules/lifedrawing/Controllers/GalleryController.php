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
             JOIN users u ON c.user_id = u.id
             WHERE c.artwork_id = ? AND c.status = 'approved' AND u.consent_state = 'granted'
             ORDER BY c.claim_type ASC",
            [$id]
        );

        // Pose metadata
        $duration = $artwork['pose_duration'] ?: null;

        // Comments — artist and model comments float to top (equal priority)
        $comments = $this->db->fetchAll(
            "SELECT c.*, u.display_name,
                    (SELECT GROUP_CONCAT(cl.claim_type)
                     FROM ld_claims cl
                     WHERE cl.artwork_id = c.artwork_id
                       AND cl.user_id = c.user_id
                       AND cl.status = 'approved') as claim_roles
             FROM ld_comments c
             JOIN users u ON c.user_id = u.id
             WHERE c.artwork_id = ? AND u.consent_state = 'granted'
             ORDER BY
                 CASE WHEN EXISTS (
                     SELECT 1 FROM ld_claims cl
                     WHERE cl.artwork_id = c.artwork_id
                       AND cl.user_id = c.user_id
                       AND cl.status = 'approved'
                 ) THEN 0 ELSE 1 END,
                 c.created_at ASC",
            [$id]
        );

        // Build OG meta for social sharing
        $uploadService = app('upload');
        $imageUrl = rtrim(config('app.url', 'http://localhost/lifedrawing'), '/')
            . $uploadService->url($artwork['file_path']);
        $pageUrl = full_url(route('artworks.show', ['id' => hex_id($id)]));
        $ogDesc = 'Artwork from ' . session_title($artwork)
            . ' (' . format_date($artwork['session_date']) . ')';

        return $this->render('gallery.show', [
            'artwork' => $artwork,
            'claims' => $claims,
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

    /** Handle artwork upload (facilitator+). */
    public function upload(Request $request): Response
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

        $files = $request->files['artworks'] ?? [];
        $caption = trim($request->input('caption', ''));
        $poseLabel = trim($request->input('pose_label', '')) ?: null;
        $uploadService = app('upload');
        $uploaded = 0;

        $poseDuration = trim($request->input('pose_duration', '')) ?: null;

        // Get current max pose_index for this session (for sequential numbering across batches)
        $maxIndex = (int) ($this->db->fetch(
            "SELECT COALESCE(MAX(pose_index), 0) as max_idx FROM ld_artworks WHERE session_id = ?",
            [$sessionId]
        )['max_idx'] ?? 0);

        // Handle multiple file upload
        if (!empty($files['name']) && is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;

                $singleFile = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i],
                ];

                try {
                    $paths = $uploadService->storeSessionArtwork($singleFile, $sessionId);

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

                    $this->provenance->log(
                        $this->userId(),
                        'artwork.upload',
                        'artwork',
                        $artworkId,
                        ['session_id' => $sessionId, 'file' => $paths['file_path'],
                         'pose_duration' => $poseDuration, 'pose_label' => $poseLabel]
                    );

                    $uploaded++;
                } catch (\App\Exceptions\AppException $e) {
                    // Log but continue with other files
                    error_log("Upload failed for file {$i}: " . $e->getMessage());
                }
            }
        } elseif (!empty($files['name']) && is_string($files['name'])) {
            // Single file upload
            try {
                $paths = $uploadService->storeSessionArtwork($files, $sessionId);

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

                $this->provenance->log(
                    $this->userId(),
                    'artwork.upload',
                    'artwork',
                    $artworkId,
                    ['session_id' => $sessionId]
                );

                $uploaded++;
            } catch (\App\Exceptions\AppException $e) {
                return $this->render('gallery.upload', [
                    'session' => $session,
                    'error' => $e->getMessage(),
                ], 'Upload Artworks');
            }
        }

        if ($uploaded === 0) {
            return $this->render('gallery.upload', [
                'session' => $session,
                'error' => 'No files were uploaded. Please select at least one image.',
            ], 'Upload Artworks');
        }

        // Redirect back to upload form for next batch (not to session view)
        return $this->render('gallery.upload', [
            'session' => $session,
            'success' => "{$uploaded} image(s) uploaded. Add another batch or go back to the session.",
        ], 'Upload Artworks');
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

        $this->table('ld_comments')->insert([
            'artwork_id' => $artworkId,
            'user_id' => $this->userId(),
            'body' => $body,
        ]);

        return Response::redirect(route('artworks.show', ['id' => hex_id($artworkId)]) . '#comments');
    }
}
