<?php

/**
 * CLI: waiting-list verification — joining when full, promotion, the
 * booking/waiting invariant, late-cancellation exemption, emails, races.
 *
 * Usage:
 *   php tools/test-facilitator-management.php --keep
 *   LDR_TEST_DATABASE=<name from storage/cache/management-test.json> php tools/test-waitlist.php
 *
 * Only runs against an isolated test database (LDR_TEST_DATABASE); never
 * against a dev or live one. Writes fictional rows and removes them.
 *
 * Requests go through the real Kernel (routing, CSRF, controllers, views).
 */

declare(strict_types=1);

define('LDR_ROOT', dirname(__DIR__));
require LDR_ROOT . '/vendor/autoload.php';
date_default_timezone_set('Africa/Johannesburg');

use App\Kernel;
use App\Request;
use App\Services\Auth\AuthService;
use App\Services\ParticipationService;
use App\Services\SessionManagementService;
use App\Services\WaitlistService;

// --- Race worker: one concurrent joinOrBook, started by the parent ---------
$worker = getopt('', ['join-worker', 'session:', 'user:']);
if (isset($worker['join-worker'])) {
    require __DIR__ . '/local-test-environment.php';
    new Kernel();
    app('db')->execute("SET innodb_lock_wait_timeout = 10");
    echo "READY\n"; flush();
    fgets(STDIN); // wait for GO, so both workers start together
    try {
        echo (new WaitlistService(app('db')))->joinOrBook((int) $worker['session'], (int) $worker['user']) . "\n";
    } catch (Throwable $e) { echo 'ERROR:' . $e->getMessage() . "\n"; }
    exit;
}

if ((string) getenv('LDR_TEST_DATABASE') === '') {
    fwrite(STDERR, "Set LDR_TEST_DATABASE to an isolated test database (see the usage note).\n");
    exit(1);
}
require __DIR__ . '/local-test-environment.php';

$_SESSION = ['_csrf_token' => 'test-token'];
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$kernel = new Kernel();
$db = app('db');

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "  PASS  {$name}\n"; }
    else { $failed++; echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function req(string $method, string $path, array $post = [], bool $htmx = false): array
{
    global $kernel;
    $_SERVER['REQUEST_URI'] = $path;
    if ($method !== 'GET') $post['_csrf_token'] = 'test-token';
    $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '127.0.0.1'];
    if ($htmx) $server['HTTP_HX_REQUEST'] = 'true';
    $response = $kernel->handle(new Request($method, $path, $path, [], $post, $server, [], []));
    return ['status' => $response->getStatus(), 'body' => $response->getBody()];
}

function actAs(int $userId, string $role = 'participant'): void
{
    $_SESSION = ['_csrf_token' => 'test-token', 'user_id' => $userId, 'user_role' => $role, 'consent_state' => 'granted'];
    $ref = new ReflectionClass(\App\Middleware\ConsentGate::class);
    $prop = $ref->getProperty('cache');
    $prop->setAccessible(true);
    $prop->setValue(null, []);
}

const MARKER = 'ZZTEST-wait';
const LATE_NOTE = 'A 50% contribution is appreciated';

// --- Fixtures -------------------------------------------------------------

$facilitatorId = (int) $db->fetchColumn("SELECT id FROM users WHERE role IN ('admin','facilitator') ORDER BY id ASC LIMIT 1");
if (!$facilitatorId) { fwrite(STDERR, "No facilitator in the test database.\n"); exit(1); }

$cleanup = function () use ($db) {
    $users = array_map('intval', array_column($db->fetchAll("SELECT id FROM users WHERE email LIKE 'zzw.%@local.test'"), 'id'));
    $sessions = array_map('intval', array_column($db->fetchAll("SELECT id FROM ld_sessions WHERE title LIKE 'ZZTEST-wait%'"), 'id'));
    foreach (['ld_session_waitlist', 'ld_session_participants', 'ld_notification_queue'] as $table) {
        if ($sessions) $db->execute("DELETE FROM {$table} WHERE session_id IN (" . implode(',', $sessions) . ")");
    }
    if ($sessions) $db->execute("DELETE FROM provenance_log WHERE entity_type = 'session' AND entity_id IN (" . implode(',', $sessions) . ")");
    if ($users) {
        $in = implode(',', $users);
        $db->execute("DELETE FROM ld_notification_queue WHERE recipient_id IN ({$in})");
        $db->execute("DELETE FROM provenance_log WHERE user_id IN ({$in})");
        $db->execute("DELETE FROM ld_artist_stats WHERE user_id IN ({$in})");
        $db->execute("DELETE FROM ld_session_participants WHERE user_id IN ({$in})");
    }
    if ($sessions) $db->execute("DELETE FROM ld_sessions WHERE id IN (" . implode(',', $sessions) . ")");
    if ($users) $db->execute("DELETE FROM users WHERE id IN (" . implode(',', $users) . ")");
};
$cleanup();

$n = 0;
$mkUser = function (string $name) use ($db, &$n) {
    $n++;
    $db->execute(
        "INSERT INTO users (display_name, email, password_hash, role, consent_state, consent_granted_at)
         VALUES (?, ?, ?, 'participant', 'granted', NOW())",
        [$name . ' ' . MARKER, "zzw.{$n}@local.test", AuthService::STUB_HASH]
    );
    return (int) $db->lastInsertId();
};
/** A session starting $hoursAhead from now, with $capacity published artist places. */
$mkSession = function (string $label, float $hoursAhead = 240, int $capacity = 2, int $published = 1) use ($db, $facilitatorId) {
    $start = (int) (time() + $hoursAhead * 3600);
    $db->execute(
        "INSERT INTO ld_sessions (title, session_date, start_time, venue, facilitator_id, max_capacity, capacity_published)
         VALUES (?, ?, ?, 'Randburg', ?, ?, ?)",
        [MARKER . ' ' . $label, date('Y-m-d', $start), date('H:i:00', $start), $facilitatorId, $capacity, $published]
    );
    return (int) $db->lastInsertId();
};
$book = function (int $sessionId, int $userId, string $role = 'artist', ?string $promotedAt = null) use ($db) {
    $db->execute("INSERT INTO ld_session_participants (session_id, user_id, role, promoted_at) VALUES (?, ?, ?, ?)",
        [$sessionId, $userId, $role, $promotedAt]);
    return (int) $db->lastInsertId();
};
$hex = fn(int $id) => hex_id($id);
$waiting = fn(int $sessionId) => array_map('intval', array_column(
    $db->fetchAll("SELECT user_id FROM ld_session_waitlist WHERE session_id = ? ORDER BY id", [$sessionId]), 'user_id'));
$booking = fn(int $sessionId, int $userId) => $db->fetch(
    "SELECT * FROM ld_session_participants WHERE session_id = ? AND user_id = ? AND role = 'artist'", [$sessionId, $userId]);
$queued = fn(int $sessionId, string $type) => (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM ld_notification_queue WHERE session_id = ? AND notification_type = ?", [$sessionId, $type]);

$ann = $mkUser('Ann'); $bob = $mkUser('Bob'); $cat = $mkUser('Cat'); $dee = $mkUser('Dee'); $eve = $mkUser('Eve');
$svc = new WaitlistService($db);

// --- 1. Join: a place, else the waiting list --------------------------------

echo "\n1. Joining a full session\n";
$s1 = $mkSession('join');
actAs($ann); req('POST', "/sessions/{$hex($s1)}/join", ['role' => 'artist']);
actAs($bob); req('POST', "/sessions/{$hex($s1)}/join", ['role' => 'artist']);
check('open places are booked directly', $booking($s1, $ann) && $booking($s1, $bob));
actAs($cat); $r = req('POST', "/sessions/{$hex($s1)}/join", ['role' => 'artist'], true);
actAs($dee); req('POST', "/sessions/{$hex($s1)}/join", ['role' => 'artist']);
check('a full session puts the next joiners on the waiting list, in order', $waiting($s1) === [$cat, $dee], json_encode($waiting($s1)));
check('a waiting person has no booking row at all (so no stats, no name access)', !$booking($s1, $cat)
    && !$db->fetchColumn("SELECT id FROM ld_session_participants WHERE session_id = ? AND user_id = ?", [$s1, $cat]));
check('the join control then says "On the waiting list (#1)"', str_contains($r['body'], 'On the waiting list (#1)'), substr(strip_tags($r['body']), 0, 200));
check('facilitators are told someone joined the waiting list', $queued($s1, 'waitlistJoined') > 0);
check('a waitlist.join audit row is written', (bool) $db->fetchColumn(
    "SELECT id FROM provenance_log WHERE action = 'waitlist.join' AND entity_id = ? AND user_id = ?", [$s1, $cat]));
actAs($eve); $r = req('GET', "/sessions/{$hex($s1)}");
check('a full session offers "Join waiting list"', str_contains($r['body'], 'Join waiting list'));

// --- 2. Repeated joins change nothing ---------------------------------------

echo "\n2. Repeated joins\n";
check('a booked artist joining again is unchanged', $svc->joinOrBook($s1, $ann) === 'unchanged');
check('a waiting artist joining again is unchanged, same position', $svc->joinOrBook($s1, $cat) === 'unchanged' && $svc->position($s1, $cat) === 1);
actAs($cat); req('POST', "/sessions/{$hex($s1)}/join", ['role' => 'artist']);
check('...also through the route, with no duplicate entry', $waiting($s1) === [$cat, $dee]);

// --- 3. Promotion when a place opens -----------------------------------------

echo "\n3. Promotion\n";
$beforeEmails = $queued($s1, 'waitlistPromoted');
actAs($ann); req('POST', "/sessions/{$hex($s1)}/leave", ['role' => 'artist']);
$catBooking = $booking($s1, $cat);
check('when an artist cancels, first in line gets the place', (bool) $catBooking);
check('promoted_at is set on the new booking', $catBooking && $catBooking['promoted_at'] !== null);
check('the promoted person is off the waiting list and #2 is now #1', $waiting($s1) === [$dee] && $svc->position($s1, $dee) === 1);
check('exactly one waitlist.promote audit row', (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM provenance_log WHERE action = 'waitlist.promote' AND entity_id = ? AND context LIKE ?", [$s1, '%"user_id": ' . $cat . '%'])
    + (int) $db->fetchColumn("SELECT COUNT(*) FROM provenance_log WHERE action = 'waitlist.promote' AND entity_id = ? AND context LIKE ?", [$s1, '%"user_id":' . $cat . '%']) === 1);
check('the promoted person and the facilitators are emailed',
    $queued($s1, 'waitlistPromoted') > $beforeEmails
    && (bool) $db->fetchColumn("SELECT id FROM ld_notification_queue WHERE session_id = ? AND notification_type = 'waitlistPromoted' AND recipient_id = ?", [$s1, $cat])
    && (bool) $db->fetchColumn("SELECT id FROM ld_notification_queue WHERE session_id = ? AND notification_type = 'waitlistPromoted' AND recipient_id = ?", [$s1, $facilitatorId]));
check('the session is still at capacity, not over it', WaitlistService::artistCount($db, $s1) === 2);

// Leaving the waiting list.
actAs($dee); req('POST', "/sessions/{$hex($s1)}/waitlist/leave");
check('a waiting person can leave the list', $waiting($s1) === []);

// --- 4. Other triggers -------------------------------------------------------

echo "\n4. Other ways a place opens, and the booking/waiting invariant\n";
$s4 = $mkSession('triggers');
$book($s4, $ann); $book($s4, $bob);
$svc->add($s4, $facilitatorId, $cat); $svc->add($s4, $facilitatorId, $dee);
$mgmt = new SessionManagementService($db);
$annPid = (int) $booking($s4, $ann)['id'];
$mgmt->changeRole($s4, $facilitatorId, $annPid, 'artist', 'observer');
check('changing an artist to observer promotes the first in line', (bool) $booking($s4, $cat) && $waiting($s4) === [$dee]);

// Role change TO artist must clear the waiting entry (direct SQL path).
$book($s4, $eve, 'observer');
$svc->add($s4, $facilitatorId, $eve);
$evePid = (int) $db->fetchColumn("SELECT id FROM ld_session_participants WHERE session_id = ? AND user_id = ? AND role = 'observer'", [$s4, $eve]);
$mgmt->changeRole($s4, $facilitatorId, $evePid, 'observer', 'artist');
check('changing someone TO artist takes them off the waiting list', !in_array($eve, $waiting($s4), true) && (bool) $booking($s4, $eve));

// Facilitator direct-add clears the entry too.
(new ParticipationService($db))->add($s4, $facilitatorId, $dee, 'artist', true);
check('a facilitator booking someone directly takes them off the list', $waiting($s4) === []);

// A stale entry beside a booking (planted raw, first in line) must not break
// someone else's cancellation: it is cleared and the next real person promoted.
$s4s = $mkSession('stale');
$book($s4s, $ann); $book($s4s, $bob);
$db->execute("INSERT INTO ld_session_waitlist (session_id, user_id) VALUES (?, ?)", [$s4s, $bob]);
$db->execute("INSERT INTO ld_session_waitlist (session_id, user_id) VALUES (?, ?)", [$s4s, $cat]);
$threw = null;
try { (new ParticipationService($db))->remove($s4s, $facilitatorId, (int) $booking($s4s, $ann)['id'], true); }
catch (Throwable $e) { $threw = $e->getMessage(); }
check('a stale entry does not roll back someone else\'s cancellation', $threw === null && !$booking($s4s, $ann), (string) $threw);
check('...the stale entry is cleared and the next real person gets the place',
    $waiting($s4s) === [] && (bool) $booking($s4s, $cat)
    && (int) $db->fetchColumn("SELECT COUNT(*) FROM ld_session_participants WHERE session_id = ? AND user_id = ?", [$s4s, $bob]) === 1);

// Capacity raise promotes.
$s4b = $mkSession('capacity', 240, 1);
$book($s4b, $ann); $svc->add($s4b, $facilitatorId, $bob);
$session = $db->fetch("SELECT * FROM ld_sessions WHERE id = ?", [$s4b]);
$mgmt->details($s4b, $facilitatorId, [
    'title' => $session['title'], 'venue' => $session['venue'], 'session_date' => $session['session_date'],
    'start_time' => substr((string) $session['start_time'], 0, 5), 'duration_minutes' => $session['duration_minutes'],
    'max_capacity' => 2, 'capacity_published' => 1, 'expected_updated_at' => $session['updated_at'],
]);
check('raising capacity promotes from the waiting list', (bool) $booking($s4b, $bob) && $waiting($s4b) === []);

// --- 5. Who is skipped, and when nothing happens ------------------------------

echo "\n5. Skips and no-ops\n";
$s5 = $mkSession('skips');
$book($s5, $ann); $book($s5, $bob);
$svc->add($s5, $facilitatorId, $cat); $svc->add($s5, $facilitatorId, $dee);
$db->execute("UPDATE users SET consent_state = 'withdrawn' WHERE id = ?", [$cat]);
(new ParticipationService($db))->remove($s5, $facilitatorId, (int) $booking($s5, $ann)['id'], true);
check('someone who withdrew consent is skipped and removed; the next person gets the place',
    !$booking($s5, $cat) && (bool) $booking($s5, $dee) && $waiting($s5) === []);
$db->execute("UPDATE users SET consent_state = 'granted' WHERE id = ?", [$cat]);

$s5b = $mkSession('started', -1);
$book($s5b, $ann); $book($s5b, $bob);
$db->execute("INSERT INTO ld_session_waitlist (session_id, user_id) VALUES (?, ?)", [$s5b, $cat]);
(new ParticipationService($db))->remove($s5b, $facilitatorId, (int) $booking($s5b, $ann)['id'], true);
check('no promotion once the session has started', !$booking($s5b, $cat) && $waiting($s5b) === [$cat]);

$s5c = $mkSession('unpublished', 240, 1, 0);
$book($s5c, $ann);
check('an unpublished capacity always books, never waitlists', $svc->joinOrBook($s5c, $bob) === 'booked');
$threw = null;
try { $svc->add($s5c, $facilitatorId, $cat); } catch (DomainException $e) { $threw = $e->getCode(); }
check('...and has no waiting list for facilitators either', $threw === 422);
$s5d = $mkSession('notfull', 240, 3);
$threw = null;
try { $svc->add($s5d, $facilitatorId, $cat); } catch (DomainException $e) { $threw = $e->getCode(); }
check('a session with open places refuses a waiting-list add', $threw === 422);

// --- 6. Late-cancellation exemption --------------------------------------------

echo "\n6. Late cancellation\n";
$s6 = $mkSession('late', 24);
$book($s6, $ann); $book($s6, $bob);
$svc->add($s6, $facilitatorId, $cat);
(new ParticipationService($db))->remove($s6, $facilitatorId, (int) $booking($s6, $ann)['id'], true);
$catLate = $booking($s6, $cat);
$s6row = $db->fetch("SELECT * FROM ld_sessions WHERE id = ?", [$s6]);
check('the promotion email tells them cancelling is free', (bool) $db->fetchColumn(
    "SELECT id FROM ld_notification_queue WHERE session_id = ? AND recipient_id = ? AND summary LIKE '%cancelling is free%'", [$s6, $cat]));
actAs($cat); $r = req('GET', "/sessions/{$hex($s6)}");
check('a person promoted inside 48 hours sees no contribution note', $catLate && !str_contains($r['body'], LATE_NOTE));
actAs($bob); $r = req('GET', "/sessions/{$hex($s6)}");
check('an ordinary booking inside 48 hours still sees it', str_contains($r['body'], LATE_NOTE));
actAs($cat); req('POST', "/sessions/{$hex($s6)}/leave", ['role' => 'artist']);
$leave = $db->fetch("SELECT context FROM provenance_log WHERE action = 'session.leave' AND entity_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1", [$s6, $cat]);
check('their own cancellation is recorded with late_notice = false', $leave && json_decode($leave['context'], true)['late_notice'] === false, $leave['context'] ?? 'no row');
check('...and the facilitator email does not flag it as late', !$db->fetchColumn(
    "SELECT id FROM ld_notification_queue WHERE session_id = ? AND notification_type = 'sessionLeft' AND summary LIKE '%inside 48 hours%'", [$s6]));

// The 48-hour boundary, with the database clock deliberately elsewhere.
$db->execute("SET time_zone = '-07:00'");
$s6b = $mkSession('boundary', 30);
$sb = $db->fetch("SELECT * FROM ld_sessions WHERE id = ?", [$s6b]);
$windowOpens = session_starts_at($sb) - 48 * 3600;
check('a promotion 1 minute inside the window is exempt',
    !booking_is_late_cancel($sb, ['promoted_at' => date('Y-m-d H:i:s', $windowOpens + 60)]));
check('a promotion 1 minute before the window is not',
    booking_is_late_cancel($sb, ['promoted_at' => date('Y-m-d H:i:s', $windowOpens - 60)]));
$book($s6b, $ann); $book($s6b, $bob);
$svc->add($s6b, $facilitatorId, $cat);
(new ParticipationService($db))->remove($s6b, $facilitatorId, (int) $booking($s6b, $ann)['id'], true);
$promotedAt = strtotime((string) ($booking($s6b, $cat)['promoted_at'] ?? ''));
$dbNow = strtotime((string) $db->fetchColumn("SELECT NOW()"));
check('promoted_at is written in app time, not the database clock',
    abs($promotedAt - time()) < 120 && abs($dbNow - time()) > 3600, "promoted_at {$promotedAt}, php " . time() . ", db {$dbNow}");
check('so a promotion with the clocks 9 hours apart is still exempt', !booking_is_late_cancel($sb, $booking($s6b, $cat)));
$db->execute("SET time_zone = SYSTEM");

// --- 7. A notice that cannot be queued rolls the promotion back --------------

echo "\n7. Notification failure\n";
$s7 = $mkSession('mailfail');
$book($s7, $ann); $book($s7, $bob);
$svc->add($s7, $facilitatorId, $cat);
$db->execute("CREATE TRIGGER fail_waitlist_notice BEFORE INSERT ON ld_notification_queue FOR EACH ROW BEGIN IF NEW.notification_type = 'waitlistPromoted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forced notice failure'; END IF; END");
$threw = null;
try { (new ParticipationService($db))->remove($s7, $facilitatorId, (int) $booking($s7, $ann)['id'], true); }
catch (Throwable $e) { $threw = $e->getMessage(); }
$db->execute("DROP TRIGGER fail_waitlist_notice");
check('the failure surfaces as an error', $threw !== null && str_contains($threw, 'Forced notice failure'), (string) $threw);
check('...and nothing changed: the cancellation and the promotion both rolled back',
    (bool) $booking($s7, $ann) && !$booking($s7, $cat) && $waiting($s7) === [$cat]);

// --- 8. Dashboard cancel controls by role --------------------------------------

echo "\n8. Dashboard cancellation by role\n";
$dash = $mkUser('Dash');
$soon = fn(string $label) => $mkSession($label, 24, 7);
$dPromoMod = $soon('d-promoted-artist+model'); $book($dPromoMod, $dash, 'artist', date('Y-m-d H:i:s')); $book($dPromoMod, $dash, 'model');
$dArtMod   = $soon('d-artist+model');          $book($dArtMod, $dash, 'artist'); $book($dArtMod, $dash, 'model');
$dPromo    = $soon('d-promoted-artist');       $book($dPromo, $dash, 'artist', date('Y-m-d H:i:s'));
$dModel    = $soon('d-model');                 $book($dModel, $dash, 'model');
$dObs      = $soon('d-observer');              $book($dObs, $dash, 'observer');
$dHost     = $soon('d-hosting');               $book($dHost, $dash, 'facilitator');
$dWait     = $mkSession('d-waiting', 240, 1);  $book($dWait, $ann); $svc->add($dWait, $facilitatorId, $dash);
actAs($dash); $r = req('GET', '/dashboard');
$entry = function (int $sessionId) use ($r): string {
    foreach (explode('class="upcoming-entry"', $r['body']) as $chunk) {
        if (preg_match('#/sessions/' . hex_id($sessionId) . '(?=[-"/?])#', $chunk)) return $chunk;
    }
    return '';
};
$cancels = fn(string $chunk, string $role) => str_contains($chunk, 'name="role" value="' . $role . '"');
// Roles sort by ENUM position (model before artist), so the existing button
// cancels the model booking first. The exemption belongs to the promoted
// artist booking only, so it must not leak onto that model cancellation.
check('promoted artist + model: button cancels model (unchanged), note still shown', $cancels($entry($dPromoMod), 'model') && str_contains($entry($dPromoMod), LATE_NOTE));
check('ordinary artist + model: button cancels model, note shown', $cancels($entry($dArtMod), 'model') && str_contains($entry($dArtMod), LATE_NOTE));
check('promoted artist alone: no note', $cancels($entry($dPromo), 'artist') && !str_contains($entry($dPromo), LATE_NOTE));
check('model-only booking keeps its cancel button and note', $cancels($entry($dModel), 'model') && str_contains($entry($dModel), LATE_NOTE));
check('observer-only booking keeps its cancel button and note', $cancels($entry($dObs), 'observer') && str_contains($entry($dObs), LATE_NOTE));
check('facilitator-only row still shows "hosting"', str_contains($entry($dHost), 'hosting') && !str_contains($entry($dHost), 'name="role"'));
check('the dashboard lists waiting lists with position and a Leave button',
    str_contains($entry($dWait), 'waiting list #1') && str_contains($entry($dWait), '/waitlist/leave'));

// --- 9. WhatsApp schedule --------------------------------------------------------

echo "\n9. WhatsApp schedule page\n";
$s9 = $mkSession('whatsapp', 1.5, 1);
$book($s9, $ann); $book($s9, $bob, 'observer'); $svc->add($s9, $facilitatorId, $cat);
actAs($facilitatorId, 'admin'); $r = req('GET', '/schedule/whatsapp');
$plain = html_entity_decode(strip_tags($r['body']));
check('observers are listed on an indented line', str_contains($plain, "\n   observing: Bob"), substr($plain, 0, 400));
check('the waiting list is listed on an indented line', str_contains($plain, "\n   waiting list: Cat"));

// --- 10. Race for the last place ---------------------------------------------------

echo "\n10. Two people race for the last place\n";
$s10 = $mkSession('race', 240, 1);
$spawn = function (int $user) use ($s10) {
    $p = proc_open([PHP_BINARY, __FILE__, '--join-worker', '--session=' . $s10, '--user=' . $user],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, LDR_ROOT, array_merge(getenv(), ['LDR_TEST_DATABASE' => getenv('LDR_TEST_DATABASE')]));
    if (trim((string) fgets($pipes[1])) !== 'READY') throw new RuntimeException('Join worker did not start: ' . stream_get_contents($pipes[2]));
    return [$p, $pipes];
};
$w1 = $spawn($dee); $w2 = $spawn($eve);
fwrite($w1[1][0], "GO\n"); fwrite($w2[1][0], "GO\n");
$out = [];
foreach ([$w1, $w2] as [$p, $pipes]) { fclose($pipes[0]); $out[] = trim(stream_get_contents($pipes[1])); fclose($pipes[1]); fclose($pipes[2]); proc_close($p); }
sort($out);
check('exactly one booked and one waitlisted', $out === ['booked', 'waitlisted'], json_encode($out));
check('...and the session holds one artist, not two', WaitlistService::artistCount($db, $s10) === 1);

// --- Teardown ----------------------------------------------------------------------

echo "\nTeardown\n";
$cleanup();
echo "  cleaned up\n";
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
