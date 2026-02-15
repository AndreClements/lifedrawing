<section class="auth-form" style="max-width:560px">
    <h2>New Session</h2>

    <?php if (!empty($errors ?? [])): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= route('sessions.store') ?>">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="title">Title</label>
            <input type="text" id="title" name="title"
                   value="<?= e($old['title'] ?? '') ?>"
                   placeholder="e.g., Thursday Evening Session"
                   required>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="session_date">Date</label>
                <input type="date" id="session_date" name="session_date"
                       value="<?= e($old['session_date'] ?? date('Y-m-d')) ?>"
                       required>
            </div>

            <div class="form-group">
                <label for="start_time">Start Time</label>
                <input type="time" id="start_time" name="start_time"
                       value="<?= e($old['start_time'] ?? '18:30') ?>">
            </div>

            <div class="form-group">
                <label for="duration_minutes">Duration (min)</label>
                <input type="number" id="duration_minutes" name="duration_minutes"
                       value="<?= e($old['duration_minutes'] ?? '180') ?>"
                       min="30" max="480">
            </div>
        </div>

        <div class="form-group">
            <label for="venue">Venue</label>
            <input type="text" id="venue" name="venue"
                   value="<?= e($old['venue'] ?? 'Randburg') ?>">
        </div>

        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description"
                      placeholder="What to expect, any special theme, materials needed..."><?= e($old['description'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Create Session</button>
            <a href="<?= route('sessions.index') ?>" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</section>
