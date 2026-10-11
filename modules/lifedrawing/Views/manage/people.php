<section>
    <h2>People <small>Facilitator access</small></h2>
    <p><a href="<?= route('manage.index') ?>">Manage sessions</a></p>
    <form method="get" action="<?= route('manage.people') ?>" class="form-row">
        <label>Find anyone <input type="search" name="q" value="<?= e($q) ?>" placeholder="Name, email, WhatsApp number or user ID"></label>
        <button type="submit" class="btn">Search</button>
    </form>
    <?php if (!$people): ?><p>No matching users.</p><?php endif; ?>
    <?php foreach ($people as $person): ?>
        <div class="manage-person-row">
            <a href="<?= route('manage.person', ['user' => hex_id((int) $person['id'])]) ?>"><?= e($person['display_name']) ?></a>
            <span><?= e($person['pseudonym'] ?? '') ?> · #<?= (int) $person['id'] ?></span>
            <span><?= str_ends_with($person['email'], '.stub@local') ? 'Stub account' : e($person['email']) ?></span>
        </div>
    <?php endforeach; ?>
    <nav class="pagination">
        <?php if ($page > 1): ?><a href="?q=<?= rawurlencode($q) ?>&amp;page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
        <span>Page <?= $page ?> of <?= $pages ?></span>
        <?php if ($page < $pages): ?><a href="?q=<?= rawurlencode($q) ?>&amp;page=<?= $page + 1 ?>">Next</a><?php endif; ?>
    </nav>
</section>
