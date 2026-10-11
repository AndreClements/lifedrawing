<?php

declare(strict_types=1);

namespace Modules\Lifedrawing\Controllers;

use App\Request;
use App\Response;
use App\Services\SessionAccess;
use App\Services\SessionNotesService;
use App\Services\SessionManagementService;
use App\Services\PoseService;
use DomainException;

final class ManagementController extends BaseController
{
    private function guard(Request $request): ?Response
    {
        return $this->requireAuth($request) ?? $this->requireRole('admin', 'facilitator');
    }

    private function privateResponse(Response $response): Response
    {
        return $response->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function data(int $id, array $old = []): array
    {
        $session = SessionAccess::session($this->db, $id, allowCancelled: true);
        $participants = $this->db->fetchAll(
            "SELECT p.*, u.display_name, u.consent_state, u.avatar_path FROM ld_session_participants p
             JOIN users u ON u.id = p.user_id WHERE p.session_id = ?
             ORDER BY FIELD(p.role, 'facilitator', 'model', 'artist', 'observer'), p.id", [$id]);
        $groups = (new PoseService($this->db))->groups($id);
        $legacy = (bool) $this->db->fetchColumn("SELECT id FROM ld_artworks WHERE session_id = ? AND pose_number IS NULL LIMIT 1", [$id]);
        $notes = (new SessionNotesService($this->db))->listing($id, $this->userId());
        return compact('session', 'participants', 'groups', 'legacy', 'notes', 'old');
    }

    public function index(Request $request): Response
    {
        if ($response = $this->guard($request)) return $response;
        $cancelled = $request->input('state') === 'cancelled';
        $page = max(1, (int) $request->input('page', 1));
        $operator = $cancelled ? '=' : '<>';
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM ld_sessions WHERE status {$operator} 'cancelled'");
        $pages = max(1, (int) ceil($total / 30));
        $page = min($page, $pages);
        $sessions = $this->db->fetchAll("SELECT * FROM ld_sessions WHERE status {$operator} 'cancelled' ORDER BY session_date DESC, id DESC LIMIT 30 OFFSET ?", [($page - 1) * 30]);
        return $this->privateResponse($this->render('manage.index', compact('sessions', 'cancelled', 'page', 'pages'), 'Manage sessions'));
    }

    public function session(Request $request): Response
    {
        if ($response = $this->guard($request)) return $response;
        try { $data = $this->data(from_hex($request->param('id'))); }
        catch (DomainException $e) { return Response::notFound(e($e->getMessage())); }
        $data['success'] = $_SESSION['management_message'] ?? null;
        unset($_SESSION['management_message']);
        return $this->privateResponse($this->render('manage.session', $data, 'Manage session'));
    }

    public function notes(Request $request): Response
    {
        if ($response = $this->guard($request)) return $response;
        try { return $this->privateResponse($this->partial('manage._notes', $this->data(from_hex($request->param('id'))))); }
        catch (DomainException $e) { return Response::notFound(e($e->getMessage())); }
    }

    private function change(Request $request, callable $action): Response
    {
        if ($response = $this->guard($request)) return $response;
        $id = from_hex($request->param('id'));
        try {
            $warning = $action($id);
            $message = $warning ?: 'Saved.';
            if ($request->wantsJson()) {
                return $this->privateResponse(Response::json(['success' => true, 'message' => $message,
                    'notes_html' => $this->view->render('manage._notes', $this->data($id))]));
            }
            $_SESSION['management_message'] = $message;
            return Response::redirect(route('manage.session', ['id' => hex_id($id)]));
        } catch (DomainException $e) {
            $status = in_array($e->getCode(), [403, 404, 409, 422], true) ? $e->getCode() : 422;
            if ($request->wantsJson()) return $this->privateResponse(Response::json(['error' => $e->getMessage()], $status));
            if ($status === 403 || $status === 404) return Response::error(e($e->getMessage()), $status);
            return $this->privateResponse($this->render('manage.session', $this->data($id, $request->post) + ['error' => $e->getMessage()], 'Manage session')->withStatus($status));
        } catch (\Throwable $e) {
            error_log('Management save failed: ' . $e->getMessage());
            $message = 'The save could not be completed. Your input is still here; please retry.';
            if ($request->wantsJson()) return $this->privateResponse(Response::json(['error' => $message], 500));
            return $this->privateResponse($this->render('manage.session', $this->data($id, $request->post) + ['error' => $message], 'Manage session')->withStatus(500));
        }
    }

    public function saveNote(Request $request): Response
    {
        return $this->change($request, function ($id) use ($request) {
            (new SessionNotesService($this->db))->create($id, $this->userId(), $request->post, (string) $request->input('request_key', ''));
            return null;
        });
    }

    public function editNote(Request $request): Response
    {
        return $this->change($request, function ($id) use ($request) {
            (new SessionNotesService($this->db))->edit($id, $this->userId(), (int) $request->input('note_id'),
                (string) $request->input('expected_updated_at', ''), $request->post, $request->input('action') === 'remove');
            return null;
        });
    }

    public function attendance(Request $request): Response
    {
        return $this->change($request, fn($id) => (new SessionNotesService($this->db))->attendance($id, $this->userId(),
            (int) $request->input('pid'), (string) $request->input('action', ''), (string) $request->input('expected_attendance', ''),
            (string) $request->input('request_key', ''))['warning']);
    }

    public function role(Request $request): Response
    {
        return $this->change($request, fn($id) => (new SessionManagementService($this->db))->changeRole(
            $id, $this->userId(), (int) $request->input('pid'), (string) $request->input('expected_role', ''), (string) $request->input('role', '')));
    }

    public function details(Request $request): Response
    {
        return $this->change($request, fn($id) => (new SessionManagementService($this->db))->details($id, $this->userId(), $request->post));
    }

    public function normalizePoses(Request $request): Response
    {
        return $this->change($request, function ($id) use ($request) {
            $this->db->transaction(function () use ($id, $request) {
                SessionAccess::session($this->db, $id, true);
                (new PoseService($this->db))->normalize($id, (bool) $request->input('confirm_groups'));
                $this->provenance->record($this->userId(), 'session.poses.number', 'session', $id);
            });
            return null;
        });
    }

    public function caption(Request $request): Response
    {
        return $this->change($request, function ($id) use ($request) {
            $caption = trim((string) $request->input('caption', ''));
            if (mb_strlen($caption) > 4000) throw new DomainException('Keep the caption under 4,000 characters.', 422);
            $this->db->transaction(function () use ($id, $request, $caption) {
                SessionAccess::session($this->db, $id, true);
                $artwork = $this->db->fetch("SELECT id, updated_at FROM ld_artworks WHERE id = ? AND session_id = ? AND visibility NOT IN ('removed','private') FOR UPDATE",
                    [(int) $request->input('artwork_id'), $id]);
                if (!$artwork) throw new DomainException('Artwork unavailable.', 404);
                if ($artwork['updated_at'] !== $request->input('expected_updated_at')) throw new DomainException('This artwork changed. Reload before editing it.', 409);
                $this->db->execute("UPDATE ld_artworks SET caption = ?, updated_at = GREATEST(NOW(6), updated_at + INTERVAL 1 MICROSECOND) WHERE id = ?", [$caption ?: null, $artwork['id']]);
                $this->provenance->record($this->userId(), 'artwork.caption', 'artwork', (int) $artwork['id']);
            });
            return null;
        });
    }

    public function pose(Request $request): Response
    {
        return $this->change($request, function ($id) use ($request) {
            $label = trim((string) $request->input('pose_label', ''));
            $duration = trim((string) $request->input('pose_duration', ''));
            if (mb_strlen($label) > 100 || mb_strlen($duration) > 50) throw new DomainException('Keep the pose label under 100 characters and duration under 50.', 422);
            $this->db->transaction(function () use ($id, $request, $label, $duration) {
                SessionAccess::session($this->db, $id, true);
                $rows = $this->db->fetchAll("SELECT id, updated_at FROM ld_artworks WHERE session_id = ? AND pose_number = ? ORDER BY id FOR UPDATE", [$id, (int) $request->input('pose_number')]);
                if (!$rows) throw new DomainException('Pose not found.', 404);
                if (!hash_equals(hash('sha256', json_encode($rows)), (string) $request->input('expected_pose', ''))) throw new DomainException('This pose changed or gained photos. Reload and review the group.', 409);
                $this->db->execute("UPDATE ld_artworks SET pose_label = ?, pose_duration = ?, updated_at = GREATEST(NOW(6), updated_at + INTERVAL 1 MICROSECOND) WHERE session_id = ? AND pose_number = ?", [$label ?: null, $duration ?: null, $id, (int) $request->input('pose_number')]);
                $this->provenance->record($this->userId(), 'session.pose.edit', 'session', $id, ['pose_number' => (int) $request->input('pose_number'), 'images' => count($rows)]);
            });
            return null;
        });
    }

    public function cancel(Request $request): Response
    {
        return $this->change($request, function ($id) use ($request) {
            $result = (new SessionManagementService($this->db))->cancel($id, $this->userId(), (string) $request->input('reason', ''));
            if ($result['changed']) {
                try { app('notifications')->sessionCancelled($result['session']); }
                catch (\Throwable $e) {
                    error_log('Cancellation notice failed: ' . $e->getMessage());
                    return 'Session cancelled. The cancellation notice could not be sent.';
                }
            }
            return $result['warning'];
        });
    }

    public function restore(Request $request): Response
    {
        return $this->change($request, fn($id) => (new SessionManagementService($this->db))->restore($id, $this->userId(), $request->input('previous_status')));
    }

    public function refresh(Request $request): Response
    {
        return $this->change($request, fn($id) => (new SessionManagementService($this->db))->refreshStats());
    }

    private const PEOPLE_FIELDS = 'u.id, u.display_name, u.pseudonym, u.email, u.whatsapp_number, u.role,
        u.consent_state, u.created_at, u.avatar_path, u.sitter_pref_friday, u.sitter_pref_saturday,
        u.sitter_pref_sunday, u.sitter_auto_rejoin, u.sitter_notes';

    public function people(Request $request): Response
    {
        if ($response = $this->guard($request)) return $response;
        $q = mb_substr(trim((string) $request->input('q', '')), 0, 200);
        $params = [];
        $where = '';
        if ($q !== '') {
            $where = " WHERE (u.display_name LIKE ? OR u.pseudonym LIKE ? OR u.email LIKE ? OR CAST(u.id AS CHAR) = ?";
            $params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%', $q];
            $digits = preg_replace('/\D/', '', $q);
            if ($digits !== '') {
                $where .= " OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(u.whatsapp_number, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '') LIKE ?";
                $params[] = '%' . $digits . '%';
            }
            $where .= ')';
        }
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM users u" . $where, $params);
        $pages = max(1, (int) ceil($total / 30));
        $page = min($pages, max(1, (int) $request->input('page', 1)));
        $people = $this->db->fetchAll("SELECT " . self::PEOPLE_FIELDS . " FROM users u" . $where . " ORDER BY u.display_name, u.id LIMIT 30 OFFSET ?", [...$params, ($page - 1) * 30]);
        return $this->privateResponse($this->render('manage.people', compact('people', 'q', 'page', 'pages'), 'People'));
    }

    public function person(Request $request): Response
    {
        if ($response = $this->guard($request)) return $response;
        $id = from_hex($request->param('user'));
        $person = $this->db->fetch("SELECT " . self::PEOPLE_FIELDS . " FROM users u WHERE u.id = ?", [$id]);
        if (!$person) return Response::notFound('Person not found.');
        $history = $this->db->fetchAll("SELECT s.id, s.title, s.session_date, s.status, p.role, p.attendance
            FROM ld_session_participants p JOIN ld_sessions s ON s.id = p.session_id WHERE p.user_id = ?
            ORDER BY s.session_date DESC, s.id DESC LIMIT 50", [$id]);
        $data = compact('person', 'history');
        return $this->privateResponse($request->isHtmx()
            ? $this->partial('manage._person', $data)
            : $this->render('manage._person', $data, 'Contact details'));
    }
}
