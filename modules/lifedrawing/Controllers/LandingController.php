<?php

declare(strict_types=1);

namespace Modules\Lifedrawing\Controllers;

use App\Request;
use App\Response;

/**
 * Landing Controller — the public face.
 *
 * Welcomes visitors, shows recent sessions, gallery highlights,
 * and community stats. The entry point to the life drawing world.
 */
final class LandingController extends BaseController
{
    /** Home page. */
    public function index(Request $request): Response
    {
        // Next upcoming session
        $upcoming = $this->table('ld_sessions')
            ->where('session_date', '>=', date('Y-m-d'))
            ->where('status', '=', 'scheduled')
            ->orderBy('session_date', 'ASC')
            ->first();

        // The three most recent sessions that have actually happened.
        //
        // Ordering by date DESC alone put the furthest-FUTURE session first. Twenty-one
        // are scheduled out to December, so the landing page was advertising 18-20 Dec
        // as "Recent Sessions" — with no artwork and nobody having attended.
        //
        // The cutoff binds PHP's date rather than CURDATE(): the database server runs
        // ~9h behind SAST, so between midnight and 09:00 CURDATE() still reads yesterday
        // and that morning's session would drop off the page.
        //
        // Status is only used to drop cancellations. Past sessions are still marked
        // 'scheduled' — nothing ever writes 'completed' for them — so requiring
        // 'completed' here would empty the list.
        $recentSessions = $this->db->fetchAll(
            "SELECT s.*, u.display_name as facilitator_name,
                    (SELECT COUNT(*) FROM ld_session_participants sp WHERE sp.session_id = s.id AND sp.role = 'artist') as participant_count,
                    (SELECT COUNT(*) FROM ld_artworks a WHERE a.session_id = s.id) as artwork_count
             FROM ld_sessions s
             LEFT JOIN users u ON s.facilitator_id = u.id
             WHERE s.session_date <= ?
               AND s.status <> 'cancelled'
             ORDER BY s.session_date DESC
             LIMIT 3",
            [date('Y-m-d')]
        );

        // Gallery highlights (recent public/claimed artworks)
        $galleryHighlights = $this->db->fetchAll(
            "SELECT a.*, s.title as session_title, s.session_date
             FROM ld_artworks a
             JOIN ld_sessions s ON a.session_id = s.id
             WHERE a.visibility IN ('claimed', 'public')
             ORDER BY a.created_at DESC
             LIMIT 8"
        );

        // Batch-load participant first names for logged-in users
        if ($recentSessions && can_see_names()) {
            $ids = array_column($recentSessions, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = $this->db->fetchAll(
                "SELECT sp.session_id, u.display_name
                 FROM ld_session_participants sp
                 JOIN users u ON sp.user_id = u.id
                 WHERE sp.session_id IN ($placeholders)
                   AND u.consent_state != 'withdrawn'
                 ORDER BY FIELD(sp.role, 'facilitator', 'model', 'artist', 'observer'), sp.id ASC",
                $ids
            );
            $bySession = [];
            foreach ($rows as $row) {
                $bySession[$row['session_id']][] = explode(' ', $row['display_name'])[0];
            }
            foreach ($recentSessions as &$s) {
                $s['participants'] = $bySession[$s['id']] ?? [];
            }
            unset($s);
        }

        // Community stats
        $communityStats = [
            'total_sessions' => (int) $this->table('ld_sessions')->count(),
            'total_artworks' => (int) $this->table('ld_artworks')->count(),
            'total_artists' => (int) $this->db->fetchColumn(
                "SELECT COUNT(DISTINCT user_id) FROM ld_session_participants"
            ) ?: 0,
        ];

        return $this->render('landing.index', [
            'upcoming' => $upcoming,
            'recentSessions' => $recentSessions,
            'galleryHighlights' => $galleryHighlights,
            'communityStats' => $communityStats,
        ], null, [
            'meta_description' => 'Weekly life drawing sessions in Randburg, Johannesburg. Join us to draw, model, and hold space for the human form.',
        ]);
    }
}
