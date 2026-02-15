<?php
/**
 * Search results partial — HTMX fragment for participant typeahead.
 * Results are clickable divs; JS fills hidden form and triggers HTMX submit.
 *
 * @var array $results   [{id, display_name, recent_sessions}, ...]
 * @var int   $sessionId
 */
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
