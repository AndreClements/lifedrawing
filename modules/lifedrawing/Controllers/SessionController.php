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
    /** List all sessions (public). Upcoming first, then past. */
    public function index(Request $request): Response
    {
        $enrichSessions = function (array $sessions): array {
            foreach ($sessions as &$s) {
                $s['participant_count'] = $this->table('ld_session_participants')
                    ->where('session_id', '=', $s['id'])->count();
                $s['artwork_count'] = $this->table('ld_artworks')
                    ->where('session_id', '=', $s['id'])->count();
            }
            return $sessions;
        };

        $today = date('Y-m-d');

        $upcoming = $enrichSessions(
            $this->table('ld_sessions')
                ->select('ld_sessions.*', 'u.display_name as facilitator_name')
                ->leftJoin('users u', 'ld_sessions.facilitator_id = u.id')
                ->whereIn('status', ['scheduled', 'active'])
                ->where('session_date', '>=', $today)
                ->orderBy('session_date', 'ASC')
                ->limit(20)->get()
        );

        $past = $enrichSessions(
            $this->table('ld_sessions')
                ->select('ld_sessions.*', 'u.display_name as facilitator_name')
                ->leftJoin('users u', 'ld_sessions.facilitator_id = u.id')
                ->where('session_date', '<', $today)
                ->orderBy('session_date', 'DESC')
                ->limit(20)->get()
        );

        return $this->render('sessions.index', [
            'upcoming' => $upcoming,
            'past' => $past,
        ], 'Sessions');
    }

    /** Show a single session with its artworks (public). */
    public function show(Request $request): Response
    {
        $id = from_hex($request->param('id'));

        $session = $this->table('ld_sessions')
            ->select('ld_sessions.*', 'u.display_name as facilitator_name')
            ->leftJoin('users u', 'ld_sessions.facilitator_id = u.id')
            ->where('ld_sessions.id', '=', $id)
            ->first();

        if (!$session) {
            return Response::notFound('Session not found.');
        }

        // Get participants
        $participants = $this->getParticipants($id);

        // Get artworks (respect visibility)
        $userId = $this->userId();
        $artworks = $this->db->fetchAll(
            "SELECT a.*, u.display_name as uploader_name,
                    (SELECT GROUP_CONCAT(CONCAT(c.claim_type, ':', cu.display_name) SEPARATOR ', ')
                     FROM ld_claims c
                     JOIN users cu ON c.claimant_id = cu.id
                     WHERE c.artwork_id = a.id AND c.status = 'approved'
                       AND cu.consent_state = 'granted'
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
        ], session_title($session));
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
        $modelSex = $request->input('model_sex', '') ?: null;
        if ($modelSex && !in_array($modelSex, ['f', 'm'], true)) $modelSex = null;

        $errors = [];
        if ($date === '' || !strtotime($date)) $errors[] = 'Valid date is required.';
        if ($duration < 30 || $duration > 480) $errors[] = 'Duration must be 30–480 minutes.';

        if (!empty($errors)) {
            return $this->render('sessions.create', [
                'errors' => $errors,
                'old' => $request->all(),
            ], 'New Session');
        }

        $id = $this->table('ld_sessions')->insert([
            'title' => $title ?: null,
            'session_date' => $date,
            'start_time' => $time,
            'duration_minutes' => $duration,
            'venue' => $venue,
            'description' => $description ?: null,
            'facilitator_id' => $this->userId(),
            'model_sex' => $modelSex,
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

        return Response::redirect(route('sessions.show', ['id' => hex_id((int) $id, $title)]));
    }

    /** Render a partial (no layout wrapper) — for HTMX fragment responses. */
    private function partial(string $view, array $data = []): Response
    {
        return Response::html($this->view->render($view, $data));
    }

    /** Verify the current user can manage this session's participants.
     *  Any facilitator/admin can manage any session — small community. */
    private function requireFacilitatorOf(array $session): ?Response
    {
        if (!$this->auth->hasRole('admin', 'facilitator')) {
            return Response::forbidden('You do not have permission for this action.');
        }
        return null;
    }

    /** Get participant list for a session (used by show + HTMX responses). */
    private function getParticipants(int $sessionId): array
    {
        return $this->db->fetchAll(
            "SELECT sp.*, u.display_name FROM ld_session_participants sp
             JOIN users u ON sp.user_id = u.id
             WHERE sp.session_id = ?
             ORDER BY FIELD(sp.role, 'facilitator', 'model', 'artist', 'observer'), u.display_name",
            [$sessionId]
        );
    }

    /** Search users for participant typeahead (facilitator, HTMX). */
    public function searchParticipants(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $sessionId = from_hex($request->param('id'));
        $q = trim((string) $request->input('q', ''));
        $role = $request->input('add-role', 'artist');

        if (strlen($q) < 1) {
            return Response::html('');
        }

        // Users matching query, ordered by participation count (last 12 months), excluding already-added for this role
        $results = $this->db->fetchAll(
            "SELECT u.id, u.display_name,
                    (SELECT COUNT(*) FROM ld_session_participants sp2
                     JOIN ld_sessions s2 ON sp2.session_id = s2.id
                     WHERE sp2.user_id = u.id
                       AND s2.session_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                    ) AS recent_sessions
             FROM users u
             WHERE u.display_name LIKE ?
               AND u.id NOT IN (
                   SELECT sp.user_id FROM ld_session_participants sp
                   WHERE sp.session_id = ? AND sp.role = ?
               )
             ORDER BY recent_sessions DESC, u.display_name ASC
             LIMIT 10",
            ['%' . $q . '%', $sessionId, $role]
        );

        return $this->partial('sessions._search_results', [
            'results' => $results,
            'sessionId' => $sessionId,
        ]);
    }

    /** Add a participant to a session (facilitator). */
    public function addParticipant(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $sessionId = from_hex($request->param('id'));
        $session = $this->table('ld_sessions')->where('id', '=', $sessionId)->first();
        if (!$session) return Response::notFound('Session not found.');
        if ($redirect = $this->requireFacilitatorOf($session)) return $redirect;

        $userId = (int) $request->input('user_id', 0);
        $role = $request->input('role', 'artist');
        $tentative = (bool) $request->input('tentative', false);

        if (!in_array($role, ['artist', 'model', 'observer'], true)) {
            $role = 'artist';
        }

        // Check user exists
        $user = $this->table('users')->where('id', '=', $userId)->first();
        if (!$user) return Response::notFound('User not found.');

        // Check not already in this role
        $existing = $this->table('ld_session_participants')
            ->where('session_id', '=', $sessionId)
            ->where('user_id', '=', $userId)
            ->where('role', '=', $role)
            ->first();

        if (!$existing) {
            $this->table('ld_session_participants')->insert([
                'session_id' => $sessionId,
                'user_id' => $userId,
                'role' => $role,
                'tentative' => $tentative ? 1 : 0,
            ]);

            $this->provenance->log(
                $this->userId(),
                'participant.add',
                'session',
                $sessionId,
                ['added_user' => $userId, 'role' => $role, 'tentative' => $tentative]
            );

            app('stats')->refreshUser($userId);
        }

        $participants = $this->getParticipants($sessionId);

        if ($request->isHtmx()) {
            return $this->partial('sessions._participant_manager', [
                'session' => $session,
                'participants' => $participants,
            ]);
        }

        return Response::redirect(route('sessions.show', ['id' => hex_id($sessionId, session_title($session))]));
    }

    /** Remove a participant from a session (facilitator). */
    public function removeParticipant(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $sessionId = from_hex($request->param('id'));
        $session = $this->table('ld_sessions')->where('id', '=', $sessionId)->first();
        if (!$session) return Response::notFound('Session not found.');
        if ($redirect = $this->requireFacilitatorOf($session)) return $redirect;

        $pid = (int) $request->input('pid', 0);

        // Fetch participant to get user_id for stats refresh
        $participant = $this->db->fetch(
            "SELECT * FROM ld_session_participants WHERE id = ? AND session_id = ?",
            [$pid, $sessionId]
        );

        if ($participant) {
            $this->db->execute(
                "DELETE FROM ld_session_participants WHERE id = ? AND session_id = ?",
                [$pid, $sessionId]
            );

            $this->provenance->log(
                $this->userId(),
                'participant.remove',
                'session',
                $sessionId,
                ['removed_user' => $participant['user_id'], 'role' => $participant['role']]
            );

            app('stats')->refreshUser((int) $participant['user_id']);
        }

        $participants = $this->getParticipants($sessionId);

        if ($request->isHtmx()) {
            return $this->partial('sessions._participant_manager', [
                'session' => $session,
                'participants' => $participants,
            ]);
        }

        return Response::redirect(route('sessions.show', ['id' => hex_id($sessionId, session_title($session))]));
    }

    /** Toggle tentative flag on a participant (facilitator). */
    public function toggleTentative(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $sessionId = from_hex($request->param('id'));
        $session = $this->table('ld_sessions')->where('id', '=', $sessionId)->first();
        if (!$session) return Response::notFound('Session not found.');
        if ($redirect = $this->requireFacilitatorOf($session)) return $redirect;

        $pid = (int) $request->input('pid', 0);

        $this->db->execute(
            "UPDATE ld_session_participants SET tentative = NOT tentative WHERE id = ? AND session_id = ?",
            [$pid, $sessionId]
        );

        $participants = $this->getParticipants($sessionId);

        if ($request->isHtmx()) {
            return $this->partial('sessions._participant_manager', [
                'session' => $session,
                'participants' => $participants,
            ]);
        }

        return Response::redirect(route('sessions.show', ['id' => hex_id($sessionId, session_title($session))]));
    }

    /** WhatsApp schedule output (facilitator). */
    public function whatsappSchedule(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $weeks = max(1, min(8, (int) ($request->input('weeks', 3))));
        $endDate = date('Y-m-d', strtotime("+{$weeks} weeks"));

        $sessions = $this->db->fetchAll(
            "SELECT s.* FROM ld_sessions s
             WHERE s.status IN ('scheduled', 'active')
               AND s.session_date >= CURDATE()
               AND s.session_date <= ?
             ORDER BY s.session_date ASC",
            [$endDate]
        );

        // Fetch participants for all sessions in one query
        $sessionIds = array_column($sessions, 'id');
        $allParticipants = [];
        if (!empty($sessionIds)) {
            $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
            $rows = $this->db->fetchAll(
                "SELECT sp.session_id, sp.role, sp.tentative, u.display_name
                 FROM ld_session_participants sp
                 JOIN users u ON sp.user_id = u.id
                 WHERE sp.session_id IN ({$placeholders})
                 ORDER BY sp.role, u.display_name",
                $sessionIds
            );
            foreach ($rows as $row) {
                $allParticipants[(int) $row['session_id']][] = $row;
            }
        }

        // Build schedule lines
        $lines = [];
        foreach ($sessions as $s) {
            $participants = $allParticipants[(int) $s['id']] ?? [];

            // Model name(s) — from role='model' participants
            $models = [];
            $artists = [];
            $artistCount = 0;

            foreach ($participants as $p) {
                $name = $p['display_name'];
                // Use first name only for brevity
                $firstName = explode(' ', $name)[0];
                $suffix = $p['tentative'] ? '?' : '';

                if ($p['role'] === 'model') {
                    $models[] = $firstName . $suffix;
                } elseif ($p['role'] === 'artist') {
                    $artists[] = $firstName . $suffix;
                    $artistCount++;
                }
            }

            $day = date('D', strtotime($s['session_date']));
            $date = date('d M', strtotime($s['session_date']));
            $sex = $s['model_sex'] ?? '';
            $modelStr = implode(', ', $models);
            $sexModel = $sex . ($modelStr ? ' ' . $modelStr : '');
            $artistStr = implode(', ', $artists);
            $capacity = $s['max_capacity'] ?? 7;

            $line = "{$day} {$date} ({$sexModel})";
            if ($artistStr) {
                $line .= " {$artistStr}";
            }
            $line .= " {$artistCount}/{$capacity}";
            $lines[] = $line;
        }

        $schedule = "Schedule\n" . implode("\n", $lines);
        $schedule .= "\n\nA session needs 3 bookings to proceed.";
        $schedule .= "\n\xF0\x9F\x91\x86Date (Model) Bookings";
        $schedule .= "\nFridays: 3 pm for 3:30 to 7pm,  ";
        $schedule .= "\nSaturdays & Sundays: 10 am for 10:30 to 2 pm. ";
        $schedule .= "\nContribution: R 350 or as near as is affordable.";

        if ($request->isHtmx()) {
            return $this->partial('sessions._whatsapp_schedule', [
                'schedule' => $schedule,
                'weeks' => $weeks,
            ]);
        }

        return $this->render('sessions.whatsapp', [
            'schedule' => $schedule,
            'weeks' => $weeks,
        ], 'WhatsApp Schedule');
    }

    /** Cancel a session (facilitator). */
    public function cancel(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $sessionId = from_hex($request->param('id'));
        $session = $this->table('ld_sessions')->where('id', '=', $sessionId)->first();
        if (!$session) return Response::notFound('Session not found.');

        $this->db->execute(
            "UPDATE ld_sessions SET status = 'cancelled' WHERE id = ?",
            [$sessionId]
        );

        $this->provenance->log(
            $this->userId(),
            'session.cancel',
            'session',
            $sessionId,
            ['previous_status' => $session['status']]
        );

        return Response::redirect(route('sessions.show', ['id' => hex_id($sessionId, session_title($session))]));
    }

    /** Join a session as participant (authenticated). */
    public function join(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;

        $sessionId = from_hex($request->param('id'));
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
