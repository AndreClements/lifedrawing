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

        $artworkId = from_hex($request->param('id'));
        $claimType = $request->input('claim_type', 'artist');

        if (!in_array($claimType, ['artist', 'model'], true)) {
            $claimType = 'artist';
        }

        // Check artwork exists AND is still claimable. Without the visibility
        // filter a direct POST can claim a deleted or consent-withdrawn artwork
        // and raise a facilitator alert about it.
        $artwork = $this->table('ld_artworks')
            ->where('id', '=', $artworkId)
            ->whereIn('visibility', ['session', 'claimed', 'public'])
            ->first();
        if (!$artwork) {
            return Response::notFound('Artwork not found.');
        }

        if ($claimType === 'model') {
            $modelClaimContext = $this->sessionModelClaimContext((int) $artwork['session_id']);
            if ($modelClaimContext['sessionHasKnownModel'] && !$modelClaimContext['isSessionModel']) {
                return $this->denyModelClaim($request);
            }
        }

        // Check not already claimed by this user in this type
        $existing = $this->table('ld_claims')
            ->where('artwork_id', '=', $artworkId)
            ->where('claimant_id', '=', $this->userId())
            ->where('claim_type', '=', $claimType)
            ->first();

        // The old guard matched on ANY status, so once a row existed in any
        // state it blocked this person forever - and answered "Already claimed",
        // which was the wrong thing to say about a claim they had withdrawn.
        //
        // A pending withdrawal deletes its row, so the only survivors that can
        // block are 'withdrawn' and 'rejected'. Those are resurrected, because
        // uk_artwork_claimant_type forbids inserting a second row alongside them.
        if ($existing && in_array($existing['status'], ['pending', 'approved'], true)) {
            if ($request->isHtmx()) {
                return $this->partial('gallery._claim_control', [
                    'artwork'   => $artwork,
                    'claimType' => $claimType,
                    'status'    => $existing['status'],
                    'claimId'   => (int) $existing['id'],
                ]);
            }
            return Response::redirect(route('artworks.show', ['id' => hex_id($artworkId)]));
        }

        if ($existing) {
            $claimId = (int) $existing['id'];
            $this->db->execute(
                "UPDATE ld_claims
                 SET status = 'pending', approved_by = NULL, resolved_at = NULL, claimed_at = NOW()
                 WHERE id = ?",
                [$claimId]
            );
        } else {
            $claimId = (int) $this->table('ld_claims')->insert([
                'artwork_id' => $artworkId,
                'claimant_id' => $this->userId(),
                'claim_type' => $claimType,
                'status' => 'pending',
            ]);
        }

        $this->provenance->log(
            $this->userId(),
            'artwork.claim',
            'artwork',
            $artworkId,
            ['claim_id' => $claimId, 'claim_type' => $claimType]
        );

        app('notifications')->claimSubmitted($artworkId, $this->userId(), $claimType, $claimId);

        if ($request->isHtmx()) {
            return $this->partial('gallery._claim_control', [
                'artwork'   => $artwork,
                'claimType' => $claimType,
                'status'    => 'pending',
                'claimId'   => $claimId,
            ]);
        }

        return Response::redirect(route('artworks.show', ['id' => hex_id($artworkId)]));
    }

    /**
     * POST /claims/{id}/withdraw - the claimant takes back their own claim.
     *
     * Two outcomes, because the two cases are genuinely different:
     *
     *   pending  - never established attribution, so the row simply ceases to
     *              be, and its queued facilitator alert is cancelled with it.
     *   approved - carries an approval history worth keeping, so it becomes
     *              'withdrawn' rather than disappearing.
     *
     * In both cases the status condition lives INSIDE the DML, never in a
     * preceding read. Otherwise an approval landing in the same moment could
     * cause an approved claim to be silently deleted.
     */
    public function withdraw(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;

        $claimId = from_hex($request->param('id'));

        $claim = $this->table('ld_claims')->where('id', '=', $claimId)->first();
        if (!$claim) {
            return Response::notFound('Claim not found.');
        }

        // The claimant's own action, so this is ownership - not the facilitator
        // IDOR rule that resolve() uses.
        if ((int) $claim['claimant_id'] !== $this->userId()) {
            return Response::forbidden('You can only withdraw your own claims.');
        }

        $artworkId = (int) $claim['artwork_id'];
        $artwork = $this->table('ld_artworks')->where('id', '=', $artworkId)->first();

        $previous = $claim['status'];
        $done = false;

        if ($previous === 'pending') {
            $done = $this->db->execute(
                "DELETE FROM ld_claims WHERE id = ? AND claimant_id = ? AND status = 'pending'",
                [$claimId, $this->userId()]
            ) > 0;

            if ($done) {
                // Claim then un-claim inside the digest window should not page
                // the facilitator about a claim that no longer exists.
                app('notifications')->cancelQueued('claim', $claimId);
            }
        } elseif ($previous === 'approved') {
            $done = $this->db->execute(
                "UPDATE ld_claims SET status = 'withdrawn', resolved_at = NOW()
                 WHERE id = ? AND claimant_id = ? AND status = 'approved'",
                [$claimId, $this->userId()]
            ) > 0;
        }

        if ($done) {
            $this->provenance->log(
                $this->userId(),
                'claim.withdraw',
                'artwork',
                $artworkId,
                ['claim_id' => $claimId, 'previous_status' => $previous]
            );

            // ld_artist_stats is a cached table. The derived read paths correct
            // themselves, but the cached totals do not.
            app('stats')->refreshUser($this->userId());
        }

        if ($request->isHtmx()) {
            // Render what is actually there, not what we hoped to do.
            //
            // $done is false when the conditional DELETE/UPDATE matched nothing:
            // the claim had already been rejected, already withdrawn, or changed
            // underneath us. Rendering 'unclaimed' in that case would show a
            // claim button for a claim that still exists, and tell the person
            // their withdrawal worked when it did not.
            $current = $done
                ? null
                : $this->table('ld_claims')->where('id', '=', $claimId)->first();

            return $this->partial('gallery._claim_control', [
                'artwork'   => $artwork,
                'claimType' => $claim['claim_type'],
                'status'    => $current && in_array($current['status'], ['pending', 'approved'], true)
                    ? $current['status']
                    : null,
                'claimId'   => $current && in_array($current['status'], ['pending', 'approved'], true)
                    ? (int) $current['id']
                    : null,
            ]);
        }

        return Response::redirect(route('artworks.show', ['id' => hex_id($artworkId)]));
    }

    /** Approve or reject a claim (facilitator+). */
    public function resolve(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $claimId = from_hex($request->param('id'));
        $action = $request->input('action'); // 'approve' or 'reject'

        if (!in_array($action, ['approve', 'reject'], true)) {
            return Response::error('Invalid action.', 400);
        }

        $claim = $this->table('ld_claims')->where('id', '=', $claimId)->first();
        if (!$claim) {
            return Response::notFound('Claim not found.');
        }

        // IDOR check: verify this facilitator owns the session (or is admin)
        $artwork = $this->table('ld_artworks')->where('id', '=', (int) $claim['artwork_id'])->first();
        if (!$artwork) {
            return Response::notFound('Associated artwork not found.');
        }
        if (in_array($artwork['visibility'], ['removed', 'private'], true)) {
            return Response::forbidden('That artwork is no longer available.');
        }
        $session = $this->table('ld_sessions')->where('id', '=', (int) $artwork['session_id'])->first();
        if (!$this->auth->hasRole('admin') && (int) ($session['facilitator_id'] ?? 0) !== $this->userId()) {
            return Response::forbidden('You can only resolve claims for your own sessions.');
        }

        $newStatus = $action === 'approve' ? 'approved' : 'rejected';

        // Guard inside the UPDATE, not in a preceding read. A claimant can
        // withdraw between this page rendering and the button being pressed;
        // without the status condition a stale page silently reinstates it.
        $affected = $this->db->execute(
            "UPDATE ld_claims SET status = ?, approved_by = ?, resolved_at = ?
             WHERE id = ? AND status = 'pending'",
            [$newStatus, $this->userId(), date('Y-m-d H:i:s'), $claimId]
        );

        if ($affected === 0) {
            $message = 'That claim is no longer pending — it may have been withdrawn or already resolved.';
            if ($request->isHtmx()) {
                return Response::html('<span class="badge badge-muted">' . e($message) . '</span>');
            }
            return Response::redirect(route('claims.pending'));
        }

        // Refresh either way: a reject must also drop the cached total, which
        // the original only did on approve.
        app('stats')->refreshUser((int) $claim['claimant_id']);

        $this->provenance->log(
            $this->userId(),
            "claim.{$action}",
            'artwork',
            (int) $claim['artwork_id'],
            ['claim_id' => $claimId, 'claimant_id' => $claim['claimant_id']]
        );

        // Notify claimant if they opted in
        app('notifications')->claimResolved($claim, $newStatus, (int) $claim['artwork_id']);

        if ($request->isHtmx()) {
            $label = $newStatus === 'approved' ? 'Approved' : 'Rejected';
            $class = $newStatus === 'approved' ? 'badge-success' : 'badge-error';
            return Response::html("<span class=\"badge {$class}\">{$label}</span>");
        }

        // Redirect back to the session
        $artwork = $this->table('ld_artworks')->where('id', '=', $claim['artwork_id'])->first();
        return Response::redirect(route('sessions.show', ['id' => $artwork['session_id'] ?? 0]));
    }

    private function denyModelClaim(Request $request): Response
    {
        $message = 'Only the scheduled model for this session can claim this artwork.';

        if ($request->wantsJson() || $request->isHtmx()) {
            return Response::json(['error' => $message], 403);
        }

        return Response::forbidden($message);
    }

    /** Approve all pending claims at once (facilitator+). */
    public function resolveAll(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $isAdmin = $this->auth->hasRole('admin');
        $claims = $this->db->fetchAll(
            "SELECT c.*, a.session_id
             FROM ld_claims c
             JOIN ld_artworks a ON c.artwork_id = a.id
             JOIN ld_sessions s ON a.session_id = s.id
             WHERE c.status = 'pending'
               AND a.visibility NOT IN ('removed', 'private')
               AND (s.facilitator_id = ? OR ? = 1)",
            [$this->userId(), $isAdmin ? 1 : 0]
        );

        $now = date('Y-m-d H:i:s');
        $refreshed = [];

        foreach ($claims as $claim) {
            $affected = $this->db->execute(
                "UPDATE ld_claims SET status = 'approved', approved_by = ?, resolved_at = ?
                 WHERE id = ? AND status = 'pending'",
                [$this->userId(), $now, (int) $claim['id']]
            );

            // Withdrawn between the page load and the button press — skip it
            // rather than reinstating something the claimant retracted.
            if ($affected === 0) {
                continue;
            }

            $this->provenance->log(
                $this->userId(),
                'claim.approve',
                'artwork',
                (int) $claim['artwork_id'],
                ['claim_id' => (int) $claim['id'], 'claimant_id' => $claim['claimant_id']]
            );

            app('notifications')->claimResolved($claim, 'approved', (int) $claim['artwork_id']);

            $claimantId = (int) $claim['claimant_id'];
            if (!in_array($claimantId, $refreshed, true)) {
                $refreshed[] = $claimantId;
            }
        }

        // Refresh stats AFTER all claims are approved (not mid-loop)
        foreach ($refreshed as $claimantId) {
            app('stats')->refreshUser($claimantId);
        }

        return Response::redirect(route('claims.pending'));
    }

    /** List pending claims (facilitator view). */
    public function pending(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $isAdmin = $this->auth->hasRole('admin');
        $claims = $this->db->fetchAll(
            "SELECT c.*, a.file_path, a.thumbnail_path, a.web_path, a.session_id,
                    s.title as session_title, s.session_date,
                    u.display_name as claimant_name
             FROM ld_claims c
             JOIN ld_artworks a ON c.artwork_id = a.id
             JOIN ld_sessions s ON a.session_id = s.id
             JOIN users u ON c.claimant_id = u.id
             WHERE c.status = 'pending'
               AND a.visibility NOT IN ('removed', 'private')
               AND (s.facilitator_id = ? OR ? = 1)
             ORDER BY c.claimed_at DESC",
            [$this->userId(), $isAdmin ? 1 : 0]
        );

        return $this->render('gallery.claims', [
            'claims' => $claims,
        ], 'Pending Claims');
    }
}
