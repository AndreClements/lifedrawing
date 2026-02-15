<?php $uploadService = app('upload'); ?>

<section class="artwork-detail">
    <div class="artwork-full">
        <img src="<?= e($uploadService->url($artwork['web_path'] ?? $artwork['file_path'])) ?>"
             alt="<?= e($artwork['caption'] ?? 'Artwork') ?>">
    </div>

    <div class="artwork-info">
        <?php if ($artwork['caption']): ?>
            <h2><?= e($artwork['caption']) ?></h2>
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
            <?php $uc = $userClaims ?? []; ?>
            <?php $hasArtistClaim = isset($uc['artist']); ?>
            <?php $hasModelClaim = isset($uc['model']); ?>
            <?php if (!$hasArtistClaim || !$hasModelClaim): ?>
                <div class="artwork-actions">
                    <?php if (!$hasArtistClaim): ?>
                        <form method="POST" action="<?= route('claims.claim', ['id' => hex_id((int) $artwork['id'])]) ?>"
                              hx-post="<?= route('claims.claim', ['id' => hex_id((int) $artwork['id'])]) ?>"
                              hx-swap="outerHTML"
                              style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="claim_type" value="artist">
                            <button type="submit" class="btn-sm">Claim as Artist</button>
                        </form>
                    <?php else: ?>
                        <span class="badge badge-<?= $uc['artist'] === 'approved' ? 'success' : 'pending' ?>">Artist <?= e($uc['artist']) ?></span>
                    <?php endif; ?>
                    <?php if (!$hasModelClaim): ?>
                        <form method="POST" action="<?= route('claims.claim', ['id' => hex_id((int) $artwork['id'])]) ?>"
                              hx-post="<?= route('claims.claim', ['id' => hex_id((int) $artwork['id'])]) ?>"
                              hx-swap="outerHTML"
                              style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="claim_type" value="model">
                            <button type="submit" class="btn-sm btn-outline">Claim as Model</button>
                        </form>
                    <?php else: ?>
                        <span class="badge badge-<?= $uc['model'] === 'approved' ? 'success' : 'pending' ?>">Model <?= e($uc['model']) ?></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="artwork-actions">
                    <span class="badge badge-<?= $uc['artist'] === 'approved' ? 'success' : 'pending' ?>">Artist <?= e($uc['artist']) ?></span>
                    <span class="badge badge-<?= $uc['model'] === 'approved' ? 'success' : 'pending' ?>">Model <?= e($uc['model']) ?></span>
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
                                <?php if (can_see_names()): ?>
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

        <?php if (app('auth')->isLoggedIn()): ?>
            <form method="POST" action="<?= route('artworks.comment', ['id' => hex_id((int) $artwork['id'])]) ?>" class="comment-form">
                <?= csrf_field() ?>
                <textarea name="body" rows="3" placeholder="Add a comment..." required maxlength="2000"></textarea>
                <button type="submit" class="btn">Post Comment</button>
            </form>
        <?php else: ?>
            <p class="comment-login"><a href="<?= route('auth.login') ?>">Sign in</a> to leave a comment.</p>
        <?php endif; ?>
    </div>

    <div class="artwork-nav">
        <a href="<?= route('sessions.show', ['id' => hex_id((int) $artwork['session_id'])]) ?>" class="btn btn-outline">&larr; Back to Session</a>
        <a href="<?= route('gallery.index') ?>" class="btn btn-outline">Gallery</a>
        <?php if (app('auth')->isLoggedIn() && (app('auth')->hasRole('admin') || app('auth')->hasRole('facilitator'))): ?>
            <form method="POST" action="<?= route('artworks.destroy', ['id' => hex_id((int) $artwork['id'])]) ?>"
                  onsubmit="return confirm('Delete this artwork? The image files will be removed from the server.')"
                  style="display:inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</section>
