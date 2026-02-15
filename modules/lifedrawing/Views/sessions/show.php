<?php $uploadService = app('upload'); ?>

<section class="session-detail">
    <div class="section-header">
        <div>
            <h2><?= e(session_title($session)) ?></h2>
            <div class="session-meta">
                <span><?= format_date($session['session_date']) ?></span>
                <span>&middot;</span>
                <span><?= e($session['venue']) ?></span>
                <span>&middot;</span>
                <span><?= $session['duration_minutes'] ?> min</span>
                <?php if ($session['model_sex']): ?>
                    <span>&middot;</span>
                    <span><?= $session['model_sex'] === 'f' ? 'Female' : 'Male' ?> figure</span>
                <?php endif; ?>
                <?php if ($session['facilitator_name']): ?>
                    <span>&middot;</span>
                    <span>hosted by <?= visible_name($session['facilitator_name'], 'Facilitator') ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="session-actions">
            <?php if (app('auth')->hasRole('admin', 'facilitator')): ?>
                <a href="<?= route('gallery.upload', ['id' => hex_id((int) $session['id'], session_title($session))]) ?>" class="btn">Upload Artworks</a>
            <?php endif; ?>
            <?php if (app('auth')->isLoggedIn()): ?>
                <form method="POST" action="<?= route('sessions.join', ['id' => hex_id((int) $session['id'], session_title($session))]) ?>" style="display:inline"
                      hx-post="<?= route('sessions.join', ['id' => hex_id((int) $session['id'], session_title($session))]) ?>"
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
                        <?= visible_name($p['display_name']) ?>
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
            <?php
            // Group artworks by batch (pose_duration + pose_label)
            $batches = [];
            foreach ($artworks as $artwork) {
                $key = ($artwork['pose_duration'] ?? '') . '|' . ($artwork['pose_label'] ?? '');
                $batches[$key][] = $artwork;
            }
            $hasBatchMetadata = count($batches) > 1
                || (count($batches) === 1 && array_key_first($batches) !== '|');
            ?>

            <?php foreach ($batches as $batchKey => $batchArtworks): ?>
                <?php
                $sample = $batchArtworks[0];
                $duration = $sample['pose_duration'] ?? null;
                $label = $sample['pose_label'] ?? null;
                $hasMeta = $duration || $label;
                ?>

                <?php if ($hasBatchMetadata && $hasMeta): ?>
                    <div class="batch-header">
                        <?php if ($label): ?>
                            <strong><?= e($label) ?></strong>
                        <?php endif; ?>
                        <?php if ($duration): ?>
                            <span class="batch-duration"><?= e($duration) ?> poses</span>
                        <?php endif; ?>
                        <span class="batch-count">(<?= count($batchArtworks) ?> image<?= count($batchArtworks) !== 1 ? 's' : '' ?>)</span>
                    </div>
                <?php endif; ?>

                <div class="gallery-grid">
                    <?php foreach ($batchArtworks as $artwork): ?>
                        <div class="artwork-card">
                            <a href="<?= route('artworks.show', ['id' => hex_id((int) $artwork['id'], $artwork['caption'] ?? '')]) ?>">
                                <img src="<?= e($uploadService->url($artwork['thumbnail_path'] ?? $artwork['web_path'] ?? $artwork['file_path'])) ?>"
                                     alt="<?= e($artwork['caption'] ?? 'Session artwork') ?>"
                                     loading="lazy">
                            </a>
                            <?php if ($artwork['caption']): ?>
                                <p class="artwork-caption"><?= e($artwork['caption']) ?></p>
                            <?php endif; ?>
                            <?php if ($artwork['claims_summary']): ?>
                                <p class="artwork-claims"><?= e($artwork['claims_summary']) ?></p>
                            <?php endif; ?>

                            <?php if (app('auth')->isLoggedIn()): ?>
                                <div class="artwork-actions">
                                    <form method="POST" action="<?= route('claims.claim', ['id' => hex_id((int) $artwork['id'], $artwork['caption'] ?? '')]) ?>"
                                          hx-post="<?= route('claims.claim', ['id' => hex_id((int) $artwork['id'], $artwork['caption'] ?? '')]) ?>"
                                          hx-swap="outerHTML"
                                          style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="claim_type" value="artist">
                                        <button type="submit" class="btn-sm">Claim as Artist</button>
                                    </form>
                                    <form method="POST" action="<?= route('claims.claim', ['id' => hex_id((int) $artwork['id'], $artwork['caption'] ?? '')]) ?>"
                                          hx-post="<?= route('claims.claim', ['id' => hex_id((int) $artwork['id'], $artwork['caption'] ?? '')]) ?>"
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
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
