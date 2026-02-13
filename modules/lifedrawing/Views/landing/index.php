<?php $uploadService = app('upload'); ?>

<!-- Hero -->
<section class="hero">
    <h1>Life Drawing Randburg</h1>
    <p class="hero-subtitle">A practice of witnessing, not judgement.</p>
    <p class="hero-body">
        We meet to draw, to model, to hold space for the human form.
        The marks we make are responses &mdash; not evaluations.
    </p>
    <div class="hero-actions">
        <a href="<?= route('sessions.index') ?>" class="btn btn-lg">View Sessions</a>
        <?php if (!app('auth')->isLoggedIn()): ?>
            <a href="<?= route('auth.register') ?>" class="btn btn-outline btn-lg">Join Us</a>
        <?php else: ?>
            <a href="<?= route('dashboard') ?>" class="btn btn-outline btn-lg">My Dashboard</a>
        <?php endif; ?>
    </div>
</section>

<!-- Next Session Highlight -->
<?php if ($upcoming): ?>
    <section class="next-session">
        <div class="next-session-inner">
            <span class="next-label">Next Session</span>
            <h2><?= e($upcoming['title']) ?></h2>
            <div class="next-meta">
                <span><?= format_date($upcoming['session_date']) ?></span>
                <?php if ($upcoming['start_time']): ?>
                    <span>&middot; <?= e(date('H:i', strtotime($upcoming['start_time']))) ?></span>
                <?php endif; ?>
                <span>&middot; <?= e($upcoming['venue']) ?></span>
            </div>
            <a href="<?= route('sessions.show', ['id' => $upcoming['id']]) ?>" class="btn btn-sm">Details &amp; RSVP</a>
        </div>
    </section>
<?php endif; ?>

<!-- Community Stats -->
<section class="community-stats">
    <div class="stats-bar wide">
        <div class="stat">
            <span class="stat-value"><?= $communityStats['total_sessions'] ?></span>
            <span class="stat-label">Sessions</span>
        </div>
        <div class="stat">
            <span class="stat-value"><?= $communityStats['total_artworks'] ?></span>
            <span class="stat-label">Artworks</span>
        </div>
        <div class="stat">
            <span class="stat-value"><?= $communityStats['total_artists'] ?></span>
            <span class="stat-label">Participants</span>
        </div>
    </div>
</section>

<!-- Gallery Highlights -->
<?php if (!empty($galleryHighlights)): ?>
    <section class="section-block">
        <div class="section-header">
            <h2>Recent Work</h2>
            <a href="<?= route('gallery.index') ?>" class="btn btn-outline btn-sm">View Gallery</a>
        </div>
        <div class="gallery-grid">
            <?php foreach ($galleryHighlights as $artwork): ?>
                <div class="artwork-thumb">
                    <img src="<?= e($uploadService->url($artwork['thumbnail_path'] ?? $artwork['file_path'])) ?>"
                         alt="<?= e($artwork['caption'] ?? 'Artwork from ' . $artwork['session_title']) ?>"
                         loading="lazy">
                    <div class="artwork-overlay">
                        <span><?= e($artwork['session_title']) ?></span>
                        <small><?= format_date($artwork['session_date']) ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<!-- Recent Sessions -->
<?php if (!empty($recentSessions)): ?>
    <section class="section-block">
        <div class="section-header">
            <h2>Recent Sessions</h2>
            <a href="<?= route('sessions.index') ?>" class="btn btn-outline btn-sm">All Sessions</a>
        </div>
        <div class="card-grid">
            <?php foreach ($recentSessions as $session): ?>
                <a href="<?= route('sessions.show', ['id' => $session['id']]) ?>" class="card card-link">
                    <div class="card-date"><?= format_date($session['session_date']) ?></div>
                    <h3><?= e($session['title']) ?></h3>
                    <div class="card-meta">
                        <?= e($session['venue']) ?>
                        <?php if ($session['facilitator_name']): ?>
                            &middot; hosted by <?= e($session['facilitator_name']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="card-stats">
                        <span><?= $session['participant_count'] ?> participant<?= $session['participant_count'] != 1 ? 's' : '' ?></span>
                        &middot;
                        <span><?= $session['artwork_count'] ?> artwork<?= $session['artwork_count'] != 1 ? 's' : '' ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<!-- Philosophy -->
<section class="philosophy">
    <blockquote>
        <p>The circulation of roles feels very true to what we do here.
           Sometimes you draw, sometimes you model, sometimes you watch.
           Each position teaches something the others cannot.</p>
    </blockquote>
</section>
