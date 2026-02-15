<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

/**
 * Email notification service.
 *
 * Checks user preferences before sending. All prefs default to off (opt-in).
 * Failures are logged by MailService — this service never throws.
 */
final class NotificationService
{
    private string $baseUrl;

    public function __construct(
        private readonly MailService $mail,
        private readonly Connection $db,
    ) {
        $this->baseUrl = rtrim(config('app.url', ''), '/');
    }

    /**
     * Broadcast: new session created.
     * Notifies all users with notify_new_session=1 (excluding the creator).
     */
    public function sessionCreated(array $session, int $creatorId): void
    {
        $recipients = $this->db->fetchAll(
            "SELECT id, email, display_name FROM users
             WHERE notify_new_session = 1 AND id != ? AND email NOT LIKE '%.stub@local'",
            [$creatorId]
        );

        if (empty($recipients)) return;

        $title = session_title($session);
        $date = format_date($session['session_date']);
        $venue = $session['venue'] ?? 'TBA';
        $hexId = hex_id((int) $session['id'], $title);
        $link = $this->baseUrl . route('sessions.show', ['id' => $hexId]);

        foreach ($recipients as $user) {
            $body = "Hi {$user['display_name']},\n\n"
                . "A new drawing session has been scheduled:\n\n"
                . "{$title}\n"
                . "{$date} at {$venue}\n\n"
                . "View details and join: {$link}\n\n"
                . "— Life Drawing Randburg\n\n"
                . "You're receiving this because you opted in to new session notifications.\n"
                . "Update your preferences: {$this->baseUrl}" . route('profiles.edit');

            $this->mail->send($user['email'], "New Session: {$title} — {$date}", $body);
        }
    }

    /**
     * Targeted: session cancelled.
     * Notifies participants of this session who have notify_session_cancelled=1.
     */
    public function sessionCancelled(array $session): void
    {
        $recipients = $this->db->fetchAll(
            "SELECT DISTINCT u.id, u.email, u.display_name
             FROM users u
             JOIN ld_session_participants sp ON sp.user_id = u.id
             WHERE sp.session_id = ? AND u.notify_session_cancelled = 1
               AND u.email NOT LIKE '%.stub@local'",
            [$session['id']]
        );

        if (empty($recipients)) return;

        $title = session_title($session);
        $date = format_date($session['session_date']);

        foreach ($recipients as $user) {
            $body = "Hi {$user['display_name']},\n\n"
                . "Unfortunately, the following session has been cancelled:\n\n"
                . "{$title}\n"
                . "{$date}\n\n"
                . "We're sorry for the inconvenience. Check the sessions page for upcoming alternatives.\n\n"
                . "{$this->baseUrl}" . route('sessions.index') . "\n\n"
                . "— Life Drawing Randburg\n\n"
                . "You're receiving this because you opted in to cancellation notifications.\n"
                . "Update your preferences: {$this->baseUrl}" . route('profiles.edit');

            $this->mail->send($user['email'], "Session Cancelled: {$title} — {$date}", $body);
        }
    }

    /**
     * Targeted: claim approved or rejected.
     * Notifies the claimant if they have notify_claim_resolved=1.
     */
    public function claimResolved(array $claim, string $status, int $artworkId): void
    {
        $claimant = $this->db->fetch(
            "SELECT id, email, display_name, notify_claim_resolved
             FROM users WHERE id = ?",
            [$claim['claimant_id']]
        );

        if (!$claimant || !$claimant['notify_claim_resolved']) return;
        if (str_ends_with($claimant['email'], '.stub@local')) return;

        $artwork = $this->db->fetch(
            "SELECT a.id, a.session_id, s.title, s.session_date
             FROM ld_artworks a JOIN ld_sessions s ON a.session_id = s.id
             WHERE a.id = ?",
            [$artworkId]
        );

        $sessionTitle = $artwork ? session_title($artwork) : 'Unknown session';
        $artworkLink = $this->baseUrl . route('artworks.show', ['id' => hex_id($artworkId)]);
        $action = $status === 'approved' ? 'approved' : 'rejected';
        $emoji = $status === 'approved' ? 'Great news!' : 'Unfortunately,';

        $body = "Hi {$claimant['display_name']},\n\n"
            . "{$emoji} Your {$claim['claim_type']} claim on an artwork from \"{$sessionTitle}\" has been {$action}.\n\n"
            . "View the artwork: {$artworkLink}\n\n"
            . "— Life Drawing Randburg\n\n"
            . "You're receiving this because you opted in to claim notifications.\n"
            . "Update your preferences: {$this->baseUrl}" . route('profiles.edit');

        $subject = "Claim " . ucfirst($action) . ": {$sessionTitle}";
        $this->mail->send($claimant['email'], $subject, $body);
    }

    /**
     * Targeted: new comment on artwork.
     * Notifies claimed artist/model (excluding the commenter) if they have notify_comment=1.
     */
    public function artworkCommented(int $artworkId, int $commenterId, string $commentBody): void
    {
        // Find users who have approved claims on this artwork (artist or model)
        $claimants = $this->db->fetchAll(
            "SELECT DISTINCT u.id, u.email, u.display_name
             FROM users u
             JOIN ld_claims c ON c.claimant_id = u.id
             WHERE c.artwork_id = ? AND c.status = 'approved'
               AND u.id != ? AND u.notify_comment = 1
               AND u.email NOT LIKE '%.stub@local'",
            [$artworkId, $commenterId]
        );

        if (empty($claimants)) return;

        $commenter = $this->db->fetch("SELECT display_name FROM users WHERE id = ?", [$commenterId]);
        $commenterName = $commenter['display_name'] ?? 'Someone';
        $snippet = mb_strlen($commentBody) > 100 ? mb_substr($commentBody, 0, 100) . '...' : $commentBody;
        $artworkLink = $this->baseUrl . route('artworks.show', ['id' => hex_id($artworkId)]) . '#comments';

        foreach ($claimants as $user) {
            $body = "Hi {$user['display_name']},\n\n"
                . "{$commenterName} commented on artwork you've claimed:\n\n"
                . "\"{$snippet}\"\n\n"
                . "View the conversation: {$artworkLink}\n\n"
                . "— Life Drawing Randburg\n\n"
                . "You're receiving this because you opted in to comment notifications.\n"
                . "Update your preferences: {$this->baseUrl}" . route('profiles.edit');

            $this->mail->send($user['email'], "New Comment on Your Artwork", $body);
        }
    }
}
