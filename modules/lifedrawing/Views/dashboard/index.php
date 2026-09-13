<?php
$uploadService = app('upload');
$mediaExplored = $stats['media_explored'] ?? [];
$memberSince = $stats['member_since'] ?? null;
?>

<section class="dashboard">
    <div class="dashboard-header">
        <div>
            <h1>Your Practice</h1>
            <p class="lead"><?= e(axiom('dashboard_lead')) ?></p>
        </div>
        <?php if ($memberSince): ?>
            <small class="text-muted">Member since <?= format_date($memberSince) ?></small>
        <?php endif; ?>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-card-value"><?= (int) ($stats['total_sessions'] ?? 0) ?></span>
            <span class="stat-card-label">Sessions Attended</span>
            <div class="stat-card-detail">
                <?php if ($stats['last_session_date'] ?? null): ?>
                    Last: <?= format_date($stats['last_session_date']) ?>
                <?php else: ?>
                    No sessions yet
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-card-value"><?= (int) ($stats['total_artworks'] ?? 0) ?></span>
            <span class="stat-card-label">Claimed Artworks</span>
            <div class="stat-card-detail">
                Your growing body of work
            </div>
        </div>
        <div class="stat-card accent">
            <span class="stat-card-value"><?= (int) ($stats['current_streak'] ?? 0) ?></span>
            <span class="stat-card-label">Current streak</span>
            <div class="stat-card-detail">
                Weekends in a row you have attended
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-card-value"><?= (int) ($stats['longest_streak'] ?? 0) ?></span>
            <span class="stat-card-label">Longest streak</span>
            <div class="stat-card-detail">
                Weekends &mdash; <?= (int) ($stats['streak_count'] ?? 0) ?>
                streak<?= (int) ($stats['streak_count'] ?? 0) === 1 ? '' : 's' ?> so far
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-card-value"><?= (int) ($stats['longest_superstreak'] ?? 0) ?></span>
            <span class="stat-card-label">Longest superstreak</span>
            <div class="stat-card-detail">
                Sessions in a row &mdash; <?= (int) ($stats['superstreak_count'] ?? 0) ?>
                superstreak<?= (int) ($stats['superstreak_count'] ?? 0) === 1 ? '' : 's' ?> so far
            </div>
        </div>
    </div>

    <!-- Sitter Queue CTA -->
    <?php if (!empty($poseQueueEntry)): ?>
        <div class="dashboard-section pose-cta-card">
            <?php if ($poseQueueEntry['status'] === 'scheduled'): ?>
                <p>You're <strong>scheduled to pose</strong>. Andr&eacute; will be in touch with details.</p>
            <?php else: ?>
                <p>You're <strong>in the sitter queue</strong>. Andr&eacute; will contact you on WhatsApp when a session is available.</p>
            <?php endif; ?>
            <a href="<?= route('pose.index') ?>" class="btn btn-outline btn-sm">View Details</a>
        </div>
    <?php else: ?>
        <div class="dashboard-section pose-cta-card">
            <p><strong>Interested in posing?</strong> Join the queue and we'll be in touch.</p>
            <a href="<?= route('pose.index') ?>" class="btn btn-sm">Join the Queue to Pose</a>
        </div>
    <?php endif; ?>

    <!-- Weekly Activity Heatmap -->
    <div class="dashboard-section">
        <h3>Weekly Activity</h3>
        <p class="section-subtitle">Last 12 weeks</p>
        <div class="week-heatmap">
            <?php foreach ($weekGrid as $week): ?>
                <div class="heatmap-cell intensity-<?= $week['intensity'] ?>"
                     title="<?= e($week['label']) ?>: <?= $week['sessions'] ?> session<?= $week['sessions'] !== 1 ? 's' : '' ?>">
                    <span class="heatmap-label"><?= e($week['label']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="heatmap-legend">
            <span>Less</span>
            <div class="heatmap-cell intensity-0 legend-cell"></div>
            <div class="heatmap-cell intensity-1 legend-cell"></div>
            <div class="heatmap-cell intensity-2 legend-cell"></div>
            <div class="heatmap-cell intensity-3 legend-cell"></div>
            <span>More</span>
        </div>
    </div>

    <div class="dashboard-columns">
        <?php if (($noShows ?? 0) > 0): ?>
            <p class="text-muted text-sm dashboard-noshow">
                Missed sessions: <?= (int) $noShows ?>
                <em>(only you and the facilitator see this)</em>
            </p>
        <?php endif; ?>

        <!-- Upcoming bookings -->
        <div class="dashboard-section">
            <h3>Your upcoming sessions</h3>
            <?php if (empty($upcoming)): ?>
                <div class="empty-state small">
                    <p>Nothing booked yet. <a href="<?= route('sessions.index') ?>">See what&rsquo;s coming up</a></p>
                </div>
            <?php else: ?>
                <div class="upcoming-list">
                    <?php foreach ($upcoming as $booking): ?>
                        <?php $bookingHex = hex_id((int) $booking['id'], session_title($booking)); ?>
                        <div class="upcoming-entry">
                            <div class="upcoming-when">
                                <strong><?= date('j', strtotime($booking['session_date'])) ?></strong>
                                <small><?= date('M', strtotime($booking['session_date'])) ?></small>
                            </div>
                            <div class="upcoming-what">
                                <a href="<?= route('sessions.show', ['id' => $bookingHex]) ?>">
                                    <em><?= e(session_title($booking)) ?></em>
                                </a>
                                <div class="text-muted text-sm">
                                    <?= format_date($booking['session_date']) ?>
                                    &middot; <?= e($booking['role']) ?>
                                </div>
                            </div>
                            <div class="upcoming-action">
                                <?php
                                // Cancellable roles only. Creating a session
                                // auto-adds the facilitator as 'facilitator',
                                // and leave() refuses that role - so André's own
                                // sessions used to show a Cancel button that
                                // confirmed, navigated, and did nothing at all.
                                $cancellable = array_values(array_intersect(
                                    array_map('trim', explode(',', (string) $booking['role'])),
                                    ['artist', 'model', 'observer']
                                ));
                                ?>
                                <?php if ($cancellable): ?>
                                    <?php
                                    $confirmText = is_late_cancel($booking)
                                        ? 'This session starts in under 48 hours. A 50% contribution is appreciated for late cancellations. Cancel your place?'
                                        : 'Cancel your place at this session?';
                                    ?>
                                    <form method="POST" action="<?= route('sessions.leave', ['id' => $bookingHex]) ?>"
                                          class="form-inline confirm-action"
                                          data-confirm="<?= e($confirmText) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="role" value="<?= e($cancellable[0]) ?>">
                                        <input type="hidden" name="return" value="dashboard">
                                        <button type="submit" class="btn-sm btn-outline">Cancel</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted text-sm">hosting</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Session Timeline -->
        <div class="dashboard-section">
            <h3>Recent Sessions</h3>
            <?php if (empty($timeline)): ?>
                <div class="empty-state small">
                    <p>No sessions yet. <a href="<?= route('sessions.index') ?>">Browse upcoming sessions</a></p>
                </div>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($timeline as $entry): ?>
                        <div class="timeline-entry">
                            <div class="timeline-date">
                                <strong><?= date('j', strtotime($entry['session_date'])) ?></strong>
                                <small><?= date('M', strtotime($entry['session_date'])) ?></small>
                            </div>
                            <div class="timeline-content">
                                <a href="<?= route('sessions.show', ['id' => hex_id((int) $entry['id'], session_title($entry))]) ?>">
                                    <em><?= e(session_title($entry)) ?></em>
                                </a>
                                <div class="timeline-meta">
                                    <span class="badge badge-<?= e($entry['role']) ?>"><?= e($entry['role']) ?></span>
                                    <?php if ($entry['my_claimed'] > 0): ?>
                                        <span class="text-accent"><?= $entry['my_claimed'] ?> claimed</span>
                                    <?php endif; ?>
                                    <?php if ($entry['artwork_count'] > 0): ?>
                                        <span class="text-muted"><?= $entry['artwork_count'] ?> artworks</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar: Roles + Media + Milestones -->
        <div class="dashboard-sidebar">
            <!-- Role Distribution -->
            <?php if (!empty($roles)): ?>
                <div class="dashboard-section">
                    <h3>Your Roles</h3>
                    <div class="role-bars">
                        <?php
                        $totalRoles = array_sum(array_column($roles, 'count'));
                        foreach ($roles as $role):
                            $pct = $totalRoles > 0 ? round($role['count'] / $totalRoles * 100) : 0;
                        ?>
                            <div class="role-bar-row">
                                <span class="badge badge-<?= e($role['role']) ?>"><?= e($role['role']) ?></span>
                                <div class="role-bar">
                                    <div class="role-bar-fill" style="width: <?= $pct ?>%"></div>
                                </div>
                                <span class="role-bar-count"><?= $role['count'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Media Explored -->
            <?php if (!empty($mediaExplored)): ?>
                <div class="dashboard-section">
                    <h3>Media Explored</h3>
                    <div class="media-tags">
                        <?php foreach ($mediaExplored as $medium): ?>
                            <span class="media-tag"><?= e($medium) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Milestones -->
            <div class="dashboard-section">
                <h3>Milestones</h3>
                <div class="milestones">
                    <?php
                    $achieved = array_filter($milestones, fn($m) => $m['achieved']);
                    $upcoming = array_filter($milestones, fn($m) => !$m['achieved'] && $m['progress'] > 0);
                    ?>

                    <?php if (!empty($achieved)): ?>
                        <?php foreach ($achieved as $m): ?>
                            <div class="milestone achieved">
                                <span class="milestone-icon">&#10003;</span>
                                <span class="milestone-label"><?= e($m['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($upcoming)): ?>
                        <h4 class="milestone-heading">In Progress</h4>
                        <?php foreach (array_slice($upcoming, 0, 3) as $m): ?>
                            <div class="milestone upcoming">
                                <div class="milestone-progress">
                                    <div class="milestone-bar">
                                        <div class="milestone-bar-fill" style="width: <?= $m['progress'] ?>%"></div>
                                    </div>
                                    <span class="milestone-label"><?= e($m['label']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (empty($achieved) && empty($upcoming)): ?>
                        <p class="text-muted">Attend your first session to start earning milestones.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Artworks -->
    <?php if (!empty($recentArtworks)): ?>
        <div class="dashboard-section">
            <h3>Recent Artworks</h3>
            <div class="gallery-grid compact">
                <?php foreach ($recentArtworks as $artwork): ?>
                    <div class="artwork-thumb">
                        <img src="<?= e($uploadService->url($artwork['thumbnail_path'] ?? $artwork['web_path'] ?? $artwork['file_path'])) ?>"
                             alt="<?= e($artwork['caption'] ?? 'Artwork') ?>"
                             loading="lazy">
                    </div>
                <?php endforeach; ?>
            </div>
            <a href="<?= route('profiles.show', ['id' => hex_id((int) $user['id'])]) ?>" class="btn btn-outline mt-md">
                View Full Profile
            </a>
        </div>
    <?php endif; ?>
</section>
