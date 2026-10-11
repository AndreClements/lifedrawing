<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use DomainException;

/** Shared role and session-state rules, including CLI callers. */
final class SessionAccess
{
    public static function canManage(?array $user): bool
    {
        return $user !== null && in_array($user['role'] ?? '', ['admin', 'facilitator'], true);
    }

    public static function requireManager(Connection $db, int $actorId): void
    {
        if (!self::canManage($db->fetch("SELECT id, role FROM users WHERE id = ?", [$actorId]))) {
            throw new DomainException('Facilitator access required.', 403);
        }
    }

    public static function session(Connection $db, int $id, bool $lock = false, bool $allowCancelled = false): array
    {
        $session = $db->fetch("SELECT * FROM ld_sessions WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if (!$session || (!$allowCancelled && $session['status'] === 'cancelled')) {
            throw new DomainException('Session unavailable.', 404);
        }
        return $session;
    }
}
