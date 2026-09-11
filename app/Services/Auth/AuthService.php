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
    public const STUB_HASH = '$2y$12$STUB.ACCOUNT.CANNOT.LOGIN.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    public function __construct(
        private readonly Connection $db,
    ) {}

    // --- Registration ---

    /**
     * Create a stub account for an unregistered participant.
     * Email is derived from a slug of the display name; password hash is dead.
     * Used by CSV import and facilitator quick-add.
     */
    public function createStub(string $displayName): int
    {
        $displayName = trim($displayName);
        if ($displayName === '') {
            throw new \App\Exceptions\AppException("Display name required to create stub.");
        }

        $slug = preg_replace('/[^a-z0-9]+/', '.', strtolower($displayName));
        $slug = trim((string) $slug, '.');
        if ($slug === '') {
            $slug = 'user';
        }

        $email = $slug . '.stub@local';
        $counter = 0;
        while ($this->db->fetchColumn("SELECT 1 FROM users WHERE email = ?", [$email])) {
            $counter++;
            $email = $slug . $counter . '.stub@local';
        }

        $this->db->execute(
            "INSERT INTO users (display_name, email, password_hash, role, consent_state, consent_granted_at)
             VALUES (?, ?, ?, 'participant', 'granted', NOW())",
            [$displayName, $email, self::STUB_HASH]
        );

        $userId = (int) $this->db->lastInsertId();
        $this->logProvenance($userId, 'user.stub.create', 'user', $userId);
        return $userId;
    }

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

    /**
     * Claim an existing stub account: upgrade it with real credentials.
     * Preserves user ID and all FK references (session history, claims).
     *
     * @return int The claimed user ID
     */
    public function claimStub(int $stubId, string $displayName, string $email, string $password): int
    {
        $stub = $this->db->fetch(
            "SELECT * FROM users WHERE id = ? AND email LIKE '%.stub@local'",
            [$stubId]
        );

        if (!$stub) {
            throw new \App\Exceptions\AppException(
                "Account not found or already claimed.",
                andYet: "Either the stub was already claimed, or someone is trying to claim a real account."
            );
        }

        // Check email not taken by another account
        $existing = $this->db->fetch(
            "SELECT id FROM users WHERE email = ? AND id != ?",
            [$email, $stubId]
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
            "UPDATE users SET display_name = ?, email = ?, password_hash = ?, consent_state = 'pending' WHERE id = ?",
            [$displayName, $email, $hash, $stubId]
        );

        $this->logProvenance($stubId, 'user.register.claim_stub', 'user', $stubId, [
            'previous_name' => $stub['display_name'],
        ]);

        return $stubId;
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
            // Clear only current device's remember token
            if (!empty($_COOKIE['ldr_remember'])) {
                $hashed = hash('sha256', $_COOKIE['ldr_remember']);
                $this->clearRememberToken($hashed);
                // Also clear legacy column if it matches (transition period)
                $this->db->execute(
                    "UPDATE users SET remember_token = NULL WHERE id = ? AND remember_token = ?",
                    [$userId, $hashed]
                );
            }
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
        $isProduction = config('app.env') === 'production';
        setcookie('ldr_remember', '', time() - 42000, '/', '', $isProduction, true);
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

    // --- Remember me (per-device tokens) ---

    /** Create a remember token for this device, return the raw token. */
    public function createRememberToken(int $userId, string $userAgent = ''): string
    {
        $token = bin2hex(random_bytes(32));
        $lifetime = (int) config('auth.remember_lifetime', 90 * 24 * 60 * 60);
        $expiresAt = date('Y-m-d H:i:s', time() + $lifetime);

        $this->db->execute(
            "INSERT INTO remember_tokens (user_id, token_hash, user_agent, expires_at) VALUES (?, ?, ?, ?)",
            [$userId, hash('sha256', $token), mb_substr($userAgent, 0, 255), $expiresAt]
        );

        return $token;
    }

    /** Attempt login from a remember-me cookie. Clears stale cookie on failure. */
    public function attemptRememberLogin(string $token): ?array
    {
        $hashed = hash('sha256', $token);
        $row = $this->db->fetch(
            "SELECT rt.id AS token_id, u.*
             FROM remember_tokens rt
             JOIN users u ON rt.user_id = u.id
             WHERE rt.token_hash = ? AND rt.expires_at > NOW()",
            [$hashed]
        );

        if (!$row) {
            // Fallback: check old users.remember_token column for pre-migration tokens.
            // Remove this fallback once the old column is dropped.
            $row = $this->attemptLegacyRememberLogin($hashed);
            if (!$row) {
                // Clear stale cookie to prevent repeated DB queries
                $isProduction = config('app.env') === 'production';
                setcookie('ldr_remember', '', time() - 42000, '/', '', $isProduction, true);
                return null;
            }
        } else {
            // Rotate token on use (prevents replay)
            $newToken = bin2hex(random_bytes(32));
            $lifetime = (int) config('auth.remember_lifetime', 90 * 24 * 60 * 60);
            $this->db->execute(
                "UPDATE remember_tokens SET token_hash = ?, expires_at = ?, last_used_at = NOW() WHERE id = ?",
                [hash('sha256', $newToken), date('Y-m-d H:i:s', time() + $lifetime), $row['token_id']]
            );
            $this->setRememberCookie($newToken);
        }

        $this->setSession($row);
        $this->logProvenance((int) $row['id'], 'user.login.remember', 'user', (int) $row['id']);

        return $row;
    }

    /**
     * Fallback for pre-migration tokens stored in users.remember_token.
     * Migrates the user to the new table on success. Remove when old column is dropped.
     */
    private function attemptLegacyRememberLogin(string $hashed): ?array
    {
        $user = $this->db->fetch(
            "SELECT * FROM users WHERE remember_token = ?",
            [$hashed]
        );
        if (!$user) {
            return null;
        }

        // Migrate to new table: create a fresh token and clear the old column
        $newToken = bin2hex(random_bytes(32));
        $lifetime = (int) config('auth.remember_lifetime', 90 * 24 * 60 * 60);
        $this->db->execute(
            "INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)",
            [$user['id'], hash('sha256', $newToken), date('Y-m-d H:i:s', time() + $lifetime)]
        );
        $this->db->execute(
            "UPDATE users SET remember_token = NULL WHERE id = ?",
            [$user['id']]
        );
        $this->setRememberCookie($newToken);

        return $user;
    }

    /** Clear a single remember token by its hash (current device). */
    public function clearRememberToken(string $tokenHash): void
    {
        $this->db->execute(
            "DELETE FROM remember_tokens WHERE token_hash = ?",
            [$tokenHash]
        );
    }

    /** Clear all remember tokens for a user (log out everywhere). */
    public function clearAllRememberTokens(int $userId): void
    {
        $this->db->execute(
            "DELETE FROM remember_tokens WHERE user_id = ?",
            [$userId]
        );
    }

    /** Set the remember-me cookie (90 days). */
    public function setRememberCookie(string $token): void
    {
        $isProduction = config('app.env') === 'production';
        $lifetime = (int) config('auth.remember_lifetime', 90 * 24 * 60 * 60);
        setcookie('ldr_remember', $token, [
            'expires'  => time() + $lifetime,
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

    /**
     * Withdraw consent, and make that withdrawal real.
     *
     * Setting visibility='private' hides the database row, but public/.htaccess
     * hands any existing file straight to Apache, so PHP never runs and the
     * image stays fetchable at its direct URL. Hiding the row is therefore not
     * enforcement on its own — the files have to leave the public tree.
     *
     * Returns a status. The caller MUST surface an incomplete result rather than
     * reporting success: a withdrawal that claims to have worked while a file is
     * still served is the one outcome this must never produce.
     *
     * @return array{complete:bool, moved:int, remaining:list<string>, locked:bool}
     */
    public function withdrawConsent(int $userId): array
    {
        $this->db->execute(
            "UPDATE users SET consent_state = ?, consent_withdrawn_at = NOW() WHERE id = ?",
            [ConsentState::Withdrawn->value, $userId]
        );
        $_SESSION['consent_state'] = ConsentState::Withdrawn->value;

        // Wait for any image-worker batch to finish before reading the paths.
        // Acquiring around only the move would not help: the worker could add or
        // rewrite files while we waited, and we would move a stale list.
        $locked = \App\Services\ImageLock::acquire();

        $uploadDir    = LDR_ROOT . '/public/assets/uploads';
        $withdrawnDir = LDR_ROOT . '/storage/withdrawn/' . $userId;
        $moved = 0;
        $paths = [];
        $remaining = [];

        // Without the lock, ABORT the file move rather than racing the worker.
        //
        // Proceeding unlocked was the earlier behaviour and it was wrong: a run
        // already in flight rewrites the original in place and writes
        // derivatives, so it can recreate a file moments after we verify it
        // gone. A withdrawal that cannot be made safe must report that, not
        // press on and claim success.
        //
        // The consent state has already changed above, so every read path is
        // gated either way; this is only about the files on disk.
        if (!$locked) {
            error_log("Consent withdrawal for user {$userId}: could not acquire the image lock; files left in place.");

            $rows = $this->db->fetchAll(
                "SELECT file_path, web_path, thumbnail_path FROM ld_artworks
                 WHERE uploaded_by = ? AND visibility != 'removed'",
                [$userId]
            );
            foreach ($rows as $row) {
                foreach (['file_path', 'web_path', 'thumbnail_path'] as $col) {
                    if (!empty($row[$col]) && is_file($uploadDir . '/' . $row[$col])) {
                        $remaining[] = $row[$col];
                    }
                }
            }

            $this->db->execute(
                "UPDATE ld_artworks SET visibility = 'private'
                 WHERE uploaded_by = ? AND visibility != 'removed'",
                [$userId]
            );

            $this->reportIncompleteWithdrawal($userId, $remaining);

            return ['complete' => false, 'moved' => 0, 'remaining' => $remaining, 'locked' => false];
        }

        try {
            // NOT already-removed artwork. The original statement swept those up
            // too, flipping 'removed' to 'private' and resurrecting deleted work
            // into the uploader escape hatch on the session page.
            $artworks = $this->db->fetchAll(
                "SELECT id, file_path, web_path, thumbnail_path
                 FROM ld_artworks
                 WHERE uploaded_by = ? AND visibility != 'removed'",
                [$userId]
            );

            foreach ($artworks as $artwork) {
                foreach (['file_path', 'web_path', 'thumbnail_path'] as $col) {
                    if (!empty($artwork[$col])) {
                        $paths[] = $artwork[$col];
                    }
                }

                // Derivatives the database does not know about.
                //
                // process_images.php writes the web image BEFORE the thumbnail
                // but records both paths only once both succeed. A thumbnail
                // failure therefore leaves a real, public web_*.webp with no row
                // pointing at it. Moving only database-listed paths would leave
                // that file served and still report success.
                foreach ($this->derivativeSiblings($artwork['file_path'] ?? '') as $sibling) {
                    if (!in_array($sibling, $paths, true)) {
                        $paths[] = $sibling;
                    }
                }
            }

            $this->db->execute(
                "UPDATE ld_artworks SET visibility = 'private'
                 WHERE uploaded_by = ? AND visibility != 'removed'",
                [$userId]
            );

            foreach ($paths as $rel) {
                $src = $uploadDir . '/' . $rel;
                if (!is_file($src)) {
                    continue;
                }
                $dest = $withdrawnDir . '/' . $rel;
                $destDir = dirname($dest);
                if (!is_dir($destDir)) {
                    @mkdir($destDir, 0755, true);
                }
                // Never fall back to deleting. There is no verified image backup,
                // and the consent page promises hiding WITHOUT deletion.
                if (@rename($src, $dest)) {
                    $moved++;
                } elseif (@copy($src, $dest) && @unlink($src)) {
                    $moved++;
                }
            }

            // Verify INSIDE the lock, before releasing it. Verifying afterwards
            // leaves a gap in which the worker can put a file back, and the
            // check would have already passed.
            foreach ($paths as $rel) {
                if (is_file($uploadDir . '/' . $rel)) {
                    $remaining[] = $rel;
                }
            }
        } finally {
            \App\Services\ImageLock::release();
        }

        $complete = ($remaining === []);

        $this->logProvenance(
            $userId,
            $complete ? 'user.consent.withdraw' : 'user.consent.withdraw.incomplete',
            'user',
            $userId
        );

        if (!$complete) {
            $this->reportIncompleteWithdrawal($userId, $remaining);
        }

        // And-Yet: This hides artworks uploaded BY the user, but not artworks
        // depicting the user as a model (uploaded by facilitators, claimed by
        // artists). A model-takedown flow — where the model can flag artworks
        // from sessions they modelled for — is a post-beta feature. For now,
        // model takedowns are handled manually by the facilitator.
        // (Risk lens: Botha, non-economic.)

        return [
            'complete'  => $complete,
            'moved'     => $moved,
            'remaining' => $remaining,
            'locked'    => $locked,
        ];
    }

    /**
     * Derivative filenames process_images.php would have written beside an
     * original, whether or not the database ever recorded them.
     *
     * @return list<string>
     */
    private function derivativeSiblings(string $filePath): array
    {
        if ($filePath === '') {
            return [];
        }

        $dir  = dirname($filePath);
        $stem = pathinfo(basename($filePath), PATHINFO_FILENAME);
        $dir  = ($dir === '.' || $dir === '') ? '' : $dir . '/';

        return [
            $dir . 'web_' . $stem . '.webp',
            $dir . 'thumb_' . $stem . '.webp',
        ];
    }

    /** Tell the facilitator, and say so out loud in the log. */
    private function reportIncompleteWithdrawal(int $userId, array $remaining): void
    {
        error_log(
            "Consent withdrawal INCOMPLETE for user {$userId}; still public: "
            . implode(', ', $remaining)
        );
        try {
            app('notifications')->withdrawalIncomplete($userId, $remaining);
        } catch (\Throwable $e) {
            error_log('withdrawalIncomplete notify failed: ' . $e->getMessage());
        }
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
        $hashed = hash('sha256', $token);
        return $this->db->fetch(
            "SELECT * FROM users WHERE api_token = ? AND consent_state = ?",
            [$hashed, ConsentState::Granted->value]
        );
    }

    public function generateApiToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->execute(
            "UPDATE users SET api_token = ? WHERE id = ?",
            [hash('sha256', $token), $userId]
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
