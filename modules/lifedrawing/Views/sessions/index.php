<section class="sessions-list">
    <div class="section-header">
        <h2>Sessions</h2>
        <?php if (app('auth')->hasRole('admin', 'facilitator')): ?>
            <div class="header-actions">
                <a href="<?= route('schedule.whatsapp') ?>" class="btn btn-outline">WhatsApp Schedule</a>
                <a href="<?= route('sessions.create') ?>" class="btn">New Session</a>
            </div>
        <?php endif; ?>
    </div>

    <p class="lead"><?= e(axiom('sessions_lead')) ?></p>

    <nav class="tab-bar">
        <a href="<?= route('sessions.index') ?>"
           class="tab <?= $activeView === 'upcoming' ? 'tab-active' : '' ?>"
           hx-get="<?= route('sessions.index') ?>?view=upcoming"
           hx-target="#tab-content"
           hx-push-url="<?= route('sessions.index') ?>"
           hx-swap="innerHTML">Upcoming</a>
        <a href="<?= route('sessions.index') ?>?view=past"
           class="tab <?= $activeView === 'past' ? 'tab-active' : '' ?>"
           hx-get="<?= route('sessions.index') ?>?view=past"
           hx-target="#tab-content"
           hx-push-url="<?= route('sessions.index') ?>?view=past"
           hx-swap="innerHTML">Past</a>
    </nav>

    <div id="tab-content">
        <?php include __DIR__ . '/_tab_content.php'; ?>
    </div>
</section>
