<section class="auth-form consent-form">
    <h2>Consent</h2>

    <div class="consent-text">
        <p>Life Drawing Randburg is a practice of <strong>witnessing, not judgement</strong>.</p>

        <p>By granting consent, you agree to the following:</p>

        <ul>
            <li><strong>Your profile</strong> may be visible to other participants</li>
            <li><strong>Session snapshots</strong> you upload or claim may appear in the gallery</li>
            <li><strong>Your participation</strong> in sessions is recorded for your personal progress tracking</li>
        </ul>

        <p>You retain full control:</p>

        <ul>
            <li>You can <strong>withdraw consent</strong> at any time from your profile settings</li>
            <li>Withdrawing consent hides your content from public view — it is not deleted</li>
            <li>You choose what to make public and what stays private</li>
        </ul>

        <p class="consent-cards">
            <em>This consent is designed around CARDS: your <strong>Competence</strong>, <strong>Autonomy</strong>,
            <strong>Relatedness</strong>, <strong>Dignity</strong>, and <strong>Safety</strong>.</em>
        </p>
    </div>

    <form method="POST" action="<?= route('auth.consent.post') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="grant" value="yes">

        <div class="form-actions">
            <button type="submit" class="btn">I Grant Consent</button>
            <a href="<?= route('home') ?>" class="btn btn-outline">Not Now</a>
        </div>
    </form>
</section>
