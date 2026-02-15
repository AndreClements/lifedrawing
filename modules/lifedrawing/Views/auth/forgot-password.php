<section class="auth-form">
    <h2>Forgot Password</h2>
    <p class="lead">Enter your email and we'll send a reset link.</p>

    <form method="POST" action="<?= route('auth.forgot_password.post') ?>">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autofocus>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Send Reset Link</button>
            <a href="<?= route('auth.login') ?>" class="btn btn-outline">Back to Sign In</a>
        </div>
    </form>
</section>
