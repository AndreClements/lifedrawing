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
            "SELECT u.id, u.display_name, u.bio, u.avatar_path,
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

    /** Show a single profile (public). */
    public function show(Request $request): Response
    {
        $id = (int) $request->param('id');

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

        // Get session history
        $sessions = $this->db->fetchAll(
            "SELECT s.id, s.title, s.session_date, sp.role
             FROM ld_sessions s
             JOIN ld_session_participants sp ON sp.session_id = s.id
             WHERE sp.user_id = ?
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
        $bio = trim($request->input('bio', ''));

        if ($displayName === '') {
            return $this->render('profile.edit', [
                'user' => $this->auth->currentUser(),
                'error' => 'Display name is required.',
            ], 'Edit Profile');
        }

        $this->table('users')
            ->where('id', '=', $this->userId())
            ->update([
                'display_name' => $displayName,
                'bio' => $bio ?: null,
            ]);

        $_SESSION['user_name'] = $displayName;

        $this->provenance->log(
            $this->userId(),
            'profile.update',
            'user',
            $this->userId(),
        );

        return Response::redirect(route('profiles.show', ['id' => $this->userId()]));
    }
}
