<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

/**
 * Provenance logger — Octagon facet 6.
 *
 * Records who did what, when, and why. Every significant user action
 * leaves a trace. This is not surveillance — it's accountability and
 * the system explaining itself.
 */
final class ProvenanceService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Log an action to the provenance ledger.
     *
     * @param int|null $userId      Who did it (null for system/anonymous)
     * @param string   $action      What happened ('session.create', 'artwork.claim', etc.)
     * @param string   $entityType  What kind of thing ('session', 'artwork', 'user')
     * @param int      $entityId    Which specific thing
     * @param array    $context     Extra metadata (JSON-serialised)
     */
    public function log(
        ?int $userId,
        string $action,
        string $entityType,
        int $entityId,
        array $context = [],
    ): void {
        try {
            $this->db->execute(
                "INSERT INTO provenance_log (user_id, action, entity_type, entity_id, context, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    $action,
                    $entityType,
                    $entityId,
                    !empty($context) ? json_encode($context) : null,
                    $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                ]
            );
        } catch (\Throwable) {
            // Provenance logging must never break the main flow.
            // And-Yet: silent failure here means we could lose audit data.
            // Future: queue failed entries for retry.
        }
    }

    /** Get provenance trail for an entity. */
    public function trail(string $entityType, int $entityId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT pl.*, u.display_name as user_name
             FROM provenance_log pl
             LEFT JOIN users u ON pl.user_id = u.id
             WHERE pl.entity_type = ? AND pl.entity_id = ?
             ORDER BY pl.created_at DESC
             LIMIT ?",
            [$entityType, $entityId, $limit]
        );
    }
}
