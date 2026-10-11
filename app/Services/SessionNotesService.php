<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use DomainException;

final class SessionNotesService
{
    public function __construct(private readonly Connection $db) {}

    public function listing(int $sessionId, int $actorId): array
    {
        SessionAccess::requireManager($this->db, $actorId);
        SessionAccess::session($this->db, $sessionId, allowCancelled: true);
        return $this->db->fetchAll(
            "SELECT l.*, u.display_name AS recorder_name, p.role AS participant_role,
                    person.display_name AS participant_name
             FROM ld_session_log l
             LEFT JOIN users u ON u.id = l.created_by
             LEFT JOIN ld_session_participants p ON p.id = l.participant_id
             LEFT JOIN users person ON person.id = p.user_id
             WHERE l.session_id = ? AND l.deleted_at IS NULL ORDER BY l.id DESC",
            [$sessionId]
        );
    }

    public static function requestKey(mixed $key): string
    {
        if (!is_string($key) || !preg_match('/\A[a-zA-Z0-9_-]{16,80}\z/', $key)) {
            throw new DomainException('A valid save request key is required.', 422);
        }
        return $key;
    }

    private function participant(int $sessionId, ?int $participantId): ?array
    {
        if ($participantId === null) return null;
        $row = $this->db->fetch("SELECT * FROM ld_session_participants WHERE id = ? AND session_id = ? FOR UPDATE",
            [$participantId, $sessionId]);
        if (!$row) throw new DomainException('Choose a participant from this session.', 422);
        return $row;
    }

    private function noteData(array $data): array
    {
        $kind = (string) ($data['kind'] ?? 'note');
        if (!in_array($kind, ['note', 'late', 'break', 'left_early', 'pose_change'], true)) {
            throw new DomainException('Choose a note type.', 422);
        }
        $text = trim((string) ($data['text'] ?? ''));
        if (mb_strlen($text) > 4000 || ($kind === 'note' && $text === '')) {
            throw new DomainException('Enter a note of up to 4,000 characters.', 422);
        }
        $numbers = [];
        foreach (['participant_id' => [1, PHP_INT_MAX], 'minutes' => [0, 1440], 'pose_number' => [1, 65535]] as $field => [$min, $max]) {
            $value = $data[$field] ?? '';
            if ($value === '' || $value === null) {
                $numbers[$field] = null;
            } elseif (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) {
                throw new DomainException('Check the participant, minutes and pose number.', 422);
            } else {
                $numbers[$field] = (int) $value;
            }
        }
        return ['kind' => $kind, 'text' => $text ?: null] + $numbers;
    }

    public function create(int $sessionId, int $actorId, array $data, string $key): array
    {
        SessionAccess::requireManager($this->db, $actorId);
        self::requestKey($key);
        return $this->db->transaction(function () use ($sessionId, $actorId, $data, $key) {
            SessionAccess::session($this->db, $sessionId, true, true);
            $prior = $this->db->fetch("SELECT * FROM ld_session_log WHERE session_id = ? AND request_key = ?", [$sessionId, $key]);
            if ($prior) return $prior;
            $note = $this->noteData($data);
            $this->participant($sessionId, $note['participant_id']);
            $this->db->execute(
                "INSERT INTO ld_session_log (session_id, participant_id, kind, text, minutes, pose_number, created_by, updated_by, request_key)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$sessionId, $note['participant_id'], $note['kind'], $note['text'], $note['minutes'], $note['pose_number'], $actorId, $actorId, $key]
            );
            $id = (int) $this->db->lastInsertId();
            (new ProvenanceService($this->db))->record($actorId, 'session.note.create', 'session', $sessionId, ['note_id' => $id, 'kind' => $note['kind']]);
            return $this->db->fetch("SELECT * FROM ld_session_log WHERE id = ?", [$id]);
        });
    }

    /** Validate CLI input without changing rows or allocating request keys. */
    public function preview(int $sessionId, int $actorId, array $data, ?string $attendance = null): array
    {
        SessionAccess::requireManager($this->db, $actorId);
        $session = SessionAccess::session($this->db, $sessionId, allowCancelled: $attendance === null);
        if ($attendance === null) {
            $note = $this->noteData($data);
            $this->participant($sessionId, $note['participant_id']);
            return $note;
        }
        if (!in_array($attendance, ['no_show', 'restore'], true)) throw new DomainException('Use no_show or restore.', 422);
        if (!session_is_over($session)) throw new DomainException('Record attendance after the session ends.', 422);
        $p = $this->participant($sessionId, (int) ($data['participant_id'] ?? 0));
        if ($p['role'] === 'facilitator') throw new DomainException('The facilitator cannot be marked no-show.', 422);
        return ['participant_id' => (int) $p['id'], 'from' => $p['attendance'],
            'to' => $attendance === 'no_show' ? 'no_show' : ($p['attended'] ? 'attended' : 'booked')];
    }

    public function edit(int $sessionId, int $actorId, int $id, string $expected, array $data, bool $remove = false): void
    {
        SessionAccess::requireManager($this->db, $actorId);
        $this->db->transaction(function () use ($sessionId, $actorId, $id, $expected, $data, $remove) {
            SessionAccess::session($this->db, $sessionId, true, true);
            $row = $this->db->fetch("SELECT * FROM ld_session_log WHERE id = ? AND session_id = ? FOR UPDATE", [$id, $sessionId]);
            if (!$row || $row['deleted_at'] !== null) throw new DomainException('Note unavailable.', 404);
            if ($row['kind'] === 'attendance') throw new DomainException('Use Undo no-show to correct attendance.', 422);
            if ($row['updated_at'] !== $expected) throw new DomainException('This note changed in another tab. Reload and review it before saving.', 409);
            if ($remove) {
                $this->db->execute("UPDATE ld_session_log SET deleted_at = NOW(6), updated_by = ?,
                    updated_at = GREATEST(NOW(6), updated_at + INTERVAL 1 MICROSECOND) WHERE id = ?", [$actorId, $id]);
            } else {
                $note = $this->noteData($data);
                $this->participant($sessionId, $note['participant_id']);
                $this->db->execute("UPDATE ld_session_log SET kind = ?, text = ?, minutes = ?, pose_number = ?, participant_id = ?, updated_by = ?,
                    updated_at = GREATEST(NOW(6), updated_at + INTERVAL 1 MICROSECOND) WHERE id = ?",
                    [$note['kind'], $note['text'], $note['minutes'], $note['pose_number'], $note['participant_id'], $actorId, $id]);
            }
            (new ProvenanceService($this->db))->record($actorId, $remove ? 'session.note.remove' : 'session.note.edit', 'session', $sessionId, ['note_id' => $id]);
        });
    }

    public function attendance(int $sessionId, int $actorId, int $participantId, string $action, string $expected, string $key): array
    {
        SessionAccess::requireManager($this->db, $actorId);
        self::requestKey($key);
        if (!in_array($action, ['no_show', 'restore'], true)) throw new DomainException('Choose no-show or restore.', 422);
        $result = $this->db->transaction(function () use ($sessionId, $actorId, $participantId, $action, $expected, $key) {
            $session = SessionAccess::session($this->db, $sessionId, true);
            $prior = $this->db->fetch("SELECT * FROM ld_session_log WHERE session_id = ? AND request_key = ?", [$sessionId, $key]);
            if ($prior) return ['changed' => false, 'user_id' => null, 'entry' => $prior];
            if (!session_is_over($session)) throw new DomainException('Record no-shows after the session has ended. You can save a note now.', 422);
            $p = $this->participant($sessionId, $participantId);
            if ($p['role'] === 'facilitator') throw new DomainException('The facilitator cannot be marked no-show.', 422);
            $target = $action === 'no_show' ? 'no_show' : ($p['attended'] ? 'attended' : 'booked');
            if ($p['attendance'] !== $expected && $p['attendance'] !== $target) {
                throw new DomainException('Attendance changed. Reload and review the current state.', 409);
            }
            $changed = $target !== $p['attendance'];
            $this->db->execute("UPDATE ld_session_participants SET attendance = ? WHERE id = ?", [$target, $participantId]);
            $this->db->execute(
                "INSERT INTO ld_session_log (session_id, participant_id, kind, text, attendance_before, attendance_after, created_by, updated_by, request_key)
                 VALUES (?, ?, 'attendance', ?, ?, ?, ?, ?, ?)",
                [$sessionId, $participantId, $action === 'no_show' ? 'Marked no-show' : 'No-show undone', $p['attendance'], $target, $actorId, $actorId, $key]
            );
            $id = (int) $this->db->lastInsertId();
            (new ProvenanceService($this->db))->record($actorId, 'session.attendance', 'session', $sessionId,
                ['participant_id' => $participantId, 'from' => $p['attendance'], 'to' => $target, 'note_id' => $id]);
            return ['changed' => $changed, 'user_id' => (int) $p['user_id']];
        });
        $result['warning'] = null;
        if ($result['changed']) {
            try { (new StatsService($this->db))->refreshUser($result['user_id']); }
            catch (\Throwable $e) {
                error_log('Attendance stats refresh failed: ' . $e->getMessage());
                $result['warning'] = 'Attendance saved. Statistics need a refresh.';
            }
        }
        return $result;
    }
}
