<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

/**
 * Keeps ld_sitter_queue in step with who is actually booked as a model.
 *
 * The bug this exists to fix: André books sitters through the add-participant
 * typeahead on the session page, as role='model'. That wrote only to
 * ld_session_participants and never touched ld_sitter_queue, whose panel on the
 * session page is by its own docblock a "read-only reference". So every sitter
 * he ever booked that way stayed 'waiting' forever. Two tables recorded the
 * same real-world fact with no link between them.
 *
 * Every path that adds or removes a model now calls in here, including the
 * register-with-intent path, which is easy to miss and reproduces the original
 * bug for anyone who signs up and books in one go.
 *
 * classify() is deliberately the ONLY place the completion rules live. Live
 * auto-completion and the backlog repair tool both call it, so they cannot
 * drift apart — an earlier draft had them disagreeing about what to do with an
 * entry whose session had been deleted.
 */
final class SitterQueueService
{
    /** Never send "thanks for sitting" about something this far back. */
    public const NOTIFY_MAX_AGE_DAYS = 30;

    public function __construct(
        private readonly Connection $db,
    ) {}

    // --- Linkage -----------------------------------------------------------

    /**
     * A model was booked onto a session: resolve their queue entry.
     *
     * Silently does nothing when the person is not in the queue, which is the
     * normal case for a sitter booked without ever having joined it.
     */
    public function onModelAdded(int $userId, int $sessionId, ?int $actorId = null): void
    {
        // Same locking protocol as apply(). A path that reads unlocked and then
        // writes conditionally reintroduces the race for every other path:
        // two requests can read the same active entry and both act on it.
        $this->db->transaction(function () use ($userId, $sessionId, $actorId) {
            $entry = $this->activeEntry($userId, true);
            if (!$entry) {
                return;
            }

            $affected = $this->db->execute(
                "UPDATE ld_sitter_queue
                 SET status = 'scheduled', scheduled_session_id = ?, resolved_at = NOW(), resolved_by = ?
                 WHERE id = ? AND status IN ('waiting', 'scheduled')",
                [$sessionId, $actorId, (int) $entry['id']]
            );

            if ($affected > 0) {
                $this->log($actorId, 'sitter_queue.schedule', (int) $entry['id'], [
                    'user_id' => $userId, 'session_id' => $sessionId, 'via' => 'participant',
                ]);
            }
        });
    }

    /**
     * A model was removed from a session: put them back in the queue.
     *
     * They did not sit, so requested_at is preserved and they keep their place
     * in line rather than going to the back of it.
     *
     * UNLESS they are still booked elsewhere. One person has one queue entry,
     * yet a sitter is often booked for both the Saturday and the Sunday of one
     * weekend; naively reverting on any removal would put a still-booked sitter
     * back into the waiting list.
     */
    public function onModelRemoved(int $userId, int $sessionId, ?int $actorId = null): void
    {
        // Locked, for a specific reason: the "are they still booked elsewhere?"
        // read decides between repointing and requeueing. Read it unlocked and a
        // booking added concurrently is missed, and a sitter who IS still booked
        // gets dropped back into the waiting list.
        $this->db->transaction(function () use ($userId, $sessionId, $actorId) {
            $entry = $this->activeEntry($userId, true);
            if (!$entry) {
                return;
            }

            $other = $this->earliestFutureBooking($userId, $sessionId, true);

            if ($other !== null) {
                $this->db->execute(
                    "UPDATE ld_sitter_queue SET scheduled_session_id = ?
                     WHERE id = ? AND status = 'scheduled'",
                    [$other, (int) $entry['id']]
                );
                $this->log($actorId, 'sitter_queue.reschedule', (int) $entry['id'], [
                    'user_id' => $userId, 'from_session' => $sessionId, 'to_session' => $other,
                ]);
                return;
            }

            $affected = $this->db->execute(
                "UPDATE ld_sitter_queue
                 SET status = 'waiting', scheduled_session_id = NULL, resolved_at = NULL, resolved_by = NULL
                 WHERE id = ? AND status = 'scheduled'",
                [(int) $entry['id']]
            );

            if ($affected > 0) {
                $this->log($actorId, 'sitter_queue.unschedule', (int) $entry['id'], [
                    'user_id' => $userId, 'session_id' => $sessionId,
                ]);
            }
        });
    }

    // --- Classification ----------------------------------------------------

    /**
     * Decide what should happen to one queue entry.
     *
     * Returns ['action' => ..., 'expect_status' => ..., 'session_id' => ?int,
     *          'reason' => string]. Actions:
     *   complete   — they sat; close the entry (and auto-rejoin if they opted in)
     *   reschedule — point at a future booking
     *   requeue    — back to waiting; the sitting did not happen
     *   skip       — leave alone
     *
     * @param array $entry a row from ld_sitter_queue
     */
    public function classify(array $entry, bool $forUpdate = false): array
    {
        $userId    = (int) $entry['user_id'];
        $status    = (string) $entry['status'];
        $sessionId = $entry['scheduled_session_id'] !== null ? (int) $entry['scheduled_session_id'] : null;
        $today     = date('Y-m-d');

        $skip = fn(string $why) => [
            'action' => 'skip', 'expect_status' => $status, 'session_id' => $sessionId, 'reason' => $why,
        ];

        if (!in_array($status, ['waiting', 'scheduled'], true)) {
            return $skip('not an active entry');
        }

        // Preserving a future booking wins over every other rule. Completing or
        // requeueing someone who is booked next weekend would be simply wrong.
        $future = $this->earliestFutureBooking($userId, null, $forUpdate);
        if ($future !== null) {
            if ($status === 'scheduled' && $sessionId === $future) {
                return $skip('already scheduled for an upcoming session');
            }
            return [
                'action' => 'reschedule', 'expect_status' => $status,
                'session_id' => $future, 'reason' => 'booked as model for an upcoming session',
            ];
        }

        // A session that was deleted, or never recorded, never auto-completes:
        // the sitting demonstrably did not happen. The live sweep and the repair
        // tool must agree on this, which is why it lives here and not in either.
        if ($status === 'scheduled') {
            $session = $sessionId !== null
                ? $this->db->fetch("SELECT id, session_date FROM ld_sessions WHERE id = ?", [$sessionId])
                : null;

            if (!$session) {
                return [
                    'action' => 'requeue', 'expect_status' => 'scheduled', 'session_id' => null,
                    'reason' => $sessionId === null
                        ? 'scheduled with no session recorded'
                        : 'scheduled session no longer exists',
                ];
            }

            if ($session['session_date'] > $today) {
                return $skip('scheduled for a session still to come');
            }

            if ($this->wasNoShow($userId, (int) $session['id'], $forUpdate)) {
                return $skip('marked no-show — whether they rejoin is the facilitator\'s call');
            }

            return [
                'action' => 'complete', 'expect_status' => 'scheduled',
                'session_id' => (int) $session['id'], 'reason' => 'sat at a session that has passed',
            ];
        }

        // status === 'waiting': did they sit since asking?
        //
        // Evidence must match THIS request. Classifying on "was ever a model on
        // a past session" would complete an entry somebody opened last week on
        // the strength of a sitting from two years ago.
        $since = $entry['requested_at'] ?? null;
        if ($since === null) {
            return $skip('no requested_at to measure against');
        }

        $past = $this->db->fetch(
            "SELECT s.id, s.session_date
             FROM ld_session_participants sp
             JOIN ld_sessions s ON s.id = sp.session_id
             WHERE sp.user_id = ? AND sp.role = 'model'
               AND s.session_date <= ?
               AND s.session_date >= DATE(?)
               AND sp.attendance != 'no_show'
             ORDER BY s.session_date DESC
             LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''),
            [$userId, $today, $since]
        );

        if (!$past) {
            return $skip('no qualifying booking since they joined the queue');
        }

        // NOTE: this deliberately accepts attendance = 'booked'. Requiring
        // 'attended' would strand the entire backlog, because nothing has ever
        // written that column for a web booking.
        return [
            'action' => 'complete', 'expect_status' => 'waiting',
            'session_id' => (int) $past['id'], 'reason' => 'sat since joining the queue',
        ];
    }

    /**
     * Apply a decision from classify(), locking the row first.
     *
     * A transaction alone is not enough: another transaction can change
     * scheduled_session_id while leaving status = 'scheduled', so an
     * expected-status check would still pass and completion would land on an
     * entry that has since been rescheduled onto a different session. So the
     * row is locked FOR UPDATE and re-classified inside the transaction.
     *
     * Returns true when something changed.
     */
    public function apply(int $entryId, ?int $actorId, bool $notify): bool
    {
        return (bool) $this->db->transaction(function () use ($entryId, $actorId, $notify) {
            $entry = $this->db->fetch(
                "SELECT * FROM ld_sitter_queue WHERE id = ? FOR UPDATE",
                [$entryId]
            );
            if (!$entry) {
                return false;
            }

            // Locked reads: the queue row alone is not enough, because another
            // transaction can change a booking while leaving the queue status
            // untouched, and the expected-status check would still pass.
            $decision = $this->classify($entry, true);
            $action = $decision['action'];

            if ($action === 'skip') {
                return false;
            }

            if ($action === 'reschedule') {
                $n = $this->db->execute(
                    "UPDATE ld_sitter_queue
                     SET status = 'scheduled', scheduled_session_id = ?, resolved_at = NOW(), resolved_by = ?
                     WHERE id = ? AND status = ?",
                    [$decision['session_id'], $actorId, $entryId, $decision['expect_status']]
                );
                if ($n > 0) {
                    $this->log($actorId, 'sitter_queue.schedule', $entryId, $decision);
                }
                return $n > 0;
            }

            if ($action === 'requeue') {
                $n = $this->db->execute(
                    "UPDATE ld_sitter_queue
                     SET status = 'waiting', scheduled_session_id = NULL, resolved_at = NULL, resolved_by = NULL
                     WHERE id = ? AND status = ?",
                    [$entryId, $decision['expect_status']]
                );
                if ($n > 0) {
                    $this->log($actorId, 'sitter_queue.unschedule', $entryId, $decision);
                }
                return $n > 0;
            }

            // complete — carry the status classification actually saw. Hardcoding
            // 'scheduled' here would silently never complete a 'waiting' entry,
            // which is most of the backlog.
            $n = $this->db->execute(
                "UPDATE ld_sitter_queue
                 SET status = 'completed', scheduled_session_id = COALESCE(scheduled_session_id, ?),
                     resolved_at = NOW(), resolved_by = ?
                 WHERE id = ? AND status = ?",
                [$decision['session_id'], $actorId, $entryId, $decision['expect_status']]
            );

            if ($n === 0) {
                return false;   // someone else got there first
            }

            $userId = (int) $entry['user_id'];
            $user = $this->db->fetch(
                "SELECT sitter_auto_rejoin FROM users WHERE id = ?",
                [$userId]
            );

            $autoRejoined = false;
            if ($user && $user['sitter_auto_rejoin']) {
                // Guarded so two concurrent completions cannot both requeue them.
                $already = (int) $this->db->fetchColumn(
                    "SELECT COUNT(*) FROM ld_sitter_queue
                     WHERE user_id = ? AND status IN ('waiting','scheduled')",
                    [$userId]
                );
                if ($already === 0) {
                    $this->db->execute(
                        "INSERT INTO ld_sitter_queue (user_id, status) VALUES (?, 'waiting')",
                        [$userId]
                    );
                    $autoRejoined = true;
                }
            }

            $this->log($actorId, 'sitter_queue.complete', $entryId, $decision + [
                'auto_rejoined' => $autoRejoined,
            ]);

            if ($notify && $this->worthNotifying($decision['session_id'])) {
                try {
                    app('notifications')->sitterSessionCompleted($userId, $autoRejoined);
                } catch (\Throwable $e) {
                    error_log('sitterSessionCompleted failed: ' . $e->getMessage());
                }
            }

            return true;
        });
    }

    /**
     * Sweep every active entry. Called from the queue views and by the repair tool.
     *
     * @return int number of entries changed
     */
    public function sweep(?int $actorId, bool $notify): int
    {
        $ids = $this->db->fetchAll(
            "SELECT id FROM ld_sitter_queue WHERE status IN ('waiting','scheduled')"
        );

        $changed = 0;
        foreach ($ids as $row) {
            if ($this->apply((int) $row['id'], $actorId, $notify)) {
                $changed++;
            }
        }
        return $changed;
    }

    // --- Helpers -----------------------------------------------------------

    /** The one active queue entry for a user, if any. */
    public function activeEntry(int $userId, bool $forUpdate = false): ?array
    {
        $row = $this->db->fetch(
            "SELECT * FROM ld_sitter_queue
             WHERE user_id = ? AND status IN ('waiting', 'scheduled')
             ORDER BY requested_at ASC LIMIT 1"
            . ($forUpdate ? ' FOR UPDATE' : ''),
            [$userId]
        );
        return $row ?: null;
    }

    /** Earliest session (today or later) where this user is booked as a model. */
    private function earliestFutureBooking(int $userId, ?int $excludeSessionId, bool $forUpdate = false): ?int
    {
        $sql = "SELECT s.id
                FROM ld_session_participants sp
                JOIN ld_sessions s ON s.id = sp.session_id
                WHERE sp.user_id = ? AND sp.role = 'model' AND s.session_date >= ?";
        $params = [$userId, date('Y-m-d')];

        if ($excludeSessionId !== null) {
            $sql .= " AND s.id != ?";
            $params[] = $excludeSessionId;
        }

        $sql .= " ORDER BY s.session_date ASC LIMIT 1";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        $row = $this->db->fetch($sql, $params);
        return $row ? (int) $row['id'] : null;
    }

    private function wasNoShow(int $userId, int $sessionId, bool $forUpdate = false): bool
    {
        $row = $this->db->fetch(
            "SELECT attendance FROM ld_session_participants
             WHERE user_id = ? AND session_id = ? AND role = 'model'"
            . ($forUpdate ? ' FOR UPDATE' : ''),
            [$userId, $sessionId]
        );
        return ($row['attendance'] ?? '') === 'no_show';
    }

    /**
     * Old sessions never trigger mail.
     *
     * Correct on its own merits — nobody wants "thanks for posing" about a
     * session from two years ago — and it stops the first facilitator page load
     * after a deploy from blasting the entire backlog.
     */
    private function worthNotifying(?int $sessionId): bool
    {
        if ($sessionId === null) {
            return false;
        }
        $date = $this->db->fetchColumn("SELECT session_date FROM ld_sessions WHERE id = ?", [$sessionId]);
        if (!$date) {
            return false;
        }
        return strtotime((string) $date) >= strtotime('-' . self::NOTIFY_MAX_AGE_DAYS . ' days');
    }

    private function log(?int $actorId, string $action, int $entryId, array $context): void
    {
        try {
            app('provenance')->log($actorId, $action, 'sitter_queue', $entryId, $context);
        } catch (\Throwable) {
            // Provenance must never break the main flow.
        }
    }
}
