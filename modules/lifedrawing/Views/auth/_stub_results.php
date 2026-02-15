<?php if (empty($results)): ?>
    <div class="stub-search-empty">No matching participants found.</div>
<?php else: ?>
    <?php foreach ($results as $stub): ?>
        <div class="stub-result-item" data-stub-id="<?= $stub['id'] ?>">
            <span class="stub-result-name"><?= e($stub['display_name']) ?></span>
            <span class="stub-result-sessions"><?= (int) $stub['session_count'] ?> sessions</span>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
