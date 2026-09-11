<?php
/**
 * Join / cancel control for one session.
 *
 * One partial for three places — the session page, the listing cards, and the
 * HTMX responses from both join() and leave() — so the button flips correctly
 * in both directions and the three stop disagreeing with each other. They used
 * to: the listing card checked whether you had already joined, the session page
 * never did, and after an HTMX join you got a dead badge with no way back.
 *
 * @var array $session
 * @var array $joinedRoles  roles the current viewer already holds on this session
 */

$hexId       = hex_id((int) $session['id'], session_title($session));
$isPast      = ($session['session_date'] ?? '') < date('Y-m-d');
$isCancelled = ($session['status'] ?? '') === 'cancelled';
$joinedRoles = $joinedRoles ?? [];
$joinedAs    = in_array('artist', $joinedRoles, true) ? 'artist'
             : (in_array('model', $joinedRoles, true) ? 'model'
             : (in_array('observer', $joinedRoles, true) ? 'observer' : null));

// The contribution note only applies inside 48 hours, so it only appears then.
// Showing it on every cancellation would train people to click past it.
$confirmText = is_late_cancel($session)
    ? 'This session starts in under 48 hours. A 50% contribution is appreciated for late cancellations. Cancel your place?'
    : 'Cancel your place at this session?';
?>
<div class="join-control">
<?php if ($isCancelled || $isPast): ?>
    <?php if ($joinedAs !== null): ?>
        <span class="card-badge">Booked as <?= e($joinedAs) ?></span>
    <?php endif; ?>

<?php elseif (!app('auth')->isLoggedIn()): ?>
    <a href="<?= route('auth.register') ?>?intent=join_session&amp;session_id=<?= $hexId ?>&amp;role=artist"
       class="btn btn-outline">Join as Artist</a>

<?php elseif ($joinedAs !== null): ?>
    <span class="card-badge badge-active">Booked as <?= e($joinedAs) ?></span>
    <form method="POST" action="<?= route('sessions.leave', ['id' => $hexId]) ?>"
          hx-post="<?= route('sessions.leave', ['id' => $hexId]) ?>"
          hx-target="closest .join-control"
          hx-swap="outerHTML"
          class="form-inline confirm-action"
          data-confirm="<?= e($confirmText) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="role" value="<?= e($joinedAs) ?>">
        <button type="submit" class="btn-sm btn-outline">Cancel my place</button>
    </form>

<?php else: ?>
    <form method="POST" action="<?= route('sessions.join', ['id' => $hexId]) ?>"
          hx-post="<?= route('sessions.join', ['id' => $hexId]) ?>"
          hx-target="closest .join-control"
          hx-swap="outerHTML"
          class="form-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="role" value="artist">
        <button type="submit" class="btn btn-outline">Join as Artist</button>
    </form>
<?php endif; ?>
</div>
