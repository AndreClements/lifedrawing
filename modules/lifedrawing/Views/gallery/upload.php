<section class="auth-form" style="max-width:560px">
    <h2>Upload Artworks</h2>
    <p class="lead">Session: <em><?= e(session_title($session)) ?></em> (<?= format_date($session['session_date']) ?>)</p>

    <?php if (!empty($success ?? '')): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if (!empty($error ?? '')): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= route('gallery.upload.post', ['id' => hex_id((int) $session['id'], session_title($session))]) ?>"
          enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="pose_duration">Pose Duration (optional)</label>
            <input type="text" id="pose_duration" name="pose_duration"
                   placeholder="e.g., 5 min, 20 min, 3 poses x 30s x 1.5 min x 5 min"
                   value="<?= e($old['pose_duration'] ?? '') ?>">
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

        <div class="upload-progress" id="upload-progress" hidden>
            <div class="upload-progress-bar" id="upload-progress-bar"></div>
            <span class="upload-progress-text" id="upload-progress-text">Uploading...</span>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn" id="upload-btn">Upload Batch</button>
            <a href="<?= route('sessions.show', ['id' => hex_id((int) $session['id'], session_title($session))]) ?>" class="btn btn-outline">Back to Session</a>
        </div>
    </form>
</section>
