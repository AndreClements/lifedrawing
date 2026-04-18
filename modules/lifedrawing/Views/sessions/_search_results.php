<?php
/**
 * Search results partial — HTMX fragment for participant typeahead.
 * Results are clickable divs; JS fills hidden form and triggers HTMX submit.
 * Trailing row offers "Create new stub" with the typed query.
 *
 * @var array  $results   [{id, display_name, recent_sessions}, ...]
 * @var int    $sessionId
 * @var string $query
 * @var string $role
 */
$hexId = hex_id((int) $sessionId);
$quickAddUrl = route('sessions.participants.quick_add_stub', ['id' => $hexId]);
?>
<?php if (empty($results)): ?>
    <div class="search-result-empty">No matches found.</div>
<?php else: ?>
    <?php foreach ($results as $user): ?>
        <div class="search-result-item" data-user-id="<?= $user['id'] ?>">
            <span class="search-result-name"><?= e($user['display_name']) ?></span>
            <span class="search-result-count"><?= (int) $user['recent_sessions'] ?> sessions</span>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php if (!empty($query)): ?>
    <form method="POST"
          action="<?= $quickAddUrl ?>"
          hx-post="<?= $quickAddUrl ?>"
          hx-target="#participant-manager"
          hx-swap="outerHTML"
          class="search-result-item search-result-create">
        <?= csrf_field() ?>
        <input type="hidden" name="display_name" value="<?= e($query) ?>">
        <input type="hidden" name="role" value="<?= e($role) ?>">
        <button type="submit" class="btn-link-inline">
            <span class="search-result-name">+ Create stub: &ldquo;<?= e($query) ?>&rdquo;</span>
            <span class="search-result-count">as <?= e($role) ?></span>
        </button>
    </form>
<?php endif; ?>
