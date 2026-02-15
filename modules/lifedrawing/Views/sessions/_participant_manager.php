<?php
/**
 * Participant manager — facilitator-only HTMX partial.
 * Used both inline in sessions/show.php and as HTMX swap target.
 *
 * @var array $session
 * @var array $participants
 */
$hexId = hex_id((int) $session['id'], session_title($session));
?>
<div id="participant-manager" class="participant-manager">
    <h3>Participants</h3>
    <div class="participant-list">
        <?php foreach ($participants as $p): ?>
            <span class="participant badge-<?= $p['role'] ?><?= $p['tentative'] ? ' tentative' : '' ?>">
                <?= visible_name($p['display_name']) ?><?php if ($p['tentative']): ?>?<?php endif; ?>
                <small>(<?= e($p['role']) ?>)</small>
                <?php if ($p['role'] !== 'facilitator'): ?>
                    <form method="POST"
                          action="<?= route('sessions.participants.tentative', ['id' => $hexId]) ?>"
                          hx-post="<?= route('sessions.participants.tentative', ['id' => $hexId]) ?>"
                          hx-target="#participant-manager"
                          hx-swap="outerHTML"
                          class="form-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                        <button type="submit" class="btn-icon" title="Toggle tentative">?</button>
                    </form>
                    <form method="POST"
                          action="<?= route('sessions.participants.remove', ['id' => $hexId]) ?>"
                          hx-post="<?= route('sessions.participants.remove', ['id' => $hexId]) ?>"
                          hx-target="#participant-manager"
                          hx-swap="outerHTML"
                          class="form-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                        <button type="submit" class="btn-icon btn-remove" title="Remove">&times;</button>
                    </form>
                <?php endif; ?>
            </span>
        <?php endforeach; ?>
        <?php if (empty($participants)): ?>
            <span class="text-muted">No participants yet.</span>
        <?php endif; ?>
    </div>

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
