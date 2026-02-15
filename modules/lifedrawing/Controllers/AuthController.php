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

        return Response::redirect(route('home'));
    }

    // --- Registration ---

    public function registerForm(Request $request): Response
    {
        if ($this->auth->isLoggedIn()) {
            return Response::redirect(route('home'));
        }
        return $this->render('auth.register', [], 'Register');
    }

    public function register(Request $request): Response
    {
        $name = trim($request->input('display_name', ''));
        $email = trim($request->input('email', ''));
        $password = $request->input('password', '');
        $confirm = $request->input('password_confirm', '');

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
            $this->auth->register($name, $email, $password);
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
                $sent = mail(
                    $email,
                    'Password Reset — Life Drawing Randburg',
                    "You requested a password reset.\n\nClick here to reset your password:\n{$resetUrl}\n\nThis link expires in 1 hour.\n\nIf you did not request this, please ignore this email.",
                    "From: noreply@lifedrawingrandburg.co.za\r\nContent-Type: text/plain; charset=UTF-8"
                );
                if (!$sent) {
                    error_log("Password reset email failed for {$email} — check SMTP configuration");
                }
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
}
