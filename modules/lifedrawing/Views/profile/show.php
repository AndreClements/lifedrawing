<?php $uploadService = app('upload'); ?>

<section class="profile-detail">
    <div class="profile-header">
        <h1><?= profile_name($profile) ?></h1>
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
        <?php // Runs live in the table below — repeating them here says the same thing twice. ?>
    </div>

    <?php
    // Count, length and current run are three different numbers, so the table keeps
    // them on separate rows rather than folding them into a sentence.
    $st    = $runs['streak'] ?? null;
    $su    = $runs['superstreak'] ?? null;
    $isOwn = app('auth')->currentUserId() === (int) $profile['id'];

    /** Whole numbers stay whole; halves are worth seeing. */
    $fmt = static function (?float $v, string $unit): string {
        if ($v === null) {
            return '&mdash;';
        }
        $n = (fmod($v, 1.0) === 0.0) ? (string) (int) $v : number_format($v, 1);
        return $n . ' ' . ($v == 1 ? rtrim($unit, 's') : $unit);
    };
    ?>
    <?php if ($st !== null && ($st['count'] > 0 || $su['count'] > 0)): ?>
        <section class="practice-runs">
            <h2><?= e(axiom('profile_runs')) ?></h2>
            <div class="table-scroll">
                <table class="runs-table">
                    <thead>
                        <tr>
                            <td></td>
                            <th scope="col">Streaks</th>
                            <th scope="col">Superstreaks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row">Runs so far</th>
                            <td><?= (int) $st['count'] ?></td>
                            <td><?= (int) $su['count'] ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Longest run</th>
                            <td><?= $st['longest'] >= 2 ? $fmt((float) $st['longest'], 'weekends') : '&mdash;' ?></td>
                            <td><?= $su['longest'] >= 2 ? $fmt((float) $su['longest'], 'sessions') : '&mdash;' ?></td>
                        </tr>
                        <tr>
                            <?php // Completed runs only — see StatsService::describe(). ?>
                            <th scope="row">Average completed run</th>
                            <td><?= $fmt($st['average'], 'weekends') ?></td>
                            <td><?= $fmt($su['average'], 'sessions') ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Current run</th>
                            <td><?= $st['current'] > 0 ? $fmt((float) $st['current'], 'weekends') : '&mdash;' ?></td>
                            <td><?= $su['current'] > 0 ? $fmt((float) $su['current'], 'sessions') : '&mdash;' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-muted text-sm">
                Streaks count weekends we meet. Superstreaks count consecutive sessions.
                Each unbroken run counts once.
                <?php if ($st['current'] === 0 && $su['current'] === 0): ?>
                    <?= $isOwn ? 'Your next visit starts a new run.' : 'The next visit starts a new run.' ?>
                <?php endif; ?>
            </p>
        </section>
    <?php endif; ?>

    <?php if (($noShows ?? null) !== null && $noShows > 0): ?>
        <p class="text-muted text-sm">
            Missed sessions: <?= (int) $noShows ?>
            <em>(only you and they see this)</em>
        </p>
    <?php endif; ?>

    <!-- Claimed Artworks -->
    <?php if (!empty($artworks)): ?>
        <h3>Artworks</h3>
        <div class="gallery-grid">
            <?php foreach ($artworks as $artwork): ?>
                <a href="<?= route('artworks.show', ['id' => hex_id((int) $artwork['id'], $artwork['caption'] ?? '')]) ?>" class="artwork-thumb">
                    <img src="<?= e($uploadService->url($artwork['thumbnail_path'] ?? $artwork['web_path'] ?? $artwork['file_path'])) ?>"
                         alt="<?= e($artwork['caption'] ?? 'Artwork') ?>"
                         loading="lazy">
                    <?php if (($artwork['comment_count'] ?? 0) > 0): ?>
                        <span class="comment-count"><?= (int) $artwork['comment_count'] ?></span>
                    <?php endif; ?>
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
