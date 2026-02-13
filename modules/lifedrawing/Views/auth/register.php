<section class="auth-form">
    <h2>Join Life Drawing Randburg</h2>
    <p class="lead">Create your account. You'll be asked about consent next.</p>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= route('auth.register.post') ?>">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="display_name">Display Name</label>
            <input type="text" id="display_name" name="display_name" value="<?= e($name ?? '') ?>" required autofocus>
        </div>

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($email ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="8">
        </div>

        <div class="form-group">
            <label for="password_confirm">Confirm Password</label>
            <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Register</button>
            <a href="<?= route('auth.login') ?>" class="btn btn-outline">Already have an account?</a>
        </div>
    </form>
</section>
