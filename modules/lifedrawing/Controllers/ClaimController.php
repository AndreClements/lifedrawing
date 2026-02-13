<?php

declare(strict_types=1);

namespace Modules\Lifedrawing\Controllers;

use App\Request;
use App\Response;

/**
 * Claim Controller — artists and models claim their artworks.
 *
 * Parametric authorship: claiming is easy (low slope), uploading is gated.
 * Claims require facilitator approval — the facilitator was there, they know.
 */
final class ClaimController extends BaseController
{
    /** Claim an artwork as artist or model (authenticated, consent required). */
    public function claim(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        $this->auth->requireConsent();

        $artworkId = (int) $request->param('id');
        $claimType = $request->input('claim_type', 'artist');

        if (!in_array($claimType, ['artist', 'model'], true)) {
            $claimType = 'artist';
        }

        // Check artwork exists
        $artwork = $this->table('ld_artworks')->where('id', '=', $artworkId)->first();
        if (!$artwork) {
            return Response::notFound('Artwork not found.');
        }

        // Check not already claimed by this user in this type
        $existing = $this->table('ld_claims')
            ->where('artwork_id', '=', $artworkId)
            ->where('claimant_id', '=', $this->userId())
            ->where('claim_type', '=', $claimType)
            ->first();

        if ($existing) {
            if ($request->isHtmx()) {
                return Response::html('<span class="badge badge-muted">Already claimed</span>');
            }
            return Response::redirect(route('sessions.show', ['id' => $artwork['session_id']]));
        }

        $claimId = (int) $this->table('ld_claims')->insert([
            'artwork_id' => $artworkId,
            'claimant_id' => $this->userId(),
            'claim_type' => $claimType,
            'status' => 'pending',
        ]);

        $this->provenance->log(
            $this->userId(),
            'artwork.claim',
            'artwork',
            $artworkId,
            ['claim_id' => $claimId, 'claim_type' => $claimType]
        );

        if ($request->isHtmx()) {
            return Response::html('<span class="badge badge-pending">Claim pending</span>');
        }

        return Response::redirect(route('sessions.show', ['id' => $artwork['session_id']]));
    }

    /** Approve or reject a claim (facilitator+). */
    public function resolve(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $claimId = (int) $request->param('id');
        $action = $request->input('action'); // 'approve' or 'reject'

        if (!in_array($action, ['approve', 'reject'], true)) {
            return Response::error('Invalid action.', 400);
        }

        $claim = $this->table('ld_claims')->where('id', '=', $claimId)->first();
        if (!$claim) {
            return Response::notFound('Claim not found.');
        }

        $newStatus = $action === 'approve' ? 'approved' : 'rejected';

        $this->table('ld_claims')
            ->where('id', '=', $claimId)
            ->update([
                'status' => $newStatus,
                'approved_by' => $this->userId(),
                'resolved_at' => date('Y-m-d H:i:s'),
            ]);

        // If approved, refresh claimant stats (artwork is already public —
        // consent happens in the room, claiming is for profile-building)
        if ($newStatus === 'approved') {
            app('stats')->refreshUser((int) $claim['claimant_id']);
        }

        $this->provenance->log(
            $this->userId(),
            "claim.{$action}",
            'artwork',
            (int) $claim['artwork_id'],
            ['claim_id' => $claimId, 'claimant_id' => $claim['claimant_id']]
        );

        if ($request->isHtmx()) {
            $label = $newStatus === 'approved' ? 'Approved' : 'Rejected';
            $class = $newStatus === 'approved' ? 'badge-success' : 'badge-error';
            return Response::html("<span class=\"badge {$class}\">{$label}</span>");
        }

        // Redirect back to the session
        $artwork = $this->table('ld_artworks')->where('id', '=', $claim['artwork_id'])->first();
        return Response::redirect(route('sessions.show', ['id' => $artwork['session_id'] ?? 0]));
    }

    /** List pending claims (facilitator view). */
    public function pending(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $claims = $this->db->fetchAll(
            "SELECT c.*, a.file_path, a.thumbnail_path, a.session_id,
                    s.title as session_title, s.session_date,
                    u.display_name as claimant_name
             FROM ld_claims c
             JOIN ld_artworks a ON c.artwork_id = a.id
             JOIN ld_sessions s ON a.session_id = s.id
             JOIN users u ON c.claimant_id = u.id
             WHERE c.status = 'pending'
             ORDER BY c.claimed_at DESC"
        );

        return $this->render('gallery.claims', [
            'claims' => $claims,
        ], 'Pending Claims');
    }
}
