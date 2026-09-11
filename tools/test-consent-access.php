<?php

/**
 * CLI: Release 1 verification — consent withdrawal, file access, eligibility.
 *
 * Usage: php tools/test-consent-access.php
 *
 * Runs against the LOCAL dev database and writes real rows and real files,
 * cleaning both up afterwards. Never point it at production.
 *
 * The load-bearing assertion is the file one. A database column reading
 * 'private' has never proved anything: public/.htaccess hands existing files to
 * Apache without PHP ever running, so the only evidence that withdrawal works is
 * the file being gone from the public tree.
 */

declare(strict_types=1);

define('LDR_ROOT', dirname(__DIR__));
require LDR_ROOT . '/vendor/autoload.php';

if (file_exists(LDR_ROOT . '/.env')) {
    foreach (file(LDR_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (str_contains($line, '=')) {
            putenv($line);
            [$k, $v] = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v, "\"'");
        }
    }
}

$_SESSION = [];

use App\Database\Connection;
use App\Services\Auth\AuthService;

$cfg = config('database');
$db = new Connection(
    host: $cfg['host'],
    database: $cfg['database'],
    username: $cfg['username'],
    password: $cfg['password'],
    port: $cfg['port'] ?? 3306,
    charset: $cfg['charset'] ?? 'utf8mb4',
);

// ConsentGate resolves app('db'); bind the same connection so it works in CLI.
\App\Container::getInstance()->singleton('db', fn() => $db);

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  {$name}\n";
    } else {
        $failed++;
        echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

const MARKER = 'ZZTEST-consent-access';

// --- Preflight ------------------------------------------------------------

echo "\nPreflight\n";

$hasSource = $db->fetch(
    "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ld_notification_queue'
       AND COLUMN_NAME = 'source_type'"
);
if ((int) $hasSource['n'] !== 1) {
    fwrite(STDERR, "Migration 020 not applied. Run: php tools/migrate.php run\n");
    exit(1);
}
check('migration 020 applied', true);

$uploadDir = LDR_ROOT . '/public/assets/uploads';
$relDir    = 'sessions/zztest';
$publicDir = $uploadDir . '/' . $relDir;

$facilitatorId = (int) $db->fetchColumn(
    "SELECT id FROM users WHERE role IN ('admin','facilitator') ORDER BY id ASC LIMIT 1"
);
if (!$facilitatorId) {
    fwrite(STDERR, "No facilitator account locally. Run: php tools/seed.php\n");
    exit(1);
}

// --- Fixtures -------------------------------------------------------------

@mkdir($publicDir, 0755, true);

$db->execute(
    "INSERT INTO users (display_name, email, password_hash, role, consent_state, consent_granted_at)
     VALUES (?, ?, ?, 'participant', 'granted', NOW())",
    [MARKER . ' uploader', 'zztest.uploader@local.test', AuthService::STUB_HASH]
);
$uploaderId = (int) $db->lastInsertId();

$db->execute(
    "INSERT INTO users (display_name, email, password_hash, role, consent_state, consent_granted_at, notify_comment)
     VALUES (?, ?, ?, 'participant', 'granted', NOW(), 1)",
    [MARKER . ' claimant', 'zztest.claimant@local.test', AuthService::STUB_HASH]
);
$claimantId = (int) $db->lastInsertId();

// One past session and one FUTURE session — the future one must not count.
$db->execute(
    "INSERT INTO ld_sessions (title, session_date, venue, facilitator_id) VALUES (?, ?, 'Randburg', ?)",
    [MARKER . ' past', date('Y-m-d', strtotime('-14 days')), $facilitatorId]
);
$pastSessionId = (int) $db->lastInsertId();

$db->execute(
    "INSERT INTO ld_sessions (title, session_date, venue, facilitator_id) VALUES (?, ?, 'Randburg', ?)",
    [MARKER . ' future', date('Y-m-d', strtotime('+14 days')), $facilitatorId]
);
$futureSessionId = (int) $db->lastInsertId();

foreach ([$pastSessionId, $futureSessionId] as $sid) {
    $db->execute(
        "INSERT INTO ld_session_participants (session_id, user_id, role) VALUES (?, ?, 'artist')",
        [$sid, $claimantId]
    );
}

/** Create an artwork row backed by real files on disk. */
function makeArtwork(Connection $db, string $publicDir, string $relDir, int $sessionId, int $uploaderId, string $stem): array
{
    $files = [];
    foreach (['' => 'file_path', 'web_' => 'web_path', 'thumb_' => 'thumbnail_path'] as $prefix => $col) {
        $name = $prefix . $stem . '.jpg';
        file_put_contents($publicDir . '/' . $name, 'test-bytes-' . $name);
        $files[$col] = $relDir . '/' . $name;
    }
    $db->execute(
        "INSERT INTO ld_artworks (session_id, uploaded_by, file_path, web_path, thumbnail_path, visibility, processed_at)
         VALUES (?, ?, ?, ?, ?, 'public', NOW())",
        [$sessionId, $uploaderId, $files['file_path'], $files['web_path'], $files['thumbnail_path']]
    );
    $files['id'] = (int) $db->lastInsertId();
    return $files;
}

$artA = makeArtwork($db, $publicDir, $relDir, $pastSessionId, $uploaderId, 'zztest-a');
$artB = makeArtwork($db, $publicDir, $relDir, $pastSessionId, $uploaderId, 'zztest-b');

// artB is deleted BEFORE withdrawal — it must stay deleted, not become archived.
$db->execute("UPDATE ld_artworks SET visibility = 'removed' WHERE id = ?", [$artB['id']]);

$db->execute(
    "INSERT INTO ld_claims (artwork_id, claimant_id, claim_type, status, approved_by, resolved_at)
     VALUES (?, ?, 'artist', 'approved', ?, NOW())",
    [$artA['id'], $claimantId, $facilitatorId]
);
$claimId = (int) $db->lastInsertId();

echo "  fixtures: uploader #{$uploaderId}, claimant #{$claimantId}, artA #{$artA['id']}, artB #{$artB['id']}\n";

// --- 1. Statistics --------------------------------------------------------

echo "\n1. Statistics count only what has happened, and only what still exists\n";

$stats = new \App\Services\StatsService($db);
$stats->refreshUser($claimantId);
$row = $db->fetch("SELECT * FROM ld_artist_stats WHERE user_id = ?", [$claimantId]);

check(
    'future booking excluded from total_sessions',
    (int) ($row['total_sessions'] ?? -1) === 1,
    'got ' . ($row['total_sessions'] ?? 'null') . ', expected 1 (the past session only)'
);
check('approved claim counted', (int) ($row['total_artworks'] ?? -1) === 1,
    'got ' . ($row['total_artworks'] ?? 'null'));

$db->execute("UPDATE ld_artworks SET visibility = 'removed' WHERE id = ?", [$artA['id']]);
$stats->refreshUser($claimantId);
$row = $db->fetch("SELECT * FROM ld_artist_stats WHERE user_id = ?", [$claimantId]);
check(
    'removed artwork drops out of total_artworks',
    (int) ($row['total_artworks'] ?? -1) === 0,
    'got ' . ($row['total_artworks'] ?? 'null') . ' — the query needs the join to ld_artworks'
);
$db->execute("UPDATE ld_artworks SET visibility = 'public' WHERE id = ?", [$artA['id']]);

// --- 2. Notification cancellation ----------------------------------------

echo "\n2. Queued mail can be traced to its source and cancelled\n";

$db->execute(
    "INSERT INTO ld_notification_queue
     (recipient_id, recipient_name, recipient_email, notification_type, session_id, source_type, source_id, subject, summary)
     VALUES (?, 'x', 'x@local.test', 'artworkCommented', ?, 'artwork', ?, 's', 's')",
    [$claimantId, $pastSessionId, $artA['id']]
);
$db->execute(
    "INSERT INTO ld_notification_queue
     (recipient_id, recipient_name, recipient_email, notification_type, session_id, source_type, source_id, subject, summary)
     VALUES (?, 'x', 'x@local.test', 'claimSubmitted', ?, 'claim', ?, 's', 's')",
    [$facilitatorId, $pastSessionId, $claimId]
);
$db->execute(
    "INSERT INTO ld_notification_queue
     (recipient_id, recipient_name, recipient_email, notification_type, session_id, subject, summary)
     VALUES (?, 'x', 'x@local.test', 'artworkCommented', ?, 's', 's')",
    [$claimantId, $pastSessionId]
);

$notifications = new \App\Services\NotificationService(
    new \App\Services\MailService('localhost', 25, '', '', 'x@local.test', 'x'),
    $db
);
$cancelled = $notifications->cancelQueuedForArtwork($artA['id']);
check('artwork-origin AND claim-origin rows both cancelled', $cancelled === 2,
    "cancelled {$cancelled}, expected 2");

$legacy = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM ld_notification_queue WHERE recipient_id = ? AND source_type IS NULL",
    [$claimantId]
);
check('legacy NULL-source row survives, as documented', $legacy === 1,
    'this is precisely why the cutover purge exists');

$db->execute("DELETE FROM ld_notification_queue WHERE recipient_email = 'x@local.test'");

// --- 3. Withdrawal removes public access ---------------------------------

echo "\n3. Withdrawal moves files out of the public tree\n";

$auth = new AuthService($db);

foreach (['file_path', 'web_path', 'thumbnail_path'] as $col) {
    if (!is_file($uploadDir . '/' . $artA[$col])) {
        fwrite(STDERR, "fixture file missing before withdrawal: {$artA[$col]}\n");
        exit(1);
    }
}

$result = $auth->withdrawConsent($uploaderId);

check('withdrawal reports complete', $result['complete'] === true,
    'remaining: ' . implode(', ', $result['remaining']));
check('three files moved', $result['moved'] === 3, "moved {$result['moved']}");

$stillPublic = [];
foreach (['file_path', 'web_path', 'thumbnail_path'] as $col) {
    if (is_file($uploadDir . '/' . $artA[$col])) {
        $stillPublic[] = $artA[$col];
    }
}
check('NO file remains under public/assets/uploads', $stillPublic === [],
    'still served by Apache: ' . implode(', ', $stillPublic));

check('original archived under storage/withdrawn',
    is_file(LDR_ROOT . '/storage/withdrawn/' . $uploaderId . '/' . $artA['file_path']));

$visA = $db->fetchColumn("SELECT visibility FROM ld_artworks WHERE id = ?", [$artA['id']]);
check("artA is now 'private'", $visA === 'private', "got {$visA}");

$visB = $db->fetchColumn("SELECT visibility FROM ld_artworks WHERE id = ?", [$artB['id']]);
check("already-removed artB stays 'removed'", $visB === 'removed',
    "got {$visB} — the UPDATE must exclude removed rows or deleted work is resurrected");

$state = $db->fetchColumn("SELECT consent_state FROM users WHERE id = ?", [$uploaderId]);
check('consent_state is withdrawn', $state === 'withdrawn', "got {$state}");

// --- 4. Withdrawn work leaves the read paths -----------------------------

echo "\n4. Withdrawn work and names leave the read paths\n";

$inSessionView = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM ld_artworks
     WHERE session_id = ? AND visibility IN ('session','claimed','public')",
    [$pastSessionId]
);
check('session page shows neither the private nor the removed artwork', $inSessionView === 0,
    "got {$inSessionView}");

$botRows = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM ld_artworks a
     JOIN ld_claims ca ON ca.artwork_id = a.id AND ca.claim_type = 'artist' AND ca.status = 'approved'
     JOIN users ua ON ca.claimant_id = ua.id
     WHERE a.session_id = ?
       AND a.visibility NOT IN ('removed','private')
       AND ua.consent_state != 'withdrawn'",
    [$pastSessionId]
);
check('ldrbot-query finds nothing to export', $botRows === 0, "got {$botRows}");

$notifiable = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM users WHERE id = ? AND consent_state != 'withdrawn'",
    [$uploaderId]
);
check('withdrawn user is no longer a notification recipient', $notifiable === 0);

// --- 5. Re-grant does not republish --------------------------------------

echo "\n5. Re-granting consent restores participation, not images\n";

$auth->grantConsent($uploaderId);
$visAfterRegrant = $db->fetchColumn("SELECT visibility FROM ld_artworks WHERE id = ?", [$artA['id']]);
check('hidden artwork stays hidden after re-grant', $visAfterRegrant === 'private', "got {$visAfterRegrant}");
check('file is still out of the public tree', !is_file($uploadDir . '/' . $artA['file_path']));

// --- 6. The image worker will not resurrect hidden files -----------------

echo "\n6. The image worker will not rebuild hidden or deleted artwork\n";

$db->execute("UPDATE ld_artworks SET processed_at = NULL WHERE id IN (?, ?)", [$artA['id'], $artB['id']]);
$wouldProcess = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM ld_artworks
     WHERE processed_at IS NULL AND file_path IS NOT NULL
       AND visibility NOT IN ('removed','private')
       AND id IN (?, ?)",
    [$artA['id'], $artB['id']]
);
check('neither private nor removed artwork is selected for processing', $wouldProcess === 0,
    "got {$wouldProcess} — a run would write derivatives straight back into the public tree");

// --- 7. A failed move must never report success --------------------------

echo "
7. A file that cannot be moved is reported, not silently left public
";

$artC = makeArtwork($db, $publicDir, $relDir, $pastSessionId, $uploaderId, 'zztest-c');

// Block the destination so both rename() and copy() fail: a directory sits
// exactly where the archived file needs to go.
$blockedDest = LDR_ROOT . '/storage/withdrawn/' . $uploaderId . '/' . $artC['file_path'];
@mkdir(dirname($blockedDest), 0755, true);
@mkdir($blockedDest, 0755, true);

$result2 = $auth->withdrawConsent($uploaderId);

check('withdrawal reports INCOMPLETE', $result2['complete'] === false,
    'it claimed success while a file was still being served');
check('the stuck file is named in the result', in_array($artC['file_path'], $result2['remaining'], true),
    'remaining: ' . implode(', ', $result2['remaining']));
check('the file was NOT deleted as a fallback', is_file($uploadDir . '/' . $artC['file_path']),
    'there is no verified image backup, and the consent page promises hiding without deletion');

$incompleteLogged = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM provenance_log
     WHERE user_id = ? AND action = 'user.consent.withdraw.incomplete'",
    [$uploaderId]
);
check('incomplete withdrawal is recorded in provenance', $incompleteLogged === 1);

@rmdir($blockedDest);

// --- 8. Consent is read from the database, not the browser session -------

echo "
8. A second logged-in browser cannot keep a stale 'granted'
";

$_SESSION['user_id'] = $uploaderId;
$_SESSION['consent_state'] = 'granted';   // what the other browser still believes

$gateState = \App\Middleware\ConsentGate::currentState();
check('gate reads withdrawn from the database, ignoring the session', $gateState->value === 'withdrawn',
    "got {$gateState->value} — a stale session would keep letting them participate");
check('session is corrected to match', ($_SESSION['consent_state'] ?? '') === 'withdrawn');

$_SESSION = [];

// --- Teardown -------------------------------------------------------------

echo "\nTeardown\n";

$db->execute("DELETE FROM provenance_log WHERE user_id IN (?, ?)", [$uploaderId, $claimantId]);
// Fixture users only, plus anything this test addressed to itself. The
// facilitator is a REAL account here - deleting their queued mail would throw
// away notifications that have nothing to do with the test.
$db->execute("DELETE FROM ld_notification_queue WHERE recipient_id IN (?, ?)", [$uploaderId, $claimantId]);
$db->execute("DELETE FROM ld_notification_queue WHERE recipient_email = 'x@local.test'");
$db->execute("DELETE FROM ld_claims WHERE artwork_id IN (?, ?, ?)", [$artA['id'], $artB['id'], $artC['id']]);
$db->execute("DELETE FROM ld_artworks WHERE id IN (?, ?, ?)", [$artA['id'], $artB['id'], $artC['id']]);
$db->execute("DELETE FROM ld_session_participants WHERE session_id IN (?, ?)", [$pastSessionId, $futureSessionId]);
$db->execute("DELETE FROM ld_artist_stats WHERE user_id IN (?, ?)", [$uploaderId, $claimantId]);
$db->execute("DELETE FROM ld_sessions WHERE id IN (?, ?)", [$pastSessionId, $futureSessionId]);
$db->execute("DELETE FROM users WHERE id IN (?, ?)", [$uploaderId, $claimantId]);

foreach (glob($publicDir . '/*zztest-*') ?: [] as $f) {
    @unlink($f);
}
$wd = LDR_ROOT . '/storage/withdrawn/' . $uploaderId;
foreach (glob($wd . '/' . $relDir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($wd . '/' . $relDir);
@rmdir($wd . '/sessions');
@rmdir($wd);
@rmdir($publicDir);

echo "  cleaned up\n";

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
