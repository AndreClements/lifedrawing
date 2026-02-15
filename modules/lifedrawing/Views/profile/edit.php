<section class="auth-form">
    <h2>Edit Profile</h2>

    <?php if (!empty($error ?? '')): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= route('profiles.update') ?>">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="display_name">Display Name</label>
            <input type="text" id="display_name" name="display_name"
                   value="<?= e($user['display_name']) ?>" required>
        </div>

        <div class="form-group">
            <label for="bio">Bio</label>
            <textarea id="bio" name="bio"
                      placeholder="Tell us about your practice..."><?= e($user['bio'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Save</button>
            <a href="<?= route('profiles.show', ['id' => $user['id']]) ?>" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</section>
