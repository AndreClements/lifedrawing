<?php $uploadService = app('upload'); ?>

<section class="profile-detail">
    <div class="profile-header">
        <h2><?= profile_name($profile) ?></h2>
        <?php if ($profile['bio']): ?>
            <p class="lead"><?= nl2br(e($profile['bio'])) ?></p>
        <?php endif; ?>

        <?php if (app('auth')->currentUserId() === (int) $profile['id']): ?>
            <a href="<?= route('profiles.edit') ?>" class="btn btn-outline">Edit Profile</a>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="stats-bar">
        <div class="stat">
            <span class="stat-value"><?= $profile['total_sessions'] ?></span>
            <span class="stat-label">Sessions</span>
        </div>
        <div class="stat">
            <span class="stat-value"><?= $profile['total_artworks'] ?></span>
            <span class="stat-label">Artworks</span>
        </div>
        <div class="stat">
            <span class="stat-value"><?= $profile['current_streak'] ?></span>
            <span class="stat-label">Week Streak</span>
        </div>
        <div class="stat">
            <span class="stat-value"><?= $profile['longest_streak'] ?></span>
            <span class="stat-label">Best Streak</span>
        </div>
    </div>

    <!-- Claimed Artworks -->
    <?php if (!empty($artworks)): ?>
        <h3>Artworks</h3>
        <div class="gallery-grid">
            <?php foreach ($artworks as $artwork): ?>
                <a href="<?= route('artworks.show', ['id' => hex_id((int) $artwork['id'], $artwork['caption'] ?? '')]) ?>" class="artwork-thumb">
                    <img src="<?= e($uploadService->url($artwork['thumbnail_path'] ?? $artwork['web_path'] ?? $artwork['file_path'])) ?>"
                         alt="<?= e($artwork['caption'] ?? 'Artwork') ?>"
                         loading="lazy">
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Sessions -->
    <?php if (!empty($sessions)): ?>
        <h3>Sessions</h3>
        <ul class="session-history">
            <?php foreach ($sessions as $s): ?>
                <li>
                    <a href="<?= route('sessions.show', ['id' => hex_id((int) $s['id'], session_title($s))]) ?>">
                        <?= format_date($s['session_date']) ?> — <em><?= e(session_title($s)) ?></em>
                    </a>
                    <small>(<?= e($s['role']) ?>)</small>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
