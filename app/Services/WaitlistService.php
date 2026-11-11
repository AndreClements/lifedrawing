<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use DomainException;

/**
 * Waiting list for full sessions.
 *
 * A separate table from bookings on purpose (see migration 025): nothing that
 * reads ld_session_participants can mistake a waiting person for a booked one.
 *
 * Invariant: a person never has both an artist booking and a waiting-list entry
 * for the same session. Every path that creates an artist booking calls
 * clearFor(), and every check below runs under the session row lock taken by
 * SessionAccess::session(..., lock: true), the same lock bookings and
 * cancellations take.
 *
 * Only sessions that publish their capacity have a waiting list. An unpublished
 * capacity means places are also sold through other channels, so the site
 * cannot know when the session is full.
 */
final class WaitlistService
{
    private ?NotificationService $notifications;

    public function __construct(private readonly Connection $db, ?NotificationService $notifications = null)
    {
        $this->notifications = $notifications;
    }

    // --- Reading ---------------------------------------------------------

    public static function artistCount(Connection $db, int $sessionId): int
    {
        return (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM ld_session_participants WHERE session_id = ? AND role = 'artist'",
            [$sessionId]
        );
    }

    /** Open artist places. Zero when capacity is unpublished: the site cannot know. */
    public static function openPlaces(Connection $db, array $session): int
    {
        if (empty($session['capacity_published'])) return 0;
        $capacity = (int) ($session['max_capacity'] ?? 7);
        return max(0, $capacity - self::artistCount($db, (int) $session['id']));
    }

    /** Full means a published capacity with every artist place taken (tentative included, as "X/7" shows). */
    public static function isFull(Connection $db, array $session): bool
    {
        return !empty($session['capacity_published'])
            && self::artistCount($db, (int) $session['id']) >= (int) ($session['max_capacity'] ?? 7);
    }

    /** 1-based position, or null when not waiting. */
    public function position(int $sessionId, int $userId): ?int
    {
        $id = $this->db->fetchColumn("SELECT id FROM ld_session_waitlist WHERE session_id = ? AND user_id = ?", [$sessionId, $userId]);
        if (!$id) return null;
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM ld_session_waitlist WHERE session_id = ? AND id <= ?", [$sessionId, $id]);
    }

    public function count(int $sessionId): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM ld_session_waitlist WHERE session_id = ?", [$sessionId]);
    }

    /** Entries in queue order, with names for the facilitator's views. */
    public function listing(int $sessionId): array
    {
        return $this->db->fetchAll(
            "SELECT w.id, w.user_id, w.created_at, u.display_name, u.consent_state
             FROM ld_session_waitlist w JOIN users u ON u.id = w.user_id
             WHERE w.session_id = ? ORDER BY w.id ASC",
            [$sessionId]
        );
    }

    // --- Self-service ------------------------------------------------------

    /**
     * An artist's own "join": a place if there is one, otherwise the waiting list.
     *
     * The fullness check runs under the session lock, so two people racing for
     * the last place get one booking and one waiting-list entry, never two
     * bookings. Repeating the request changes nothing.
     *
     * @return 'booked'|'waitlisted'|'unchanged'
     */
    public function joinOrBook(int $sessionId, int $userId): string
    {
        return $this->db->transaction(function () use ($sessionId, $userId) {
            $session = SessionAccess::session($this->db, $sessionId, true);
            if (time() >= session_starts_at($session)) throw new DomainException('That session has already started.', 422);
            if ($this->hasArtistBooking($sessionId, $userId) || $this->entry($sessionId, $userId)) return 'unchanged';

            if (self::isFull($this->db, $session)) {
                $this->insertEntry($sessionId, $userId, $userId);
                (new ProvenanceService($this->db))->record($userId, 'waitlist.join', 'session', $sessionId, ['user_id' => $userId]);
                return 'waitlisted';
            }

            $booked = (new ParticipationService($this->db))->add($sessionId, $userId, $userId, 'artist');
            return $booked ? 'booked' : 'unchanged';
        });
    }

    /** Take yourself off a waiting list. Allowed on a cancelled session too: undoing is never harder than doing. */
    public function leave(int $sessionId, int $userId): bool
    {
        return $this->db->transaction(function () use ($sessionId, $userId) {
            SessionAccess::session($this->db, $sessionId, true, true);
            $entry = $this->entry($sessionId, $userId, true);
            if (!$entry) return false;
            $this->db->execute("DELETE FROM ld_session_waitlist WHERE id = ?", [$entry['id']]);
            (new ProvenanceService($this->db))->record($userId, 'waitlist.leave', 'session', $sessionId, ['user_id' => $userId]);
            return true;
        });
    }

    // --- Facilitator -------------------------------------------------------

    /**
     * Put someone on the waiting list. Only for a full session with a published
     * capacity: with an open place the facilitator should book them, and with an
     * unpublished capacity there is no waiting list.
     *
     * @return 'waitlisted'|'unchanged'
     */
    public function add(int $sessionId, int $actorId, int $userId): string
    {
        SessionAccess::requireManager($this->db, $actorId);
        return $this->db->transaction(function () use ($sessionId, $actorId, $userId) {
            $session = SessionAccess::session($this->db, $sessionId, true);
            if (!$this->db->fetchColumn("SELECT id FROM users WHERE id = ?", [$userId])) throw new DomainException('User not found.', 404);
            if ($this->hasArtistBooking($sessionId, $userId) || $this->entry($sessionId, $userId)) return 'unchanged';
            if (empty($session['capacity_published'])) {
                throw new DomainException('This session does not publish its capacity, so it has no waiting list. Book them directly.', 422);
            }
            if (!self::isFull($this->db, $session)) {
                throw new DomainException('This session still has open places. Book them directly.', 422);
            }
            $this->insertEntry($sessionId, $userId, $actorId);
            (new ProvenanceService($this->db))->record($actorId, 'waitlist.add', 'session', $sessionId, ['user_id' => $userId]);
            return 'waitlisted';
        });
    }

    public function remove(int $sessionId, int $actorId, int $entryId): ?array
    {
        SessionAccess::requireManager($this->db, $actorId);
        return $this->db->transaction(function () use ($sessionId, $actorId, $entryId) {
            SessionAccess::session($this->db, $sessionId, true, true);
            $entry = $this->db->fetch("SELECT * FROM ld_session_waitlist WHERE id = ? AND session_id = ? FOR UPDATE", [$entryId, $sessionId]);
            if (!$entry) return null;
            $this->db->execute("DELETE FROM ld_session_waitlist WHERE id = ?", [$entryId]);
            (new ProvenanceService($this->db))->record($actorId, 'waitlist.remove', 'session', $sessionId, ['user_id' => (int) $entry['user_id']]);
            return $entry;
        });
    }

    /**
     * Give a waiting person a place by hand, out of turn and even past capacity:
     * the facilitator's call. It counts as a promotion, so the person gets the
     * same email and the same late-cancellation exemption.
     */
    public function promote(int $sessionId, int $actorId, int $entryId): ?int
    {
        SessionAccess::requireManager($this->db, $actorId);
        return $this->db->transaction(function () use ($sessionId, $actorId, $entryId) {
            $session = SessionAccess::session($this->db, $sessionId, true);
            if (time() >= session_starts_at($session)) throw new DomainException('That session has already started.', 422);
            $entry = $this->db->fetch("SELECT * FROM ld_session_waitlist WHERE id = ? AND session_id = ? FOR UPDATE", [$entryId, $sessionId]);
            if (!$entry) return null;
            if ($this->hasArtistBooking($sessionId, (int) $entry['user_id'])) {
                $this->clearFor($sessionId, (int) $entry['user_id'], $actorId);
                return null;
            }
            return $this->promoteEntry($session, $entry, $actorId);
        });
    }

    // --- Called from other write paths, inside their locked transaction ----

    /** Keep the invariant: whoever now holds an artist booking is no longer waiting. */
    public function clearFor(int $sessionId, int $userId, ?int $actorId): void
    {
        $entry = $this->entry($sessionId, $userId, true);
        if (!$entry) return;
        $this->db->execute("DELETE FROM ld_session_waitlist WHERE id = ?", [$entry['id']]);
        (new ProvenanceService($this->db))->record($actorId, 'waitlist.clear', 'session', $sessionId,
            ['user_id' => $userId, 'reason' => 'artist_booking']);
    }

    /**
     * Move people from the waiting list into open places, first in line first.
     *
     * Must run inside a transaction that already holds the session row lock.
     * Re-reads the session so a capacity change saved earlier in the same
     * transaction is seen. Returns the promoted user ids, for stats refresh
     * after commit.
     *
     * @return int[]
     */
    public function fillOpenPlaces(int $sessionId): array
    {
        $session = $this->db->fetch("SELECT * FROM ld_sessions WHERE id = ?", [$sessionId]);
        if (!$session || !in_array($session['status'], ['scheduled', 'active'], true)) return [];
        if (time() >= session_starts_at($session)) return [];

        $promoted = [];
        while (self::openPlaces($this->db, $session) > 0) {
            $entry = $this->db->fetch("SELECT * FROM ld_session_waitlist WHERE session_id = ? ORDER BY id ASC LIMIT 1 FOR UPDATE", [$sessionId]);
            if (!$entry) break;
            $userId = (int) $entry['user_id'];

            // A stale entry beside an existing booking: clear it rather than
            // fail on the duplicate and roll back someone else's cancellation.
            if ($this->hasArtistBooking($sessionId, $userId)) {
                $this->clearFor($sessionId, $userId, null);
                continue;
            }

            $consent = $this->db->fetchColumn("SELECT consent_state FROM users WHERE id = ?", [$userId]);
            if ($consent === 'withdrawn') {
                $this->db->execute("DELETE FROM ld_session_waitlist WHERE id = ?", [$entry['id']]);
                (new ProvenanceService($this->db))->record(null, 'waitlist.skip', 'session', $sessionId,
                    ['user_id' => $userId, 'reason' => 'consent_withdrawn']);
                continue;
            }

            $promoted[] = $this->promoteEntry($session, $entry, null);
        }
        return $promoted;
    }

    // --- Internals -----------------------------------------------------------

    private function promoteEntry(array $session, array $entry, ?int $actorId): int
    {
        $sessionId = (int) $session['id'];
        $userId = (int) $entry['user_id'];
        // App-timezone time from PHP, not MySQL NOW(): the database clock runs
        // about nine hours behind SAST, and this is compared with the session
        // start to decide the late-cancellation exemption.
        $this->db->execute(
            "INSERT INTO ld_session_participants (session_id, user_id, role, tentative, promoted_at) VALUES (?, ?, 'artist', 0, ?)",
            [$sessionId, $userId, date('Y-m-d H:i:s')]
        );
        $this->db->execute("DELETE FROM ld_session_waitlist WHERE id = ?", [$entry['id']]);
        (new ProvenanceService($this->db))->record($actorId, 'waitlist.promote', 'session', $sessionId,
            ['user_id' => $userId, 'waited_since' => $entry['created_at']]);
        // Checked: if the notice cannot be queued, the promotion (and whatever
        // opened the place) rolls back rather than booking someone silently.
        $this->notifications()->waitlistPromoted($sessionId, $userId);
        return $userId;
    }

    private function insertEntry(int $sessionId, int $userId, ?int $addedBy): void
    {
        $this->db->execute(
            "INSERT INTO ld_session_waitlist (session_id, user_id, added_by, created_at) VALUES (?, ?, ?, ?)",
            [$sessionId, $userId, $addedBy, (new \DateTimeImmutable())->format('Y-m-d H:i:s.u')]
        );
    }

    private function entry(int $sessionId, int $userId, bool $lock = false): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM ld_session_waitlist WHERE session_id = ? AND user_id = ?" . ($lock ? ' FOR UPDATE' : ''),
            [$sessionId, $userId]
        ) ?: null;
    }

    private function hasArtistBooking(int $sessionId, int $userId): bool
    {
        return (bool) $this->db->fetchColumn(
            "SELECT id FROM ld_session_participants WHERE session_id = ? AND user_id = ? AND role = 'artist'",
            [$sessionId, $userId]
        );
    }

    private function notifications(): NotificationService
    {
        return $this->notifications ??= app('notifications');
    }
}
