<?php
/**
 * WhatsApp schedule output — facilitator tool.
 *
 * @var string $schedule  The formatted schedule text
 * @var int    $weeks     Current weeks-ahead setting
 */
?>
<section class="whatsapp-schedule">
    <div class="section-header">
        <h2>WhatsApp Schedule</h2>
        <a href="<?= route('sessions.index') ?>" class="btn btn-outline">Back to Sessions</a>
    </div>

    <div class="whatsapp-controls">
        <label for="weeks-select">Weeks ahead:</label>
        <select id="weeks-select" name="weeks" class="input input-sm"
                hx-get="<?= route('schedule.whatsapp') ?>"
                hx-target="#schedule-output"
                hx-swap="outerHTML"
                hx-include="this">
            <?php for ($i = 1; $i <= 8; $i++): ?>
                <option value="<?= $i ?>"<?= $i === $weeks ? ' selected' : '' ?>><?= $i ?></option>
            <?php endfor; ?>
        </select>
    </div>

    <?php include __DIR__ . '/_whatsapp_schedule.php'; ?>
</section>
