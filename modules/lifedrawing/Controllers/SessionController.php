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
    /** List sessions (public). Tabbed: upcoming (default) or past (paginated). */
    public function index(Request $request): Response
    {
        $enrichSessions = function (array $sessions): array {
            foreach ($sessions as &$s) {
                $s['participant_count'] = $this->table('ld_session_participants')
                    ->where('session_id', '=', $s['id'])
                    ->where('role', '=', 'artist')->count();
                $s['artwork_count'] = $this->table('ld_artworks')
                    ->where('session_id', '=', $s['id'])->count();
            }
            return $sessions;
        };

        $activeView = $request->input('view', '') === 'past' ? 'past' : 'upcoming';
        $today = date('Y-m-d');
        $perPage = 20;
        $page = max(1, (int) $request->input('page', 1));
        $totalPages = 1;

        if ($activeView === 'upcoming') {
            $sessions = $enrichSessions(
                $this->table('ld_sessions')
                    ->select('ld_sessions.*', 'u.display_name as facilitator_name')
                    ->leftJoin('users u', 'ld_sessions.facilitator_id = u.id')
                    ->whereIn('status', ['scheduled', 'active'])
                    ->where('session_date', '>=', $today)
                    ->orderBy('session_date', 'ASC')
                    ->get()
            );
        } else {
            $total = $this->table('ld_sessions')
                ->where('status', '<>', 'cancelled')
                ->where('session_date', '<', $today)
                ->count();
            $totalPages = max(1, (int) ceil($total / $perPage));
            $page = min($page, $totalPages);

            $sessions = $enrichSessions(
                $this->table('ld_sessions')
                    ->select('ld_sessions.*', 'u.display_name as facilitator_name')
                    ->leftJoin('users u', 'ld_sessions.facilitator_id = u.id')
                    ->where('status', '<>', 'cancelled')
                    ->where('session_date', '<', $today)
                    ->orderBy('session_date', 'DESC')
                    ->limit($perPage)
                    ->offset(($page - 1) * $perPage)
                    ->get()
            );
        }

        // Pre-compute "joined as artist" flag per session for the current user
        $auth = app('auth');
        if ($sessions && $activeView === 'upcoming' && $auth->isLoggedIn()) {
            $userId = $auth->currentUserId();
            $ids = array_column($sessions, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $joined = $this->db->fetchAll(
                "SELECT session_id FROM ld_session_participants
                 WHERE user_id = ? AND role = 'artist' AND session_id IN ($placeholders)",
                array_merge([$userId], $ids)
            );
            $joinedSet = array_column($joined, 'session_id');
            foreach ($sessions as &$s) {
                $s['joined_as_artist'] = in_array((int) $s['id'], array_map('intval', $joinedSet), true);
            }
            unset($s);
        }

        // Batch-load participant first names for logged-in users
        if ($sessions && can_see_names()) {
            $ids = array_column($sessions, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = $this->db->fetchAll(
                "SELECT sp.session_id, u.display_name
                 FROM ld_session_participants sp
                 JOIN users u ON sp.user_id = u.id
                 WHERE sp.session_id IN ($placeholders)
                 ORDER BY FIELD(sp.role, 'facilitator', 'model', 'artist', 'observer'), sp.id ASC",
                $ids
            );
            $bySession = [];
            foreach ($rows as $row) {
                $bySession[$row['session_id']][] = explode(' ', $row['display_name'])[0];
            }
            foreach ($sessions as &$s) {
                $s['participants'] = $bySession[$s['id']] ?? [];
            }
            unset($s);
        }

        $data = [
            'sessions' => $sessions,
            'activeView' => $activeView,
            'page' => $page,
            'totalPages' => $totalPages,
        ];

        if ($request->isHtmx()) {
            return $this->partial('sessions._tab_content', $data);
        }

        return $this->render('sessions.index', $data, 'Sessions', [
            'meta_description' => 'Upcoming and past life drawing sessions in Randburg, Johannesburg. View dates, venues, and RSVP.',
            'json_ld' => self::breadcrumbJsonLd([['Home', '/'], ['Sessions', '/sessions']]),
        ]);
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

        if ($session['status'] === 'cancelled') {
            return can_manage_session($session)
                ? Response::redirect(route('manage.session', ['id' => hex_id($id)]))->withHeader('Cache-Control', 'private, no-store')
                : Response::notFound('Session not found.');
        }

        // Get participants — the facilitator's manager needs the full set,
        // every other viewer gets the consent-filtered one.
        $participants = $this->getParticipants($id, $this->auth->hasRole('admin', 'facilitator'));

        // Get artworks. The old query carried an "OR a.uploaded_by = ?" escape
        // hatch so an uploader saw their own work regardless of visibility. The
        // only other states are 'removed' and 'private', and both must stay
        // hidden from everyone — 'private' is set by consent withdrawal, and
        // showing it back to the uploader is showing it to the person who asked
        // for it to be gone.
        $artworks = $this->db->fetchAll(
            "SELECT a.*, u.display_name as uploader_name,
                    (SELECT GROUP_CONCAT(CONCAT(c.claim_type, ':', cu.display_name) SEPARATOR ', ')
                     FROM ld_claims c
                     JOIN users cu ON c.claimant_id = cu.id
                     WHERE c.artwork_id = a.id AND c.status = 'approved'
                       AND cu.consent_state = 'granted'
                    ) as claims_summary,
                    (SELECT COUNT(*) FROM ld_comments ldc
                     JOIN users ldu ON ldc.user_id = ldu.id
                     WHERE ldc.artwork_id = a.id AND ldu.consent_state = 'granted'
                    ) as comment_count
             FROM ld_artworks a
             JOIN users u ON a.uploaded_by = u.id
             WHERE a.session_id = ?
               AND a.visibility IN ('session', 'claimed', 'public')
             ORDER BY a.pose_index ASC, a.created_at ASC",
            [$id]
        );

        $participantCount = count($participants);
        $artworkCount = count($artworks);
        $modelClaimContext = $this->sessionModelClaimContext($id);
        $joinedRoles = $this->joinedRoles($id, $this->userId());
        $sessionDesc = 'Life drawing session on ' . format_date($session['session_date'])
            . ' at ' . $session['venue'] . '. '
            . $participantCount . ' participant' . ($participantCount !== 1 ? 's' : '')
            . ', ' . $artworkCount . ' artwork' . ($artworkCount !== 1 ? 's' : '') . '.';

        // Event JSON-LD
        $startTime = $session['start_time'] ? 'T' . $session['start_time'] : 'T10:00:00';
        $endMinutes = (int) ($session['duration_minutes'] ?? 180);
        $startDt = $session['session_date'] . $startTime . '+02:00';
        $endDt = date('Y-m-d\TH:i:s', strtotime($session['session_date'] . ' ' . ($session['start_time'] ?: '10:00:00')) + $endMinutes * 60) . '+02:00';
        $eventJsonLd = '<script type="application/ld+json">'
            . json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'Event',
                'name' => session_title($session),
                'startDate' => $startDt,
                'endDate' => $endDt,
                'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                'eventStatus' => 'https://schema.org/EventScheduled',
                'location' => [
                    '@type' => 'Place',
                    'name' => $session['venue'],
                    'address' => [
                        '@type' => 'PostalAddress',
                        'streetAddress' => '10 Victoria Street, Kensington-B',
                        'addressLocality' => 'Randburg',
                        'addressRegion' => 'Gauteng',
                        'postalCode' => '2194',
                        'addressCountry' => 'ZA',
                    ],
                ],
                'description' => $sessionDesc,
                'organizer' => [
                    '@type' => 'Organization',
                    'name' => 'Life Drawing Randburg',
                    'url' => 'https://lifedrawing.andresclements.com/randburg',
                ],
                'offers' => [
                    '@type' => 'Offer',
                    'price' => '350',
                    'priceCurrency' => 'ZAR',
                    'availability' => 'https://schema.org/InStock',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . '</script>';

        $breadcrumbs = self::breadcrumbJsonLd([
            ['Home', '/'],
            ['Sessions', '/sessions'],
            [session_title($session), '/sessions/' . hex_id($id, session_title($session))],
        ]);

        return $this->render('sessions.show', [
            'session' => $session,
            'participants' => $participants,
            'artworks' => $artworks,
            'sessionHasKnownModel' => $modelClaimContext['sessionHasKnownModel'],
            'isSessionModel' => $modelClaimContext['isSessionModel'],
            'joinedRoles' => $joinedRoles,
        ], session_title($session), [
            'meta_description' => $sessionDesc,
            'json_ld' => $eventJsonLd . $breadcrumbs,
        ]);
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
        $subtitle = trim($request->input('subtitle', 'Regular Session Format'));
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
            'subtitle' => $subtitle ?: 'Regular Session Format',
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
            // Both columns, together. No-show undo restores from `attended`, so
            // a row with attended=1 but attendance='booked' would be *promoted*
            // to 'attended' by an undo - a state it never held.
            'attendance' => 'attended',
        ]);

        $this->provenance->log(
            $this->userId(),
            'session.create',
            'session',
            (int) $id,
            ['title' => $title, 'date' => $date]
        );

        // Notify opted-in users about the new session
        $session = $this->table('ld_sessions')->where('id', '=', (int) $id)->first();
        if ($session) {
            app('notifications')->sessionCreated($session, $this->userId());
        }

        return Response::redirect(route('sessions.show', ['id' => hex_id((int) $id, $title)]));
    }

    /**
     * Get participant list for a session (used by show + HTMX responses).
     *
     * This feeds two audiences. The public list must not name someone who has
     * withdrawn consent; the facilitator's participant manager must still show
     * them, because André has real obligations to people and cannot act on a
     * row he cannot see. So withdrawn users keep their place in the list — the
     * count stays correct, and the listing cards still match the WhatsApp
     * numbering — but lose their name, which visible_name() renders as
     * "Participant".
     */
    private function getParticipants(int $sessionId, bool $forFacilitator = false): array
    {
        $nameExpr = $forFacilitator
            ? 'u.display_name'
            : "CASE WHEN u.consent_state = 'withdrawn' THEN NULL ELSE u.display_name END";

        return $this->db->fetchAll(
            "SELECT sp.*, {$nameExpr} AS display_name, u.consent_state
             FROM ld_session_participants sp
             JOIN users u ON sp.user_id = u.id
             WHERE sp.session_id = ?
             ORDER BY FIELD(sp.role, 'facilitator', 'model', 'artist', 'observer'), sp.id ASC",
            [$sessionId]
        );
    }

    /** Search users for participant typeahead (facilitator, HTMX). */
    public function searchParticipants(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $sessionId = from_hex($request->param('id'));
        $target = $this->db->fetch("SELECT status FROM ld_sessions WHERE id = ?", [$sessionId]);
        if (!$target || $target['status'] === 'cancelled') return Response::notFound('Session unavailable.');
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
                       AND s2.status <> 'cancelled' AND s2.session_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
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
            'query' => $q,
            'role' => $role,
        ]);
    }

    /** Create a new stub user and add them to the session as a participant (facilitator). */
    public function quickAddStub(Request $request): Response
    {
        if ($redirect = $this->requireAuth($request)) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;
        $id = from_hex($request->param('id'));
        $name = trim((string) $request->input('display_name', ''));
        if ($name === '' || mb_strlen($name) > 100) return Response::error('Enter a name of up to 100 characters.', 422);
        try {
            $userId = $this->db->transaction(function () use ($id, $name, $request) {
                \App\Services\SessionAccess::session($this->db, $id, true);
                $existing = $this->db->fetchColumn("SELECT id FROM users WHERE display_name = ? LIMIT 1", [$name]);
                $userId = $existing ? (int) $existing : $this->auth->createStub($name);
                (new \App\Services\ParticipationService($this->db))->add($id, $this->userId(), $userId,
                    (string) $request->input('role', 'artist'), true, (bool) $request->input('tentative'));
                return $userId;
            });
            $warning = (new \App\Services\SessionManagementService($this->db))->refreshStats($userId);
            if ($warning) $_SESSION['management_message'] = $warning;
            return $this->participantResponse($request, $id);
        } catch (\DomainException $e) { return Response::error(e($e->getMessage()), $e->getCode() ?: 422); }
    }

    /** Add a participant to a session (facilitator). */
    public function addParticipant(Request $request): Response
    {
        if ($redirect = $this->requireAuth($request)) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;
        $id = from_hex($request->param('id'));
        $userId = (int) $request->input('user_id');
        try {
            (new \App\Services\ParticipationService($this->db))->add($id, $this->userId(), $userId,
                (string) $request->input('role', 'artist'), true, (bool) $request->input('tentative'));
            $warning = (new \App\Services\SessionManagementService($this->db))->refreshStats($userId);
            if ($warning) $_SESSION['management_message'] = $warning;
            return $this->participantResponse($request, $id);
        } catch (\DomainException $e) { return Response::error(e($e->getMessage()), $e->getCode() ?: 422); }
    }

    /** Remove a participant from a session (facilitator). */
    public function removeParticipant(Request $request): Response
    {
        if ($redirect = $this->requireAuth($request)) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;
        $id = from_hex($request->param('id'));
        try {
            $p = (new \App\Services\ParticipationService($this->db))->remove($id, $this->userId(), (int) $request->input('pid'), true);
            if ($p) {
                $warning = (new \App\Services\SessionManagementService($this->db))->refreshStats((int) $p['user_id']);
                if ($warning) $_SESSION['management_message'] = $warning;
            }
            return $this->participantResponse($request, $id);
        } catch (\DomainException $e) { return Response::error(e($e->getMessage()), $e->getCode() ?: 422); }
    }

    /** Toggle tentative flag on a participant (facilitator). */
    public function toggleTentative(Request $request): Response
    {
        if ($redirect = $this->requireAuth($request)) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;
        $id = from_hex($request->param('id'));
        try {
            $this->db->transaction(function () use ($id, $request) {
                \App\Services\SessionAccess::session($this->db, $id, true);
                $this->db->execute("UPDATE ld_session_participants SET tentative = ? WHERE id = ? AND session_id = ? AND role <> 'facilitator'",
                    [$request->input('tentative') ? 1 : 0, (int) $request->input('pid'), $id]);
            });
            return $this->participantResponse($request, $id);
        } catch (\DomainException $e) { return Response::error(e($e->getMessage()), $e->getCode() ?: 422); }
    }

    /** WhatsApp schedule output (facilitator). */
    public function whatsappSchedule(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        // Use the app timezone (Africa/Johannesburg) for "today", not the
        // MySQL server's timezone — DreamHost is US-based, so CURDATE() can
        // still read as yesterday and leak a past session into the schedule.
        $sessions = $this->db->fetchAll(
            "SELECT s.* FROM ld_sessions s
             WHERE s.status IN ('scheduled', 'active')
               AND s.session_date >= ?
             ORDER BY s.session_date ASC, s.start_time ASC, s.id ASC
             LIMIT 6",
            [date('Y-m-d')]
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
                 ORDER BY FIELD(sp.role, 'facilitator', 'model', 'artist', 'observer'), sp.id ASC",
                $sessionIds
            );
            foreach ($rows as $row) {
                $allParticipants[(int) $row['session_id']][] = $row;
            }
        }

        return $this->render('sessions.whatsapp', [
            'schedule' => whatsapp_schedule($sessions, $allParticipants),
        ], 'WhatsApp Schedule');
    }

    /** Retain the session as cancelled, before its start and before any uploads. */
    public function cancel(Request $request): Response
    {
        return (new ManagementController(\App\Container::getInstance()))->cancel($request);
    }

    /** Join a session as participant (authenticated). */
    public function join(Request $request): Response
    {
        if ($redirect = $this->requireAuth($request)) return $redirect;
        $id = from_hex($request->param('id'));
        $role = (string) $request->input('role', 'artist');
        if (!in_array($role, ['artist','model','observer'], true)) $role = 'artist';
        try {
            $changed = (new \App\Services\ParticipationService($this->db))->add($id, $this->userId(), $this->userId(), $role);
            if ($changed) {
                (new \App\Services\SessionManagementService($this->db))->refreshStats($this->userId());
                app('notifications')->sessionJoined($id, $this->userId(), $role);
            }
            $session = \App\Services\SessionAccess::session($this->db, $id);
            if ($request->isHtmx()) return $this->partial('sessions._join_control', ['session' => $session, 'joinedRoles' => $this->joinedRoles($id, $this->userId())]);
            return Response::redirect(route('sessions.show', ['id' => hex_id($id)]));
        } catch (\DomainException $e) { return Response::error(e($e->getMessage()), $e->getCode() ?: 422); }
    }

    /**
     * POST /sessions/{id}/leave - cancel your own booking.
     *
     * Auth only, deliberately outside ConsentGate: see the note in routes.php.
     */
    public function leave(Request $request): Response
    {
        if ($redirect = $this->requireAuth($request)) return $redirect;
        $id = from_hex($request->param('id'));
        $role = (string) $request->input('role', 'artist');
        try {
            $session = \App\Services\SessionAccess::session($this->db, $id);
            $pid = (int) $this->db->fetchColumn("SELECT id FROM ld_session_participants WHERE session_id = ? AND user_id = ? AND role = ?", [$id, $this->userId(), $role]);
            $p = (new \App\Services\ParticipationService($this->db))->remove($id, $this->userId(), $pid);
            if ($p) {
                (new \App\Services\SessionManagementService($this->db))->refreshStats($this->userId());
                app('notifications')->sessionLeft($id, $this->userId(), $role, is_late_cancel($session));
            }
            if ($request->isHtmx()) return $this->partial('sessions._join_control', ['session' => $session, 'joinedRoles' => $this->joinedRoles($id, $this->userId())]);
            return Response::redirect($request->input('return') === 'dashboard' ? route('dashboard') : route('sessions.show', ['id' => hex_id($id)]));
        } catch (\DomainException $e) { return Response::error(e($e->getMessage()), $e->getCode() ?: 422); }
    }

    /**
     * POST /sessions/{id}/participants/no-show - facilitator marks a booking as
     * not honoured, or takes the mark off again.
     *
     * The historical route name is retained; commands now carry explicit target states.
     */
    public function toggleNoShow(Request $request): Response
    {
        if ($redirect = $this->requireAuth($request)) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;
        $id = from_hex($request->param('id'));
        try {
            $result = (new \App\Services\SessionNotesService($this->db))->attendance($id, $this->userId(),
                (int) $request->input('pid'), (string) $request->input('action', ''),
                (string) $request->input('expected_attendance', ''), (string) $request->input('request_key', ''));
            if ($result['warning']) $_SESSION['management_message'] = $result['warning'];
            return $this->participantResponse($request, $id);
        } catch (\DomainException $e) { return Response::error(e($e->getMessage()), $e->getCode() ?: 422); }
    }

    private function participantResponse(Request $request, int $id): Response
    {
        $session = \App\Services\SessionAccess::session($this->db, $id);
        if ($request->isHtmx()) {
            return $this->partial('sessions._participant_manager', [
                'session' => $session, 'participants' => $this->getParticipants($id, true),
            ])->withHeader('Cache-Control', 'private, no-store');
        }
        return Response::redirect(route('manage.session', ['id' => hex_id($id)]));
    }

    /** Roles the given user already holds on a session. */
    private function joinedRoles(int $sessionId, ?int $userId): array
    {
        if (!$userId) return [];

        $rows = $this->db->fetchAll(
            "SELECT role FROM ld_session_participants WHERE session_id = ? AND user_id = ?",
            [$sessionId, $userId]
        );
        return array_column($rows, 'role');
    }
}
