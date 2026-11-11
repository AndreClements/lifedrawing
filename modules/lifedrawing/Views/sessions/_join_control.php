<?php
/**
 * Join / cancel control for one session.
 *
 * One partial for three places — the session page, the listing cards, and the
 * HTMX responses from join(), leave() and leaveWaitlist() — so the button flips
 * correctly in every direction and the three stop disagreeing with each other.
 *
 * Four states for an upcoming session: booked (cancel), on the waiting list
 * (leave it), full (join the waiting list), or open (join as artist).
 *
 * @var array $session
 * @var array $myBookings  the current viewer's bookings on this session, keyed by role
 *                         (rows with id, role, promoted_at)
 */

$hexId       = hex_id((int) $session['id'], session_title($session));
$isPast      = ($session['session_date'] ?? '') < date('Y-m-d');
$isCancelled = ($session['status'] ?? '') === 'cancelled';
$myBookings  = $myBookings ?? [];
$joinedAs    = isset($myBookings['artist']) ? 'artist'
             : (isset($myBookings['model']) ? 'model'
             : (isset($myBookings['observer']) ? 'observer' : null));
$waitState   = (!$isPast && !$isCancelled)
    ? session_waitlist_state($session, app('auth')->currentUserId())
    : ['full' => false, 'count' => 0, 'position' => null];

// The contribution note only appears inside 48 hours, and never for a place
// someone was given from the waiting list inside that window.
$confirmText = ($joinedAs !== null && booking_is_late_cancel($session, $myBookings[$joinedAs]))
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
       class="btn btn-outline"><?= $waitState['full'] ? 'Join waiting list' : 'Join as Artist' ?></a>

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

<?php elseif ($waitState['position'] !== null): ?>
    <span class="card-badge badge-waiting">On the waiting list (#<?= (int) $waitState['position'] ?>)</span>
    <form method="POST" action="<?= route('sessions.waitlist.leave', ['id' => $hexId]) ?>"
          hx-post="<?= route('sessions.waitlist.leave', ['id' => $hexId]) ?>"
          hx-target="closest .join-control"
          hx-swap="outerHTML"
          class="form-inline">
        <?= csrf_field() ?>
        <button type="submit" class="btn-sm btn-outline">Leave waiting list</button>
    </form>

<?php else: ?>
    <form method="POST" action="<?= route('sessions.join', ['id' => $hexId]) ?>"
          hx-post="<?= route('sessions.join', ['id' => $hexId]) ?>"
          hx-target="closest .join-control"
          hx-swap="outerHTML"
          class="form-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="role" value="artist">
        <?php if ($waitState['full']): ?>
            <button type="submit" class="btn btn-outline" title="The session is full. You'll be booked automatically if a place opens, and emailed.">Join waiting list</button>
        <?php else: ?>
            <button type="submit" class="btn btn-outline">Join as Artist</button>
        <?php endif; ?>
    </form>
<?php endif; ?>
</div>
