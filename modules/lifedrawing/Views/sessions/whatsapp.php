<?php
/**
 * WhatsApp schedule output — facilitator tool.
 *
 * @var string $schedule  The formatted schedule text
 */
?>
<section class="whatsapp-schedule">
    <div class="section-header">
        <h2>WhatsApp Schedule</h2>
        <a href="<?= route('sessions.index') ?>" class="btn btn-outline">Back to Sessions</a>
    </div>

    <?php include __DIR__ . '/_whatsapp_schedule.php'; ?>
</section>
