<?php

declare(strict_types=1);

namespace Modules\Lifedrawing\Controllers;

use App\Request;
use App\Response;

/**
 * Pose Controller — sitter/model queue management.
 *
 * Public info page at /pose. Facilitator queue at /pose/queue.
 * Users see only their own status, never the queue itself.
 */
final class PoseController extends BaseController
{
    /**
     * GET /pose — info page + queue join form for logged-in users.
     */
    public function index(Request $request): Response
    {
        $userId = $this->userId();
        $data = [
            'loggedIn' => $userId !== null,
            'inQueue'  => false,
            'entry'    => null,
            'user'     => null,
            'scheduledSession' => null,
        ];

        if ($userId) {
            $data['user'] = $this->auth->currentUser();
            $data['entry'] = $this->table('ld_sitter_queue')
                ->where('user_id', '=', $userId)
                ->whereIn('status', ['waiting', 'scheduled'])
                ->first();
            $data['inQueue'] = $data['entry'] !== null;

            if ($data['entry'] && $data['entry']['scheduled_session_id']) {
                $data['scheduledSession'] = $this->table('ld_sessions')
                    ->where('id', '=', (int) $data['entry']['scheduled_session_id'])
                    ->first();
            }
        }

        return $this->render('pose.index', $data, 'Pose for Us', [
            'meta_description' => 'Pose as a life drawing model in Randburg, Johannesburg. Join our sitter queue and get scheduled for upcoming sessions.',
            'json_ld' => self::breadcrumbJsonLd([['Home', '/'], ['Pose', '/pose']]),
        ]);
    }

    /**
     * POST /pose/join — join the sitter queue (consent-gated).
     */
    public function join(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;

        $userId = $this->userId();

        // Duplicate check: max one active entry.
        //
        // Read-then-insert with no lock is the very pattern SitterQueueService
        // refuses to use, and a double-submit here leaves two 'waiting' rows.
        // activeEntry() only ever resolves the earliest, so the second would sit
        // in the queue forever, invisible to the sitter and looking like an
        // available model to the facilitator.
        $existing = $this->table('ld_sitter_queue')
            ->where('user_id', '=', $userId)
            ->whereIn('status', ['waiting', 'scheduled'])
            ->first();

        if ($existing) {
            return Response::redirect(route('pose.index'));
        }

        $whatsapp    = trim($request->input('whatsapp_number', ''));
        $prefFri     = $request->input('day_friday') ? 1 : 0;
        $prefSat     = $request->input('day_saturday') ? 1 : 0;
        $prefSun     = $request->input('day_sunday') ? 1 : 0;
        $autoRejoin  = $request->input('auto_rejoin') ? 1 : 0;
        $note        = trim($request->input('note', ''));

        $errors = [];
        if ($whatsapp === '') {
            $errors[] = 'WhatsApp number is required so we can contact you.';
        }
        if (!$prefFri && !$prefSat && !$prefSun) {
            $errors[] = 'Please select at least one day you are available.';
        }

        if (!empty($errors)) {
            $data = [
                'loggedIn' => true,
                'inQueue'  => false,
                'entry'    => null,
                'user'     => $this->auth->currentUser(),
                'errors'   => $errors,
                'old'      => $request->all(),
            ];
            return $this->render('pose.index', $data, 'Pose for Us');
        }

        // Save sitter prefs to user profile (persist across queue entries)
        $this->table('users')
            ->where('id', '=', $userId)
            ->update([
                'whatsapp_number'      => $whatsapp,
                'sitter_pref_friday'   => $prefFri,
                'sitter_pref_saturday' => $prefSat,
                'sitter_pref_sunday'   => $prefSun,
                'sitter_auto_rejoin'   => $autoRejoin,
            ]);

        // One statement, so a double-submit cannot create two active entries.
        // The check above is for the redirect; this is what actually guarantees
        // it. activeEntry() only ever resolves the earliest row, so a second one
        // would sit in the queue invisible to the sitter and look like an
        // available model to the facilitator.
        //
        // requested_at from PHP, in the app timezone: left to MySQL's DEFAULT it
        // takes the server clock, which runs about nine hours behind SAST, and
        // classify() compares it against a PHP date.
        $this->db->execute(
            "INSERT INTO ld_sitter_queue (user_id, note, status, requested_at)
             SELECT ?, ?, 'waiting', ?
             FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM ld_sitter_queue
                 WHERE user_id = ? AND status IN ('waiting', 'scheduled')
             )",
            [$userId, $note ?: null, date('Y-m-d H:i:s'), $userId]
        );

        $entryId = (int) $this->db->fetchColumn(
            "SELECT id FROM ld_sitter_queue
             WHERE user_id = ? AND status IN ('waiting', 'scheduled')
             ORDER BY requested_at ASC LIMIT 1",
            [$userId]
        );

        $this->provenance->log(
            $userId,
            'sitter_queue.join',
            'sitter_queue',
            (int) $entryId,
            ['days' => compact('prefFri', 'prefSat', 'prefSun')]
        );

        app('notifications')->sitterQueueJoined($userId);

        return Response::redirect(route('pose.index'));
    }

    /**
     * POST /pose/withdraw — user withdraws from queue.
     */
    public function withdraw(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;

        $userId = $this->userId();
        $entry = $this->table('ld_sitter_queue')
            ->where('user_id', '=', $userId)
            ->whereIn('status', ['waiting', 'scheduled'])
            ->first();

        if ($entry) {
            $this->table('ld_sitter_queue')
                ->where('id', '=', (int) $entry['id'])
                ->update([
                    'status'      => 'withdrawn',
                    'resolved_at' => date('Y-m-d H:i:s'),
                ]);

            $this->provenance->log(
                $userId,
                'sitter_queue.withdraw',
                'sitter_queue',
                (int) $entry['id'],
                []
            );
        }

        return Response::redirect(route('pose.index'));
    }

    /**
     * GET /pose/queue — facilitator queue management page.
     */
    public function queue(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        // Auto-complete scheduled entries whose session has passed
        $this->autoCompleteExpired();

        $activeView = $request->input('view', '') === 'history' ? 'history' : 'active';

        if ($activeView === 'active') {
            $entries = $this->db->fetchAll(
                "SELECT q.*, u.display_name, u.email, u.whatsapp_number, u.consent_state,
                        u.sitter_pref_friday, u.sitter_pref_saturday, u.sitter_pref_sunday,
                        u.sitter_auto_rejoin, u.sitter_notes,
                        s.title as session_title, s.session_date, s.id as sid
                 FROM ld_sitter_queue q
                 JOIN users u ON q.user_id = u.id
                 LEFT JOIN ld_sessions s ON q.scheduled_session_id = s.id
                 WHERE q.status IN ('waiting', 'scheduled')
                 ORDER BY FIELD(q.status, 'waiting', 'scheduled'), q.requested_at ASC"
            );
        } else {
            $entries = $this->db->fetchAll(
                "SELECT q.*, u.display_name, u.email, u.whatsapp_number, u.consent_state,
                        u.sitter_pref_friday, u.sitter_pref_saturday, u.sitter_pref_sunday,
                        u.sitter_notes,
                        s.title as session_title, s.session_date, s.id as sid,
                        r.display_name as resolved_by_name
                 FROM ld_sitter_queue q
                 JOIN users u ON q.user_id = u.id
                 LEFT JOIN ld_sessions s ON q.scheduled_session_id = s.id
                 LEFT JOIN users r ON q.resolved_by = r.id
                 WHERE q.status IN ('completed', 'withdrawn')
                 ORDER BY q.resolved_at DESC
                 LIMIT 50"
            );
        }

        $upcomingSessions = $this->table('ld_sessions')
            ->where('session_date', '>=', date('Y-m-d'))
            ->whereIn('status', ['scheduled', 'active'])
            ->orderBy('session_date', 'ASC')
            ->get();

        $data = [
            'entries'          => $entries,
            'activeView'       => $activeView,
            'upcomingSessions' => $upcomingSessions,
        ];

        if ($request->isHtmx()) {
            return $this->partial('pose._queue_tab_content', $data);
        }

        return $this->render('pose.queue', $data, 'Sitter Queue');
    }

    /**
     * POST /pose/queue/{id}/schedule — facilitator marks entry as scheduled.
     */
    public function schedule(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $entryId = from_hex($request->param('id'));
        $sessionId = (int) $request->input('session_id', 0);

        $entry = $this->table('ld_sitter_queue')
            ->where('id', '=', $entryId)
            ->where('status', '=', 'waiting')
            ->first();

        if (!$entry) {
            return Response::notFound('Queue entry not found.');
        }

        // A real session is now required. "No specific session" left
        // scheduled_session_id NULL, which the auto-complete could never match,
        // so those entries sat in the queue forever.
        $session = $sessionId > 0
            ? $this->table('ld_sessions')->where('id', '=', $sessionId)->first()
            : null;

        if (!$session) {
            return Response::error('Pick the session they are booked for.', 400);
        }

        // The dropdown only offers upcoming sessions, but the POST was not
        // checked. Scheduling onto a past session inserts a participant row and
        // rewrites attendance history, which leave() explicitly refuses to do
        // for exactly the same reason.
        if (session_starts_at($session) <= time()) {
            return Response::error('That session has already started.', 400);
        }

        $userId = (int) $entry['user_id'];

        // Scheduling must also produce the booking. Marking the queue entry
        // without adding the participant left the queue saying "scheduled"
        // while the session itself had no model.
        $already = $this->table('ld_session_participants')
            ->where('session_id', '=', $sessionId)
            ->where('user_id', '=', $userId)
            ->where('role', '=', 'model')
            ->first();

        if (!$already) {
            $this->table('ld_session_participants')->insert([
                'session_id' => $sessionId,
                'user_id'    => $userId,
                'role'       => 'model',
            ]);
            $this->provenance->log(
                $this->userId(),
                'participant.add',
                'session',
                $sessionId,
                ['added_user' => $userId, 'role' => 'model', 'via' => 'sitter_queue']
            );
            app('stats')->refreshUser($userId);
        }

        // One code path owns the queue state.
        app('sitterQueue')->onModelAdded($userId, $sessionId, $this->userId());

        return Response::redirect(route('pose.queue'));
    }

    /**
     * POST /pose/queue/{id}/complete — facilitator marks as completed, notifies.
     */
    public function complete(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $entryId = from_hex($request->param('id'));

        $entry = $this->table('ld_sitter_queue')
            ->where('id', '=', $entryId)
            ->where('status', '=', 'scheduled')
            ->first();

        if (!$entry) {
            return Response::notFound('Queue entry not found.');
        }

        // Pressed by hand, so notify — subject to the service's own cutoff on
        // sessions more than 30 days past.
        app('sitterQueue')->apply($entryId, $this->userId(), true);

        return Response::redirect(route('pose.queue'));
    }

    /**
     * POST /pose/queue/{id}/remove — facilitator deletes entry.
     */
    public function remove(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $entryId = from_hex($request->param('id'));

        $this->db->execute("DELETE FROM ld_sitter_queue WHERE id = ?", [$entryId]);

        $this->provenance->log(
            $this->userId(),
            'sitter_queue.remove',
            'sitter_queue',
            $entryId,
            []
        );

        return Response::redirect(route('pose.queue'));
    }

    /**
     * POST /pose/queue/{id}/notes — facilitator updates sitter notes (HTMX).
     */
    public function updateNotes(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $entryId = from_hex($request->param('id'));
        $entry = $this->table('ld_sitter_queue')->where('id', '=', $entryId)->first();

        if (!$entry) {
            return Response::notFound('Queue entry not found.');
        }

        $notes = trim($request->input('sitter_notes', ''));
        $this->table('users')
            ->where('id', '=', (int) $entry['user_id'])
            ->update(['sitter_notes' => $notes ?: null]);

        // Return updated notes display for HTMX swap
        $display = $notes ? e($notes) : '<span class="text-muted">No notes</span>';
        return Response::html($display);
    }

    /**
     * GET /pose/queue/panel — compact queue panel for session pages (HTMX).
     */
    public function queuePanel(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;
        if ($redirect = $this->requireRole('admin', 'facilitator')) return $redirect;

        $this->autoCompleteExpired();

        $entries = $this->db->fetchAll(
            "SELECT q.*, u.display_name, u.whatsapp_number, u.consent_state,
                    u.sitter_pref_friday, u.sitter_pref_saturday, u.sitter_pref_sunday,
                    u.sitter_notes
             FROM ld_sitter_queue q
             JOIN users u ON q.user_id = u.id
             WHERE q.status IN ('waiting', 'scheduled')
             ORDER BY FIELD(q.status, 'waiting', 'scheduled'), q.requested_at ASC"
        );

        $sessionDay = $request->input('day', '');

        return $this->partial('pose._queue_panel', [
            'entries'    => $entries,
            'sessionDay' => $sessionDay,
        ]);
    }

    // --- Private helpers ---

    /**
     * Bring the queue up to date.
     *
     * The rules live in SitterQueueService::classify(), shared with
     * tools/fix-sitter-queue.php, so the live sweep and the repair tool cannot
     * drift apart. The old version here had its own logic and an inner JOIN, so
     * an entry whose session had been deleted was unreachable forever.
     *
     * Off until the backlog has been swept — see config/app.php.
     */
    private function autoCompleteExpired(): void
    {
        if (!config('app.sitter_auto_complete')) {
            return;
        }

        app('sitterQueue')->sweep($this->userId());
    }
}
