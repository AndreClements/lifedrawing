<section id="profile-picture">
    <h3>Profile picture <small>Optional</small></h3>
    <p>A clear, non-nude photo is highly recommended for sitters, to help the facilitator recognise you. You can still join and sit without one.</p>
    <p class="form-hint">Your photo appears on your public profile and in sitter listings. Use a clothed portrait; a square crop is made from the centre.</p>
    <?php if (!empty($user['avatar_path'])): ?>
        <img class="profile-picture" src="<?= e(app('upload')->url($user['avatar_path'])) ?>" alt="Your current profile picture">
    <?php else: ?><div class="profile-picture-placeholder" aria-label="No profile photo">No photo yet</div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= route('profiles.picture') ?>">
        <?= csrf_field() ?>
        <label>Choose a photo <input type="file" name="picture" accept="image/jpeg,image/png,image/webp" required></label>
        <small class="form-hint">JPEG, PNG or WebP, up to 10MB and 16 megapixels.</small>
        <label class="checkbox-inline"><input type="checkbox" name="non_nude" value="1" required> This is a non-nude photo of me that I want to use on my profile.</label>
        <button type="submit" class="btn"><?= !empty($user['avatar_path']) ? 'Replace photo' : 'Upload photo' ?></button>
    </form>
    <?php if (!empty($user['avatar_path'])): ?>
        <form method="post" action="<?= route('profiles.picture.remove') ?>"><?= csrf_field() ?><button type="submit" class="btn-sm" data-confirm="Remove your profile picture?">Remove photo</button></form>
    <?php endif; ?>
</section>
