<section class="artists-list">
    <h2>Artists</h2>
    <p class="lead">The circulation of roles feels very true to what we do here.</p>

    <?php if (empty($artists ?? [])): ?>
        <div class="empty-state">
            <p>No artist profiles yet. Join a session to appear here.</p>
        </div>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($artists as $artist): ?>
                <a href="<?= route('profiles.show', ['id' => $artist['id']]) ?>" class="card card-link">
                    <h3><?= e($artist['display_name']) ?></h3>
                    <?php if ($artist['bio']): ?>
                        <p class="card-bio"><?= e(excerpt($artist['bio'], 100)) ?></p>
                    <?php endif; ?>
                    <div class="card-stats">
                        <span><?= $artist['total_sessions'] ?> session<?= $artist['total_sessions'] != 1 ? 's' : '' ?></span>
                        &middot;
                        <span><?= $artist['total_artworks'] ?> artwork<?= $artist['total_artworks'] != 1 ? 's' : '' ?></span>
                        <?php if ($artist['current_streak'] > 0): ?>
                            &middot;
                            <span><?= $artist['current_streak'] ?>w streak</span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
