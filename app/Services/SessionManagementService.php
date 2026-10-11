<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use DomainException;

final class SessionManagementService
{
    public function __construct(private readonly Connection $db) {}

    public function refreshStats(?int $userId = null): ?string
    {
        try {
            $stats = new StatsService($this->db);
            $userId === null ? $stats->refreshAll() : $stats->refreshUser($userId);
            return null;
        } catch (\Throwable $e) {
            error_log('Management stats refresh failed: ' . $e->getMessage());
            return 'Changes saved. Statistics need a refresh.';
        }
    }

    public function changeRole(int $sessionId, int $actorId, int $pid, string $expected, string $role): ?string
    {
        SessionAccess::requireManager($this->db, $actorId);
        if (!in_array($role, ['artist', 'model', 'observer'], true)) throw new DomainException('Choose Artist, Sitter or Observer.', 422);
        $userId = $this->db->transaction(function () use ($sessionId, $actorId, $pid, $expected, $role) {
            $session = SessionAccess::session($this->db, $sessionId, true);
            $p = $this->db->fetch("SELECT * FROM ld_session_participants WHERE id = ? AND session_id = ? FOR UPDATE", [$pid, $sessionId]);
            if (!$p) throw new DomainException('Participation not found.', 404);
            if ($p['role'] === 'facilitator') throw new DomainException('Facilitator reassignment is separate from a role correction.', 422);
            if ($p['role'] === $role) return (int) $p['user_id'];
            if ($p['role'] !== $expected) throw new DomainException('The role changed. Reload and review before saving.', 409);
            $collision = $this->db->fetchColumn("SELECT id FROM ld_session_participants WHERE session_id = ? AND user_id = ? AND role = ?", [$sessionId, $p['user_id'], $role]);
            if ($collision) throw new DomainException('This person already has that role (booking #' . $collision . '). Review both bookings; neither has been removed.', 409);
            $this->db->execute("UPDATE ld_session_participants SET role = ? WHERE id = ?", [$role, $pid]);
            $queue = new SitterQueueService($this->db);
            if ($p['role'] === 'model') $queue->onModelRemoved((int) $p['user_id'], $sessionId, $actorId);
            if ($role === 'model' && !session_is_over($session)) $queue->onModelAdded((int) $p['user_id'], $sessionId, $actorId);
            (new ProvenanceService($this->db))->record($actorId, 'participant.role', 'session', $sessionId,
                ['participant_id' => $pid, 'from' => $p['role'], 'to' => $role]);
            return (int) $p['user_id'];
        });
        return $this->refreshStats($userId);
    }

    public function details(int $sessionId, int $actorId, array $data): ?string
    {
        SessionAccess::requireManager($this->db, $actorId);
        $values = [];
        foreach (['title' => 200, 'subtitle' => 200, 'venue' => 200, 'description' => 10000, 'booking_note' => 255] as $field => $max) {
            $value = trim((string) ($data[$field] ?? ''));
            if (mb_strlen($value) > $max || ($field === 'venue' && $value === '')) throw new DomainException('Check the session title, venue and description lengths.', 422);
            $values[$field] = $value === '' ? null : $value;
        }
        $date = (string) ($data['session_date'] ?? '');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new DomainException('Enter a valid session date.', 422);
        $time = (string) ($data['start_time'] ?? '');
        if ($time !== '' && !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::00)?\z/', $time)) throw new DomainException('Enter a valid start time.', 422);
        $values['session_date'] = $date;
        $values['start_time'] = $time ?: null;
        foreach (['duration_minutes' => [1, 1440], 'max_capacity' => [1, 10000]] as $field => [$min, $max]) {
            if (filter_var($data[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) throw new DomainException('Check duration and artist places.', 422);
            $values[$field] = (int) $data[$field];
        }
        $sex = (string) ($data['model_sex'] ?? '');
        if (!in_array($sex, ['', 'f', 'm'], true)) throw new DomainException('Invalid sitter listing value.', 422);
        $values['model_sex'] = $sex ?: null;
        foreach (['capacity_published', 'model_join_enabled'] as $flag) $values[$flag] = empty($data[$flag]) ? 0 : 1;
        $dateChanged = $this->db->transaction(function () use ($sessionId, $actorId, $data, $values) {
            $old = SessionAccess::session($this->db, $sessionId, true);
            if (($data['expected_updated_at'] ?? '') !== $old['updated_at']) throw new DomainException('Session details changed. Reload and review before saving.', 409);
            $scheduleChanged = $old['session_date'] !== $values['session_date'] || substr((string) $old['start_time'], 0, 5) !== substr((string) $values['start_time'], 0, 5)
                || (int) $old['duration_minutes'] !== $values['duration_minutes'];
            if ($scheduleChanged && empty($data['confirm_schedule'])) throw new DomainException('Review and confirm the date/time change.', 422);
            if ($scheduleChanged) {
                if (session_starts_at($old) <= time() && session_starts_at($values) > time()) {
                    throw new DomainException('A session that has started cannot be moved back into the future. Create a separate session for a new booking.', 422);
                }
                if (!session_is_over($values) && $this->db->fetchColumn("SELECT id FROM ld_session_participants WHERE session_id = ? AND attendance = 'no_show' LIMIT 1", [$sessionId])) {
                    throw new DomainException('Undo recorded no-shows before changing this into an unfinished session.', 422);
                }
                $this->checkSitterConflicts($sessionId, $values);
                foreach ($this->db->fetchAll("SELECT requested_at FROM ld_sitter_queue WHERE scheduled_session_id = ? AND status = 'scheduled' FOR UPDATE", [$sessionId]) as $entry) {
                    if (session_starts_at($values) < strtotime($entry['requested_at'])) {
                        throw new DomainException('The new start precedes a linked sitter request. Review that queue booking before changing the date/time.', 409);
                    }
                }
            }
            $assign = implode(', ', array_map(static fn($field) => $field . ' = ?', array_keys($values)));
            $this->db->execute("UPDATE ld_sessions SET {$assign}, updated_at = GREATEST(NOW(6), updated_at + INTERVAL 1 MICROSECOND) WHERE id = ?", [...array_values($values), $sessionId]);
            (new ProvenanceService($this->db))->record($actorId, 'session.edit', 'session', $sessionId,
                ['before' => array_intersect_key($old, $values), 'after' => $values]);
            return $old['session_date'] !== $values['session_date'];
        });
        return $dateChanged ? $this->refreshStats() : null;
    }

    public function cancel(int $sessionId, int $actorId, string $reason): array
    {
        SessionAccess::requireManager($this->db, $actorId);
        if (mb_strlen($reason) > 2000) throw new DomainException('Keep the reason under 2,000 characters.', 422);
        $result = $this->db->transaction(function () use ($sessionId, $actorId, $reason) {
            $session = SessionAccess::session($this->db, $sessionId, true, true);
            if ($session['status'] === 'cancelled') return ['changed' => false, 'session' => $session];
            if (!in_array($session['status'], ['scheduled', 'active'], true)) throw new DomainException('A completed session cannot be cancelled.', 422);
            if (time() >= session_starts_at($session)) throw new DomainException('This session has started. Record attendance and notes instead of cancelling it.', 422);
            if ($this->db->fetchColumn("SELECT id FROM ld_artworks WHERE session_id = ? LIMIT 1", [$sessionId])) throw new DomainException('A session with uploaded photos cannot be cancelled.', 422);
            $this->db->execute("UPDATE ld_sessions SET cancelled_from_status = status, status = 'cancelled',
                cancelled_at = ?, cancelled_by = ?, cancellation_reason = ? WHERE id = ?",
                [date('Y-m-d H:i:s'), $actorId, trim($reason) ?: null, $sessionId]);
            $queue = new SitterQueueService($this->db);
            foreach ($this->db->fetchAll("SELECT user_id FROM ld_session_participants WHERE session_id = ? AND role = 'model'", [$sessionId]) as $p) {
                $queue->onModelRemoved((int) $p['user_id'], $sessionId, $actorId);
            }
            // Catch scheduled entries without a matching participant row too.
            foreach ($this->db->fetchAll("SELECT user_id FROM ld_sitter_queue WHERE scheduled_session_id = ? AND status = 'scheduled'", [$sessionId]) as $p) {
                $queue->onModelRemoved((int) $p['user_id'], $sessionId, $actorId);
            }
            app('notifications')->cancelQueued('session', $sessionId);
            (new ProvenanceService($this->db))->record($actorId, 'session.cancel', 'session', $sessionId, ['previous_status' => $session['status']]);
            return ['changed' => true, 'session' => $session];
        });
        $result['warning'] = $result['changed'] ? $this->refreshStats() : null;
        return $result;
    }

    public function restore(int $sessionId, int $actorId, ?string $legacyStatus): ?string
    {
        SessionAccess::requireManager($this->db, $actorId);
        $changed = $this->db->transaction(function () use ($sessionId, $actorId, $legacyStatus) {
            $session = SessionAccess::session($this->db, $sessionId, true, true);
            if ($session['status'] !== 'cancelled') return false;
            $target = $session['cancelled_from_status'] ?: $legacyStatus;
            if (!in_array($target, ['scheduled', 'active', 'completed'], true)) throw new DomainException('Choose the correct previous session state for this legacy cancellation.', 422);
            $models = $this->db->fetchAll("SELECT user_id FROM ld_session_participants WHERE session_id = ? AND role = 'model'", [$sessionId]);
            if (!session_is_over($session)) $this->checkSitterConflicts($sessionId, $session);
            foreach ($models as $p) {
                $entry = (new SitterQueueService($this->db))->activeEntry((int) $p['user_id'], true);
                if (!session_is_over($session) && $entry && $entry['status'] === 'scheduled' && (int) $entry['scheduled_session_id'] !== $sessionId) {
                    throw new DomainException('A sitter is now scheduled elsewhere. Resolve their queue booking before restoring.', 409);
                }
            }
            $this->db->execute("UPDATE ld_sessions SET status = ?, cancelled_from_status = NULL, cancelled_at = NULL, cancelled_by = NULL, cancellation_reason = NULL WHERE id = ?", [$target, $sessionId]);
            if (!session_is_over($session)) foreach ($models as $p) (new SitterQueueService($this->db))->onModelAdded((int) $p['user_id'], $sessionId, $actorId);
            (new ProvenanceService($this->db))->record($actorId, 'session.restore', 'session', $sessionId, ['status' => $target]);
            return true;
        });
        return $changed ? $this->refreshStats() : null;
    }

    private function checkSitterConflicts(int $sessionId, array $candidate): void
    {
        $start = session_starts_at($candidate);
        $end = $start + ((int) ($candidate['duration_minutes'] ?? 180) ?: 180) * 60;
        $others = $this->db->fetchAll(
            "SELECT DISTINCT s.id, s.session_date, s.start_time, s.duration_minutes FROM ld_sessions s
             JOIN ld_session_participants p ON p.session_id = s.id AND p.role = 'model'
             JOIN ld_session_participants ours ON ours.user_id = p.user_id AND ours.role = 'model' AND ours.session_id = ?
             WHERE s.id <> ? AND s.status <> 'cancelled' AND s.session_date BETWEEN ? AND ?",
            [$sessionId, $sessionId, date('Y-m-d', $start - 86400), date('Y-m-d', $end)]);
        foreach ($others as $other) {
            $otherStart = session_starts_at($other);
            $otherEnd = $otherStart + ((int) ($other['duration_minutes'] ?? 180) ?: 180) * 60;
            if ($start < $otherEnd && $end > $otherStart) {
                throw new DomainException('A sitter has an overlapping booking. Resolve that conflict before changing the schedule.', 409);
            }
        }
    }
}
