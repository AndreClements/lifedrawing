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

        $sessionId = (int) $request->param('id');
        $session = $this->table('ld_sessions')->where('id', '=', $sessionId)->first();

        if (!$session) {
            return Response::notFound('Session not found.');
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

        $sessionId = (int) $request->param('id');
        $session = $this->table('ld_sessions')->where('id', '=', $sessionId)->first();

        if (!$session) {
            return Response::notFound('Session not found.');
        }

        $files = $request->files['artworks'] ?? [];
        $caption = trim($request->input('caption', ''));
        $uploadService = app('upload');
        $uploaded = 0;

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
                        'pose_index' => $i + 1,
                        'visibility' => 'public',
                    ]);

                    $this->provenance->log(
                        $this->userId(),
                        'artwork.upload',
                        'artwork',
                        $artworkId,
                        ['session_id' => $sessionId, 'file' => $paths['file_path']]
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

        return Response::redirect(route('sessions.show', ['id' => $sessionId]));
    }
}
