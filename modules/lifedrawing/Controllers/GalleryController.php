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
             WHERE a.id = ? AND a.visibility IN ('claimed', 'public') AND s.status <> 'cancelled'",
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
            // The id comes along now, because the Undo control needs something
            // to post to. Still filtered to pending/approved: a withdrawn or
            // rejected row must read as unclaimed so the claim button returns.
            $rows = $this->db->fetchAll(
                "SELECT id, claim_type, status FROM ld_claims
                 WHERE artwork_id = ? AND claimant_id = ? AND status IN ('pending', 'approved')",
                [$id, $this->userId()]
            );
            foreach ($rows as $row) {
                $userClaims[$row['claim_type']] = [
                    'id'     => (int) $row['id'],
                    'status' => $row['status'],
                ];
            }
        }

        $modelClaimContext = $this->sessionModelClaimContext((int) $artwork['session_id']);

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

        $artworkLabel = $artwork['caption'] ?: 'Artwork #' . $id;
        $breadcrumbs = self::breadcrumbJsonLd([
            ['Home', '/'],
            ['Gallery', '/gallery'],
            [$artworkLabel, '/artworks/' . hex_id($id, $artwork['caption'] ?? '')],
        ]);

        return $this->render('gallery.show', [
            'artwork' => $artwork,
            'claims' => $claims,
            'userClaims' => $userClaims,
            'sessionHasKnownModel' => $modelClaimContext['sessionHasKnownModel'],
            'isSessionModel' => $modelClaimContext['isSessionModel'],
            'comments' => $comments,
            'duration' => $duration,
            'pageUrl' => $pageUrl,
        ], $artworkLabel, [
            'meta_description' => $ogDesc,
            'og_image' => $imageUrl,
            'og_description' => $ogDesc,
            'og_url' => $pageUrl,
            'og_type' => 'article',
            'json_ld' => $breadcrumbs,
        ]);
    }

    /** Browse all artworks across sessions (public). */
    public function index(Request $request): Response
    {
        $sessionId = $request->input('session');

        $query = $this->db->fetchAll(
            "SELECT a.*, s.title as session_title, s.session_date,
                    u.display_name as uploader_name,
                    (SELECT COUNT(*) FROM ld_comments ldc
                     JOIN users ldu ON ldc.user_id = ldu.id
                     WHERE ldc.artwork_id = a.id AND ldu.consent_state = 'granted'
                    ) as comment_count
             FROM ld_artworks a
             JOIN ld_sessions s ON a.session_id = s.id
             JOIN users u ON a.uploaded_by = u.id
             WHERE a.visibility IN ('claimed', 'public') AND s.status <> 'cancelled'
             " . ($sessionId ? "AND a.session_id = ?" : "") . "
             ORDER BY s.session_date DESC, a.pose_index ASC
             LIMIT 60",
            $sessionId ? [(int) $sessionId] : []
        );

        // Get available sessions for filter
        $sessions = $this->table('ld_sessions')
            ->select('id', 'title', 'session_date')
            ->where('status', '<>', 'cancelled')
            ->orderBy('session_date', 'DESC')
            ->limit(50)
            ->get();

        return $this->render('gallery.index', [
            'artworks' => $query,
            'sessions' => $sessions,
            'currentSession' => $sessionId,
        ], 'Gallery', [
            'meta_description' => 'Gallery of artworks from Life Drawing Randburg sessions. Figure studies, gesture drawings, and more.',
            'json_ld' => self::breadcrumbJsonLd([['Home', '/'], ['Gallery', '/gallery']]),
        ]);
    }

    /** Show upload form for a session (facilitator+). */
    public function uploadForm(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;
        $id = from_hex($request->param('id'));
        try {
            return $this->render('gallery.upload', $this->uploadData($id), 'Upload artworks')->withHeader('Cache-Control', 'private, no-store');
        } catch (\DomainException $e) { return Response::error(e($e->getMessage()), $e->getCode() ?: 422); }
    }

    private function uploadData(int $id, array $old = []): array
    {
        $session = \App\Services\SessionAccess::session($this->db, $id);
        $notes = (new \App\Services\SessionNotesService($this->db))->listing($id, $this->userId());
        $participants = $this->db->fetchAll("SELECT p.*, u.display_name FROM ld_session_participants p JOIN users u ON u.id = p.user_id WHERE p.session_id = ? ORDER BY p.role, p.id", [$id]);
        $groups = (new \App\Services\PoseService($this->db))->groups($id);
        $legacy = (bool) $this->db->fetchColumn("SELECT id FROM ld_artworks WHERE session_id = ? AND pose_number IS NULL LIMIT 1", [$id]);
        return compact('session', 'notes', 'participants', 'groups', 'legacy', 'old');
    }

    /** Handle artwork upload (facilitator+). */
    public function upload(Request $request): Response
    {
        if ($redirect = $this->requireAuth($request)) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;
        $id = from_hex($request->param('id'));
        $uploaded = 0;
        $already = 0;
        $errors = [];
        try {
            $metadata = [];
            foreach (['caption' => 4000, 'pose_label' => 100, 'pose_duration' => 50] as $field => $max) {
                $metadata[$field] = trim((string) $request->input($field, ''));
                if (mb_strlen($metadata[$field]) > $max) throw new \DomainException('Caption or pose details are too long.', 422);
            }
            $poseService = new \App\Services\PoseService($this->db);
            $batch = $poseService->batch($id, $this->userId(), (string) $request->input('batch_key', ''), (bool) $request->input('confirm_groups'));
            $files = $request->files['artworks'] ?? [];
            $items = [];
            if (is_array($files['name'] ?? null)) {
                foreach ($files['name'] as $i => $name) {
                    $items[] = ['name' => $name, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                }
            } elseif (!empty($files['name'])) {
                $items[] = $files;
            }
            if (!$items) throw new \DomainException('Select at least one image.', 422);
            foreach ($items as $file) {
                try {
                    if ($poseService->saveFile($batch, $this->userId(), $file, $metadata)) $uploaded++;
                    else $already++;
                } catch (\Throwable $e) {
                    error_log('Artwork upload failed: ' . $e->getMessage());
                    $errors[] = basename($file['name']) . ': ' . ($e instanceof \DomainException || $e instanceof \App\Exceptions\AppException ? $e->getMessage() : 'Could not save. Please retry.');
                }
            }
        } catch (\DomainException $e) {
            if ($request->wantsJson()) return Response::json(['success' => false, 'error' => $e->getMessage()], $e->getCode() ?: 422);
            if (in_array($e->getCode(), [403, 404], true)) return Response::error(e($e->getMessage()), $e->getCode());
            return $this->render('gallery.upload', $this->uploadData($id, $request->post) + ['error' => $e->getMessage()], 'Upload artworks')->withStatus($e->getCode() ?: 422);
        }
        $message = $uploaded . ' image(s) saved' . ($already ? ', ' . $already . ' already saved' : '') . '.';
        if ($errors) {
            $message .= ' Retry this batch to finish: ' . implode(' ', $errors);
            if ($request->wantsJson()) return Response::json(['success' => false, 'error' => $message, 'uploaded' => $uploaded], 422);
            return $this->render('gallery.upload', $this->uploadData($id, $request->post) + ['error' => $message], 'Upload artworks')->withStatus(422);
        }
        if ($request->wantsJson()) return Response::json(['success' => true, 'message' => $message . ' Ready for the next pose.', 'uploaded' => $uploaded, 'batch_key' => bin2hex(random_bytes(16))]);
        return $this->render('gallery.upload', $this->uploadData($id) + ['success' => $message], 'Upload artworks');
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

        // All facilitators/admins may manage any session.
        $session = $this->table('ld_sessions')->where('id', '=', (int) $artwork['session_id'])->first();
        if (!$session || $session['status'] === 'cancelled') return Response::notFound('Session unavailable.');
        if (!can_manage_session($session)) return Response::forbidden();

        // Whoever holds approved claims loses an artwork from their totals.
        // Collected before the delete, refreshed after.
        $claimants = $this->db->fetchAll(
            "SELECT DISTINCT claimant_id FROM ld_claims WHERE artwork_id = ? AND status = 'approved'",
            [$id]
        );

        // Hold the image-worker lock across unlink + soft-delete. Without it a
        // process_images.php run already in flight can rewrite the original or
        // write fresh derivatives back into the public tree after the unlink.
        $locked = \App\Services\ImageLock::acquire();

        if (!$locked) {
            // Refuse rather than race. Deleting unlocked can miss derivatives
            // the worker writes while we work, leaving files public for an
            // artwork the page now reports as deleted.
            return Response::error(
                'The image processor is busy. Try again in a moment.',
                503
            );
        }

        try {
            // Re-read INSIDE the lock. The row read before acquiring may be
            // stale: the worker could have written web/thumbnail paths while we
            // were waiting for it to finish, and those files would survive.
            $artwork = $this->table('ld_artworks')->where('id', '=', $id)->first();
            if (!$artwork) {
                return Response::notFound('Artwork not found.');
            }

            $uploadDir = app('upload')->uploadDir;
            $stuck = [];

            foreach (artwork_public_paths($artwork) as $rel) {
                $fullPath = $uploadDir . '/' . $rel;
                if (is_file($fullPath) && !@unlink($fullPath)) {
                    $stuck[] = $rel;
                }
            }

            // A file that survives here is invisible afterwards: withdrawal
            // deliberately skips 'removed' rows on the grounds that their files
            // were unlinked, so an unlink that quietly failed leaves the image
            // public forever with nothing left to find it.
            if ($stuck !== []) {
                error_log('Artwork delete #' . $id . ': files still public: ' . implode(', ', $stuck));
            }

            // Soft-delete: hide from all queries
            $this->table('ld_artworks')
                ->where('id', '=', $id)
                ->update(['visibility' => 'removed']);
        } finally {
            \App\Services\ImageLock::release();
        }

        // Cancel mail that would otherwise arrive minutes later pointing at a
        // page that no longer resolves — both artwork-origin and claim-origin.
        app('notifications')->cancelQueuedForArtwork($id);

        foreach ($claimants as $c) {
            app('stats')->refreshUser((int) $c['claimant_id']);
        }

        $this->provenance->log(
            $this->userId(),
            'artwork.delete',
            'artwork',
            $id,
            [
                'session_id' => $artwork['session_id'],
                'file' => $artwork['file_path'],
                'lock_held' => $locked,
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
            "SELECT a.id FROM ld_artworks a JOIN ld_sessions s ON s.id = a.session_id WHERE a.id = ? AND a.visibility IN ('claimed', 'public') AND s.status <> 'cancelled'",
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
