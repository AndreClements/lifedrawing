<section class="auth-form">
    <h2>Sign In</h2>
    <p class="lead">Welcome back.</p>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= route('auth.login.post') ?>">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($email ?? '') ?>" required autofocus>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>

        <div class="form-group form-check">
            <label><input type="checkbox" name="remember" value="1"> Remember me</label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Sign In</button>
            <a href="<?= route('auth.register') ?>" class="btn btn-outline">Register</a>
        </div>

        <p class="auth-link"><a href="<?= route('auth.forgot_password') ?>">Forgot your password?</a></p>
    </form>
</section>
