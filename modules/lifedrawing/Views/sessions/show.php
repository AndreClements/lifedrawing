<?php $uploadService = app('upload'); ?>

<section class="session-detail">
    <div class="section-header">
        <div>
            <h2><?= e($session['title']) ?></h2>
            <div class="session-meta">
                <span><?= format_date($session['session_date']) ?></span>
                <span>&middot;</span>
                <span><?= e($session['venue']) ?></span>
                <span>&middot;</span>
                <span><?= $session['duration_minutes'] ?> min</span>
                <?php if ($session['facilitator_name']): ?>
                    <span>&middot;</span>
                    <span>hosted by <?= e($session['facilitator_name']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="session-actions">
            <?php if (app('auth')->hasRole('admin', 'facilitator')): ?>
                <a href="<?= route('gallery.upload', ['id' => $session['id']]) ?>" class="btn">Upload Artworks</a>
            <?php endif; ?>
            <?php if (app('auth')->isLoggedIn()): ?>
                <form method="POST" action="<?= route('sessions.join', ['id' => $session['id']]) ?>" style="display:inline"
                      hx-post="<?= route('sessions.join', ['id' => $session['id']]) ?>"
                      hx-swap="outerHTML">
                    <?= csrf_field() ?>
                    <input type="hidden" name="role" value="artist">
                    <button type="submit" class="btn btn-outline">Join as Artist</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($session['description']): ?>
        <div class="session-description">
            <p><?= nl2br(e($session['description'])) ?></p>
        </div>
    <?php endif; ?>

    <!-- Participants -->
    <?php if (!empty($participants)): ?>
        <div class="participants-section">
            <h3>Participants</h3>
            <div class="participant-list">
                <?php foreach ($participants as $p): ?>
                    <span class="participant badge-<?= $p['role'] ?>">
                        <?= e($p['display_name']) ?>
                        <small>(<?= e($p['role']) ?>)</small>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Artworks -->
    <div class="artworks-section">
        <h3>Artworks</h3>
        <?php if (empty($artworks)): ?>
            <div class="empty-state">
                <p>No artworks uploaded for this session yet.</p>
            </div>
        <?php else: ?>
            <div class="gallery-grid">
                <?php foreach ($artworks as $artwork): ?>
                    <div class="artwork-card">
                        <img src="<?= e($uploadService->url($artwork['thumbnail_path'] ?? $artwork['file_path'])) ?>"
                             alt="<?= e($artwork['caption'] ?? 'Session artwork') ?>"
                             loading="lazy">
                        <?php if ($artwork['caption']): ?>
                            <p class="artwork-caption"><?= e($artwork['caption']) ?></p>
                        <?php endif; ?>
                        <?php if ($artwork['claims_summary']): ?>
                            <p class="artwork-claims"><?= e($artwork['claims_summary']) ?></p>
                        <?php endif; ?>

                        <?php if (app('auth')->isLoggedIn()): ?>
                            <div class="artwork-actions">
                                <form method="POST" action="<?= route('claims.claim', ['id' => $artwork['id']]) ?>"
                                      hx-post="<?= route('claims.claim', ['id' => $artwork['id']]) ?>"
                                      hx-swap="outerHTML"
                                      style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="claim_type" value="artist">
                                    <button type="submit" class="btn-sm">Claim as Artist</button>
                                </form>
                                <form method="POST" action="<?= route('claims.claim', ['id' => $artwork['id']]) ?>"
                                      hx-post="<?= route('claims.claim', ['id' => $artwork['id']]) ?>"
                                      hx-swap="outerHTML"
                                      style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="claim_type" value="model">
                                    <button type="submit" class="btn-sm btn-outline">Claim as Model</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
