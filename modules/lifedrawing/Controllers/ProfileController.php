<?php

declare(strict_types=1);

namespace Modules\Lifedrawing\Controllers;

use App\Request;
use App\Response;

/**
 * Profile Controller — artist and model profiles.
 *
 * Profiles grow organically through claimed artworks and session attendance.
 * No hierarchy of skill — the system tracks engagement, not quality.
 */
final class ProfileController extends BaseController
{
    /** List all artists with public profiles. */
    public function artists(Request $request): Response
    {
        $artists = $this->db->fetchAll(
            "SELECT u.id, u.display_name, u.pseudonym, u.bio, u.avatar_path,
                    COALESCE(s.total_sessions, 0) as total_sessions,
                    COALESCE(s.total_artworks, 0) as total_artworks,
                    COALESCE(s.current_streak, 0) as current_streak
             FROM users u
             LEFT JOIN ld_artist_stats s ON u.id = s.user_id
             WHERE u.consent_state = 'granted'
               AND (SELECT COUNT(*) FROM ld_session_participants sp WHERE sp.user_id = u.id) > 0
             ORDER BY s.total_sessions DESC, u.display_name ASC"
        );

        return $this->render('profile.artists', [
            'artists' => $artists,
        ], 'Artists');
    }

    /** List sitters (people who have modeled). */
    public function sitters(Request $request): Response
    {
        $sitters = $this->db->fetchAll(
            "SELECT u.id, u.display_name, u.pseudonym, u.bio, u.avatar_path,
                    COUNT(DISTINCT sp.session_id) as sessions_sat,
                    COALESCE(s.total_sessions, 0) as total_sessions,
                    COALESCE(s.current_streak, 0) as current_streak
             FROM users u
             JOIN ld_session_participants sp ON sp.user_id = u.id AND sp.role = 'model'
             LEFT JOIN ld_artist_stats s ON u.id = s.user_id
             WHERE u.consent_state = 'granted'
             GROUP BY u.id
             ORDER BY sessions_sat DESC, u.display_name ASC"
        );

        return $this->render('profile.sitters', [
            'sitters' => $sitters,
        ], 'Sitters');
    }

    /** Show a single profile (public). */
    public function show(Request $request): Response
    {
        $id = from_hex($request->param('id'));

        $user = $this->db->fetch(
            "SELECT u.*, COALESCE(s.total_sessions, 0) as total_sessions,
                    COALESCE(s.total_artworks, 0) as total_artworks,
                    COALESCE(s.current_streak, 0) as current_streak,
                    COALESCE(s.longest_streak, 0) as longest_streak,
                    s.media_explored, s.last_session_date
             FROM users u
             LEFT JOIN ld_artist_stats s ON u.id = s.user_id
             WHERE u.id = ? AND u.consent_state = 'granted'",
            [$id]
        );

        if (!$user) {
            return Response::notFound('Profile not found.');
        }

        // Get claimed artworks
        $artworks = $this->db->fetchAll(
            "SELECT a.*, s.title as session_title, s.session_date, c.claim_type
             FROM ld_artworks a
             JOIN ld_claims c ON c.artwork_id = a.id
             JOIN ld_sessions s ON a.session_id = s.id
             WHERE c.claimant_id = ? AND c.status = 'approved'
               AND a.visibility IN ('claimed', 'public')
             ORDER BY s.session_date DESC",
            [$id]
        );

        // Get session history (GROUP_CONCAT avoids duplicates for multi-role sessions)
        $sessions = $this->db->fetchAll(
            "SELECT s.id, s.title, s.session_date,
                    GROUP_CONCAT(sp.role ORDER BY sp.role SEPARATOR ', ') as role
             FROM ld_sessions s
             JOIN ld_session_participants sp ON sp.session_id = s.id
             WHERE sp.user_id = ?
             GROUP BY s.id
             ORDER BY s.session_date DESC
             LIMIT 20",
            [$id]
        );

        return $this->render('profile.show', [
            'profile' => $user,
            'artworks' => $artworks,
            'sessions' => $sessions,
        ], $user['display_name']);
    }

    /** Edit own profile (authenticated). */
    public function edit(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;

        $user = $this->auth->currentUser();
        return $this->render('profile.edit', [
            'user' => $user,
        ], 'Edit Profile');
    }

    /** Update own profile (authenticated). */
    public function update(Request $request): Response
    {
        if ($redirect = $this->requireAuth()) return $redirect;

        $displayName = trim($request->input('display_name', ''));
        $pseudonym = trim($request->input('pseudonym', ''));
        $bio = trim($request->input('bio', ''));

        if ($displayName === '') {
            return $this->render('profile.edit', [
                'user' => $this->auth->currentUser(),
                'error' => 'Display name is required.',
            ], 'Edit Profile');
        }

        // Handle password change (only if current_password is filled)
        $currentPassword = $request->input('current_password', '');
        $newPassword = $request->input('new_password', '');
        $newPasswordConfirm = $request->input('new_password_confirm', '');
        $passwordChanged = false;

        if ($currentPassword !== '') {
            if (strlen($newPassword) < 8) {
                return $this->render('profile.edit', [
                    'user' => $this->auth->currentUser(),
                    'error' => 'New password must be at least 8 characters.',
                ], 'Edit Profile');
            }
            if ($newPassword !== $newPasswordConfirm) {
                return $this->render('profile.edit', [
                    'user' => $this->auth->currentUser(),
                    'error' => 'New passwords do not match.',
                ], 'Edit Profile');
            }
            if (!$this->auth->changePassword($this->userId(), $currentPassword, $newPassword)) {
                return $this->render('profile.edit', [
                    'user' => $this->auth->currentUser(),
                    'error' => 'Current password is incorrect.',
                ], 'Edit Profile');
            }
            $passwordChanged = true;
        }

        $this->table('users')
            ->where('id', '=', $this->userId())
            ->update([
                'display_name' => $displayName,
                'pseudonym' => $pseudonym ?: null,
                'bio' => $bio ?: null,
                'notify_new_session' => $request->input('notify_new_session') ? 1 : 0,
                'notify_session_cancelled' => $request->input('notify_session_cancelled') ? 1 : 0,
                'notify_claim_resolved' => $request->input('notify_claim_resolved') ? 1 : 0,
                'notify_comment' => $request->input('notify_comment') ? 1 : 0,
            ]);

        $_SESSION['user_name'] = $displayName;

        $this->provenance->log(
            $this->userId(),
            'profile.update',
            'user',
            $this->userId(),
        );

        if ($passwordChanged) {
            return $this->render('profile.edit', [
                'user' => $this->auth->currentUser(),
                'success' => 'Profile updated and password changed.',
            ], 'Edit Profile');
        }

        return Response::redirect(route('profiles.show', ['id' => hex_id($this->userId())]));
    }
}
