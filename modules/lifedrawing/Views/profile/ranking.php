<?php
/**
 * Shared ranking page for streaks and superstreaks.
 *
 * Names go through profile_name(), and the profile link only carries a name slug when
 * can_see_names() allows it — the same consent handling as the artists and sitters lists.
 *
 * @var list<array<string,mixed>> $rows
 * @var string $kind     'streak' | 'superstreak'
 * @var string $heading
 * @var string $leadKey
 */
$isSuper = ($kind ?? 'streak') === 'superstreak';
?>
<section class="artists-list">
    <h1><?= e($heading) ?></h1>
    <p class="lead"><?= e(axiom($leadKey)) ?></p>

    <p class="text-muted text-sm">
        <?php if ($isSuper): ?>
            A <strong>superstreak</strong> is consecutive sessions, in the order they were
            held &mdash; a Friday, Saturday and Sunday attended in full counts as three.
            Missing any session that ran breaks it.
        <?php else: ?>
            A <strong>streak</strong> is consecutive weekends the studio ran that you came
            to. Weekends with no session cost nothing, so a fortnight off between sessions
            keeps a streak alive. Two or more in a row counts as one streak.
        <?php endif; ?>
    </p>

    <p class="text-sm">
        <?php if ($isSuper): ?>
            <a href="<?= route('profiles.streaks') ?>">See streaks instead</a>
        <?php else: ?>
            <a href="<?= route('profiles.superstreaks') ?>">See superstreaks instead</a>
        <?php endif; ?>
        &middot;
        <a href="<?= route('profiles.artists') ?>">All artists</a>
    </p>

    <?php if (empty($rows ?? [])): ?>
        <div class="empty-state">
            <p>Nobody has a <?= $isSuper ? 'superstreak' : 'streak' ?> yet. Come to two in a row and you will.</p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="ranking-table">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Artist</th>
                        <th scope="col">Best</th>
                        <th scope="col"><?= $isSuper ? 'Superstreaks' : 'Streaks' ?></th>
                        <th scope="col">Sessions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 0; $prevBest = null; $shown = 0; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $shown++;
                        // Equal bests share a rank; the next rank skips accordingly.
                        if ($prevBest === null || (int) $row['best_run'] !== $prevBest) {
                            $rank = $shown;
                            $prevBest = (int) $row['best_run'];
                        }
                        ?>
                        <tr>
                            <td class="rank"><?= $rank ?></td>
                            <td>
                                <a href="<?= route('profiles.show', ['id' => hex_id((int) $row['id'], can_see_names() ? $row['display_name'] : '')]) ?>">
                                    <?= profile_name($row) ?>
                                </a>
                                <?php if ((int) $row['current_run'] > 1): ?>
                                    <span class="badge badge-success">on <?= (int) $row["current_run"] ?> now</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= (int) $row['best_run'] ?></strong></td>
                            <td><?= (int) $row['run_count'] ?></td>
                            <td><?= (int) $row['total_sessions'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
