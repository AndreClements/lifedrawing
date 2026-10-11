<?php
$sid = hex_id((int) $session['id']);
$editOld = isset($old['note_id']) ? $old : [];
$old = isset($old['text']) && !$editOld ? $old : [];
$kinds = ['note' => 'Note', 'late' => 'Late', 'break' => 'Break', 'left_early' => 'Left early', 'pose_change' => 'Pose change'];
$sitters = array_values(array_filter($participants, static fn($p) => $p['role'] === 'model'));
?>
<section id="session-notes" class="session-notes" data-panel-url="<?= route('manage.notes', ['id' => $sid]) ?>">
    <h3>Session notes <small>Private</small></h3>
    <p class="form-hint">Visible to facilitators and admins. Record what happened, whenever you have a moment.</p>
    <div class="note-shortcuts">
        <button type="button" class="btn-sm" data-note-kind="late">Late</button>
        <button type="button" class="btn-sm" data-note-kind="break" data-note-text="Loo break" data-sitter-id="<?= count($sitters) === 1 ? (int) $sitters[0]['id'] : '' ?>">Sitter loo break</button>
        <button type="button" class="btn-sm" data-note-kind="break">Other break</button>
        <button type="button" class="btn-sm" data-note-kind="left_early">Left early</button>
        <button type="button" class="btn-sm" data-note-kind="pose_change">Pose change</button>
    </div>
    <form method="post" action="<?= route('manage.notes.save', ['id' => $sid]) ?>" data-manage-form data-note-create>
        <?= csrf_field() ?>
        <input type="hidden" name="request_key" value="<?= e($old['request_key'] ?? bin2hex(random_bytes(16))) ?>">
        <div class="form-row">
            <label>Type
                <select name="kind"><?php foreach ($kinds as $value => $label): ?><option value="<?= $value ?>"<?= ($old['kind'] ?? 'note') === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
            </label>
            <label>Person (optional)
                <select name="participant_id">
                    <option value="">Whole session / not sure</option>
                    <?php foreach ($participants as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (string) ($old['participant_id'] ?? '') === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['display_name']) ?> (<?= $p['role'] === 'model' ? 'Sitter' : e(ucfirst($p['role'])) ?>)</option><?php endforeach; ?>
                </select>
            </label>
        </div>
        <label>What happened?
            <textarea name="text" maxlength="4000" rows="2" placeholder="A quick note, or use your phone keyboard's dictation…"><?= e($old['text'] ?? '') ?></textarea>
        </label>
        <details><summary>Minutes or pose (optional)</summary>
            <div class="form-row">
                <label>Minutes <input type="number" name="minutes" min="0" max="1440" value="<?= e($old['minutes'] ?? '') ?>"></label>
                <label>Pose number <input type="number" name="pose_number" min="1" max="65535" value="<?= e($old['pose_number'] ?? '') ?>"></label>
            </div>
        </details>
        <button type="submit" class="btn">Save note</button>
        <span class="save-feedback" role="status" aria-live="polite"></span>
    </form>

    <?php if ($session['status'] !== 'cancelled'): ?>
    <details class="attendance-controls">
        <summary>Attendance and contact details</summary>
        <?php if (!session_is_over($session)): ?><p class="form-hint">No-show controls become available after the session ends. You can record a note now.</p><?php endif; ?>
        <?php foreach ($participants as $p): ?>
            <div class="manage-person-row">
                <span><?= e($p['display_name']) ?> <small><?= $p['role'] === 'model' ? 'Sitter' : e(ucfirst($p['role'])) ?></small></span>
                <a href="<?= route('manage.person', ['user' => hex_id((int) $p['user_id'])]) ?>" target="_blank" rel="noopener">Contact details</a>
                <?php if ($p['role'] !== 'facilitator' && session_is_over($session)): ?>
                    <form method="post" action="<?= route('manage.attendance', ['id' => $sid]) ?>" data-manage-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="pid" value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="expected_attendance" value="<?= e($p['attendance']) ?>">
                        <input type="hidden" name="action" value="<?= $p['attendance'] === 'no_show' ? 'restore' : 'no_show' ?>">
                        <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
                        <button class="btn-sm" type="submit"><?= $p['attendance'] === 'no_show' ? 'Undo no-show' : 'No-show' ?></button>
                        <span class="save-feedback" role="status"></span>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </details>
    <?php endif; ?>

    <?php if (!empty($session['session_notes'])): ?>
        <details><summary>Earlier notes</summary><p class="note-text"><?= nl2br(e($session['session_notes'])) ?></p></details>
    <?php endif; ?>
    <details class="note-history"><summary>Recorded notes (<?= count($notes) ?>)</summary>
    <div class="note-list">
        <?php if (!$notes): ?><p class="text-muted">No notes recorded yet.</p><?php endif; ?>
        <?php foreach ($notes as $note): if ((int) ($editOld['note_id'] ?? 0) === (int) $note['id']) $note = array_merge($note, $editOld); ?>
            <article class="session-note">
                <p><strong><?= e($kinds[$note['kind']] ?? 'Attendance') ?></strong>
                    <?php if ($note['participant_name']): ?> · <?= e($note['participant_name']) ?><?php endif; ?>
                    <?php if ($note['minutes'] !== null): ?> · <?= (int) $note['minutes'] ?> min<?php endif; ?>
                    <?php if ($note['pose_number'] !== null): ?> · Pose <?= (int) $note['pose_number'] ?><?php endif; ?>
                </p>
                <?php if ($note['text']): ?><p class="note-text"><?= nl2br(e($note['text'])) ?></p><?php endif; ?>
                <small class="text-muted">Recorded <?= e(substr($note['created_at'], 0, 16)) ?> by <?= e($note['recorder_name'] ?? 'Former user') ?></small>
                <?php if ($note['kind'] !== 'attendance'): ?>
                    <details><summary>Edit or remove</summary>
                        <form method="post" action="<?= route('manage.notes.edit', ['id' => $sid]) ?>" data-manage-form>
                            <?= csrf_field() ?>
                            <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                            <input type="hidden" name="expected_updated_at" value="<?= e($note['expected_updated_at'] ?? $note['updated_at']) ?>">
                            <label>Type <select name="kind"><?php foreach ($kinds as $value => $label): ?><option value="<?= $value ?>"<?= $note['kind'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                            <label>Person <select name="participant_id"><option value="">Whole session</option><?php foreach ($participants as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (int) $note['participant_id'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['display_name']) ?> (<?= e($p['role']) ?>)</option><?php endforeach; ?></select></label>
                            <label>Note <textarea name="text" maxlength="4000"><?= e($note['text'] ?? '') ?></textarea></label>
                            <div class="form-row">
                                <label>Minutes <input type="number" name="minutes" min="0" max="1440" value="<?= e($note['minutes'] ?? '') ?>"></label>
                                <label>Pose <input type="number" name="pose_number" min="1" max="65535" value="<?= e($note['pose_number'] ?? '') ?>"></label>
                            </div>
                            <button type="submit" name="action" value="edit" class="btn-sm">Save changes</button>
                            <button type="submit" name="action" value="remove" class="btn-sm" data-confirm="Remove this note?">Remove note</button>
                            <span class="save-feedback" role="status"></span>
                        </form>
                    </details>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    </details>
</section>
