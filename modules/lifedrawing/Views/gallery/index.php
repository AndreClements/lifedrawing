<?php $uploadService = app('upload'); ?>

<section class="gallery">
    <h2>Gallery</h2>
    <p class="lead">Marks are responses, not evaluations.</p>

    <?php if (!empty($sessions ?? [])): ?>
        <div class="gallery-filter">
            <label for="session-filter">Filter by session:</label>
            <select id="session-filter" onchange="window.location='<?= route('gallery.index') ?>?session='+this.value">
                <option value="">All sessions</option>
                <?php foreach ($sessions as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= ($currentSession ?? '') == $s['id'] ? 'selected' : '' ?>>
                        <?= format_date($s['session_date']) ?> — <?= e($s['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <?php if (empty($artworks ?? [])): ?>
        <div class="empty-state">
            <p>Gallery awaiting its first session snapshots.</p>
        </div>
    <?php else: ?>
        <div class="gallery-grid">
            <?php foreach ($artworks as $artwork): ?>
                <div class="artwork-thumb">
                    <img src="<?= e($uploadService->url($artwork['thumbnail_path'] ?? $artwork['file_path'])) ?>"
                         alt="<?= e($artwork['caption'] ?? 'Artwork') ?>"
                         loading="lazy">
                    <div class="artwork-overlay">
                        <span><?= e($artwork['session_title']) ?></span>
                        <small><?= format_date($artwork['session_date']) ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
