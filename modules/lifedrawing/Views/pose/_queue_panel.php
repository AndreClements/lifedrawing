<?php
/**
 * Compact sitter queue panel — embedded in session create/show pages.
 * Read-only reference for facilitators.
 *
 * @var array $entries  — active queue entries with user prefs
 * @var string $sessionDay — day of week for the session (e.g., 'Friday'), empty if unknown
 */
$dayMap = [
    'Friday'   => 'sitter_pref_friday',
    'Saturday' => 'sitter_pref_saturday',
    'Sunday'   => 'sitter_pref_sunday',
];
$matchCol = $dayMap[$sessionDay] ?? '';
?>
<div class="queue-panel">
    <div class="queue-panel-header">
        <h4>Sitter Queue</h4>
        <a href="<?= route('pose.queue') ?>" class="text-muted">Manage &rarr;</a>
    </div>
    <?php if (empty($entries)): ?>
        <p class="text-muted">No one in the queue.</p>
    <?php else: ?>
        <div class="queue-panel-list">
            <?php foreach ($entries as $entry): ?>
                <?php
                $days = [];
                if ($entry['sitter_pref_friday']) $days[] = 'Fri';
                if ($entry['sitter_pref_saturday']) $days[] = 'Sat';
                if ($entry['sitter_pref_sunday']) $days[] = 'Sun';
                $matches = $matchCol && !empty($entry[$matchCol]);
                ?>
                <div class="queue-panel-entry<?= $matches ? ' queue-panel-match' : '' ?><?= $entry['status'] === 'scheduled' ? ' queue-panel-scheduled' : '' ?>">
                    <div>
                        <strong><?= e($entry['display_name']) ?></strong>
                        <?php if ($entry['status'] === 'scheduled'): ?>
                            <span class="badge badge-info" title="Already scheduled">Sched.</span>
                        <?php endif; ?>
                        <?php if ($days): ?>
                            <span class="badge badge-muted"><?= implode(', ', $days) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($entry['whatsapp_number']): ?>
                        <?php $waNum = preg_replace('/[^0-9]/', '', $entry['whatsapp_number']); ?>
                        <a href="https://wa.me/<?= e($waNum) ?>" target="_blank" rel="noopener" class="queue-panel-wa" title="WhatsApp">WA</a>
                    <?php endif; ?>
                    <?php if ($entry['sitter_notes']): ?>
                        <div class="queue-panel-notes text-muted"><?= e($entry['sitter_notes']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
