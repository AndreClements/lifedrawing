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
                   placeholder="A name for public view"
                   autocomplete="off">
        </div>

        <div class="form-group">
            <label for="bio">Bio</label>
            <textarea id="bio" name="bio"
                      placeholder="Tell us about your practice..."><?= e($user['bio'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <label for="whatsapp_number">WhatsApp Number <small>(optional)</small></label>
            <input type="tel" id="whatsapp_number" name="whatsapp_number"
                   value="<?= e($user['whatsapp_number'] ?? '') ?>"
                   placeholder="+27 82 123 4567"
                   autocomplete="tel">
            <small class="text-muted">Used for sitter queue communication. Visible only to facilitators.</small>
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

<?php
/**
 * Consent withdrawal.
 *
 * The consent page tells people this lives "in your profile settings", so this
 * is where it has to be. The copy is deliberately specific about the limit:
 * withdrawal hides what you uploaded, it cannot retract a drawing somebody else
 * made of you. Promising more than the software does is how consent notices
 * become dishonest.
 */
// Authoritative, not the browser session. Withdrawing on one device leaves
// another device's session reading 'granted', and this page would then offer to
// withdraw consent that has already been withdrawn.
$consentState = \App\Middleware\ConsentGate::currentState()->value;
?>
<?php if ($consentState === 'granted'): ?>
<section class="profile-edit consent-withdraw">
    <h2>Withdraw consent</h2>

    <p>Withdrawing consent hides any artwork you uploaded and removes your name from
       public pages, the artist and sitter directories, and the site&rsquo;s feedback tooling.
       Your session history is kept, because it is part of other people&rsquo;s records too.</p>

    <p><strong>What it cannot do.</strong> It cannot withdraw a drawing another artist made
       of you. Those belong to the person who drew them, and taking one down is a
       conversation with Andr&eacute; rather than something this button can settle.
       Call or message him and he will sort it out.</p>

    <p>You can grant consent again whenever you like. Work that was hidden stays hidden
       until you ask for it back, in case some of it was meant to stay private.</p>

    <form method="POST" action="<?= route('auth.consent.withdraw') ?>"
          class="form-inline confirm-action"
          data-confirm="Withdraw consent? Your uploads will be hidden and your name removed from public pages.">
        <?= csrf_field() ?>
        <input type="hidden" name="confirm" value="yes">
        <button type="submit" class="btn btn-outline btn-danger">Withdraw my consent</button>
    </form>
</section>
<?php endif; ?>
