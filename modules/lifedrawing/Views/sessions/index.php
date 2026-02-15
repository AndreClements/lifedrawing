<section class="sessions-list">
    <div class="section-header">
        <h2>Sessions</h2>
        <?php if (app('auth')->hasRole('admin', 'facilitator')): ?>
            <a href="<?= route('sessions.create') ?>" class="btn">New Session</a>
        <?php endif; ?>
    </div>

    <p class="lead">Life drawing sessions are as much <em>shelter</em> as they are <em>dance</em>.</p>

    <?php if (empty($sessions ?? [])): ?>
        <div class="empty-state">
            <p>No sessions yet. The first one is being drawn up.</p>
        </div>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($sessions as $session): ?>
                <a href="<?= route('sessions.show', ['id' => $session['id']]) ?>" class="card card-link">
                    <div class="card-date"><?= format_date($session['session_date']) ?></div>
                    <h3><?= e($session['title']) ?></h3>
                    <div class="card-meta">
                        <?= e($session['venue']) ?>
                        <?php if ($session['facilitator_name']): ?>
                            &middot; hosted by <?= e($session['facilitator_name']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="card-stats">
                        <span><?= $session['participant_count'] ?> participant<?= $session['participant_count'] !== 1 ? 's' : '' ?></span>
                        <span>&middot;</span>
                        <span><?= $session['artwork_count'] ?> artwork<?= $session['artwork_count'] !== 1 ? 's' : '' ?></span>
                    </div>
                    <div class="card-badge badge-<?= $session['status'] ?>"><?= e(ucfirst($session['status'])) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
