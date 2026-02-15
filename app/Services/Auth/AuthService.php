<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Database\Connection;
use App\Exceptions\ConsentException;

/**
 * Authentication service.
 *
 * Session-based auth with consent-gated registration.
 * No magic — explicit session management, bcrypt hashing,
 * and a clean consent state machine.
 */
final class AuthService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    // --- Registration ---

    /**
     * Register a new user with consent pending.
     *
     * @return int The new user ID
     */
    public function register(string $displayName, string $email, string $password, string $role = 'participant'): int
    {
        // Check for existing email
        $existing = $this->db->fetch(
            "SELECT id FROM users WHERE email = ?",
            [$email]
        );
        if ($existing) {
            throw new \App\Exceptions\AppException(
                "Email already registered.",
                andYet: "We don't distinguish 'already registered' from 'registration failed' to avoid enumeration."
            );
        }

        $hash = password_hash($password, config('auth.hash_algo', PASSWORD_BCRYPT), [
            'cost' => config('auth.hash_cost', 12),
        ]);

        $this->db->execute(
            "INSERT INTO users (display_name, email, password_hash, role, consent_state) VALUES (?, ?, ?, ?, ?)",
            [$displayName, $email, $hash, $role, ConsentState::Pending->value]
        );

        $userId = (int) $this->db->lastInsertId();

        // Log provenance
        $this->logProvenance($userId, 'user.register', 'user', $userId);

        return $userId;
    }

    // --- Login / Logout ---

    public function attempt(string $email, string $password): ?array
    {
        $user = $this->db->fetch(
            "SELECT * FROM users WHERE email = ?",
            [$email]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        // Start session
        $this->setSession($user);
        $this->logProvenance((int) $user['id'], 'user.login', 'user', (int) $user['id']);

        return $user;
    }

    public function logout(): void
    {
        $userId = $this->currentUserId();
        if ($userId) {
            $this->clearRememberToken($userId);
            $this->logProvenance($userId, 'user.logout', 'user', $userId);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly'],
            );
        }
        // Clear remember-me cookie
        setcookie('ldr_remember', '', time() - 42000, '/', '', false, true);
        session_destroy();
    }

    // --- Session management ---

    private function setSession(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['display_name'];
        $_SESSION['consent_state'] = $user['consent_state'];
    }

    public function currentUserId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public function currentUser(): ?array
    {
        $id = $this->currentUserId();
        if ($id === null) {
            return null;
        }
        return $this->db->fetch("SELECT * FROM users WHERE id = ?", [$id]);
    }

    public function isLoggedIn(): bool
    {
        return $this->currentUserId() !== null;
    }

    public function hasRole(string ...$roles): bool
    {
        $userRole = $_SESSION['user_role'] ?? '';
        return in_array($userRole, $roles, true);
    }

    // --- Remember me ---

    /** Create a remember token, store its hash, return the raw token. */
    public function createRememberToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->execute(
            "UPDATE users SET remember_token = ? WHERE id = ?",
            [hash('sha256', $token), $userId]
        );
        return $token;
    }

    /** Attempt login from a remember-me cookie. Returns the user or null. */
    public function attemptRememberLogin(string $token): ?array
    {
        $hashed = hash('sha256', $token);
        $user = $this->db->fetch(
            "SELECT * FROM users WHERE remember_token = ?",
            [$hashed]
        );
        if (!$user) {
            return null;
        }

        $this->setSession($user);
        $this->logProvenance((int) $user['id'], 'user.login.remember', 'user', (int) $user['id']);

        // Rotate token on use (prevents replay)
        $newToken = bin2hex(random_bytes(32));
        $this->db->execute(
            "UPDATE users SET remember_token = ? WHERE id = ?",
            [hash('sha256', $newToken), $user['id']]
        );
        $this->setRememberCookie($newToken);

        return $user;
    }

    /** Clear the remember token from DB. */
    public function clearRememberToken(int $userId): void
    {
        $this->db->execute(
            "UPDATE users SET remember_token = NULL WHERE id = ?",
            [$userId]
        );
    }

    /** Set the remember-me cookie (30 days). */
    public function setRememberCookie(string $token): void
    {
        $isProduction = config('app.env') === 'production';
        setcookie('ldr_remember', $token, [
            'expires'  => time() + (30 * 24 * 60 * 60),
            'path'     => '/',
            'secure'   => $isProduction,
            'httponly'  => true,
            'samesite'  => 'Lax',
        ]);
    }

    // --- Consent ---

    public function grantConsent(int $userId): void
    {
        $this->db->execute(
            "UPDATE users SET consent_state = ?, consent_granted_at = NOW() WHERE id = ?",
            [ConsentState::Granted->value, $userId]
        );
        $_SESSION['consent_state'] = ConsentState::Granted->value;
        $this->logProvenance($userId, 'user.consent.grant', 'user', $userId);
    }

    public function withdrawConsent(int $userId): void
    {
        $this->db->execute(
            "UPDATE users SET consent_state = ?, consent_withdrawn_at = NOW() WHERE id = ?",
            [ConsentState::Withdrawn->value, $userId]
        );
        $_SESSION['consent_state'] = ConsentState::Withdrawn->value;

        // Hide user's artworks (parametric authorship: withdraw hides, doesn't delete)
        $this->db->execute(
            "UPDATE ld_artworks SET visibility = 'private' WHERE uploaded_by = ?",
            [$userId]
        );
        // And-Yet: This hides artworks uploaded BY the user, but not artworks
        // depicting the user as a model (uploaded by facilitators, claimed by artists).
        // A model-takedown flow — where the model can flag artworks from sessions
        // they modelled for — is a post-beta feature. For now, model takedowns
        // are handled manually by the facilitator. (Risk lens: Botha, non-economic.)

        $this->logProvenance($userId, 'user.consent.withdraw', 'user', $userId);
    }

    public function consentState(): ConsentState
    {
        $state = $_SESSION['consent_state'] ?? ConsentState::Pending->value;
        return ConsentState::from($state);
    }

    public function requireConsent(): void
    {
        if (!$this->consentState()->canParticipate()) {
            throw new ConsentException(
                "This action requires your consent.",
                andYet: "We halt here but should redirect to a consent-granting page."
            );
        }
    }

    // --- API token auth ---

    public function authenticateByToken(string $token): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM users WHERE api_token = ? AND consent_state = ?",
            [$token, ConsentState::Granted->value]
        );
    }

    public function generateApiToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->execute(
            "UPDATE users SET api_token = ? WHERE id = ?",
            [$token, $userId]
        );
        $this->logProvenance($userId, 'user.token.generate', 'user', $userId);
        return $token;
    }

    // --- Password Reset ---

    /**
     * Create a password reset token and return the raw token.
     * The token is stored as a SHA-256 hash in the DB.
     * Returns null silently for non-existent emails (anti-enumeration).
     */
    public function createPasswordResetToken(string $email): ?string
    {
        $user = $this->db->fetch("SELECT id FROM users WHERE email = ?", [$email]);
        if (!$user) {
            return null;
        }

        // Invalidate old tokens for this email
        $this->db->execute("DELETE FROM password_resets WHERE email = ?", [$email]);

        $token = bin2hex(random_bytes(32));

        $this->db->execute(
            "INSERT INTO password_resets (email, token) VALUES (?, ?)",
            [$email, hash('sha256', $token)]
        );

        $this->logProvenance((int) $user['id'], 'user.password_reset.request', 'user', (int) $user['id']);

        return $token;
    }

    /**
     * Verify a password reset token.
     * Returns the associated email if valid, null if expired/invalid/used.
     * Tokens expire after 1 hour.
     */
    public function verifyResetToken(string $token): ?string
    {
        $hashedToken = hash('sha256', $token);

        $record = $this->db->fetch(
            "SELECT email FROM password_resets
             WHERE token = ? AND used_at IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [$hashedToken]
        );

        return $record['email'] ?? null;
    }

    /**
     * Reset password using a valid token.
     * Verifies token, updates password, marks token as used.
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        $email = $this->verifyResetToken($token);
        if ($email === null) {
            return false;
        }

        $hash = password_hash($newPassword, config('auth.hash_algo', PASSWORD_BCRYPT), [
            'cost' => config('auth.hash_cost', 12),
        ]);

        $this->db->execute(
            "UPDATE users SET password_hash = ? WHERE email = ?",
            [$hash, $email]
        );

        // Mark token as used
        $this->db->execute(
            "UPDATE password_resets SET used_at = NOW() WHERE token = ?",
            [hash('sha256', $token)]
        );

        $user = $this->db->fetch("SELECT id FROM users WHERE email = ?", [$email]);
        if ($user) {
            $this->logProvenance((int) $user['id'], 'user.password_reset.complete', 'user', (int) $user['id']);
        }

        return true;
    }

    // --- Change Password ---

    /**
     * Change password for an authenticated user.
     * Verifies the current password before updating.
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $user = $this->db->fetch("SELECT password_hash FROM users WHERE id = ?", [$userId]);
        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return false;
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, [
            'cost' => config('auth.hash_cost', 12),
        ]);
        $this->db->execute("UPDATE users SET password_hash = ? WHERE id = ?", [$hash, $userId]);
        $this->logProvenance($userId, 'user.password_change', 'user', $userId);

        return true;
    }

    // --- Provenance ---

    private function logProvenance(int $userId, string $action, string $entityType, int $entityId): void
    {
        try {
            $this->db->execute(
                "INSERT INTO provenance_log (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)",
                [$userId, $action, $entityType, $entityId, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']
            );
        } catch (\Throwable) {
            // Provenance logging should never break the main flow
        }
    }
}
