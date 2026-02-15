<?php $uploadService = app('upload'); ?>

<section class="claims-list">
    <h2>Pending Claims</h2>

    <?php if (empty($claims ?? [])): ?>
        <div class="empty-state">
            <p>No pending claims. All caught up.</p>
        </div>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($claims as $claim): ?>
                <div class="card claim-card">
                    <?php $claimImg = $claim['thumbnail_path'] ?? $claim['web_path'] ?? $claim['file_path'] ?? null; ?>
                    <?php if ($claimImg): ?>
                        <img src="<?= e($uploadService->url($claimImg)) ?>"
                             alt="Claimed artwork" loading="lazy" class="claim-thumb">
                    <?php endif; ?>

                    <div class="claim-info">
                        <strong><?= e($claim['claimant_name']) ?></strong>
                        claims as <em><?= e($claim['claim_type']) ?></em>
                        <br>
                        <small>Session: <em><?= e(session_title($claim)) ?></em> (<?= format_date($claim['session_date']) ?>)</small>
                    </div>

                    <div class="claim-actions">
                        <form method="POST" action="<?= route('claims.resolve', ['id' => hex_id((int) $claim['id'])]) ?>"
                              hx-post="<?= route('claims.resolve', ['id' => hex_id((int) $claim['id'])]) ?>"
                              hx-target="closest .claim-card" hx-swap="outerHTML"
                              class="form-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn">Approve</button>
                        </form>
                        <form method="POST" action="<?= route('claims.resolve', ['id' => hex_id((int) $claim['id'])]) ?>"
                              hx-post="<?= route('claims.resolve', ['id' => hex_id((int) $claim['id'])]) ?>"
                              hx-target="closest .claim-card" hx-swap="outerHTML"
                              class="form-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="btn btn-outline">Reject</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
