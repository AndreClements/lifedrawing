<?php if ($activeView === 'active'): ?>
    <?php if (empty($entries)): ?>
        <div class="empty-state small">
            <p>No one in the queue right now.</p>
        </div>
    <?php else: ?>
        <div class="queue-list">
            <?php foreach ($entries as $entry): ?>
                <div class="queue-entry<?= $entry['status'] === 'scheduled' ? ' queue-scheduled' : '' ?>">
                    <div class="queue-entry-info">
                        <strong>
                            <a href="<?= route('profiles.show', ['id' => hex_id((int) $entry['user_id'])]) ?>">
                                <?= e($entry['display_name']) ?>
                            </a>
                        </strong>

                        <?php if ($entry['status'] === 'scheduled'): ?>
                            <span class="badge badge-info">Scheduled<?php
                                if ($entry['session_title'] ?? $entry['session_date'] ?? null): ?>
                                    &nbsp;&mdash; <?= format_date($entry['session_date']) ?>
                                <?php endif; ?></span>
                        <?php endif; ?>

                        <div class="queue-meta">
                            <?php
                            $days = [];
                            if ($entry['sitter_pref_friday']) $days[] = 'Fri';
                            if ($entry['sitter_pref_saturday']) $days[] = 'Sat';
                            if ($entry['sitter_pref_sunday']) $days[] = 'Sun';
                            ?>
                            <?php if ($days): ?>
                                <span class="badge badge-muted"><?= implode(', ', $days) ?></span>
                            <?php endif; ?>
                            <?php if ($entry['sitter_auto_rejoin'] ?? false): ?>
                                <span class="badge badge-muted" title="Auto-rejoin enabled">&#x21bb;</span>
                            <?php endif; ?>
                            <span class="text-muted">Requested <?= format_date($entry['requested_at']) ?></span>
                        </div>

                        <?php if ($entry['whatsapp_number']): ?>
                            <?php $waNum = preg_replace('/[^0-9]/', '', $entry['whatsapp_number']); ?>
                            <div class="queue-whatsapp">
                                <a href="https://wa.me/<?= e($waNum) ?>" target="_blank" rel="noopener">
                                    WhatsApp: <?= e($entry['whatsapp_number']) ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if ($entry['note']): ?>
                            <div class="queue-note"><?= e($entry['note']) ?></div>
                        <?php endif; ?>

                        <div class="queue-sitter-notes"
                             id="sitter-notes-<?= $entry['id'] ?>">
                            <small class="text-muted">Notes:</small>
                            <span class="sitter-notes-display"
                                  hx-get="<?= route('pose.notes', ['id' => hex_id((int) $entry['id'])]) ?>"
                                  hx-trigger="none"><?= $entry['sitter_notes'] ? e($entry['sitter_notes']) : '<span class="text-muted">No notes</span>' ?></span>
                            <form method="POST"
                                  action="<?= route('pose.notes', ['id' => hex_id((int) $entry['id'])]) ?>"
                                  hx-post="<?= route('pose.notes', ['id' => hex_id((int) $entry['id'])]) ?>"
                                  hx-target="#sitter-notes-<?= $entry['id'] ?> .sitter-notes-display"
                                  hx-swap="innerHTML"
                                  class="sitter-notes-form hidden">
                                <?= csrf_field() ?>
                                <input type="text" name="sitter_notes"
                                       value="<?= e($entry['sitter_notes'] ?? '') ?>"
                                       placeholder="Facilitator notes about this sitter..."
                                       class="input-sm">
                                <button type="submit" class="btn-sm">Save</button>
                            </form>
                            <button type="button" class="btn-icon sitter-notes-edit" title="Edit notes">&#9998;</button>
                        </div>
                    </div>

                    <div class="queue-entry-actions">
                        <?php if ($entry['status'] === 'waiting'): ?>
                            <form method="POST"
                                  action="<?= route('pose.schedule', ['id' => hex_id((int) $entry['id'])]) ?>"
                                  class="form-inline">
                                <?= csrf_field() ?>
                                <?php if (!empty($upcomingSessions)): ?>
                                    <select name="session_id" class="input-sm">
                                        <option value="0">No specific session</option>
                                        <?php foreach ($upcomingSessions as $s): ?>
                                            <option value="<?= $s['id'] ?>">
                                                <?= date('D j M', strtotime($s['session_date'])) ?>
                                                &mdash; <?= e(session_title($s)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-sm">Schedule</button>
                            </form>
                        <?php else: ?>
                            <form method="POST"
                                  action="<?= route('pose.complete', ['id' => hex_id((int) $entry['id'])]) ?>"
                                  class="form-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline">Complete &amp; Notify</button>
                            </form>
                        <?php endif; ?>

                        <form method="POST"
                              action="<?= route('pose.remove', ['id' => hex_id((int) $entry['id'])]) ?>"
                              class="form-inline confirm-action" data-confirm="Remove this entry?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn-icon btn-remove" title="Remove">&times;</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- History -->
    <?php if (empty($entries)): ?>
        <div class="empty-state small">
            <p>No queue history yet.</p>
        </div>
    <?php else: ?>
        <div class="queue-list">
            <?php foreach ($entries as $entry): ?>
                <div class="queue-entry queue-entry-history">
                    <div class="queue-entry-info">
                        <strong>
                            <a href="<?= route('profiles.show', ['id' => hex_id((int) $entry['user_id'])]) ?>">
                                <?= e($entry['display_name']) ?>
                            </a>
                        </strong>
                        <div class="queue-meta">
                            <span class="badge badge-<?= $entry['status'] === 'completed' ? 'success' : 'muted' ?>">
                                <?= e(ucfirst($entry['status'])) ?>
                            </span>
                            <?php if ($entry['session_date'] ?? null): ?>
                                <span><?= format_date($entry['session_date']) ?></span>
                            <?php endif; ?>
                            <?php if ($entry['resolved_at']): ?>
                                <span class="text-muted"><?= format_date($entry['resolved_at']) ?></span>
                            <?php endif; ?>
                            <?php if ($entry['resolved_by_name'] ?? null): ?>
                                <span class="text-muted">by <?= e($entry['resolved_by_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
