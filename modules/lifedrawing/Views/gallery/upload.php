<section class="auth-form" style="max-width:560px">
    <h2>Upload Artworks</h2>
    <p class="lead">Session: <?= e($session['title']) ?> (<?= format_date($session['session_date']) ?>)</p>

    <?php if (!empty($error ?? '')): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= route('gallery.upload.post', ['id' => $session['id']]) ?>"
          enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="artworks">Select Images</label>
            <input type="file" id="artworks" name="artworks[]"
                   accept="image/jpeg,image/png,image/webp"
                   multiple required>
            <small class="form-hint">JPEG, PNG, or WebP. Max 10MB per file.</small>
        </div>

        <div class="form-group">
            <label for="caption">Caption (optional)</label>
            <input type="text" id="caption" name="caption"
                   placeholder="e.g., Charcoal gesture drawings, 5-minute poses">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Upload</button>
            <a href="<?= route('sessions.show', ['id' => $session['id']]) ?>" class="btn btn-outline">Back to Session</a>
        </div>
    </form>
</section>
