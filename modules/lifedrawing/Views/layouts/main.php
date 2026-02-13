<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Life Drawing Randburg — a community of artists and models meeting for life drawing sessions. A practice of witnessing, not judgement.">
    <meta name="theme-color" content="#fafaf8">
    <meta property="og:title" content="<?= e($title ?? 'Life Drawing Randburg') ?>">
    <meta property="og:description" content="A community of artists and models meeting for life drawing sessions in Randburg.">
    <meta property="og:type" content="website">
    <title><?= e($title ?? 'Life Drawing Randburg') ?></title>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <script src="https://unpkg.com/htmx.org@2.0.4" integrity="sha384-HGfztofotfshcF7+8n44JQL2oJmowVChPTg48S+jvZoztPfvwD79OC/LTtG6dMp+" crossorigin="anonymous" defer></script>
</head>
<body>
    <header class="site-header">
        <nav class="nav-main">
            <a href="<?= route('home') ?>" class="nav-brand">
                <strong>Life Drawing Randburg</strong>
            </a>
            <div class="nav-links">
                <a href="<?= route('sessions.index') ?>"<?= active_if('/sessions') ?>>Sessions</a>
                <a href="<?= route('gallery.index') ?>"<?= active_if('/gallery') ?>>Gallery</a>
                <a href="<?= route('profiles.artists') ?>"<?= active_if('/artists') ?>>Artists</a>
                <?php if (app('auth')->isLoggedIn()): ?>
                    <span class="nav-sep">|</span>
                    <a href="<?= route('dashboard') ?>"<?= active_if('/dashboard') ?>>Dashboard</a>
                    <a href="<?= route('auth.logout') ?>"><?= e($_SESSION['user_name'] ?? 'Account') ?> (logout)</a>
                <?php else: ?>
                    <span class="nav-sep">|</span>
                    <a href="<?= route('auth.login') ?>">Sign In</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <main class="container">
        <?= $content ?? '' ?>
    </main>

    <footer class="site-footer">
        <p>Life Drawing Randburg &mdash; a practice of witnessing, not judgement.</p>
    </footer>
</body>
</html>
