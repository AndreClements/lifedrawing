<section class="sessions-list">
    <div class="section-header">
        <h2>Sessions</h2>
        <?php if (app('auth')->hasRole('admin', 'facilitator')): ?>
            <a href="<?= route('sessions.create') ?>" class="btn">New Session</a>
        <?php endif; ?>
    </div>

    <p class="lead"><?= e(axiom('sessions_lead')) ?></p>

    <?php if (!empty($upcoming)): ?>
        <h3>Upcoming</h3>
        <div class="card-grid">
            <?php foreach ($upcoming as $session): ?>
                <a href="<?= route('sessions.show', ['id' => hex_id((int) $session['id'], session_title($session))]) ?>" class="card card-link">
                    <div class="card-date"><?= format_date($session['session_date']) ?></div>
                    <h3><?= e(session_title($session)) ?></h3>
                    <div class="card-meta">
                        <?= e($session['venue']) ?>
                        <?php if ($session['model_sex']): ?>
                            &middot; <?= $session['model_sex'] === 'f' ? '♀' : '♂' ?>
                        <?php endif; ?>
                    </div>
                    <div class="card-stats">
                        <span><?= $session['participant_count'] ?>/<?= $session['max_capacity'] ?></span>
                    </div>
                    <div class="card-badge badge-<?= $session['status'] ?>"><?= e(ucfirst($session['status'])) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($past)): ?>
        <h3>Past</h3>
        <div class="card-grid">
            <?php foreach ($past as $session): ?>
                <a href="<?= route('sessions.show', ['id' => hex_id((int) $session['id'], session_title($session))]) ?>" class="card card-link">
                    <div class="card-date"><?= format_date($session['session_date']) ?></div>
                    <h3><?= e(session_title($session)) ?></h3>
                    <div class="card-meta">
                        <?= e($session['venue']) ?>
                        <?php if ($session['model_sex']): ?>
                            &middot; <?= $session['model_sex'] === 'f' ? '♀' : '♂' ?>
                        <?php endif; ?>
                    </div>
                    <div class="card-stats">
                        <span><?= $session['participant_count'] ?> participant<?= $session['participant_count'] !== 1 ? 's' : '' ?></span>
                        <span>&middot;</span>
                        <span><?= $session['artwork_count'] ?> artwork<?= $session['artwork_count'] !== 1 ? 's' : '' ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($upcoming) && empty($past)): ?>
        <div class="empty-state">
            <p>No sessions yet. The first one is being drawn up.</p>
        </div>
    <?php endif; ?>
</section>
