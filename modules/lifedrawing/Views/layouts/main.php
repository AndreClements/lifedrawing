<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e(axiom('meta_description')) ?>">
    <meta name="theme-color" content="#fafaf8">
    <link rel="icon" href="<?= asset('favicon/favicon.svg') ?>" type="image/svg+xml">
    <meta property="og:title" content="<?= e($title ?? 'Life Drawing Randburg') ?>">
    <meta property="og:description" content="<?= e($og_description ?? axiom('meta_description')) ?>">
    <meta property="og:type" content="<?= e($og_type ?? 'website') ?>">
    <?php if (!empty($og_image)): ?>
        <meta property="og:image" content="<?= e($og_image) ?>">
        <meta name="twitter:card" content="summary_large_image">
    <?php endif; ?>
    <?php if (!empty($og_url)): ?>
        <meta property="og:url" content="<?= e($og_url) ?>">
    <?php endif; ?>
    <title><?= e($title ?? 'Life Drawing Randburg') ?></title>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <script src="https://unpkg.com/htmx.org@2.0.4" integrity="sha384-HGfztofotfshcF7+8n44JQL2oJmowVChPTg48S+jvZoztPfvwD79OC/LTtG6dMp+" crossorigin="anonymous" defer></script>
</head>
<body>
    <header class="site-header">
        <nav class="nav-main">
            <a href="<?= route('home') ?>" class="nav-brand">
                <svg class="octagram-mark" xmlns="http://www.w3.org/2000/svg" viewBox="42 279 518 518" role="img" aria-hidden="true" focusable="false"><g fill="currentColor"><polygon points="190.06 448.32 190.06 537.91 190.07 537.91 190.07 554.24 204.25 568.41 204.25 497.87 204.23 497.87 204.23 448.32"/><polygon points="204.23 304.51 319.36 419.63 339.4 419.63 204.23 284.47 200.08 280.31 190.06 284.47 190.06 290.34 190.06 419.98 204.23 419.98"/><polygon points="397.59 304.51 397.59 467.32 411.76 481.49 411.76 290.33 411.76 284.46 401.74 280.31 397.59 284.46 305.92 376.13 315.94 386.15"/><polygon points="419.19 556.35 419.19 576.39 554.36 441.23 558.51 437.07 554.36 427.05 548.49 427.05 418.85 427.05 418.85 441.23 534.32 441.23"/><polygon points="390.85 584.7 340.96 634.58 340.97 634.59 305.94 669.62 315.96 679.64 379.31 616.3 379.3 616.29 390.85 604.74"/><polygon points="442.65 522.87 379.31 459.52 379.3 459.53 367.75 447.98 347.7 447.98 397.59 497.87 397.6 497.86 432.63 532.89"/><polygon points="554.36 634.58 462.7 542.91 452.67 552.93 534.32 634.58 371.51 634.58 357.34 648.75 548.49 648.75 554.37 648.75 558.51 638.73"/><polygon points="411.77 627.49 411.77 537.91 411.76 537.91 411.76 521.58 397.59 507.4 397.59 577.95 397.6 577.95 397.6 627.49"/><polygon points="397.6 771.31 282.48 656.18 262.43 656.18 397.6 791.35 401.75 795.5 411.77 791.35 411.77 785.48 411.77 655.84 397.6 655.84"/><polygon points="204.25 771.31 204.25 608.5 190.07 594.33 190.07 785.48 190.07 791.36 200.1 795.5 204.25 791.35 295.92 699.69 285.89 689.66"/><polygon points="300.92 648.75 317.25 648.75 331.42 634.58 260.88 634.58 260.88 634.59 211.33 634.59 211.33 648.76 300.92 648.76"/><polygon points="182.64 519.47 182.64 499.42 47.48 634.59 43.32 638.75 47.48 648.76 53.35 648.76 182.99 648.76 182.99 634.59 67.52 634.59"/><polygon points="210.99 491.12 260.88 441.24 260.87 441.23 295.9 406.2 285.88 396.18 222.53 459.52 222.54 459.53 210.99 471.08"/><polygon points="159.18 552.95 222.53 616.3 222.54 616.29 234.09 627.84 254.13 627.84 204.25 577.95 204.24 577.96 169.21 542.93"/><polygon points="139.14 532.91 149.16 522.88 67.52 441.24 230.32 441.24 244.5 427.06 53.34 427.06 47.47 427.06 43.32 437.09 47.47 441.24"/><polygon points="340.96 441.24 340.96 441.23 390.5 441.23 390.5 427.05 300.92 427.05 300.92 427.06 284.59 427.06 270.41 441.24"/></g></svg>
                <strong>Life Drawing Randburg</strong>
            </a>
            <div class="nav-links">
                <a href="<?= route('sessions.index') ?>"<?= active_if('/sessions') ?>>Sessions</a>
                <a href="<?= route('gallery.index') ?>"<?= active_if('/gallery') ?>>Gallery</a>
                <a href="<?= route('profiles.artists') ?>"<?= active_if('/artists') ?>>Artists</a>
                <a href="<?= route('profiles.sitters') ?>"<?= active_if('/sitters') ?>>Sitters</a>
                <?php if (app('auth')->isLoggedIn()): ?>
                    <span class="nav-sep">|</span>
                    <a href="<?= route('dashboard') ?>"<?= active_if('/dashboard') ?>>Dashboard</a>
                    <a href="<?= route('profiles.edit') ?>"<?= active_if('/profile/edit') ?>><?= e($_SESSION['user_name'] ?? 'Account') ?></a>
                    <a href="<?= route('auth.logout') ?>">Logout</a>
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
        <p><?= axiom('footer') ?></p>
    </footer>
    <script src="<?= asset('js/app.js') ?>" defer></script>
</body>
</html>
