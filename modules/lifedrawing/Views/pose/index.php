<section class="pose-page">
    <h1>Pose for Life Drawing Randburg</h1>
    <p class="lead">We welcome models of all body types, ages, and experience levels.
    Posing for life drawing is a collaborative, dignified practice.</p>

    <div class="info-block">
        <h3>What to Expect</h3>
        <ul>
            <li>Sessions run on Fridays (3&ndash;7 pm) and Saturdays/Sundays (10 am&ndash;2 pm)</li>
            <li>You hold a mix of short dynamic poses and longer sustained poses</li>
            <li>Remuneration is <strong>R 700</strong> per session, paid immediately after</li>
            <li>The facilitator (Andr&eacute;) will contact you via WhatsApp to arrange scheduling</li>
        </ul>
        <p>For more details, see the <a href="<?= route('pages.faq') ?>">FAQ</a>.</p>
    </div>

    <?php if (!empty($errors ?? [])): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!($loggedIn ?? false)): ?>
        <!-- Not logged in -->
        <div class="pose-cta">
            <h3>Interested?</h3>
            <p>Register an account to join the queue. We'll be in touch via WhatsApp.</p>
            <div class="hero-actions">
                <a href="<?= route('auth.register') ?>?intent=join_pose_queue" class="btn btn-lg">Register to Join</a>
                <a href="<?= route('auth.login') ?>?intent=join_pose_queue" class="btn btn-outline btn-lg">Already registered? Sign In</a>
            </div>
        </div>

    <?php elseif ($inQueue ?? false): ?>
        <!-- In the queue -->
        <div class="pose-status">
            <?php if ($entry['status'] === 'scheduled'): ?>
                <div class="alert alert-info">
                    <strong>You're scheduled to pose!</strong>
                    <?php if ($scheduledSession): ?>
                        Session: <em><?= e(session_title($scheduledSession)) ?></em>
                        on <?= format_date($scheduledSession['session_date']) ?>.
                    <?php endif; ?>
                    Andr&eacute; will be in touch with details via WhatsApp.
                </div>
            <?php else: ?>
                <div class="alert alert-success">
                    <strong>You're in the queue.</strong>
                    Andr&eacute; will contact you on WhatsApp when a session is available
                    that matches your availability.
                </div>
            <?php endif; ?>

            <div class="queue-details">
                <?php
                $user = $user ?? [];
                $days = [];
                if (!empty($user['sitter_pref_friday'])) $days[] = 'Fridays';
                if (!empty($user['sitter_pref_saturday'])) $days[] = 'Saturdays';
                if (!empty($user['sitter_pref_sunday'])) $days[] = 'Sundays';
                ?>
                <?php if ($days): ?>
                    <p><strong>Available:</strong> <?= implode(', ', $days) ?></p>
                <?php endif; ?>
                <p><strong>Requested:</strong> <?= format_date($entry['requested_at']) ?></p>
                <?php if ($entry['note']): ?>
                    <p><strong>Note:</strong> <?= e($entry['note']) ?></p>
                <?php endif; ?>
                <?php if (!empty($user['sitter_auto_rejoin'])): ?>
                    <p class="text-muted">Auto-rejoin is on &mdash; you'll be added back after your session.</p>
                <?php endif; ?>
            </div>

            <form method="POST" action="<?= route('pose.withdraw') ?>"
                  class="confirm-action" data-confirm="Withdraw from the queue?">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline btn-danger">Withdraw from Queue</button>
            </form>
        </div>

    <?php else: ?>
        <!-- Logged in, not in queue -->
        <div class="pose-join">
            <h3>Join the Queue</h3>
            <form method="POST" action="<?= route('pose.join') ?>">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label for="whatsapp_number">WhatsApp Number <small>(required)</small></label>
                    <input type="tel" id="whatsapp_number" name="whatsapp_number"
                           value="<?= e($old['whatsapp_number'] ?? $user['whatsapp_number'] ?? '') ?>"
                           placeholder="+27 82 123 4567" required>
                    <small class="text-muted">So Andr&eacute; can contact you to arrange a session.</small>
                </div>

                <fieldset class="form-group">
                    <legend>Which days are you available?</legend>
                    <div class="notification-prefs">
                        <label class="checkbox-label">
                            <input type="checkbox" name="day_friday" value="1"
                                   <?= !empty($old['day_friday'] ?? $user['sitter_pref_friday'] ?? false) ? 'checked' : '' ?>>
                            <span><strong>Fridays</strong> &mdash; 3 pm to 7 pm</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="day_saturday" value="1"
                                   <?= !empty($old['day_saturday'] ?? $user['sitter_pref_saturday'] ?? false) ? 'checked' : '' ?>>
                            <span><strong>Saturdays</strong> &mdash; 10 am to 2 pm</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="day_sunday" value="1"
                                   <?= !empty($old['day_sunday'] ?? $user['sitter_pref_sunday'] ?? false) ? 'checked' : '' ?>>
                            <span><strong>Sundays</strong> &mdash; 10 am to 2 pm</span>
                        </label>
                    </div>
                </fieldset>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="auto_rejoin" value="1"
                               <?= !empty($old['auto_rejoin'] ?? $user['sitter_auto_rejoin'] ?? false) ? 'checked' : '' ?>>
                        <span>Automatically add me back to the queue after I pose</span>
                    </label>
                </div>

                <div class="form-group">
                    <label for="note">Anything else? <small>(optional)</small></label>
                    <textarea id="note" name="note" rows="3"
                              placeholder="Experience, preferences, availability notes..."
                    ><?= e($old['note'] ?? '') ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">Join the Queue</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</section>
