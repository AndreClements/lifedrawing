<section class="auth-form">
    <h2>Edit Profile</h2>

    <?php if (!empty($error ?? '')): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($success ?? '')): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= route('profiles.update') ?>">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="display_name">Display Name</label>
            <input type="text" id="display_name" name="display_name"
                   value="<?= e($user['display_name']) ?>" required>
        </div>

        <div class="form-group">
            <label for="pseudonym">Pseudonym <small>(optional — shown to public visitors)</small></label>
            <input type="text" id="pseudonym" name="pseudonym"
                   value="<?= e($user['pseudonym'] ?? '') ?>"
                   placeholder="A name for public view">
        </div>

        <div class="form-group">
            <label for="bio">Bio</label>
            <textarea id="bio" name="bio"
                      placeholder="Tell us about your practice..."><?= e($user['bio'] ?? '') ?></textarea>
        </div>

        <hr>
        <h3>Change Password</h3>
        <p class="text-muted">Leave blank to keep your current password.</p>

        <!-- Hidden username for browser password manager detection -->
        <input type="hidden" name="username" autocomplete="username" value="<?= e($user['email'] ?? '') ?>">

        <div class="form-group">
            <label for="current_password">Current Password</label>
            <div class="password-wrapper">
                <input type="password" id="current_password" name="current_password" autocomplete="current-password">
                <button type="button" class="password-toggle" aria-label="Toggle password visibility">Show</button>
            </div>
        </div>

        <div class="form-group">
            <label for="new_password">New Password</label>
            <div class="password-wrapper">
                <input type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password">
                <button type="button" class="password-toggle" aria-label="Toggle password visibility">Show</button>
            </div>
        </div>

        <div class="form-group">
            <label for="new_password_confirm">Confirm New Password</label>
            <div class="password-wrapper">
                <input type="password" id="new_password_confirm" name="new_password_confirm" minlength="8" autocomplete="new-password">
                <button type="button" class="password-toggle" aria-label="Toggle password visibility">Show</button>
            </div>
        </div>

        <hr>
        <h3>Email Notifications</h3>
        <p class="text-muted">Choose which emails you'd like to receive. All off by default.</p>

        <div class="notification-prefs">
            <label class="checkbox-label">
                <input type="checkbox" name="notify_new_session" value="1"
                       <?= !empty($user['notify_new_session']) ? 'checked' : '' ?>>
                <span><strong>New session announced</strong> — When a new drawing session is scheduled</span>
            </label>

            <label class="checkbox-label">
                <input type="checkbox" name="notify_session_cancelled" value="1"
                       <?= !empty($user['notify_session_cancelled']) ? 'checked' : '' ?>>
                <span><strong>Session cancelled</strong> — When a session you've joined is cancelled</span>
            </label>

            <label class="checkbox-label">
                <input type="checkbox" name="notify_claim_resolved" value="1"
                       <?= !empty($user['notify_claim_resolved']) ? 'checked' : '' ?>>
                <span><strong>Claim resolved</strong> — When your artwork claim is approved or rejected</span>
            </label>

            <label class="checkbox-label">
                <input type="checkbox" name="notify_comment" value="1"
                       <?= !empty($user['notify_comment']) ? 'checked' : '' ?>>
                <span><strong>New comment</strong> — When someone comments on artwork you've claimed</span>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Save</button>
            <a href="<?= route('profiles.show', ['id' => hex_id($user['id'])]) ?>" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</section>
