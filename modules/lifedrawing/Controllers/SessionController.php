<?php

declare(strict_types=1);

namespace Modules\Lifedrawing\Controllers;

use App\Request;
use App\Response;

/**
 * Session Controller — CRUD for life drawing sessions.
 *
 * Sessions are the core unit. Everything else (artworks, claims, stats)
 * hangs off sessions. The session is the container for shared experience.
 */
final class SessionController extends BaseController
{
    /** List all sessions (public). */
    public function index(Request $request): Response
    {
        $status = $request->input('status', 'all');
        $query = $this->table('ld_sessions')
            ->select('ld_sessions.*', 'u.display_name as facilitator_name')
            ->leftJoin('users u', 'ld_sessions.facilitator_id = u.id')
            ->orderBy('session_date', 'DESC');

        if ($status !== 'all') {
            $query = $query->where('status', '=', $status);
        }

        $sessions = $query->limit(20)->get();

        // Get participant counts per session
        foreach ($sessions as &$session) {
            $session['participant_count'] = $this->table('ld_session_participants')
                ->where('session_id', '=', $session['id'])
                ->count();

            $session['artwork_count'] = $this->table('ld_artworks')
                ->where('session_id', '=', $session['id'])
                ->count();
        }

        return $this->render('sessions.index', [
            'sessions' => $sessions,
            'status' => $status,
        ], 'Sessions');
    }

    /** Show a single session with its artworks (public). */
    public function show(Request $request): Response
    {
        $id = (int) $request->param('id');

        $session = $this->table('ld_sessions')
            ->select('ld_sessions.*', 'u.display_name as facilitator_name')
            ->leftJoin('users u', 'ld_sessions.facilitator_id = u.id')
            ->where('ld_sessions.id', '=', $id)
            ->first();

        if (!$session) {
            return Response::notFound('Session not found.');
        }

        // Get participants
        $participants = $this->db->fetchAll(
            "SELECT sp.*, u.display_name FROM ld_session_participants sp
             JOIN users u ON sp.user_id = u.id
             WHERE sp.session_id = ?
             ORDER BY sp.role, u.display_name",
            [$id]
        );

        // Get artworks (respect visibility)
        $userId = $this->userId();
        $artworks = $this->db->fetchAll(
            "SELECT a.*, u.display_name as uploader_name,
                    (SELECT GROUP_CONCAT(CONCAT(c.claim_type, ':', cu.display_name) SEPARATOR ', ')
                     FROM ld_claims c
                     JOIN users cu ON c.claimant_id = cu.id
                     WHERE c.artwork_id = a.id AND c.status = 'approved'
                    ) as claims_summary
             FROM ld_artworks a
             JOIN users u ON a.uploaded_by = u.id
             WHERE a.session_id = ?
               AND (a.visibility IN ('session', 'claimed', 'public') OR a.uploaded_by = ?)
             ORDER BY a.pose_index ASC, a.created_at ASC",
            [$id, $userId ?? 0]
        );

        return $this->render('sessions.show', [
            'session' => $session,
            'participants' => $participants,
            'artworks' => $artworks,
        ], $session['title']);
    }

    /** Show create session form (facilitator+). */
    public function create(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        return $this->render('sessions.create', [], 'New Session');
    }

    /** Store a new session (facilitator+). */
    public function store(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $title = trim($request->input('title', ''));
        $date = $request->input('session_date', '');
        $time = $request->input('start_time', '') ?: null;
        $duration = (int) ($request->input('duration_minutes', 180));
        $venue = trim($request->input('venue', 'Randburg'));
        $description = trim($request->input('description', ''));

        $errors = [];
        if ($title === '') $errors[] = 'Title is required.';
        if ($date === '' || !strtotime($date)) $errors[] = 'Valid date is required.';
        if ($duration < 30 || $duration > 480) $errors[] = 'Duration must be 30–480 minutes.';

        if (!empty($errors)) {
            return $this->render('sessions.create', [
                'errors' => $errors,
                'old' => $request->all(),
            ], 'New Session');
        }

        $id = $this->table('ld_sessions')->insert([
            'title' => $title,
            'session_date' => $date,
            'start_time' => $time,
            'duration_minutes' => $duration,
            'venue' => $venue,
            'description' => $description ?: null,
            'facilitator_id' => $this->userId(),
            'status' => 'scheduled',
        ]);

        // Auto-add facilitator as participant
        $this->table('ld_session_participants')->insert([
            'session_id' => (int) $id,
            'user_id' => $this->userId(),
            'role' => 'facilitator',
            'attended' => true,
        ]);

        $this->provenance->log(
            $this->userId(),
            'session.create',
            'session',
            (int) $id,
            ['title' => $title, 'date' => $date]
        );

        return Response::redirect(route('sessions.show', ['id' => $id]));
    }

    /** Join a session as participant (authenticated). */
    public function join(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;

        $sessionId = (int) $request->param('id');
        $role = $request->input('role', 'artist');

        if (!in_array($role, ['artist', 'model', 'observer'], true)) {
            $role = 'artist';
        }

        // Check session exists
        $session = $this->table('ld_sessions')->where('id', '=', $sessionId)->first();
        if (!$session) {
            return Response::notFound('Session not found.');
        }

        // Check not already joined in this role
        $existing = $this->table('ld_session_participants')
            ->where('session_id', '=', $sessionId)
            ->where('user_id', '=', $this->userId())
            ->where('role', '=', $role)
            ->first();

        if (!$existing) {
            $this->table('ld_session_participants')->insert([
                'session_id' => $sessionId,
                'user_id' => $this->userId(),
                'role' => $role,
            ]);

            $this->provenance->log(
                $this->userId(),
                'session.join',
                'session',
                $sessionId,
                ['role' => $role]
            );

            // Refresh stats after joining
            app('stats')->refreshUser($this->userId());
        }

        if ($request->isHtmx()) {
            return Response::html('<span class="badge">Joined as ' . e($role) . '</span>');
        }

        return Response::redirect(route('sessions.show', ['id' => $sessionId]));
    }
}
