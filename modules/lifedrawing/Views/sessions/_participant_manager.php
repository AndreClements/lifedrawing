<?php
/**
 * Participant manager — facilitator-only HTMX partial.
 * Used both inline in sessions/show.php and as HTMX swap target.
 *
 * @var array $session
 * @var array $participants
 */
$hexId = hex_id((int) $session['id'], session_title($session));

// A no-show is not knowable in advance, so the control only appears once the
// session has happened. The controller enforces the same rule — this is the
// convenience, that is the guard.
$sessionIsPast = $session['status'] !== 'cancelled' && session_is_over($session);
?>
<div id="participant-manager" class="participant-manager">
    <h3>Participants</h3>
    <div class="participant-list">
        <?php foreach ($participants as $p): ?>
            <?php $isNoShow = ($p['attendance'] ?? 'booked') === 'no_show'; ?>
            <span class="participant badge-<?= $p['role'] ?><?= $p['tentative'] ? ' tentative' : '' ?><?= $isNoShow ? ' no-show' : '' ?>">
                <?= visible_name($p['display_name']) ?><?php if ($p['tentative']): ?>?<?php endif; ?>
                <small>(<?= $p['role'] === 'model' ? 'Sitter' : e(ucfirst($p['role'])) ?>)</small>
                <?php if ($isNoShow): ?>
                    <small class="badge badge-muted">no-show</small>
                <?php endif; ?>
                <a href="<?= route('manage.person', ['user' => hex_id((int) $p['user_id'])]) ?>" target="_blank" rel="noopener" title="Contact details">Contact</a>
                <?php if ($p['role'] !== 'facilitator'): ?>
                    <form method="POST"
                          action="<?= route('sessions.participants.tentative', ['id' => $hexId]) ?>"
                          hx-post="<?= route('sessions.participants.tentative', ['id' => $hexId]) ?>"
                          hx-target="#participant-manager"
                          hx-swap="outerHTML"
                          class="form-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                        <input type="hidden" name="tentative" value="<?= $p['tentative'] ? '0' : '1' ?>">
                        <button type="submit" class="btn-icon" title="Change tentative booking">?</button>
                    </form>
                    <?php if ($sessionIsPast): ?>
                        <form method="POST"
                              action="<?= route('sessions.participants.noshow', ['id' => $hexId]) ?>"
                              hx-post="<?= route('sessions.participants.noshow', ['id' => $hexId]) ?>"
                              hx-target="#participant-manager"
                              hx-swap="outerHTML"
                              class="form-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                            <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
                            <input type="hidden" name="action" value="<?= $isNoShow ? 'restore' : 'no_show' ?>">
                            <input type="hidden" name="expected_attendance" value="<?= e($p['attendance']) ?>">
                            <button type="submit" class="btn-icon"
                                    title="<?= $isNoShow ? 'Undo no-show' : 'Mark as a no-show' ?>"><?= $isNoShow ? '&#8617;' : '&#8709;' ?></button>
                        </form>
                    <?php endif; ?>
                    <form method="POST"
                          action="<?= route('sessions.participants.remove', ['id' => $hexId]) ?>"
                          hx-post="<?= route('sessions.participants.remove', ['id' => $hexId]) ?>"
                          hx-target="#participant-manager"
                          hx-swap="outerHTML"
                          class="form-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                        <button type="submit" class="btn-icon btn-remove" title="Remove booking">&times;</button>
                    </form>
                <?php endif; ?>
            </span>
        <?php endforeach; ?>
        <?php if (empty($participants)): ?>
            <span class="text-muted">No participants yet.</span>
        <?php endif; ?>
    </div>

    <?php
    // Loaded here rather than passed in, so every place that renders this
    // partial (session page, manage page, HTMX swaps) shows it without change.
    $waitlist = (new \App\Services\WaitlistService(app('db')))->listing((int) $session['id']);
    ?>
    <?php if ($waitlist): ?>
        <h4>Waiting list</h4>
        <p class="form-hint">First in line gets the next artist place automatically, and is emailed.</p>
        <div class="participant-list waitlist">
            <?php foreach ($waitlist as $i => $w): ?>
                <span class="participant badge-waiting">
                    <?= $i + 1 ?>. <?= visible_name($w['display_name']) ?>
                    <a href="<?= route('manage.person', ['user' => hex_id((int) $w['user_id'])]) ?>" target="_blank" rel="noopener" title="Contact details">Contact</a>
                    <?php if ($session['status'] !== 'cancelled' && !session_is_over($session)): ?>
                        <form method="POST"
                              action="<?= route('sessions.waitlist.promote', ['id' => $hexId]) ?>"
                              hx-post="<?= route('sessions.waitlist.promote', ['id' => $hexId]) ?>"
                              hx-target="#participant-manager"
                              hx-swap="outerHTML"
                              class="form-inline confirm-action"
                              data-confirm="Give <?= e($w['display_name']) ?> a place now, even if the session is full?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="wid" value="<?= (int) $w['id'] ?>">
                            <button type="submit" class="btn-icon" title="Give a place now">&uarr;</button>
                        </form>
                    <?php endif; ?>
                    <form method="POST"
                          action="<?= route('sessions.waitlist.remove', ['id' => $hexId]) ?>"
                          hx-post="<?= route('sessions.waitlist.remove', ['id' => $hexId]) ?>"
                          hx-target="#participant-manager"
                          hx-swap="outerHTML"
                          class="form-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="wid" value="<?= (int) $w['id'] ?>">
                        <button type="submit" class="btn-icon btn-remove" title="Take off the waiting list">&times;</button>
                    </form>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="add-participant">
        <h4>Add participant</h4>
        <div class="add-participant-controls">
            <label class="radio-inline">
                <input type="radio" name="add-role" value="artist" checked> Artist
            </label>
            <label class="radio-inline">
                <input type="radio" name="add-role" value="model"> Model
            </label>
            <label class="radio-inline">
                <input type="radio" name="add-role" value="observer"> Observer
            </label>
            <label class="radio-inline" title="Only for a full session that shows its capacity">
                <input type="radio" name="add-role" value="waitlist"> Waiting list
            </label>
            <label class="checkbox-inline">
                <input type="checkbox" name="add-tentative" value="1"> Tentative
            </label>
        </div>

        <!-- Hidden form for adding participants — always in DOM so HTMX processes it -->
        <form id="add-participant-form" method="POST"
              action="<?= route('sessions.participants.add', ['id' => $hexId]) ?>"
              hx-post="<?= route('sessions.participants.add', ['id' => $hexId]) ?>"
              hx-target="#participant-manager"
              hx-swap="outerHTML"
              class="hidden">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" id="add-user-id" value="">
            <input type="hidden" name="role" id="add-role-field" value="artist">
            <input type="hidden" name="tentative" id="add-tentative-field" value="0">
        </form>

        <div class="search-wrapper">
            <input type="text"
                   id="participant-search"
                   class="input"
                   placeholder="Type a name..."
                   autocomplete="off"
                   hx-get="<?= route('sessions.participants.search', ['id' => $hexId]) ?>"
                   hx-trigger="input changed delay:300ms"
                   hx-target="#search-results"
                   hx-swap="innerHTML"
                   hx-include="[name='add-role']"
                   name="q">
            <div id="search-results" class="search-results"></div>
        </div>
    </div>
</div>
