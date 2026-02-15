<section class="auth-form" style="max-width:560px">
    <h2>Upload Artworks</h2>
    <p class="lead">Session: <?= e(session_title($session)) ?> (<?= format_date($session['session_date']) ?>)</p>

    <?php if (!empty($success ?? '')): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if (!empty($error ?? '')): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= route('gallery.upload.post', ['id' => $session['id']]) ?>"
          enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="pose_duration">Pose Duration</label>
            <select id="pose_duration" name="pose_duration">
                <option value="">— not specified —</option>
                <option value="60">1 minute</option>
                <option value="120">2 minutes</option>
                <option value="300">5 minutes</option>
                <option value="600">10 minutes</option>
                <option value="1200">20 minutes</option>
                <option value="2700">45 minutes</option>
                <option value="custom">Custom...</option>
            </select>
        </div>

        <div class="form-group" id="custom-duration-group" style="display:none">
            <label for="custom_duration">Custom Duration (minutes)</label>
            <input type="number" id="custom_duration" name="custom_duration"
                   min="0.5" max="120" step="0.5"
                   placeholder="e.g., 1.5 for 90 seconds">
        </div>

        <div class="form-group">
            <label for="pose_label">Round / Exercise (optional)</label>
            <input type="text" id="pose_label" name="pose_label"
                   placeholder="e.g., Warmup, Sustained, Quick gestures, Blind contour"
                   value="<?= e($old['pose_label'] ?? '') ?>">
            <small class="form-hint">All images in this upload share this label.</small>
        </div>

        <div class="form-group">
            <label for="artworks">Select Images</label>
            <input type="file" id="artworks" name="artworks[]"
                   accept="image/jpeg,image/png,image/webp"
                   multiple required>
            <small class="form-hint">JPEG, PNG, or WebP. Max 10MB per file. Upload one batch at a time — come back for the next round.</small>
        </div>

        <div class="form-group">
            <label for="caption">Caption (optional)</label>
            <input type="text" id="caption" name="caption"
                   placeholder="e.g., Charcoal gesture drawings">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Upload Batch</button>
            <a href="<?= route('sessions.show', ['id' => $session['id']]) ?>" class="btn btn-outline">Back to Session</a>
        </div>
    </form>
</section>

<script>
document.getElementById('pose_duration').addEventListener('change', function() {
    var customGroup = document.getElementById('custom-duration-group');
    customGroup.style.display = this.value === 'custom' ? '' : 'none';
});
</script>
