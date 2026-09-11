<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

/**
 * Email notification service.
 *
 * Checks user preferences before sending. All prefs default to off (opt-in).
 * Buffered notifications are queued to ld_notification_queue and sent by
 * tools/flush_notifications.php. Immediate notifications send synchronously.
 * This service never throws — enqueue failures are logged.
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
             WHERE notify_new_session = 1 AND id != ? AND email NOT LIKE '%.stub@local'
               AND consent_state != 'withdrawn'",
            [$creatorId]
        );

        if (empty($recipients)) return;

        $title = session_title($session);
        $date = format_date($session['session_date']);
        $venue = $session['venue'] ?? 'TBA';
        $hexId = hex_id((int) $session['id'], $title);
        $link = $this->baseUrl . route('sessions.show', ['id' => $hexId]);
        $sessionId = (int) $session['id'];
        $footer = "You're receiving this because you opted in to new session notifications.\n"
            . "Update your preferences: {$this->baseUrl}" . route('profiles.edit');

        foreach ($recipients as $user) {
            $this->enqueue(
                (int) $user['id'],
                $user['display_name'],
                $user['email'],
                'sessionCreated',
                "New Session: {$title} — {$date}",
                "A new drawing session has been scheduled:\n\n{$title}\n{$date} at {$venue}",
                $sessionId,
                "View details and join: {$link}",
                $footer
            );
        }
    }

    /**
     * Targeted: session cancelled. IMMEDIATE — not buffered.
     * Notifies participants of this session who have notify_session_cancelled=1.
     */
    public function sessionCancelled(array $session): void
    {
        $recipients = $this->db->fetchAll(
            "SELECT DISTINCT u.id, u.email, u.display_name
             FROM users u
             JOIN ld_session_participants sp ON sp.user_id = u.id
             WHERE sp.session_id = ? AND u.notify_session_cancelled = 1
               AND u.email NOT LIKE '%.stub@local'
               AND u.consent_state != 'withdrawn'",
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
             FROM users WHERE id = ? AND consent_state != 'withdrawn'",
            [$claim['claimant_id']]
        );

        if (!$claimant || !$claimant['notify_claim_resolved']) return;
        if (str_ends_with($claimant['email'], '.stub@local')) return;

        $artwork = $this->db->fetch(
            "SELECT s.id, s.title, s.session_date, a.session_id
             FROM ld_artworks a JOIN ld_sessions s ON a.session_id = s.id
             WHERE a.id = ?",
            [$artworkId]
        );

        $sessionTitle = $artwork ? session_title($artwork) : 'Unknown session';
        $artworkLink = $this->baseUrl . route('artworks.show', ['id' => hex_id($artworkId)]);
        $action = $status === 'approved' ? 'approved' : 'rejected';
        $emoji = $status === 'approved' ? 'Great news!' : 'Unfortunately,';
        $subject = "Claim " . ucfirst($action) . ": {$sessionTitle}";
        $footer = "You're receiving this because you opted in to claim notifications.\n"
            . "Update your preferences: {$this->baseUrl}" . route('profiles.edit');

        $this->enqueue(
            (int) $claimant['id'],
            $claimant['display_name'],
            $claimant['email'],
            'claimResolved',
            $subject,
            "{$emoji} Your {$claim['claim_type']} claim on an artwork from \"{$sessionTitle}\" has been {$action}.",
            $artwork ? (int) $artwork['session_id'] : null,
            "View the artwork: {$artworkLink}",
            $footer,
            isset($claim['id']) ? 'claim' : 'artwork',
            isset($claim['id']) ? (int) $claim['id'] : $artworkId
        );
    }

    /**
     * Operational: new claim submitted.
     * Notifies facilitators so they can review and approve/reject.
     */
    public function claimSubmitted(int $artworkId, int $claimantId, string $claimType, ?int $claimId = null): void
    {
        $facilitators = $this->db->fetchAll(
            "SELECT id, email, display_name FROM users
             WHERE role IN ('admin', 'facilitator') AND id != ?
               AND email NOT LIKE '%.stub@local'
               AND consent_state != 'withdrawn'",
            [$claimantId]
        );

        if (empty($facilitators)) return;

        $claimant = $this->db->fetch("SELECT display_name FROM users WHERE id = ?", [$claimantId]);
        $claimantName = $claimant['display_name'] ?? 'Someone';

        $artwork = $this->db->fetch(
            "SELECT s.id, s.title, s.session_date, a.session_id
             FROM ld_artworks a JOIN ld_sessions s ON a.session_id = s.id
             WHERE a.id = ?",
            [$artworkId]
        );
        $sessionTitle = $artwork ? session_title($artwork) : 'Unknown session';
        $claimsLink = $this->baseUrl . route('claims.pending');

        foreach ($facilitators as $user) {
            $this->enqueue(
                (int) $user['id'],
                $user['display_name'],
                $user['email'],
                'claimSubmitted',
                "New {$claimType} claim from {$claimantName}",
                "{$claimantName} has submitted a {$claimType} claim on an artwork from \"{$sessionTitle}\".",
                $artwork ? (int) $artwork['session_id'] : null,
                "Review pending claims: {$claimsLink}",
                null,
                $claimId !== null ? 'claim' : 'artwork',
                $claimId ?? $artworkId
            );
        }
    }

    /**
     * Operational: someone booked a place at a session.
     *
     * Fired from BOTH join paths — SessionController::join() and the
     * register-with-intent path in AuthController. They are separate code, and
     * missing the second would silence exactly the bookings André most wants to
     * hear about: the ones from people who have only just signed up.
     */
    public function sessionJoined(int $sessionId, int $userId, string $role): void
    {
        $facilitators = $this->facilitators($userId);
        if (empty($facilitators)) return;

        $session = $this->db->fetch("SELECT * FROM ld_sessions WHERE id = ?", [$sessionId]);
        if (!$session) return;

        $user = $this->db->fetch("SELECT display_name FROM users WHERE id = ?", [$userId]);
        $name = $user['display_name'] ?? 'Someone';
        $title = session_title($session);
        $date = format_date($session['session_date']);
        $link = $this->baseUrl . route('sessions.show', ['id' => hex_id($sessionId, $title)]);

        $artists = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM ld_session_participants WHERE session_id = ? AND role = 'artist'",
            [$sessionId]
        );

        foreach ($facilitators as $fac) {
            $this->enqueue(
                (int) $fac['id'],
                $fac['display_name'],
                $fac['email'],
                'sessionJoined',
                "Booking: {$name} for {$title}",
                "{$name} booked a place as {$role}.

{$title}
{$date}
Artists now booked: {$artists}",
                $sessionId,
                "View the session: {$link}",
                null,
                'session',
                $sessionId
            );
        }
    }

    /**
     * Operational: someone cancelled their own booking.
     *
     * The other half of the loop. Without it a cancellation is silent and the
     * only clue is a number that quietly went down. $lateNotice flags a
     * cancellation inside 48 hours, which is the case worth seeing at a glance.
     */
    public function sessionLeft(int $sessionId, int $userId, string $role, bool $lateNotice): void
    {
        $facilitators = $this->facilitators($userId);
        if (empty($facilitators)) return;

        $session = $this->db->fetch("SELECT * FROM ld_sessions WHERE id = ?", [$sessionId]);
        if (!$session) return;

        $user = $this->db->fetch("SELECT display_name FROM users WHERE id = ?", [$userId]);
        $name = $user['display_name'] ?? 'Someone';
        $title = session_title($session);
        $date = format_date($session['session_date']);
        $link = $this->baseUrl . route('sessions.show', ['id' => hex_id($sessionId, $title)]);

        $artists = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM ld_session_participants WHERE session_id = ? AND role = 'artist'",
            [$sessionId]
        );

        $late = $lateNotice
            ? "

This is inside 48 hours of the session."
            : '';

        foreach ($facilitators as $fac) {
            $this->enqueue(
                (int) $fac['id'],
                $fac['display_name'],
                $fac['email'],
                'sessionLeft',
                "Cancellation: {$name} for {$title}",
                "{$name} cancelled their {$role} booking.

{$title}
{$date}
Artists still booked: {$artists}{$late}",
                $sessionId,
                "View the session: {$link}",
                null,
                'session',
                $sessionId
            );
        }
    }

    /**
     * Operational: a new account was created.
     *
     * Plain registrations only. Claiming a stub already sends stubClaimed, and
     * two emails about one person arriving reads as a bug.
     */
    public function userRegistered(int $userId): void
    {
        $facilitators = $this->facilitators($userId);
        if (empty($facilitators)) return;

        $user = $this->db->fetch("SELECT display_name, email FROM users WHERE id = ?", [$userId]);
        if (!$user) return;

        $profileLink = $this->baseUrl . route('profiles.show', ['id' => hex_id($userId)]);

        foreach ($facilitators as $fac) {
            $this->enqueue(
                (int) $fac['id'],
                $fac['display_name'],
                $fac['email'],
                'userRegistered',
                "New member: {$user['display_name']}",
                "{$user['display_name']} ({$user['email']}) has registered.

"
                    . "They did not claim an existing stub account, so if they have been to "
                    . "sessions before, their history may need merging.",
                null,
                "View their profile: {$profileLink}",
                null,
                'user',
                $userId
            );
        }
    }

    /** Facilitator recipients for operational mail, excluding one user. */
    private function facilitators(int $excludeUserId): array
    {
        return $this->db->fetchAll(
            "SELECT id, email, display_name FROM users
             WHERE role IN ('admin', 'facilitator') AND id != ?
               AND email NOT LIKE '%.stub@local'
               AND consent_state != 'withdrawn'",
            [$excludeUserId]
        );
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
               AND email NOT LIKE '%.stub@local'
               AND consent_state != 'withdrawn'"
        );

        if (empty($facilitators)) return;

        $profileLink = $this->baseUrl . route('profiles.show', ['id' => hex_id($stubId)]);

        foreach ($facilitators as $user) {
            $this->enqueue(
                (int) $user['id'],
                $user['display_name'],
                $user['email'],
                'stubClaimed',
                "Stub claimed: {$previousName} → {$newName}",
                "{$newName} ({$newEmail}) has registered and claimed the stub account \"{$previousName}\" ({$sessionCount} sessions of history).",
                null,
                "View their profile: {$profileLink}"
            );
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
               AND email NOT LIKE '%.stub@local'
               AND consent_state != 'withdrawn'",
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
            $this->enqueue(
                (int) $fac['id'],
                $fac['display_name'],
                $fac['email'],
                'sitterQueueJoined',
                "Sitter queue: {$userName} wants to pose",
                "{$userName} has joined the sitter queue.\n\nWhatsApp: {$whatsapp}\nAvailable: {$dayStr}",
                null,
                "View the queue: {$queueLink}"
            );
        }
    }

    /**
     * Targeted: sitter session completed, invite to rejoin queue. IMMEDIATE — not buffered.
     */
    public function sitterSessionCompleted(int $userId, bool $autoRejoined): void
    {
        $user = $this->db->fetch(
            "SELECT id, email, display_name FROM users
             WHERE id = ? AND consent_state != 'withdrawn'",
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
               AND u.email NOT LIKE '%.stub@local'
               AND u.consent_state != 'withdrawn'",
            [$artworkId, $commenterId]
        );

        if (empty($claimants)) return;

        $commenter = $this->db->fetch("SELECT display_name FROM users WHERE id = ?", [$commenterId]);
        $commenterName = $commenter['display_name'] ?? 'Someone';
        $snippet = mb_strlen($commentBody) > 100 ? mb_substr($commentBody, 0, 100) . '...' : $commentBody;
        $artworkLink = $this->baseUrl . route('artworks.show', ['id' => hex_id($artworkId)]) . '#comments';
        $footer = "You're receiving this because you opted in to comment notifications.\n"
            . "Update your preferences: {$this->baseUrl}" . route('profiles.edit');

        // Get session_id for cohort grouping
        $artwork = $this->db->fetch(
            "SELECT session_id FROM ld_artworks WHERE id = ?",
            [$artworkId]
        );

        foreach ($claimants as $user) {
            $this->enqueue(
                (int) $user['id'],
                $user['display_name'],
                $user['email'],
                'artworkCommented',
                "New Comment on Your Artwork",
                "{$commenterName} commented on artwork you've claimed:\n\n\"{$snippet}\"",
                $artwork ? (int) $artwork['session_id'] : null,
                "View the conversation: {$artworkLink}",
                $footer,
                'artwork',
                $artworkId
            );
        }
    }

    /**
     * Operational, IMMEDIATE: a consent withdrawal could not fully remove files
     * from the public tree.
     *
     * Not queued. A queued digest would arrive up to seven minutes later, and
     * this is the one failure where the gap between happening and being told
     * matters, because the images are still being served the whole time.
     */
    public function withdrawalIncomplete(int $userId, array $remainingPaths): void
    {
        $facilitators = $this->db->fetchAll(
            "SELECT id, email, display_name FROM users
             WHERE role IN ('admin', 'facilitator')
               AND email NOT LIKE '%.stub@local'"
        );

        if (empty($facilitators)) return;

        $user = $this->db->fetch("SELECT display_name FROM users WHERE id = ?", [$userId]);
        $name = $user['display_name'] ?? "User #{$userId}";
        $list = implode("
", array_map(fn($p) => '  - ' . $p, $remainingPaths));

        foreach ($facilitators as $fac) {
            $body = "Hi {$fac['display_name']},

"
                . "{$name} withdrew consent, but these files could NOT be moved out of "
                . "the public uploads directory and are still reachable by direct URL:

"
                . "{$list}

"
                . "The database rows are already hidden, so the images no longer appear "
                . "anywhere on the site. The files themselves still need removing by hand.

"
                . "— Life Drawing Randburg";

            $this->mail->send($fac['email'], "Action needed: consent withdrawal incomplete", $body);
        }
    }

    /**
     * Cancel unsent queued notifications for one source.
     *
     * Only unsent rows are touched — a delivered email cannot be recalled.
     * Returns the number cancelled.
     */
    public function cancelQueued(string $sourceType, int $sourceId): int
    {
        try {
            return $this->db->execute(
                "DELETE FROM ld_notification_queue
                 WHERE sent_at IS NULL AND source_type = ? AND source_id = ?",
                [$sourceType, $sourceId]
            );
        } catch (\Throwable $e) {
            error_log("Notification cancel failed ({$sourceType} #{$sourceId}): " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Cancel everything queued about an artwork, including mail raised by claims
     * on it. Cancelling only the artwork-origin rows would leave claim mail
     * pointing at a page that no longer resolves.
     *
     * And-Yet: rows queued before migration 020 carry a NULL source and cannot be
     * matched here. The cutover purge clears those once; after it, this is complete.
     */
    public function cancelQueuedForArtwork(int $artworkId): int
    {
        $cancelled = $this->cancelQueued('artwork', $artworkId);

        try {
            $cancelled += $this->db->execute(
                "DELETE FROM ld_notification_queue
                 WHERE sent_at IS NULL AND source_type = 'claim'
                   AND source_id IN (SELECT id FROM ld_claims WHERE artwork_id = ?)",
                [$artworkId]
            );
        } catch (\Throwable $e) {
            error_log("Notification cancel (claims of artwork #{$artworkId}) failed: " . $e->getMessage());
        }

        return $cancelled;
    }

    /**
     * Queue a notification for batched delivery.
     *
     * $sourceType/$sourceId tie the row back to the artwork or claim that caused
     * it, so deleting that thing can cancel the mail before it goes out. Rows
     * queued before migration 020 carry NULL and cannot be cancelled.
     *
     * Never throws — failures are logged.
     */
    private function enqueue(
        int $recipientId,
        string $recipientName,
        string $recipientEmail,
        string $type,
        string $subject,
        string $summary,
        ?int $sessionId = null,
        ?string $detail = null,
        ?string $footer = null,
        ?string $sourceType = null,
        ?int $sourceId = null
    ): void {
        try {
            $this->db->execute(
                "INSERT INTO ld_notification_queue
                 (recipient_id, recipient_name, recipient_email, notification_type, session_id,
                  source_type, source_id, subject, summary, detail, footer)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$recipientId, $recipientName, $recipientEmail, $type, $sessionId,
                 $sourceType, $sourceId, $subject, $summary, $detail, $footer]
            );
        } catch (\Throwable $e) {
            error_log("Notification enqueue failed ({$type} to {$recipientEmail}): " . $e->getMessage());
        }
    }
}
