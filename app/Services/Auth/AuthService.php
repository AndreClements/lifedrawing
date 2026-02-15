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
