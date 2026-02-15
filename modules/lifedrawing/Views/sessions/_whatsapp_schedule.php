<?php
/**
 * WhatsApp schedule output partial — HTMX swappable.
 *
 * @var string $schedule  The formatted schedule text
 * @var int    $weeks     Current weeks-ahead setting
 */
?>
<div id="schedule-output" class="schedule-output">
    <textarea id="schedule-text" readonly rows="<?= max(8, substr_count($schedule, "\n") + 2) ?>" class="schedule-textarea"><?= e($schedule) ?></textarea>
    <button type="button" class="btn copy-schedule" data-target="schedule-text">Copy to clipboard</button>
</div>
