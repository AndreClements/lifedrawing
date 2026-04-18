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
            <?php
                $sessionHexId = hex_id((int) $session['id'], session_title($session));
                $sessionUrl = route('sessions.show', ['id' => $sessionHexId]);
                $canJoin = $activeView === 'upcoming'
                    && $session['status'] !== 'cancelled'
                    && $session['session_date'] >= date('Y-m-d');
            ?>
            <div class="card">
                <a href="<?= $sessionUrl ?>" class="card-link-area">
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
                    <?php if (!empty($session['participants'])): ?>
                        <div class="card-participants"><?= e(implode(', ', $session['participants'])) ?></div>
                    <?php endif; ?>
                    <?php if ($activeView === 'upcoming'): ?>
                        <div class="card-badge badge-<?= $session['status'] ?>"><?= e(ucfirst($session['status'])) ?></div>
                    <?php endif; ?>
                </a>
                <?php if ($canJoin): ?>
                    <div class="card-actions">
                        <?php if (app('auth')->isLoggedIn()): ?>
                            <?php if (empty($session['joined_as_artist'])): ?>
                                <form method="POST" action="<?= route('sessions.join', ['id' => $sessionHexId]) ?>" class="form-inline"
                                      hx-post="<?= route('sessions.join', ['id' => $sessionHexId]) ?>"
                                      hx-swap="outerHTML">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="role" value="artist">
                                    <button type="submit" class="btn btn-outline btn-sm">Join as Artist</button>
                                </form>
                            <?php else: ?>
                                <span class="card-badge badge-active">Joined as artist</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <a href="<?= route('auth.register') ?>?intent=join_session&session_id=<?= $sessionHexId ?>&role=artist" class="btn btn-outline btn-sm">Join as Artist</a>
                            <a href="<?= route('auth.register') ?>?intent=join_session&session_id=<?= $sessionHexId ?>&role=model" class="btn btn-outline btn-sm">Join as Model</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
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
