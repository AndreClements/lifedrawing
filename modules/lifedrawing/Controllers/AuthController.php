<?php

declare(strict_types=1);

namespace Modules\Lifedrawing\Controllers;

use App\Request;
use App\Response;

/**
 * Auth Controller — login, registration, consent, password reset.
 *
 * Extracted from Kernel closures. Thin handlers that validate input,
 * delegate to AuthService, and return responses.
 */
final class AuthController extends BaseController
{
    // --- Login ---

    public function loginForm(Request $request): Response
    {
        if ($this->auth->isLoggedIn()) {
            return Response::redirect(route('home'));
        }
        $this->captureIntent($request);
        return $this->render('auth.login', [], 'Login');
    }

    public function login(Request $request): Response
    {
        $user = $this->auth->attempt(
            $request->input('email', ''),
            $request->input('password', ''),
        );

        if ($user === null) {
            return $this->render('auth.login', [
                'error' => 'Invalid email or password.',
                'email' => $request->input('email', ''),
            ], 'Login');
        }

        if ($request->input('remember', '') === '1') {
            $token = $this->auth->createRememberToken((int) $user['id']);
            $this->auth->setRememberCookie($token);
        }

        // Fulfill pending intent if user already has consent
        $intent = consume_intent();
        if ($intent && $user['consent_state'] === 'granted') {
            return $this->fulfillIntent($intent, (int) $user['id']);
        }
        // Re-store intent for consent page to fulfill
        if ($intent) {
            store_intent($intent['action'], $intent['params']);
        }

        return Response::redirect(route('home'));
    }

    // --- Registration ---

    public function registerForm(Request $request): Response
    {
        if ($this->auth->isLoggedIn()) {
            return Response::redirect(route('home'));
        }
        $this->captureIntent($request);
        return $this->render('auth.register', [
            'has_intent' => !empty($_SESSION['_pending_intent']),
        ], 'Register');
    }

    public function register(Request $request): Response
    {
        $name = trim($request->input('display_name', ''));
        $email = trim($request->input('email', ''));
        $password = $request->input('password', '');
        $confirm = $request->input('password_confirm', '');
        $claimStubId = (int) $request->input('claim_stub_id', 0);

        $errors = [];
        if ($name === '') $errors[] = 'Display name is required.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm) $errors[] = 'Passwords do not match.';

        if (!empty($errors)) {
            return $this->render('auth.register', [
                'errors' => $errors,
                'name' => $name,
                'email' => $email,
            ], 'Register');
        }

        try {
            if ($claimStubId > 0) {
                $this->auth->claimStub($claimStubId, $name, $email, $password);
            } else {
                $this->auth->register($name, $email, $password);
            }
            $this->auth->attempt($email, $password);
            return Response::redirect(route('auth.consent'));
        } catch (\App\Exceptions\AppException $e) {
            return $this->render('auth.register', [
                'errors' => [$e->getMessage()],
                'name' => $name,
                'email' => $email,
            ], 'Register');
        }
    }

    // --- Consent ---

    public function consentForm(Request $request): Response
    {
        return $this->render('auth.consent', [], 'Consent');
    }

    public function consent(Request $request): Response
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return Response::redirect(route('auth.login'));
        }
        if ($request->input('grant') === 'yes') {
            $this->auth->grantConsent($userId);

            // Fulfill pending intent after consent
            $intent = consume_intent();
            if ($intent) {
                return $this->fulfillIntent($intent, $userId);
            }
        }
        return Response::redirect(route('home'));
    }

    // --- Logout ---

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return Response::redirect(route('auth.login'));
    }

    // --- Forgot Password ---

    public function forgotPasswordForm(Request $request): Response
    {
        return $this->render('auth.forgot-password', [], 'Forgot Password');
    }

    public function forgotPassword(Request $request): Response
    {
        $email = trim($request->input('email', ''));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $token = $this->auth->createPasswordResetToken($email);
            if ($token !== null) {
                $resetUrl = config('app.url', 'http://localhost/lifedrawing/public')
                    . route('auth.reset_password') . '?token=' . $token;
                app('mail')->send(
                    $email,
                    'Password Reset — Life Drawing Randburg',
                    "You requested a password reset.\n\nClick here to reset your password:\n{$resetUrl}\n\nThis link expires in 1 hour.\n\nIf you did not request this, please ignore this email."
                );
            }
        }

        // Always show success (anti-enumeration)
        return $this->render('auth.forgot-password-sent', [], 'Check Your Email');
    }

    // --- Reset Password ---

    public function resetPasswordForm(Request $request): Response
    {
        $token = $request->input('token', '');
        $email = $this->auth->verifyResetToken($token);

        if ($email === null) {
            return $this->render('auth.reset-expired', [], 'Invalid Link');
        }

        return $this->render('auth.reset-password', ['token' => $token], 'Reset Password');
    }

    public function resetPassword(Request $request): Response
    {
        $token = $request->input('token', '');
        $password = $request->input('password', '');
        $confirm = $request->input('password_confirm', '');

        $errors = [];
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (!empty($errors)) {
            return $this->render('auth.reset-password', [
                'token' => $token,
                'errors' => $errors,
            ], 'Reset Password');
        }

        $success = $this->auth->resetPassword($token, $password);
        if (!$success) {
            return $this->render('auth.reset-expired', [], 'Invalid Link');
        }

        return Response::redirect(route('auth.login'));
    }

    // --- Stub Account Search (for registration typeahead) ---

    public function searchStubs(Request $request): Response
    {
        $q = trim((string) $request->input('display_name', ''));
        if (mb_strlen($q) < 2) {
            return Response::html('');
        }

        $results = $this->db->fetchAll(
            "SELECT u.id, u.display_name,
                    (SELECT COUNT(*) FROM ld_session_participants sp
                     WHERE sp.user_id = u.id) AS session_count
             FROM users u
             WHERE u.email LIKE '%.stub@local'
               AND u.display_name LIKE ?
             ORDER BY session_count DESC, u.display_name ASC
             LIMIT 8",
            ['%' . $q . '%']
        );

        return Response::html($this->view->render('auth._stub_results', [
            'results' => $results,
        ]));
    }

    // --- Intent Helpers ---

    private function captureIntent(Request $request): void
    {
        $intent = $request->input('intent', '');
        $allowed = ['claim_artwork', 'join_session', 'comment_artwork'];
        if ($intent !== '' && in_array($intent, $allowed, true)) {
            store_intent($intent, array_filter([
                'artwork_id' => $request->input('artwork_id'),
                'session_id' => $request->input('session_id'),
                'claim_type' => $request->input('claim_type'),
                'role' => $request->input('role'),
            ]));
        }
    }

    private function fulfillIntent(array $intent, int $userId): Response
    {
        $action = $intent['action'];
        $params = $intent['params'] ?? [];

        switch ($action) {
            case 'claim_artwork':
                $artworkId = from_hex($params['artwork_id'] ?? '0');
                $claimType = in_array($params['claim_type'] ?? '', ['artist', 'model'], true)
                    ? $params['claim_type'] : 'artist';

                $artwork = db('ld_artworks')->where('id', '=', $artworkId)->first();
                if ($artwork) {
                    $existing = db('ld_claims')
                        ->where('artwork_id', '=', $artworkId)
                        ->where('claimant_id', '=', $userId)
                        ->where('claim_type', '=', $claimType)
                        ->first();

                    if (!$existing) {
                        $this->db->execute(
                            "INSERT INTO ld_claims (artwork_id, claimant_id, claim_type, status) VALUES (?, ?, ?, 'pending')",
                            [$artworkId, $userId, $claimType]
                        );
                        $this->provenance->log($userId, 'claim.create', 'claim', (int) $this->db->lastInsertId(), [
                            'claim_type' => $claimType, 'via' => 'intent',
                        ]);
                    }
                    return Response::redirect(route('artworks.show', ['id' => hex_id($artworkId)]));
                }
                break;

            case 'join_session':
                $sessionId = from_hex($params['session_id'] ?? '0');
                $role = in_array($params['role'] ?? '', ['artist', 'model', 'observer'], true)
                    ? $params['role'] : 'artist';

                $session = db('ld_sessions')->where('id', '=', $sessionId)->first();
                if ($session) {
                    $existing = db('ld_session_participants')
                        ->where('session_id', '=', $sessionId)
                        ->where('user_id', '=', $userId)
                        ->where('role', '=', $role)
                        ->first();

                    if (!$existing) {
                        $this->db->execute(
                            "INSERT INTO ld_session_participants (session_id, user_id, role) VALUES (?, ?, ?)",
                            [$sessionId, $userId, $role]
                        );
                        app('stats')->refreshUser($userId);
                        $this->provenance->log($userId, 'session.join', 'session_participant', $sessionId, [
                            'role' => $role, 'via' => 'intent',
                        ]);
                    }
                    return Response::redirect(route('sessions.show', ['id' => hex_id($sessionId, session_title($session))]));
                }
                break;

            case 'comment_artwork':
                $artworkId = from_hex($params['artwork_id'] ?? '0');
                return Response::redirect(route('artworks.show', ['id' => hex_id($artworkId)]) . '#comments');
        }

        return Response::redirect(route('home'));
    }
}
