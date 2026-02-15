<section class="artists-list">
    <h2>Artists</h2>
    <p class="lead"><?= e(axiom('artists_lead')) ?></p>

    <?php if (empty($artists ?? [])): ?>
        <div class="empty-state">
            <p>No artist profiles yet. Join a session to appear here.</p>
        </div>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($artists as $artist): ?>
                <a href="<?= route('profiles.show', ['id' => hex_id((int) $artist['id'], can_see_names() ? $artist['display_name'] : '')]) ?>" class="card card-link">
                    <h3><?= profile_name($artist) ?></h3>
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
