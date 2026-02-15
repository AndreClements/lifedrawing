<section class="artists-list">
    <h2>Sitters</h2>
    <p class="lead"><?= e(axiom('sitters_lead')) ?></p>

    <?php if (empty($sitters ?? [])): ?>
        <div class="empty-state">
            <p>No one has sat yet. The first session is being drawn up.</p>
        </div>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($sitters as $sitter): ?>
                <a href="<?= route('profiles.show', ['id' => hex_id((int) $sitter['id'], can_see_names() ? $sitter['display_name'] : '')]) ?>" class="card card-link">
                    <h3><?= profile_name($sitter) ?></h3>
                    <?php if ($sitter['bio']): ?>
                        <p class="card-bio"><?= e(excerpt($sitter['bio'], 100)) ?></p>
                    <?php endif; ?>
                    <div class="card-stats">
                        <span><?= $sitter['sessions_sat'] ?> time<?= $sitter['sessions_sat'] != 1 ? 's' : '' ?> sat</span>
                        &middot;
                        <span><?= $sitter['total_sessions'] ?> session<?= $sitter['total_sessions'] != 1 ? 's' : '' ?> total</span>
                        <?php if ($sitter['current_streak'] > 0): ?>
                            &middot;
                            <span><?= $sitter['current_streak'] ?>w streak</span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
