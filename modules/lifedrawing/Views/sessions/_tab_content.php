<?php if (empty($sessions)): ?>
    <div class="empty-state">
        <?php if ($activeView === 'upcoming'): ?>
            <p>No upcoming sessions scheduled. Check back soon.</p>
        <?php else: ?>
            <p>No past sessions found.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card-grid">
        <?php foreach ($sessions as $session): ?>
            <a href="<?= route('sessions.show', ['id' => hex_id((int) $session['id'], session_title($session))]) ?>" class="card card-link">
                <div class="card-date"><?= format_date($session['session_date']) ?></div>
                <h3><em><?= e(session_title($session)) ?></em></h3>
                <?php if (!empty($session['subtitle'])): ?>
                    <div class="card-subtitle"><?= e($session['subtitle']) ?></div>
                <?php endif; ?>
                <div class="card-meta">
                    <?= e($session['venue']) ?>
                    <?php if ($session['model_sex']): ?>
                        &middot; <?= $session['model_sex'] === 'f' ? '♀' : '♂' ?>
                    <?php endif; ?>
                </div>
                <div class="card-stats">
                    <?php if ($activeView === 'upcoming'): ?>
                        <span><?= $session['participant_count'] ?>/<?= $session['max_capacity'] ?></span>
                    <?php else: ?>
                        <span><?= $session['participant_count'] ?> participant<?= $session['participant_count'] != 1 ? 's' : '' ?></span>
                        <span>&middot;</span>
                        <span><?= $session['artwork_count'] ?> artwork<?= $session['artwork_count'] != 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($activeView === 'upcoming'): ?>
                    <div class="card-badge badge-<?= $session['status'] ?>"><?= e(ucfirst($session['status'])) ?></div>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($activeView === 'past' && $totalPages > 1): ?>
        <nav class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= route('sessions.index') ?>?view=past&page=<?= $page - 1 ?>"
                   class="btn btn-outline btn-sm"
                   hx-get="<?= route('sessions.index') ?>?view=past&page=<?= $page - 1 ?>"
                   hx-target="#tab-content"
                   hx-push-url="<?= route('sessions.index') ?>?view=past&page=<?= $page - 1 ?>"
                   hx-swap="innerHTML">&larr; Newer</a>
            <?php endif; ?>
            <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a href="<?= route('sessions.index') ?>?view=past&page=<?= $page + 1 ?>"
                   class="btn btn-outline btn-sm"
                   hx-get="<?= route('sessions.index') ?>?view=past&page=<?= $page + 1 ?>"
                   hx-target="#tab-content"
                   hx-push-url="<?= route('sessions.index') ?>?view=past&page=<?= $page + 1 ?>"
                   hx-swap="innerHTML">Older &rarr;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
