<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use DomainException;

/** All booking entry points share a session lock with cancellation. */
final class ParticipationService
{
    public function __construct(private readonly Connection $db) {}

    public function add(int $sessionId, int $actorId, int $userId, string $role, bool $manager = false, bool $tentative = false): bool
    {
        if ($manager) SessionAccess::requireManager($this->db, $actorId);
        elseif ($actorId !== $userId) throw new DomainException('Cannot book for another user.', 403);
        if (!in_array($role, ['artist', 'model', 'observer'], true)) throw new DomainException('Invalid booking role.', 422);
        return $this->db->transaction(function () use ($sessionId, $actorId, $userId, $role, $manager, $tentative) {
            $session = SessionAccess::session($this->db, $sessionId, true);
            if (!$manager && time() >= session_starts_at($session)) throw new DomainException('That session has already started.', 422);
            if (!$manager && $role === 'model' && !model_join_open($session)) throw new DomainException('Sitter bookings are handled separately for this session.', 422);
            if (!$this->db->fetchColumn("SELECT id FROM users WHERE id = ?", [$userId])) throw new DomainException('User not found.', 404);
            if ($this->db->fetchColumn("SELECT id FROM ld_session_participants WHERE session_id = ? AND user_id = ? AND role = ?", [$sessionId, $userId, $role])) return false;
            $this->db->execute("INSERT INTO ld_session_participants (session_id, user_id, role, tentative) VALUES (?, ?, ?, ?)", [$sessionId, $userId, $role, $tentative ? 1 : 0]);
            if ($role === 'model' && !session_is_over($session)) (new SitterQueueService($this->db))->onModelAdded($userId, $sessionId, $actorId);
            (new ProvenanceService($this->db))->record($actorId, $manager ? 'participant.add' : 'session.join', 'session', $sessionId, ['user_id' => $userId, 'role' => $role]);
            return true;
        });
    }

    public function remove(int $sessionId, int $actorId, int $pid, bool $manager = false): ?array
    {
        if ($manager) SessionAccess::requireManager($this->db, $actorId);
        return $this->db->transaction(function () use ($sessionId, $actorId, $pid, $manager) {
            $session = SessionAccess::session($this->db, $sessionId, true);
            if (!$manager && time() >= session_starts_at($session)) throw new DomainException('That session has already started.', 422);
            $p = $this->db->fetch("SELECT * FROM ld_session_participants WHERE id = ? AND session_id = ? FOR UPDATE", [$pid, $sessionId]);
            if (!$p) return null;
            if ($p['role'] === 'facilitator' || (!$manager && (int) $p['user_id'] !== $actorId)) throw new DomainException('This booking cannot be removed here.', 403);
            $this->db->execute("DELETE FROM ld_session_participants WHERE id = ?", [$pid]);
            if ($p['role'] === 'model') (new SitterQueueService($this->db))->onModelRemoved((int) $p['user_id'], $sessionId, $actorId);
            (new ProvenanceService($this->db))->record($actorId, $manager ? 'participant.remove' : 'session.leave', 'session', $sessionId,
                ['user_id' => (int) $p['user_id'], 'role' => $p['role'], 'late_notice' => is_late_cancel($session)]);
            return $p;
        });
    }
}
