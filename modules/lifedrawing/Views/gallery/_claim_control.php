<?php
/**
 * Claim / undo control for one claim type on one artwork.
 *
 * Returned by both claim() and withdraw() for HTMX swaps, replacing the inline
 * HTML strings those actions used to emit, so the button flips in both
 * directions instead of leaving a dead badge behind.
 *
 * @var array       $artwork
 * @var string      $claimType  'artist' or 'model'
 * @var string|null $status     'pending' | 'approved' | null when unclaimed
 * @var int|null    $claimId
 */

$label   = $claimType === 'model' ? 'Model' : 'Artist';
$button  = $claimType === 'model' ? "That's me" : "That's mine";
$hexArt  = hex_id((int) $artwork['id']);
?>
<div class="claim-control claim-control-<?= e($claimType) ?>">
<?php if ($status === null): ?>
    <form method="POST" action="<?= route('claims.claim', ['id' => $hexArt]) ?>"
          hx-post="<?= route('claims.claim', ['id' => $hexArt]) ?>"
          hx-target="closest .claim-control"
          hx-swap="outerHTML"
          class="form-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="claim_type" value="<?= e($claimType) ?>">
        <button type="submit" class="btn-sm<?= $claimType === 'model' ? ' btn-outline' : '' ?>"><?= e($button) ?></button>
    </form>
<?php else: ?>
    <span class="badge badge-<?= $status === 'approved' ? 'success' : 'pending' ?>">
        <?= e($label) ?> <?= e($status) ?>
    </span>
    <?php if ($claimId !== null): ?>
        <?php
        // Undo stays available after approval, not only while pending. Claims
        // are often bulk-approved before the person notices they picked the
        // wrong picture, which is precisely the case worth being able to undo.
        $confirm = $status === 'approved'
            ? 'Undo this claim? The credit will be removed from the artwork and from your profile.'
            : 'Undo this claim?';
        ?>
        <form method="POST" action="<?= route('claims.withdraw', ['id' => hex_id($claimId)]) ?>"
              hx-post="<?= route('claims.withdraw', ['id' => hex_id($claimId)]) ?>"
              hx-target="closest .claim-control"
              hx-swap="outerHTML"
              class="form-inline confirm-action"
              data-confirm="<?= e($confirm) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn-sm btn-link" title="This was not mine">Undo</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
</div>
