<?php
$stub = str_ends_with(strtolower($person['email']), '.stub@local');
$email = !$stub && filter_var($person['email'], FILTER_VALIDATE_EMAIL) ? $person['email'] : null;
$number = $person['whatsapp_number'] ?? '';
$dial = preg_replace('/[\s().-]/', '', $number);
$callable = (bool) preg_match('/\A\+?[0-9]{7,15}\z/', $dial);
$whatsapp = $callable && str_starts_with($dial, '+') ? substr($dial, 1) : null;
?>
<section class="person-details">
    <p><a href="<?= route('manage.people') ?>">People</a> · Private contact details</p>
    <h2><?= e($person['display_name']) ?></h2>
    <?php if (!empty($person['avatar_path'])): ?><img class="profile-picture" src="<?= e(app('upload')->url($person['avatar_path'])) ?>" alt="Profile picture"><?php endif; ?>
    <?php if (!empty($person['avatar_path'])): ?><form method="post" action="<?= route('manage.picture.remove', ['user' => hex_id((int) $person['id'])]) ?>"><?= csrf_field() ?><button class="btn-sm" type="submit" data-confirm="Remove this profile photo?">Remove unsuitable photo</button></form><?php endif; ?>
    <?php if ($person['pseudonym']): ?><p><?= e($person['pseudonym']) ?></p><?php endif; ?>
    <dl>
        <dt>User ID</dt><dd><?= (int) $person['id'] ?></dd>
        <dt>Email</dt><dd>
            <?= e($email ?? 'No email recorded') ?>
            <?php if ($email): ?><button type="button" class="btn-sm" data-copy="<?= e($email) ?>">Copy email</button> <a href="mailto:<?= e($email) ?>">Email</a><?php endif; ?>
        </dd>
        <dt>WhatsApp / contact number</dt><dd>
            <?= e($number ?: 'Not provided') ?>
            <?php if ($number): ?><button type="button" class="btn-sm" data-copy="<?= e($number) ?>">Copy number</button><?php endif; ?>
            <?php if ($callable): ?><a href="tel:<?= e($dial) ?>">Call</a><?php endif; ?>
            <?php if ($whatsapp): ?><a href="https://wa.me/<?= e($whatsapp) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
        </dd>
        <dt>Account</dt><dd><?= $stub ? 'Stub' : 'Registered' ?> · <?= e(ucfirst($person['role'])) ?></dd>
        <dt>Consent</dt><dd><?= e($person['consent_state']) ?></dd>
        <dt>Created</dt><dd><?= e($person['created_at']) ?></dd>
        <dt>Sitter day preferences</dt><dd><?php
            $days = [];
            foreach (['friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'] as $key => $label) if ($person['sitter_pref_' . $key]) $days[] = $label;
            echo $days ? e(implode(', ', $days)) : 'Not provided';
        ?></dd>
        <dt>Auto-rejoin sitter queue</dt><dd><?= $person['sitter_auto_rejoin'] ? 'Yes' : 'No' ?></dd>
        <dt>Facilitator sitter notes</dt><dd class="note-text"><?= nl2br(e($person['sitter_notes'] ?: 'None recorded')) ?></dd>
    </dl>
    <?php if ($person['consent_state'] === 'granted'): ?><p><a href="<?= route('profiles.show', ['id' => hex_id((int) $person['id'])]) ?>">Public profile</a></p><?php endif; ?>
    <h3>Recent bookings and session history</h3>
    <?php if (!$history): ?><p>No bookings recorded.</p><?php endif; ?>
    <?php foreach ($history as $s): ?>
        <p><a href="<?= route('manage.session', ['id' => hex_id((int) $s['id'])]) ?>"><?= format_date($s['session_date']) ?> · <?= e(session_title($s)) ?></a>
            — <?= $s['role'] === 'model' ? 'Sitter' : e(ucfirst($s['role'])) ?> · <?= e($s['status'] === 'cancelled' ? 'Cancelled' : $s['attendance']) ?></p>
    <?php endforeach; ?>
</section>
