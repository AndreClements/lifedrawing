<?php
$sid = hex_id((int) $session['id']);
$cancelled = $session['status'] === 'cancelled';
$details = array_merge($session, isset($old['session_date']) ? $old : []);
if (isset($old['session_date'])) {
    foreach (['capacity_published', 'model_join_enabled'] as $flag) $details[$flag] = empty($old[$flag]) ? 0 : 1;
}
?>
<section class="session-management">
    <nav class="manage-nav"><a href="<?= route('manage.index') ?>">Manage sessions</a><a href="<?= route('manage.people') ?>">People</a>
        <?php if (!$cancelled): ?><a href="<?= route('sessions.show', ['id' => $sid]) ?>">View session</a><a href="<?= route('gallery.upload', ['id' => $sid]) ?>">Upload artworks</a><?php endif; ?>
    </nav>
    <h2><?= e(session_title($session)) ?></h2>
    <p><?= format_date($session['session_date']) ?> · <?= e(substr($session['start_time'] ?: '10:00', 0, 5)) ?> · <?= e($session['venue']) ?></p>
    <?php if (!empty($error)): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>
    <?php if (!empty($success)): ?><p class="alert alert-success"><?= e($success) ?></p><?php endif; ?>
    <?php if ($cancelled): ?>
        <p class="alert">Cancelled. Visible only to facilitators and admins. <?= e($session['cancellation_reason'] ?? '') ?></p>
        <details><summary>Restore session</summary>
            <p>Restore the retained bookings for <?= format_date($session['session_date']) ?>. Review the sitter queue first if bookings have changed.</p>
            <form method="post" action="<?= route('manage.restore', ['id' => $sid]) ?>">
                <?= csrf_field() ?>
                <?php if (!$session['cancelled_from_status']): ?>
                    <label>Previous state <select name="previous_status" required><option value="">Choose the correct state</option><option value="scheduled">Scheduled</option><option value="active">Active</option><option value="completed">Completed</option></select></label>
                <?php else: ?><p>Previous state: <?= e($session['cancelled_from_status']) ?></p><?php endif; ?>
                <button class="btn" type="submit" data-confirm="Restore this session and its retained bookings?">Restore session</button>
            </form>
        </details>
    <?php endif; ?>

    <?= app('view')->render('manage._notes', compact('session', 'participants', 'notes', 'old')) ?>

    <section id="people">
        <h3>People and roles</h3>
        <?php foreach ($participants as $p): ?>
            <div class="manage-person-row">
                <span><?= e($p['display_name']) ?> <small>#<?= (int) $p['id'] ?> · <?= e($p['attendance']) ?><?= $p['tentative'] ? ' · tentative' : '' ?></small></span>
                <a target="_blank" rel="noopener" href="<?= route('manage.person', ['user' => hex_id((int) $p['user_id'])]) ?>">Contact details</a>
                <?php if (!$cancelled && $p['role'] !== 'facilitator'): ?>
                    <form method="post" action="<?= route('manage.role', ['id' => $sid]) ?>">
                        <?= csrf_field() ?><input type="hidden" name="pid" value="<?= (int) $p['id'] ?>"><input type="hidden" name="expected_role" value="<?= e($p['role']) ?>">
                        <label>Role <select name="role"><?php foreach (['artist' => 'Artist', 'model' => 'Sitter', 'observer' => 'Observer'] as $role => $label): ?><option value="<?= $role ?>"<?= (($old['pid'] ?? null) == $p['id'] ? ($old['role'] ?? $p['role']) : $p['role']) === $role ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                        <button class="btn-sm" type="submit">Change role</button>
                    </form>
                <?php else: ?><span><?= $p['role'] === 'model' ? 'Sitter' : e(ucfirst($p['role'])) ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$cancelled): ?><details><summary>Add or remove bookings</summary><?= app('view')->render('sessions._participant_manager', compact('session', 'participants')) ?></details><?php endif; ?>
    </section>

    <?php if (!$cancelled): ?>
    <details id="details"<?= isset($old['session_date']) ? ' open' : '' ?>><summary>Edit session details</summary>
        <form method="post" action="<?= route('manage.details', ['id' => $sid]) ?>">
            <?= csrf_field() ?><input type="hidden" name="expected_updated_at" value="<?= e(isset($old['session_date']) ? $old['expected_updated_at'] : $session['updated_at']) ?>">
            <div class="form-row">
                <label>Date <input type="date" name="session_date" required value="<?= e($details['session_date']) ?>"></label>
                <label>Start time <input type="time" name="start_time" value="<?= e(substr($details['start_time'] ?? '', 0, 5)) ?>"></label>
                <label>Duration (minutes) <input type="number" name="duration_minutes" min="1" max="1440" required value="<?= (int) $details['duration_minutes'] ?>"></label>
            </div>
            <p class="form-hint">Currently <?= format_date($session['session_date']) ?> at <?= e(substr($session['start_time'] ?: '10:00', 0, 5)) ?> for <?= (int) $session['duration_minutes'] ?> minutes.</p>
            <label class="checkbox-inline"><input type="checkbox" name="confirm_schedule" value="1"> I have reviewed the old and new date/time if rescheduling.</label>
            <div class="form-row">
                <label>Title (optional) <input name="title" maxlength="200" value="<?= e($details['title'] ?? '') ?>"></label>
                <label>Format <input name="subtitle" maxlength="200" value="<?= e($details['subtitle'] ?? '') ?>"></label>
                <label>Venue <input name="venue" maxlength="200" required value="<?= e($details['venue']) ?>"></label>
            </div>
            <label>Public description <textarea name="description" maxlength="10000"><?= e($details['description'] ?? '') ?></textarea></label>
            <div class="form-row">
                <label>Artist places <input type="number" name="max_capacity" min="1" max="10000" value="<?= (int) $details['max_capacity'] ?>"></label>
                <label>Sitter listing <select name="model_sex"><option value="">Unspecified</option><option value="f"<?= $details['model_sex'] === 'f' ? ' selected' : '' ?>>Female</option><option value="m"<?= $details['model_sex'] === 'm' ? ' selected' : '' ?>>Male</option></select></label>
            </div>
            <p class="form-hint"><?= count(array_filter($participants, static fn($p) => $p['role'] === 'artist')) ?> artists currently booked. Reducing capacity does not remove bookings.</p>
            <label class="checkbox-inline"><input type="checkbox" name="capacity_published" value="1"<?= !empty($details['capacity_published']) ? ' checked' : '' ?>> Show capacity</label>
            <label class="checkbox-inline"><input type="checkbox" name="model_join_enabled" value="1"<?= !empty($details['model_join_enabled']) ? ' checked' : '' ?>> Allow sitter self-booking</label>
            <label>Schedule note <input name="booking_note" maxlength="255" value="<?= e($details['booking_note'] ?? '') ?>"></label>
            <button type="submit" class="btn">Save session details</button>
        </form>
    </details>
    <details id="photos"<?= isset($old['artwork_id']) || isset($old['pose_number']) ? ' open' : '' ?>><summary>Photo captions and poses</summary>
        <?php if (!$groups): ?><p>No photos uploaded yet.</p><?php endif; ?>
        <?php if ($legacy): ?>
            <p>These older photos use the displayed groups below. Confirm the groups before editing poses or uploading the next batch. If a group combines separate poses, resolve that grouping before confirming.</p>
            <form method="post" action="<?= route('manage.poses.normalize', ['id' => $sid]) ?>">
                <?= csrf_field() ?><label><input type="checkbox" name="confirm_groups" value="1" required> Keep the displayed pose groups</label>
                <button type="submit" class="btn-sm">Confirm pose groups</button>
            </form>
        <?php endif; ?>
        <?php foreach ($groups as $group): $first = $group[0]; $editPose = isset($old['expected_pose']) && (string) ($old['pose_number'] ?? '') === (string) $first['pose_number'] ? array_merge($first, $old) : $first; ?>
            <section class="manage-pose">
                <h4><?= $first['pose_number'] !== null ? 'Pose ' . (int) $first['pose_number'] : 'Unnumbered group' ?> · <?= count($group) ?> photos</h4>
                <?php if (!$legacy):
                    $versions = array_map(static fn($a) => ['id' => $a['id'], 'updated_at' => $a['updated_at']], $group);
                    usort($versions, static fn($a, $b) => $a['id'] <=> $b['id']);
                ?>
                    <form method="post" action="<?= route('manage.pose', ['id' => $sid]) ?>">
                        <?= csrf_field() ?><input type="hidden" name="pose_number" value="<?= (int) $first['pose_number'] ?>"><input type="hidden" name="expected_pose" value="<?= e($editPose['expected_pose'] ?? hash('sha256', json_encode($versions))) ?>">
                        <div class="form-row"><label>Label <input name="pose_label" maxlength="100" value="<?= e($editPose['pose_label'] ?? '') ?>"></label><label>Duration <input name="pose_duration" maxlength="50" value="<?= e($editPose['pose_duration'] ?? '') ?>"></label></div>
                        <button type="submit" class="btn-sm">Save for <?= count($group) ?> photos</button>
                    </form>
                <?php else: ?><p><?= e($editPose['pose_label'] ?? '') ?> · <?= e($editPose['pose_duration'] ?? '') ?></p><?php endif; ?>
                <div class="manage-photo-grid">
                    <?php foreach ($group as $artwork): if (in_array($artwork['visibility'], ['private', 'removed'], true)) continue; $editArtwork = isset($old['artwork_id']) && (int) $old['artwork_id'] === (int) $artwork['id'] ? array_merge($artwork, $old) : $artwork; ?>
                        <article>
                            <a href="<?= route('artworks.show', ['id' => hex_id((int) $artwork['id'])]) ?>"><img loading="lazy" src="<?= e(app('upload')->url($artwork['thumbnail_path'] ?: ($artwork['web_path'] ?: $artwork['file_path']))) ?>" alt="Artwork <?= (int) $artwork['id'] ?>"></a>
                            <form method="post" action="<?= route('manage.caption', ['id' => $sid]) ?>">
                                <?= csrf_field() ?><input type="hidden" name="artwork_id" value="<?= (int) $artwork['id'] ?>"><input type="hidden" name="expected_updated_at" value="<?= e($editArtwork['expected_updated_at'] ?? $artwork['updated_at']) ?>">
                                <label>Caption <textarea name="caption" maxlength="4000" rows="2"><?= e($editArtwork['caption'] ?? '') ?></textarea></label>
                                <button type="submit" class="btn-sm">Save caption</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </details>
    <details><summary>Cancel session</summary>
        <?php if (time() >= session_starts_at($session)): ?>
            <p>This session has started. Use attendance and notes to record what happened.</p>
        <?php elseif ($groups): ?>
            <p>Photos have been uploaded. This session cannot be cancelled.</p>
        <?php else: ?>
            <p>Keep the bookings and notes, and hide the session from everyone except facilitators/admins.</p>
            <form method="post" action="<?= route('manage.cancel', ['id' => $sid]) ?>">
                <?= csrf_field() ?><label>Reason (optional, private) <textarea name="reason" maxlength="2000"><?= e($old['reason'] ?? '') ?></textarea></label>
                <button type="submit" class="btn btn-outline" data-confirm="Cancel this session? Its bookings and notes will be retained.">Cancel session</button>
            </form>
        <?php endif; ?>
    </details>
    <?php endif; ?>
    <details><summary>Refresh statistics</summary><form method="post" action="<?= route('manage.refresh', ['id' => $sid]) ?>"><?= csrf_field() ?><button type="submit" class="btn-sm">Refresh statistics</button></form></details>
</section>
