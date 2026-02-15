<?php
$uploadService = app('upload');
$mediaExplored = $stats['media_explored'] ?? [];
$memberSince = $stats['member_since'] ?? null;
?>

<section class="dashboard">
    <div class="dashboard-header">
        <div>
            <h2>Your Practice</h2>
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
            <span class="stat-card-value"><?= (int) ($stats['current_streak'] ?? 0) ?>w</span>
            <span class="stat-card-label">Current Streak</span>
            <div class="stat-card-detail">
                Consecutive weeks attended
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-card-value"><?= (int) ($stats['longest_streak'] ?? 0) ?>w</span>
            <span class="stat-card-label">Best Streak</span>
            <div class="stat-card-detail">
                Your personal record
            </div>
        </div>
    </div>

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
