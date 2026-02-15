<section class="auth-form">
    <h2>Reset Password</h2>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $err): ?>
                <p><?= e($err) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= route('auth.reset_password.post') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token ?? '') ?>">

        <div class="form-group">
            <label for="password">New Password</label>
            <input type="password" id="password" name="password" required minlength="8">
        </div>

        <div class="form-group">
            <label for="password_confirm">Confirm Password</label>
            <input type="password" id="password_confirm" name="password_confirm" required>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Reset Password</button>
        </div>
    </form>
</section>
