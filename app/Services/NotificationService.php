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
        $parsed = parse_url(config('app.url', 'http://localhost'));
        $this->baseUrl = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? 'localhost')
            . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
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
     * Operational: new claim submitted.
     * Notifies facilitators so they can review and approve/reject.
     */
    public function claimSubmitted(int $artworkId, int $claimantId, string $claimType): void
    {
        $facilitators = $this->db->fetchAll(
            "SELECT id, email, display_name FROM users
             WHERE role IN ('admin', 'facilitator') AND id != ?
               AND email NOT LIKE '%.stub@local'",
            [$claimantId]
        );

        if (empty($facilitators)) return;

        $claimant = $this->db->fetch("SELECT display_name FROM users WHERE id = ?", [$claimantId]);
        $claimantName = $claimant['display_name'] ?? 'Someone';

        $artwork = $this->db->fetch(
            "SELECT a.id, s.title, s.session_date
             FROM ld_artworks a JOIN ld_sessions s ON a.session_id = s.id
             WHERE a.id = ?",
            [$artworkId]
        );
        $sessionTitle = $artwork ? session_title($artwork) : 'Unknown session';
        $claimsLink = $this->baseUrl . route('claims.pending');

        foreach ($facilitators as $user) {
            $body = "Hi {$user['display_name']},\n\n"
                . "{$claimantName} has submitted a {$claimType} claim on an artwork from \"{$sessionTitle}\".\n\n"
                . "Review pending claims: {$claimsLink}\n\n"
                . "— Life Drawing Randburg";

            $this->mail->send($user['email'], "New {$claimType} claim from {$claimantName}", $body);
        }
    }

    /**
     * Operational: stub account claimed during registration.
     * Notifies facilitators of the provenance change.
     */
    public function stubClaimed(int $stubId, string $newName, string $newEmail, string $previousName, int $sessionCount): void
    {
        $facilitators = $this->db->fetchAll(
            "SELECT id, email, display_name FROM users
             WHERE role IN ('admin', 'facilitator')
               AND email NOT LIKE '%.stub@local'"
        );

        if (empty($facilitators)) return;

        $profileLink = $this->baseUrl . route('profiles.show', ['id' => hex_id($stubId)]);

        foreach ($facilitators as $user) {
            $body = "Hi {$user['display_name']},\n\n"
                . "{$newName} ({$newEmail}) has registered and claimed the stub account \"{$previousName}\" ({$sessionCount} sessions of history).\n\n"
                . "View their profile: {$profileLink}\n\n"
                . "— Life Drawing Randburg";

            $this->mail->send($user['email'], "Stub claimed: {$previousName} → {$newName}", $body);
        }
    }

    /**
     * Operational: someone joined the sitter queue.
     * Notifies facilitators so they can review and contact.
     */
    public function sitterQueueJoined(int $userId): void
    {
        $facilitators = $this->db->fetchAll(
            "SELECT id, email, display_name FROM users
             WHERE role IN ('admin', 'facilitator') AND id != ?
               AND email NOT LIKE '%.stub@local'",
            [$userId]
        );

        if (empty($facilitators)) return;

        $user = $this->db->fetch(
            "SELECT display_name, whatsapp_number, sitter_pref_friday, sitter_pref_saturday, sitter_pref_sunday
             FROM users WHERE id = ?",
            [$userId]
        );

        $userName = $user['display_name'] ?? 'Someone';
        $whatsapp = $user['whatsapp_number'] ?? 'not provided';
        $days = [];
        if ($user['sitter_pref_friday'] ?? false) $days[] = 'Fri';
        if ($user['sitter_pref_saturday'] ?? false) $days[] = 'Sat';
        if ($user['sitter_pref_sunday'] ?? false) $days[] = 'Sun';
        $dayStr = $days ? implode(', ', $days) : 'none specified';
        $queueLink = $this->baseUrl . route('pose.queue');

        foreach ($facilitators as $fac) {
            $body = "Hi {$fac['display_name']},\n\n"
                . "{$userName} has joined the sitter queue.\n\n"
                . "WhatsApp: {$whatsapp}\n"
                . "Available: {$dayStr}\n\n"
                . "View the queue: {$queueLink}\n\n"
                . "— Life Drawing Randburg";

            $this->mail->send($fac['email'], "Sitter queue: {$userName} wants to pose", $body);
        }
    }

    /**
     * Targeted: sitter session completed, invite to rejoin queue.
     */
    public function sitterSessionCompleted(int $userId, bool $autoRejoined): void
    {
        $user = $this->db->fetch(
            "SELECT id, email, display_name FROM users WHERE id = ?",
            [$userId]
        );

        if (!$user) return;
        if (str_ends_with($user['email'], '.stub@local')) return;

        $poseLink = $this->baseUrl . route('pose.index');

        if ($autoRejoined) {
            $body = "Hi {$user['display_name']},\n\n"
                . "Thank you for posing at Life Drawing Randburg!\n\n"
                . "You've been automatically added back to the sitter queue (auto-rejoin is on).\n"
                . "If you'd like to adjust your preferences or withdraw: {$poseLink}\n\n"
                . "— Life Drawing Randburg";
        } else {
            $body = "Hi {$user['display_name']},\n\n"
                . "Thank you for posing at Life Drawing Randburg!\n\n"
                . "If you'd like to pose again, you're welcome to rejoin the queue:\n"
                . "{$poseLink}\n\n"
                . "— Life Drawing Randburg";
        }

        $this->mail->send($user['email'], "Thanks for posing — rejoin the queue?", $body);
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
