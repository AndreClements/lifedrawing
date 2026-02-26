<section class="pose-queue">
    <div class="section-header">
        <h2>Sitter Queue</h2>
    </div>

    <div class="tab-bar">
        <button class="tab <?= $activeView === 'active' ? 'active' : '' ?>"
                hx-get="<?= route('pose.queue') ?>?view=active"
                hx-target="#queue-content" hx-swap="innerHTML">
            Active
        </button>
        <button class="tab <?= $activeView === 'history' ? 'active' : '' ?>"
                hx-get="<?= route('pose.queue') ?>?view=history"
                hx-target="#queue-content" hx-swap="innerHTML">
            History
        </button>
    </div>

    <div id="queue-content">
        <?php include __DIR__ . '/_queue_tab_content.php'; ?>
    </div>
</section>
