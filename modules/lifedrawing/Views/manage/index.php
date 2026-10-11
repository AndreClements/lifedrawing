<section>
    <h2>Manage sessions</h2>
    <nav class="manage-nav">
        <a href="<?= route('manage.index') ?>">Sessions</a>
        <a href="<?= route('manage.index') ?>?state=cancelled">Cancelled</a>
        <a href="<?= route('manage.people') ?>">People</a>
        <a href="<?= route('sessions.create') ?>">New session</a>
    </nav>
    <h3><?= $cancelled ? 'Cancelled sessions' : 'Sessions' ?></h3>
    <?php if (!$sessions): ?><p>No sessions here.</p><?php endif; ?>
    <div class="manage-session-list">
        <?php foreach ($sessions as $s): ?>
            <p><a href="<?= route('manage.session', ['id' => hex_id((int) $s['id'])]) ?>"><?= format_date($s['session_date']) ?> · <?= e(session_title($s)) ?></a>
                <?php if ($cancelled && $s['cancellation_reason']): ?><small><?= e($s['cancellation_reason']) ?></small><?php endif; ?></p>
        <?php endforeach; ?>
    </div>
    <nav class="pagination">
        <?php if ($page > 1): ?><a href="?state=<?= $cancelled ? 'cancelled' : '' ?>&amp;page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
        <span>Page <?= $page ?> of <?= $pages ?></span>
        <?php if ($page < $pages): ?><a href="?state=<?= $cancelled ? 'cancelled' : '' ?>&amp;page=<?= $page + 1 ?>">Next</a><?php endif; ?>
    </nav>
</section>
