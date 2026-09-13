<?php $uploadService = app('upload'); ?>

<section class="session-detail">
    <div class="section-header">
        <div>
            <h1><em><?= e(session_title($session)) ?></em></h1>
            <?php if (!empty($session['subtitle'])): ?>
                <div class="session-subtitle"><?= e($session['subtitle']) ?></div>
            <?php endif; ?>
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
                <?php if ($session['status'] === 'cancelled'): ?>
                    <span>&middot;</span>
                    <span class="badge-cancelled">Cancelled</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="session-actions">
            <?php if (app('auth')->hasRole('admin', 'facilitator')): ?>
                <a href="<?= route('gallery.upload', ['id' => hex_id((int) $session['id'], session_title($session))]) ?>" class="btn">Upload Artworks</a>
                <?php if ($session['status'] !== 'cancelled'): ?>
                    <form method="POST" action="<?= route('sessions.cancel', ['id' => hex_id((int) $session['id'], session_title($session))]) ?>"
                          class="form-inline confirm-action" data-confirm="Cancel this session?">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline btn-danger">Cancel Session</button>
                    </form>
                <?php else: ?>
                    <span class="badge-cancelled">Cancelled</span>
                <?php endif; ?>
            <?php endif; ?>
            <?php
            // One shared control for join and cancel. This page used never to
            // check whether you had already joined, so it offered "Join as
            // Artist" to people who were already booked, while the listing
            // cards got it right. Now both include the same partial.
            include __DIR__ . '/_join_control.php';
            ?>
        </div>
    </div>

    <?php if ($session['description']): ?>
        <div class="session-description">
            <p><?= nl2br(e($session['description'])) ?></p>
        </div>
    <?php endif; ?>

    <!-- Participants -->
    <?php if (app('auth')->hasRole('admin', 'facilitator')): ?>
        <?php include __DIR__ . '/_participant_manager.php'; ?>
        <?php if ($session['session_date'] >= date('Y-m-d')): ?>
            <!-- Sitter queue reference panel -->
            <?php $sessionDayName = date('l', strtotime($session['session_date'])); ?>
            <div hx-get="<?= route('pose.queue.panel') ?>?day=<?= e($sessionDayName) ?>" hx-trigger="load" hx-swap="innerHTML">
            </div>
        <?php endif; ?>
    <?php elseif (!empty($participants)): ?>
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
            // Group consecutive artworks into the poses they were made during.
            //
            // pose_number (migration 022) is the real answer: one pose, one number,
            // set per import directory. Where it is missing — older uploads, or a web
            // upload that carried no pose information — fall back to runs of matching
            // duration+label, which is the best the data supports.
            //
            // Never key the batches on duration+label: a keyed batch merges two poses
            // that only happen to last the same time, and renders where its FIRST image
            // fell, so a 1 hr pose could appear after drawings made an hour later.
            $batches = [];
            $lastKey = null;
            foreach ($artworks as $artwork) {
                // ?? null, not just null-check: this view has to survive being deployed
                // ahead of migration 022, where a.* simply has no pose_number.
                $key = ($artwork['pose_number'] ?? null) !== null
                    ? 'n' . $artwork['pose_number']
                    : ($artwork['pose_duration'] ?? '') . '|' . ($artwork['pose_label'] ?? '');
                if ($key !== $lastKey) {
                    $batches[] = [];
                    $lastKey = $key;
                }
                $batches[array_key_last($batches)][] = $artwork;
            }
            // More than one batch always means a pose boundary worth labelling. With a
            // single batch, only label it if it actually carries pose information.
            $firstSample = $batches[0][0] ?? null;
            $hasBatchMetadata = count($batches) > 1
                || ($firstSample !== null
                    && (($firstSample['pose_duration'] ?? '') !== '' || ($firstSample['pose_label'] ?? '') !== ''));
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
                            <?php if (($artwork['comment_count'] ?? 0) > 0): ?>
                                <p class="artwork-comments"><?= (int) $artwork['comment_count'] ?> comment<?= (int) $artwork['comment_count'] !== 1 ? 's' : '' ?></p>
                            <?php endif; ?>

                            <?php if (app('auth')->isLoggedIn()): ?>
                                <?php $artistAlreadyClaimed = $artwork['claims_summary'] && str_contains($artwork['claims_summary'], 'artist:'); ?>
                                <?php $canClaimAsModel = ($isSessionModel ?? false) || !($sessionHasKnownModel ?? false); ?>
                                <div class="artwork-actions">
                                    <?php if (!$artistAlreadyClaimed): ?>
                                    <form method="POST" action="<?= route('claims.claim', ['id' => hex_id((int) $artwork['id'], $artwork['caption'] ?? '')]) ?>"
                                          hx-post="<?= route('claims.claim', ['id' => hex_id((int) $artwork['id'], $artwork['caption'] ?? '')]) ?>"
                                          hx-swap="outerHTML"
                                          class="form-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="claim_type" value="artist">
                                        <button type="submit" class="btn-sm">That's mine</button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ($canClaimAsModel): ?>
                                        <form method="POST" action="<?= route('claims.claim', ['id' => hex_id((int) $artwork['id'], $artwork['caption'] ?? '')]) ?>"
                                              hx-post="<?= route('claims.claim', ['id' => hex_id((int) $artwork['id'], $artwork['caption'] ?? '')]) ?>"
                                              hx-swap="outerHTML"
                                              class="form-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="claim_type" value="model">
                                            <button type="submit" class="btn-sm btn-outline">That's me</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
