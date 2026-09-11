<?php $uploadService = app('upload'); ?>

<section class="artwork-detail">
    <div class="artwork-full">
        <img src="<?= e($uploadService->url($artwork['web_path'] ?? $artwork['file_path'])) ?>"
             alt="<?= e($artwork['caption'] ?: 'Life drawing from ' . format_date($artwork['session_date']) . ' at ' . $artwork['venue']) ?>">
    </div>

    <div class="artwork-info">
        <?php if ($artwork['caption']): ?>
            <h1><?= e($artwork['caption']) ?></h1>
        <?php endif; ?>

        <div class="artwork-meta">
            <a href="<?= route('sessions.show', ['id' => hex_id((int) $artwork['session_id'])]) ?>">
                <?= format_date($artwork['session_date']) ?>
            </a>
            <?php $realTitle = $artwork['title'] ?? $artwork['session_title'] ?? null; ?>
            <?php if ($realTitle): ?>
                <span>&middot;</span>
                <span><?= e($realTitle) ?></span>
            <?php endif; ?>
            <?php if ($duration): ?>
                <span>&middot;</span>
                <span><?= e($duration) ?> pose</span>
            <?php endif; ?>
            <?php if ($artwork['pose_label']): ?>
                <span>&middot;</span>
                <span><?= e($artwork['pose_label']) ?></span>
            <?php endif; ?>
        </div>

        <?php if (!empty($claims)): ?>
            <div class="artwork-credits">
                <?php foreach ($claims as $claim): ?>
                    <span class="credit badge-<?= $claim['claim_type'] ?>">
                        <?php if (can_see_names()): ?>
                            <a href="<?= route('profiles.show', ['id' => hex_id((int) $claim['user_id'], $claim['display_name'])]) ?>">
                                <?= e($claim['display_name']) ?>
                            </a>
                        <?php else: ?>
                            Participant
                        <?php endif; ?>
                        <small>(<?= e($claim['claim_type']) ?>)</small>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (app('auth')->isLoggedIn()): ?>
          <?php if (app('auth')->consentState()->canParticipate()): ?>
            <?php
            /*
             * One shared control per claim type, so claim and undo swap cleanly
             * in both directions.
             *
             * This also fixes a latent bug in the block it replaces: the else
             * branch fired when somebody ELSE had claimed as artist, and then
             * read $uc['artist'], which is unset in exactly that case - emitting
             * "Artist " plus a PHP warning.
             */
            $uc = $userClaims ?? [];
            $artistClaimedByOther = !isset($uc['artist'])
                && !empty(array_filter($claims, fn($c) => $c['claim_type'] === 'artist'));
            $canClaimAsModel = ($isSessionModel ?? false) || !($sessionHasKnownModel ?? false);
            ?>
            <div class="artwork-actions">
                <?php if (!$artistClaimedByOther): ?>
                    <?php
                    $claimType = 'artist';
                    $status    = $uc['artist']['status'] ?? null;
                    $claimId   = $uc['artist']['id'] ?? null;
                    include __DIR__ . '/_claim_control.php';
                    ?>
                <?php endif; ?>

                <?php if ($canClaimAsModel || isset($uc['model'])): ?>
                    <?php
                    $claimType = 'model';
                    $status    = $uc['model']['status'] ?? null;
                    $claimId   = $uc['model']['id'] ?? null;
                    include __DIR__ . '/_claim_control.php';
                    ?>
                <?php endif; ?>
            </div>
          <?php else: ?>
                <div class="artwork-actions">
                    <a href="<?= route('auth.consent') ?>" class="btn-sm">Grant Consent to Claim</a>
                    <p class="text-muted text-sm">You need to grant consent before claiming artworks.</p>
                </div>
          <?php endif; ?>
        <?php else: ?>
            <?php $hexId = hex_id((int) $artwork['id']); ?>
            <?php $artistAlreadyClaimed = !empty(array_filter($claims, fn($c) => $c['claim_type'] === 'artist')); ?>
            <?php if (!$artistAlreadyClaimed): ?>
            <div class="artwork-actions artwork-actions-guest">
                <a href="<?= route('auth.register') ?>?intent=claim_artwork&artwork_id=<?= $hexId ?>&claim_type=artist" class="btn-sm">That's mine</a>
                <p class="text-muted text-sm">Already registered? <a href="<?= route('auth.login') ?>?intent=claim_artwork&artwork_id=<?= $hexId ?>&claim_type=artist">Sign in</a></p>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Comments -->
    <div class="comments-section" id="comments">
        <h3>Comments<?php if (!empty($comments)): ?> <small>(<?= count($comments) ?>)</small><?php endif; ?></h3>

        <?php if (!empty($comments)): ?>
            <div class="comments-list">
                <?php foreach ($comments as $comment): ?>
                    <?php $roles = $comment['claim_roles'] ? explode(',', $comment['claim_roles']) : []; ?>
                    <div class="comment<?= !empty($roles) ? ' comment-credited' : '' ?>">
                        <div class="comment-header">
                            <strong>
                                <?php if ($comment['display_name'] === 'LDRBot'): ?>
                                    <?= e($comment['display_name']) ?>
                                <?php elseif (can_see_names()): ?>
                                    <a href="<?= route('profiles.show', ['id' => hex_id((int) $comment['user_id'], $comment['display_name'])]) ?>">
                                        <?= e($comment['display_name']) ?>
                                    </a>
                                <?php else: ?>
                                    Participant
                                <?php endif; ?>
                            </strong>
                            <?php foreach ($roles as $role): ?>
                                <span class="comment-badge badge-<?= e(trim($role)) ?>"><?= e(trim($role)) ?></span>
                            <?php endforeach; ?>
                            <time class="comment-time"><?= format_date($comment['created_at'], 'j M Y, H:i') ?></time>
                        </div>
                        <div class="comment-body"><?= nl2br(e($comment['body'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (app('auth')->isLoggedIn() && app('auth')->consentState()->canParticipate()): ?>
            <form method="POST" action="<?= route('artworks.comment', ['id' => hex_id((int) $artwork['id'])]) ?>" class="comment-form">
                <?= csrf_field() ?>
                <textarea name="body" rows="3" placeholder="Add a comment..." required maxlength="2000"></textarea>
                <button type="submit" class="btn">Post Comment</button>
            </form>
        <?php elseif (app('auth')->isLoggedIn()): ?>
            <p class="comment-login">
                <a href="<?= route('auth.consent') ?>">Grant consent</a> to leave a comment.
            </p>
        <?php else: ?>
            <p class="comment-login">
                <a href="<?= route('auth.register') ?>?intent=comment_artwork&artwork_id=<?= hex_id((int) $artwork['id']) ?>">Register</a>
                or <a href="<?= route('auth.login') ?>?intent=comment_artwork&artwork_id=<?= hex_id((int) $artwork['id']) ?>">sign in</a> to leave a comment.
            </p>
        <?php endif; ?>
    </div>

    <div class="artwork-nav">
        <a href="<?= route('sessions.show', ['id' => hex_id((int) $artwork['session_id'])]) ?>" class="btn btn-outline">&larr; Back to Session</a>
        <a href="<?= route('gallery.index') ?>" class="btn btn-outline">Gallery</a>
        <?php if (app('auth')->isLoggedIn() && (app('auth')->hasRole('admin') || app('auth')->hasRole('facilitator'))): ?>
            <form method="POST" action="<?= route('artworks.destroy', ['id' => hex_id((int) $artwork['id'])]) ?>"
                  class="form-inline confirm-action"
                  data-confirm="Delete this artwork? The image files will be removed from the server.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</section>
