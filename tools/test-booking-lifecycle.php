<?php

/**
 * CLI: Release 2 verification — bookings, claims, attendance, sitter queue.
 *
 * Usage: php tools/test-booking-lifecycle.php
 *
 * Runs against the LOCAL dev database, writing and cleaning up real rows.
 * Never point it at production.
 *
 * Requests go through the real Kernel, so routing, CSRF and the controllers are
 * all exercised rather than simulated. Kernel::boot() skips session_start()
 * under CLI, so $_SESSION here is a plain array we control.
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

use App\Database\Connection;
use App\Kernel;
use App\Request;
use App\Services\Auth\AuthService;

$_SESSION = ['_csrf_token' => 'test-token'];
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$cfg = config('database');
$db = new Connection(
    host: $cfg['host'],
    database: $cfg['database'],
    username: $cfg['username'],
    password: $cfg['password'],
    port: $cfg['port'] ?? 3306,
    charset: $cfg['charset'] ?? 'utf8mb4',
);

$kernel = new Kernel();

// Anything this run writes for the real facilitator account is bounded by this.
// Deleting all of their provenance at teardown would destroy real history.
$runStartedAt = date('Y-m-d H:i:s');

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

/** Drive a real request through the kernel. */
function req(string $method, string $path, array $post = []): array
{
    global $kernel;
    $_SERVER['REQUEST_URI'] = $path;
    if ($method !== 'GET') {
        $post['_csrf_token'] = 'test-token';
    }
    $request = new Request(
        $method, $path, $path, [], $post,
        ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '127.0.0.1'],
        [], []
    );
    $response = $kernel->handle($request);
    return ['status' => $response->getStatus()];
}

function actAs(int $userId, string $role = 'participant', string $consent = 'granted'): void
{
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_role'] = $role;
    $_SESSION['consent_state'] = $consent;
    // ConsentGate caches authoritative state per request; clear between actors.
    $ref = new ReflectionClass(\App\Middleware\ConsentGate::class);
    $prop = $ref->getProperty('cache');
    $prop->setAccessible(true);
    $prop->setValue(null, []);
}

const MARKER = 'ZZTEST-booking';

// --- Preflight ------------------------------------------------------------

echo "\nPreflight\n";

$hasAttendance = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ld_session_participants'
       AND COLUMN_NAME = 'attendance'"
);
if ($hasAttendance !== 1) {
    fwrite(STDERR, "Migration 021 not applied. Run: php tools/migrate.php run\n");
    exit(1);
}
check('migration 021 applied', true);

// The check that proves the backfill erased nothing.
$mismatch = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM ld_session_participants WHERE attended = 1 AND attendance != 'attended'"
);
check('every attended=1 row reads attendance=attended', $mismatch === 0, "{$mismatch} rows disagree");

// --- Fixtures -------------------------------------------------------------

$facilitatorId = (int) $db->fetchColumn(
    "SELECT id FROM users WHERE role IN ('admin','facilitator') ORDER BY id ASC LIMIT 1"
);

// Clear anything a previous crashed run left behind, so this is rerunnable.
$stale = $db->fetchAll("SELECT id FROM users WHERE email LIKE 'zzb.%@local.test'");
$staleIds = array_column($stale, 'id');
if ($staleIds) {
    $in = implode(',', array_fill(0, count($staleIds), '?'));
    $db->execute("DELETE FROM ld_notification_queue WHERE recipient_id IN ($in)", $staleIds);
    $db->execute("DELETE FROM provenance_log WHERE user_id IN ($in)", $staleIds);
    $db->execute("DELETE FROM ld_sitter_queue WHERE user_id IN ($in)", $staleIds);
    $db->execute("DELETE FROM ld_artist_stats WHERE user_id IN ($in)", $staleIds);
    $db->execute("DELETE FROM ld_session_participants WHERE user_id IN ($in)", $staleIds);
    $db->execute("DELETE FROM users WHERE id IN ($in)", $staleIds);
}
$db->execute("DELETE FROM ld_claims WHERE artwork_id IN (SELECT id FROM ld_artworks WHERE file_path LIKE 'sessions/zzb/%')");
$db->execute("DELETE FROM ld_artworks WHERE file_path LIKE 'sessions/zzb/%'");
$db->execute("DELETE FROM ld_sessions WHERE title LIKE 'ZZTEST-booking%'");

$mk = function (string $name, string $email) use ($db) {
    $db->execute(
        "INSERT INTO users (display_name, email, password_hash, role, consent_state, consent_granted_at)
         VALUES (?, ?, ?, 'participant', 'granted', NOW())",
        [MARKER . ' ' . $name, $email, AuthService::STUB_HASH]
    );
    return (int) $db->lastInsertId();
};

$artistId = $mk('artist', 'zzb.artist@local.test');
$otherId  = $mk('other', 'zzb.other@local.test');
$sitterId = $mk('sitter', 'zzb.sitter@local.test');

$mkSession = function (string $label, string $date) use ($db, $facilitatorId) {
    $db->execute(
        "INSERT INTO ld_sessions (title, session_date, start_time, venue, facilitator_id)
         VALUES (?, ?, '10:00:00', 'Randburg', ?)",
        [MARKER . ' ' . $label, $date, $facilitatorId]
    );
    return (int) $db->lastInsertId();
};

$future  = $mkSession('future', date('Y-m-d', strtotime('+10 days')));
$future2 = $mkSession('future2', date('Y-m-d', strtotime('+11 days')));
$past    = $mkSession('past', date('Y-m-d', strtotime('-10 days')));

$cleanupSessions = [$future, $future2, $past];
$cleanupUsers = [$artistId, $otherId, $sitterId];

echo "  fixtures: artist #{$artistId}, sitter #{$sitterId}, sessions {$future}/{$future2}/{$past}\n";

// --- 1. The 48-hour helper ------------------------------------------------

echo "\n1. Late-cancellation window\n";

check('72 hours out is not late',
    !is_late_cancel(['session_date' => date('Y-m-d', strtotime('+72 hours')), 'start_time' => '10:00:00']));
check('24 hours out is late',
    is_late_cancel(['session_date' => date('Y-m-d', strtotime('+24 hours')), 'start_time' => '10:00:00']));
check('null start_time still resolves (falls back to 10:00)',
    is_late_cancel(['session_date' => date('Y-m-d', strtotime('+24 hours')), 'start_time' => null]));

// --- 2. Join, then cancel -------------------------------------------------

echo "\n2. Booking and cancelling\n";

actAs($artistId);
$hexFuture = hex_id($future, MARKER . ' future');

req('POST', "/sessions/{$hexFuture}/join", ['role' => 'artist']);
$booked = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM ld_session_participants WHERE session_id = ? AND user_id = ?",
    [$future, $artistId]
);
check('join creates the booking', $booked === 1);

$queuedJoin = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM ld_notification_queue WHERE notification_type = 'sessionJoined' AND session_id = ?",
    [$future]
);
check('facilitator is told about the booking', $queuedJoin >= 1, "got {$queuedJoin}");

req('POST', "/sessions/{$hexFuture}/leave", ['role' => 'artist']);
$stillBooked = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM ld_session_participants WHERE session_id = ? AND user_id = ?",
    [$future, $artistId]
);
check('cancel removes the booking', $stillBooked === 0);

$queuedLeft = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM ld_notification_queue WHERE notification_type = 'sessionLeft' AND session_id = ?",
    [$future]
);
check('facilitator is told about the cancellation', $queuedLeft >= 1, "got {$queuedLeft}");

$prov = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM provenance_log WHERE user_id = ? AND action = 'session.leave'",
    [$artistId]
);
check('cancellation is recorded in provenance', $prov === 1);

$again = req('POST', "/sessions/{$hexFuture}/leave", ['role' => 'artist']);
check('cancelling when not booked is a no-op, not an error', $again['status'] < 400,
    "status {$again['status']}");

// Past sessions cannot be un-booked: nothing records attendance separately, so
// deleting the row would rewrite history rather than cancel anything.
$db->execute("INSERT INTO ld_session_participants (session_id, user_id, role) VALUES (?, ?, 'artist')",
    [$past, $artistId]);
$hexPast = hex_id($past, MARKER . ' past');
req('POST', "/sessions/{$hexPast}/leave", ['role' => 'artist']);
$pastRow = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM ld_session_participants WHERE session_id = ? AND user_id = ?",
    [$past, $artistId]
);
check('a past booking cannot be cancelled', $pastRow === 1);

// --- 3. No-show -----------------------------------------------------------

echo "\n3. Marking and un-marking a no-show\n";

actAs($facilitatorId, 'facilitator');

$pid = (int) $db->fetchColumn(
    "SELECT id FROM ld_session_participants WHERE session_id = ? AND user_id = ?",
    [$past, $artistId]
);

// Pretend this row came from the historical import, so un-marking has something
// to restore. This is the case that silently degrades if writers disagree.
$db->execute("UPDATE ld_session_participants SET attended = 1, attendance = 'attended' WHERE id = ?", [$pid]);

req('POST', "/sessions/{$hexPast}/participants/no-show", ['pid' => $pid]);
$state = $db->fetchColumn("SELECT attendance FROM ld_session_participants WHERE id = ?", [$pid]);
check('marking sets no_show', $state === 'no_show', "got {$state}");

$stats = $db->fetch("SELECT * FROM ld_artist_stats WHERE user_id = ?", [$artistId]);
check('a no-show does not count as attendance', (int) ($stats['total_sessions'] ?? -1) === 0,
    'got ' . ($stats['total_sessions'] ?? 'null'));

req('POST', "/sessions/{$hexPast}/participants/no-show", ['pid' => $pid]);
$state = $db->fetchColumn("SELECT attendance FROM ld_session_participants WHERE id = ?", [$pid]);
check('un-marking restores attended, not booked', $state === 'attended',
    "got {$state} — the legacy attended column is what makes this recoverable");

$futureNoShow = req('POST', "/sessions/{$hexFuture}/participants/no-show", ['pid' => $pid]);
check('a no-show cannot be recorded before the session', $futureNoShow['status'] === 403,
    "status {$futureNoShow['status']} — the controller must guard, not just the view");

// --- 4. Claims ------------------------------------------------------------

echo "\n4. Claiming and undoing a claim\n";

$db->execute(
    "INSERT INTO ld_artworks (session_id, uploaded_by, file_path, visibility)
     VALUES (?, ?, 'sessions/zzb/zzb.jpg', 'public')",
    [$past, $facilitatorId]
);
$artworkId = (int) $db->lastInsertId();
$hexArt = hex_id($artworkId);

actAs($artistId);
req('POST', "/artworks/{$hexArt}/claim", ['claim_type' => 'artist']);
$claim = $db->fetch("SELECT * FROM ld_claims WHERE artwork_id = ? AND claimant_id = ?", [$artworkId, $artistId]);
check('claim is created as pending', ($claim['status'] ?? '') === 'pending');

$queuedClaim = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM ld_notification_queue WHERE source_type = 'claim' AND source_id = ?",
    [(int) $claim['id']]
);
check('claim notification carries its source', $queuedClaim >= 1);

// Pending withdrawal DELETES — it never established attribution.
req('POST', '/claims/' . hex_id((int) $claim['id']) . '/withdraw');
$gone = (int) $db->fetchColumn("SELECT COUNT(*) FROM ld_claims WHERE id = ?", [(int) $claim['id']]);
check('withdrawing a pending claim removes the row entirely', $gone === 0);

$queuedAfter = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM ld_notification_queue WHERE source_type = 'claim' AND source_id = ?",
    [(int) $claim['id']]
);
check('its queued notification is cancelled with it', $queuedAfter === 0);

// And re-claiming works, which the old status-agnostic guard made impossible.
req('POST', "/artworks/{$hexArt}/claim", ['claim_type' => 'artist']);
$claim2 = $db->fetch("SELECT * FROM ld_claims WHERE artwork_id = ? AND claimant_id = ?", [$artworkId, $artistId]);
check('the artwork can be claimed again afterwards', ($claim2['status'] ?? '') === 'pending');

// Approved withdrawal KEEPS the row, because the approval is history.
$db->execute("UPDATE ld_claims SET status = 'approved', approved_by = ?, resolved_at = NOW() WHERE id = ?",
    [$facilitatorId, (int) $claim2['id']]);
app('stats')->refreshUser($artistId);
$before = (int) $db->fetchColumn("SELECT total_artworks FROM ld_artist_stats WHERE user_id = ?", [$artistId]);

req('POST', '/claims/' . hex_id((int) $claim2['id']) . '/withdraw');
$after = $db->fetch("SELECT * FROM ld_claims WHERE id = ?", [(int) $claim2['id']]);
check('withdrawing an approved claim keeps the row as withdrawn', ($after['status'] ?? '') === 'withdrawn',
    'got ' . ($after['status'] ?? 'gone'));

$total = (int) $db->fetchColumn("SELECT total_artworks FROM ld_artist_stats WHERE user_id = ?", [$artistId]);
check('cached stats drop after withdrawal', $before === 1 && $total === 0, "before {$before}, after {$total}");

// A stale pending page must not be able to reinstate it.
actAs($facilitatorId, 'facilitator');
$resolveStale = req('POST', '/claims/' . hex_id((int) $claim2['id']) . '/resolve', ['action' => 'approve']);
$stillWithdrawn = $db->fetchColumn("SELECT status FROM ld_claims WHERE id = ?", [(int) $claim2['id']]);
check('approving a withdrawn claim is refused', $stillWithdrawn === 'withdrawn',
    "status became {$stillWithdrawn}");

actAs($otherId);
$forbidden = req('POST', '/claims/' . hex_id((int) $claim2['id']) . '/withdraw');
check("you cannot withdraw someone else's claim", $forbidden['status'] === 403,
    "status {$forbidden['status']}");

// --- 5. Sitter queue linkage ---------------------------------------------

echo "\n5. Booking a sitter resolves their queue entry\n";

$db->execute(
    "INSERT INTO ld_sitter_queue (user_id, status, requested_at) VALUES (?, 'waiting', ?)",
    [$sitterId, date('Y-m-d H:i:s', strtotime('-30 days'))]
);
$entryId = (int) $db->lastInsertId();
$requestedAt = $db->fetchColumn("SELECT requested_at FROM ld_sitter_queue WHERE id = ?", [$entryId]);

actAs($facilitatorId, 'facilitator');
req('POST', "/sessions/{$hexFuture}/participants/add", ['user_id' => $sitterId, 'role' => 'model']);

$entry = $db->fetch("SELECT * FROM ld_sitter_queue WHERE id = ?", [$entryId]);
check('adding a model schedules their queue entry', $entry['status'] === 'scheduled',
    "got {$entry['status']} — this is the bug the release exists to fix");
check('the entry points at the right session', (int) $entry['scheduled_session_id'] === $future);

// Booked for both days of a weekend: removing one must not requeue them.
$hexFuture2 = hex_id($future2, MARKER . ' future2');
req('POST', "/sessions/{$hexFuture2}/participants/add", ['user_id' => $sitterId, 'role' => 'model']);
$pidF = (int) $db->fetchColumn(
    "SELECT id FROM ld_session_participants WHERE session_id = ? AND user_id = ? AND role = 'model'",
    [$future, $sitterId]
);
req('POST', "/sessions/{$hexFuture}/participants/remove", ['pid' => $pidF]);

$entry = $db->fetch("SELECT * FROM ld_sitter_queue WHERE id = ?", [$entryId]);
check('a sitter still booked elsewhere stays scheduled', $entry['status'] === 'scheduled',
    "got {$entry['status']}");
check('and is repointed at the remaining booking', (int) $entry['scheduled_session_id'] === $future2,
    'got ' . $entry['scheduled_session_id']);

// Removing the last booking returns them to waiting, keeping their place.
$pidF2 = (int) $db->fetchColumn(
    "SELECT id FROM ld_session_participants WHERE session_id = ? AND user_id = ? AND role = 'model'",
    [$future2, $sitterId]
);
req('POST', "/sessions/{$hexFuture2}/participants/remove", ['pid' => $pidF2]);
$entry = $db->fetch("SELECT * FROM ld_sitter_queue WHERE id = ?", [$entryId]);
check('removing the last booking returns them to waiting', $entry['status'] === 'waiting',
    "got {$entry['status']}");
check('and they keep their place in the queue', $entry['requested_at'] === $requestedAt,
    'requested_at changed — they would have gone to the back of the line');

// --- 6. The backlog repair -----------------------------------------------

echo "\n6. Backlog repair\n";

$queue = new \App\Services\SitterQueueService($db);

// The exact backlog shape: booked as a model on a past session, never dequeued,
// and carrying attendance 'booked' because nothing writes 'attended' on the web.
$db->execute("INSERT INTO ld_session_participants (session_id, user_id, role) VALUES (?, ?, 'model')",
    [$past, $sitterId]);

$entry = $db->fetch("SELECT * FROM ld_sitter_queue WHERE id = ?", [$entryId]);
$decision = $queue->classify($entry);
check('a waiting entry with a past sitting is completed', $decision['action'] === 'complete',
    "got {$decision['action']}: {$decision['reason']}");

check('an ordinary booked attendance still counts',
    $db->fetchColumn("SELECT attendance FROM ld_session_participants WHERE session_id = ? AND user_id = ? AND role='model'",
        [$past, $sitterId]) === 'booked');

$queue->apply($entryId, null, false);
$entry = $db->fetch("SELECT * FROM ld_sitter_queue WHERE id = ?", [$entryId]);
check('applying it actually reaches completed', $entry['status'] === 'completed',
    "got {$entry['status']} — a hardcoded WHERE status='scheduled' would skip this silently");

// Evidence must match THIS request: a queue entry opened after the sitting
// must not be closed by it.
$db->execute("INSERT INTO ld_sitter_queue (user_id, status, requested_at) VALUES (?, 'waiting', NOW())",
    [$sitterId]);
$freshId = (int) $db->lastInsertId();
$fresh = $db->fetch("SELECT * FROM ld_sitter_queue WHERE id = ?", [$freshId]);
$freshDecision = $queue->classify($fresh);
check('a newer request is not closed by an older sitting', $freshDecision['action'] === 'skip',
    "got {$freshDecision['action']}: {$freshDecision['reason']}");

// A no-show sitter is left alone — whether they rejoin is the facilitator's call.
$db->execute("UPDATE ld_session_participants SET attendance = 'no_show'
              WHERE session_id = ? AND user_id = ? AND role = 'model'", [$past, $sitterId]);
$db->execute("UPDATE ld_sitter_queue SET status = 'scheduled', scheduled_session_id = ? WHERE id = ?",
    [$past, $freshId]);
$fresh = $db->fetch("SELECT * FROM ld_sitter_queue WHERE id = ?", [$freshId]);
$noShowDecision = $queue->classify($fresh);
check('a no-show sitter is not auto-completed', $noShowDecision['action'] === 'skip',
    "got {$noShowDecision['action']}: {$noShowDecision['reason']}");

// A scheduled entry whose session vanished goes back to waiting, never completed.
$db->execute("UPDATE ld_sitter_queue SET scheduled_session_id = NULL WHERE id = ?", [$freshId]);
$fresh = $db->fetch("SELECT * FROM ld_sitter_queue WHERE id = ?", [$freshId]);
$orphan = $queue->classify($fresh);
check('an entry with no session returns to waiting, never completes', $orphan['action'] === 'requeue',
    "got {$orphan['action']}: {$orphan['reason']}");

// Idempotence: once applied, a second pass must propose nothing.
$queue->apply($freshId, null, false);
$fresh = $db->fetch("SELECT * FROM ld_sitter_queue WHERE id = ?", [$freshId]);
$second = $queue->classify($fresh);
check('a second pass proposes nothing', $second['action'] === 'skip',
    "got {$second['action']}: {$second['reason']}");

// --- Teardown -------------------------------------------------------------

echo "\nTeardown\n";

$db->execute("DELETE FROM ld_notification_queue WHERE session_id IN (?, ?, ?) OR recipient_id IN (?, ?, ?)",
    [$future, $future2, $past, $artistId, $otherId, $sitterId]);
$db->execute("DELETE FROM provenance_log WHERE user_id IN (?, ?, ?)",
    [$artistId, $otherId, $sitterId]);
// The facilitator is a real account: only remove what THIS run wrote.
$db->execute("DELETE FROM provenance_log WHERE user_id = ? AND created_at >= ?",
    [$facilitatorId, $runStartedAt]);
$db->execute("DELETE FROM ld_claims WHERE artwork_id = ?", [$artworkId]);
$db->execute("DELETE FROM ld_artworks WHERE id = ?", [$artworkId]);
$db->execute("DELETE FROM ld_sitter_queue WHERE user_id = ?", [$sitterId]);
$db->execute("DELETE FROM ld_session_participants WHERE session_id IN (?, ?, ?)", [$future, $future2, $past]);
$db->execute("DELETE FROM ld_artist_stats WHERE user_id IN (?, ?, ?)", [$artistId, $otherId, $sitterId]);
$db->execute("DELETE FROM ld_sessions WHERE id IN (?, ?, ?)", [$future, $future2, $past]);
$db->execute("DELETE FROM users WHERE id IN (?, ?, ?)", [$artistId, $otherId, $sitterId]);

echo "  cleaned up\n";

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
