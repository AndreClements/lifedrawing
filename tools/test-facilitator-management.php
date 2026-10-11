<?php

declare(strict_types=1);

/** Isolated local integration tests. --keep retains fictional fixtures for browser checks. */
define('LDR_ROOT', dirname(__DIR__));
require LDR_ROOT . '/vendor/autoload.php';
date_default_timezone_set('Africa/Johannesburg');
$worker = getopt('', ['cancel-worker', 'database:', 'session:', 'actor:']);
if (isset($worker['cancel-worker'])) {
    putenv('LDR_TEST_DATABASE=' . ($worker['database'] ?? 'invalid'));
    require __DIR__ . '/local-test-environment.php';
    new App\Kernel();
    app('db')->execute("SET innodb_lock_wait_timeout = 5");
    echo "READY\n"; flush();
    try {
        (new App\Services\SessionManagementService(app('db')))->cancel((int) $worker['session'], (int) $worker['actor'], 'Race fixture');
        echo "UNEXPECTED_CANCEL\n";
    } catch (DomainException $e) { echo (string) $e->getCode() . ':' . $e->getMessage() . "\n"; }
    exit;
}
$database = 'ldr_test_management_' . bin2hex(random_bytes(4));
$admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE " . $database . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$_ENV = array_merge($_ENV, ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => $database,
    'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'APP_ENV' => 'local', 'APP_BASE_PATH' => '',
    'APP_URL' => 'http://127.0.0.1:8765', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '9']);
$_SESSION = ['_csrf_token' => 'management-test-token'];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$kernel = new App\Kernel();
$db = app('db');
$migration = new App\Database\Migration($db);
$migration->run(LDR_ROOT . '/database/migrations');
$migration->run(LDR_ROOT . '/modules/lifedrawing/migrations', 'lifedrawing');
$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail && !$ok ? ': ' . $detail : '') . PHP_EOL;
}
function rejects(string $label, callable $action, int $code = 0): void {
    try { $action(); check($label, false, 'No exception'); }
    catch (Throwable $e) { check($label, !$code || $e->getCode() === $code, $e->getMessage()); }
}
function cli(array $arguments): array {
    $process = proc_open([PHP_BINARY, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, LDR_ROOT);
    if (!is_resource($process)) throw new RuntimeException('Cannot start CLI check.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $output];
}
function actor(?int $id): void {
    global $db;
    $_SESSION = ['_csrf_token' => 'management-test-token'];
    if ($id !== null) {
        $u = $db->fetch("SELECT * FROM users WHERE id = ?", [$id]);
        $_SESSION += ['user_id' => $id, 'user_role' => $u['role'], 'user_name' => $u['display_name'], 'consent_state' => $u['consent_state']];
    }
}
function request(string $method, string $path, array $post = [], array $query = [], bool $csrf = true): App\Response {
    global $kernel;
    $cache = new ReflectionProperty(App\Middleware\ConsentGate::class, 'cache');
    $cache->setValue(null, []);
    if ($method !== 'GET' && $csrf) $post['_csrf_token'] = 'management-test-token';
    $_SERVER['REQUEST_URI'] = $path;
    return $kernel->handle(new App\Request($method, $path, $path, $query, $post,
        ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'], [], []));
}
function user(string $name, string $role = 'participant', string $consent = 'granted', ?string $email = null): int {
    global $db;
    $db->execute("INSERT INTO users (display_name, email, password_hash, role, consent_state, whatsapp_number, notify_session_cancelled)
        VALUES (?, ?, ?, ?, ?, '+27 82 555 0101', 0)", [$name, $email ?? strtolower(str_replace(' ', '.', $name)) . '@local.test',
        password_hash('Test-only-portrait-123', PASSWORD_DEFAULT), $role, $consent]);
    return (int) $db->lastInsertId();
}
function session(string $name, string $date, int $facilitator, string $status = 'scheduled', string $time = '10:00:00'): int {
    global $db;
    $db->execute("INSERT INTO ld_sessions (title, session_date, start_time, facilitator_id, status) VALUES (?, ?, ?, ?, ?)", [$name, $date, $time, $facilitator, $status]);
    return (int) $db->lastInsertId();
}
function participation(int $session, int $user, string $role = 'artist'): int {
    global $db;
    $db->execute("INSERT INTO ld_session_participants (session_id, user_id, role) VALUES (?, ?, ?)", [$session, $user, $role]);
    return (int) $db->lastInsertId();
}
$facilitator = user('Test Facilitator', 'facilitator');
$otherFacilitator = user('Other Facilitator', 'facilitator');
$artist = user('Test Artist');
$sitter = user('Test Sitter');
$stub = user('Unbooked Stub', 'participant', 'pending', 'unbooked.stub@local');
$withdrawn = user('Withdrawn Person', 'participant', 'withdrawn');
$past = session('Finished practice', date('Y-m-d', strtotime('-1 day')), $facilitator);
$future = session('Future practice', date('Y-m-d', strtotime('+10 days')), $facilitator);
$cancelled = session('Cancelled fixture', date('Y-m-d', strtotime('-7 days')), $facilitator, 'cancelled');
$pid = participation($past, $artist);
$sitterPid = participation($past, $sitter, 'artist');
participation($cancelled, $artist);
participation($future, $sitter, 'model');
$notes = new App\Services\SessionNotesService($db);
$management = new App\Services\SessionManagementService($db);
$poses = new App\Services\PoseService($db);
try {
    $boundary = ['session_date' => date('Y-m-d'), 'start_time' => null, 'duration_minutes' => 180];
    $end = session_starts_at($boundary) + 180 * 60;
    check('shared end boundary is strict and handles null start', !session_is_over($boundary, $end) && session_is_over($boundary, $end + 1));
    actor($artist);
    foreach (['/manage', '/manage/users', '/manage/users/' . hex_id($stub), '/manage/sessions/' . hex_id($past) . '/notes'] as $path) {
        check('participant denied ' . $path, request('GET', $path)->getStatus() === 403);
    }
    actor(null);
    check('guest cannot read notes', request('GET', '/manage/sessions/' . hex_id($past) . '/notes')->getStatus() === 401);
    check('cancelled direct page is absent', request('GET', '/sessions/' . hex_id($cancelled))->getStatus() === 404);
    foreach (['/sessions', '/gallery', '/', '/sitemap.xml'] as $path) {
        $response = request('GET', $path, [], ['view' => 'past']);
        check('public read filters cancellation: ' . $path, $response->getStatus() === 200 && !str_contains($response->getBody(), 'Cancelled fixture'), (string) $response->getStatus());
    }
    actor($otherFacilitator);
    foreach (['/manage', '/manage/users', '/manage/users/' . hex_id($stub), '/manage/users/' . hex_id($withdrawn), '/manage/sessions/' . hex_id($past), '/sessions/' . hex_id($past) . '/upload'] as $path) {
        $response = request('GET', $path);
        check('another facilitator can open ' . $path, $response->getStatus() === 200, $response->getBody());
    }
    $contact = request('GET', '/manage/users/' . hex_id($stub))->getBody();
    check('stub contact shown without fake email action or secrets', str_contains($contact, 'No email recorded') && !str_contains($contact, 'mailto:unbooked.stub') && !str_contains($contact, 'password_hash'));
    $search = request('GET', '/manage/users', [], ['q' => '27825550101']);
    check('phone searches ignore formatting', str_contains($search->getBody(), 'Unbooked Stub'));
    $row = $notes->create($past, $otherFacilitator, ['kind' => 'break', 'participant_id' => $pid, 'text' => 'Loo break <script>alert(1)</script>', 'minutes' => 5], 'notes-test-create-0001');
    $again = $notes->create($past, $otherFacilitator, ['text' => 'Changed retry'], 'notes-test-create-0001');
    check('creation retry retains its original result', $row['id'] === $again['id'] && $row['text'] === $again['text']);
    $panel = request('GET', '/manage/sessions/' . hex_id($past) . '/notes')->getBody();
    check('note output escapes HTML', str_contains($panel, '&lt;script&gt;') && !str_contains($panel, '<script>alert(1)'));
    rejects('cross-session participation rejected', fn() => $notes->create($future, $facilitator, ['text' => 'Invalid', 'participant_id' => $pid], 'notes-test-cross-0001'), 422);
    $notes->edit($past, $facilitator, (int) $row['id'], $row['updated_at'], ['text' => 'Corrected']);
    rejects('stale edit rejected', fn() => $notes->edit($past, $facilitator, (int) $row['id'], $row['updated_at'], ['text' => 'Stale']), 409);
    $fresh = $db->fetch("SELECT * FROM ld_session_log WHERE id = ?", [$row['id']]);
    $notes->edit($past, $facilitator, (int) $row['id'], $fresh['updated_at'], [], true);
    $deleted = $notes->create($past, $facilitator, ['text' => 'Retry removed'], 'notes-test-create-0001');
    check('retry cannot recreate a removed note', $deleted['deleted_at'] !== null);
    $db->execute("UPDATE ld_session_participants SET attended = 1, attendance = 'attended' WHERE id = ?", [$pid]);
    $notes->attendance($past, $facilitator, $pid, 'no_show', 'attended', 'attendance-test-0001');
    $notes->attendance($past, $facilitator, $pid, 'restore', 'no_show', 'attendance-test-0002');
    $notes->attendance($past, $facilitator, $pid, 'no_show', 'attended', 'attendance-test-0001');
    check('undo restores legacy attendance and old retry cannot reverse undo', $db->fetchColumn("SELECT attendance FROM ld_session_participants WHERE id = ?", [$pid]) === 'attended');
    $requestPath = '/manage/sessions/' . hex_id($past) . '/notes';
    check('CSRF rejected', request('POST', $requestPath, ['text' => 'bad'], [], false)->getStatus() === 403);
    $previewBefore = (int) $db->fetchColumn("SELECT COUNT(*) FROM ld_session_log");
    $notes->preview($past, $facilitator, ['text' => 'preview only']);
    check('note preview writes nothing', (int) $db->fetchColumn("SELECT COUNT(*) FROM ld_session_log") === $previewBefore);
    $db->execute("CREATE TRIGGER fail_management_audit BEFORE INSERT ON provenance_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forced audit failure'");
    rejects('audit failure aborts attendance', fn() => $notes->attendance($past, $facilitator, $pid, 'no_show', 'attended', 'attendance-audit-fail'));
    check('failed audit leaves attendance intact', $db->fetchColumn("SELECT attendance FROM ld_session_participants WHERE id = ?", [$pid]) === 'attended');
    $db->execute("DROP TRIGGER fail_management_audit");
    $before = (int) $db->fetchColumn("SELECT COUNT(*) FROM provenance_log");
    try { $db->transaction(function () use ($db, $facilitator) {
        $db->transaction(fn() => (new App\Services\ProvenanceService($db))->record($facilitator, 'test.inner', 'user', $facilitator));
        throw new RuntimeException('outer rollback');
    }); } catch (RuntimeException) {}
    check('inner success does not commit outer transaction', (int) $db->fetchColumn("SELECT COUNT(*) FROM provenance_log") === $before);
    $db->transaction(function () use ($db, $facilitator) {
        try { $db->transaction(function () use ($db, $facilitator) {
            (new App\Services\ProvenanceService($db))->record($facilitator, 'test.rollback', 'user', $facilitator);
            throw new RuntimeException('inner rollback');
        }); } catch (RuntimeException) {}
        (new App\Services\ProvenanceService($db))->record($facilitator, 'test.outer', 'user', $facilitator);
    });
    check('caught inner failure preserves usable outer transaction', (int) $db->fetchColumn("SELECT COUNT(*) FROM provenance_log") === $before + 1);
    $db->execute("INSERT INTO ld_sitter_queue (user_id, status, requested_at) VALUES (?, 'waiting', ?)", [$sitter, date('Y-m-d H:i:s')]);
    $entry = (int) $db->lastInsertId();
    $management->changeRole($past, $facilitator, $sitterPid, 'artist', 'model');
    check('historical role correction leaves a new queue request waiting', $db->fetchColumn("SELECT status FROM ld_sitter_queue WHERE id = ?", [$entry]) === 'waiting');
    $futurePid = participation($future, $artist);
    $db->execute("INSERT INTO ld_sitter_queue (user_id, status, requested_at) VALUES (?, 'waiting', ?)", [$artist, date('Y-m-d H:i:s')]);
    $artistEntry = (int) $db->lastInsertId();
    $db->execute("CREATE TRIGGER fail_role_audit BEFORE INSERT ON provenance_log FOR EACH ROW BEGIN IF NEW.action = 'participant.role' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forced role failure'; END IF; END");
    rejects('role and nested queue operation roll back together', fn() => $management->changeRole($future, $facilitator, $futurePid, 'artist', 'model'));
    check('participant and queue retain original states', $db->fetchColumn("SELECT role FROM ld_session_participants WHERE id = ?", [$futurePid]) === 'artist' && $db->fetchColumn("SELECT status FROM ld_sitter_queue WHERE id = ?", [$artistEntry]) === 'waiting');
    $db->execute("DROP TRIGGER fail_role_audit");
    $management->changeRole($future, $facilitator, $futurePid, 'artist', 'model');
    $b1 = $poses->batch($past, $facilitator, 'upload-test-batch-0001');
    $retry = $poses->batch($past, $facilitator, 'upload-test-batch-0001');
    $b2 = $poses->batch($past, $facilitator, 'upload-test-batch-0002');
    check('batch retries retain pose; different batches get distinct numbers', $b1['id'] === $retry['id'] && $b1['pose_number'] !== $b2['pose_number']);
    rejects('started sessions cannot be cancelled', fn() => $management->cancel($past, $facilitator, ''), 422);
    $atStart = session('Start boundary', date('Y-m-d'), $facilitator, 'scheduled', date('H:i:s'));
    rejects('exact start boundary refuses cancellation', fn() => $management->cancel($atStart, $facilitator, ''), 422);
    $withPhoto = session('Already photographed', date('Y-m-d', strtotime('+12 days')), $facilitator);
    $db->execute("INSERT INTO ld_artworks (session_id, uploaded_by, file_path, visibility) VALUES (?, ?, 'fixture.jpg', 'removed')", [$withPhoto, $facilitator]);
    rejects('even removed photos prevent cancellation', fn() => $management->cancel($withPhoto, $facilitator, ''), 422);
    $notes->create($future, $facilitator, ['text' => 'Retain this note'], 'cancel-note-test-0001');
    $management->cancel($future, $facilitator, 'Venue unavailable');
    check('cancellation retains bookings and notes', (int) $db->fetchColumn("SELECT COUNT(*) FROM ld_session_participants WHERE session_id = ?", [$future]) === 2 && count($notes->listing($future, $facilitator)) === 1);
    check('cancellation requeues a linked sitter', $db->fetchColumn("SELECT status FROM ld_sitter_queue WHERE id = ?", [$artistEntry]) === 'waiting');
    check('repeated cancellation is a no-op', !$management->cancel($future, $facilitator, '')['changed']);
    actor($artist);
    check('cancelled join refused', request('POST', '/sessions/' . hex_id($future) . '/join')->getStatus() === 404);
    actor($facilitator);
    check('cancelled upload page refused', request('GET', '/sessions/' . hex_id($future) . '/upload')->getStatus() === 404);
    $notes->create($future, $facilitator, ['text' => 'Cancellation follow-up'], 'cancel-note-test-0002');
    $management->restore($future, $facilitator, null);
    check('restoration recovers previous status and bookings', $db->fetchColumn("SELECT status FROM ld_sessions WHERE id = ?", [$future]) === 'scheduled');
    (new App\Services\StatsService($db))->refreshAll();
    check('cancelled history excluded from attendance totals', (int) $db->fetchColumn("SELECT total_sessions FROM ld_artist_stats WHERE user_id = ?", [$artist]) === 1);
    $image = imagecreatetruecolor(20, 30);
    $source = LDR_ROOT . '/storage/cache/profile-source-test.png';
    $target = LDR_ROOT . '/storage/cache/profile-output-test.jpg';
    imagepng($image, $source); imagedestroy($image);
    (new App\Services\ProfilePictureService($db))->encode($source, $target);
    $size = getimagesize($target);
    check('profile photo is re-encoded to a square JPEG', $size[0] === 512 && $size[1] === 512 && $size['mime'] === 'image/jpeg');
    unlink($source); unlink($target);
    check('profile edit offers optional non-nude photo', str_contains(request('GET', '/profile/edit')->getBody(), 'highly recommended for sitters'));
    rejects('profile photo requires non-nude confirmation', fn() => (new App\Services\ProfilePictureService($db))->upload($artist, [], false), 422);

    // Photo removal must survive a failed audit; withdrawal uses the existing archive.
    $avatar = 'avatars/' . $artist . '/' . bin2hex(random_bytes(16)) . '.jpg';
    $avatarFile = LDR_ROOT . '/public/assets/uploads/' . $avatar;
    if (!is_dir(dirname($avatarFile))) mkdir(dirname($avatarFile), 0755, true);
    file_put_contents($avatarFile, 'Fictional test portrait');
    $db->execute("UPDATE users SET avatar_path = ? WHERE id = ?", [$avatar, $artist]);
    rejects('participant cannot remove another person picture', fn() => (new App\Services\ProfilePictureService($db))->remove($artist, $sitter), 403);
    $db->execute("CREATE TRIGGER fail_picture_audit BEFORE INSERT ON provenance_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forced photo audit failure'");
    rejects('photo removal fails when audit cannot be saved', fn() => (new App\Services\ProfilePictureService($db))->remove($artist, $facilitator));
    check('failed photo removal keeps both file and profile pointer', is_file($avatarFile) && $db->fetchColumn("SELECT avatar_path FROM users WHERE id = ?", [$artist]) === $avatar);
    $db->execute("DROP TRIGGER fail_picture_audit");
    $withdrawal = app('auth')->withdrawConsent($artist);
    $archive = LDR_ROOT . '/storage/withdrawn/' . $artist . '/' . $avatar;
    check('consent withdrawal removes and archives the profile photo', $withdrawal['complete'] && !is_file($avatarFile) && is_file($archive));
    check('withdrawal clears public profile photo pointer', $db->fetchColumn("SELECT avatar_path FROM users WHERE id = ?", [$artist]) === null);
    if (is_file($archive)) unlink($archive);
    $db->execute("UPDATE users SET consent_state = 'granted' WHERE id = ?", [$artist]);
    actor($facilitator);

    // Legacy visual groups and pose/caption editing retain identity.
    $legacySession = session('Legacy poses', date('Y-m-d', strtotime('-2 days')), $facilitator);
    foreach ([['Warmup', '5 min'], ['Warmup', '5 min'], ['Long', '20 min']] as $index => [$label, $duration]) {
        $db->execute("INSERT INTO ld_artworks (session_id, uploaded_by, file_path, pose_label, pose_duration, pose_index, visibility)
            VALUES (?, ?, 'fixture.jpg', ?, ?, ?, 'public')", [$legacySession, $facilitator, $label, $duration, $index + 1]);
    }
    rejects('legacy pose groups require review', fn() => $poses->batch($legacySession, $facilitator, 'legacy-review-test-01'), 409);
    $poses->batch($legacySession, $facilitator, 'legacy-review-test-01', true);
    $groups = $poses->groups($legacySession);
    check('normalization preserves consecutive legacy groups', count($groups) === 2 && count($groups[0]) === 2 && count($groups[1]) === 1);
    $art = $groups[0][0];
    $artPage = request('GET', '/artworks/' . hex_id((int) $art['id']));
    check('artwork page offers facilitator caption editing', $artPage->getStatus() === 200 && str_contains($artPage->getBody(), 'Edit caption or pose'));
    $caption = ['artwork_id' => $art['id'], 'caption' => 'Corrected caption', 'expected_updated_at' => $art['updated_at']];
    $captionPath = '/manage/sessions/' . hex_id($legacySession) . '/caption';
    check('caption correction succeeds through route', request('POST', $captionPath, $caption)->getStatus() === 200);
    check('stale caption rejected through route', request('POST', $captionPath, $caption)->getStatus() === 409);
    $versions = $db->fetchAll("SELECT id, updated_at FROM ld_artworks WHERE session_id = ? AND pose_number = ? ORDER BY id", [$legacySession, $art['pose_number']]);
    $pose = ['pose_number' => $art['pose_number'], 'pose_label' => 'First exercise', 'pose_duration' => '6 min', 'expected_pose' => hash('sha256', json_encode($versions))];
    $posePath = '/manage/sessions/' . hex_id($legacySession) . '/pose';
    check('whole pose correction succeeds', request('POST', $posePath, $pose)->getStatus() === 200);
    check('stale pose edit rejected', request('POST', $posePath, $pose)->getStatus() === 409);
    check('pose correction retains IDs and updates just its two images', (int) $db->fetchColumn("SELECT COUNT(*) FROM ld_artworks WHERE session_id = ? AND pose_label = 'First exercise'", [$legacySession]) === 2
        && $db->fetchColumn("SELECT caption FROM ld_artworks WHERE id = ?", [$art['id']]) === 'Corrected caption');
    // New schedule boundaries and post-commit cache failure.
    $editable = $db->fetch("SELECT * FROM ld_sessions WHERE id = ?", [$legacySession]);
    $details = $editable + ['expected_updated_at' => $editable['updated_at'], 'confirm_schedule' => 1];
    $details['session_date'] = date('Y-m-d', strtotime('+3 days'));
    rejects('cannot move a started session into the future to cancel it', fn() => $management->details($legacySession, $facilitator, $details), 422);
    $details['session_date'] = date('Y-m-d', strtotime('-3 days'));
    $db->execute("CREATE TRIGGER fail_stats_refresh BEFORE UPDATE ON ld_artist_stats FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forced cache failure'");
    $warning = $management->details($legacySession, $facilitator, $details);
    check('failed stats refresh reports separately after saved date edit', $warning !== null && $db->fetchColumn("SELECT session_date FROM ld_sessions WHERE id = ?", [$legacySession]) === $details['session_date']);
    $db->execute("DROP TRIGGER fail_stats_refresh");
    rejects('stale session details rejected', fn() => $management->details($legacySession, $facilitator, $details), 409);
    $collision = participation($past, $artist, 'observer');
    rejects('role collision keeps both participation rows', fn() => $management->changeRole($past, $facilitator, $pid, 'artist', 'observer'), 409);
    check('role conflict leaves original rows', $db->fetchColumn("SELECT role FROM ld_session_participants WHERE id = ?", [$pid]) === 'artist' && $db->fetchColumn("SELECT id FROM ld_session_participants WHERE id = ?", [$collision]) == $collision);
    // Cancelled evidence must never complete a later queue request.
    $onlyCancelled = user('Cancelled Booking Only');
    participation($cancelled, $onlyCancelled, 'model');
    $db->execute("INSERT INTO ld_sitter_queue (user_id, status, requested_at, scheduled_session_id) VALUES (?, 'scheduled', ?, ?)", [$onlyCancelled, date('Y-m-d H:i:s', strtotime('-20 days')), $cancelled]);
    $entryRow = $db->fetch("SELECT * FROM ld_sitter_queue WHERE id = ?", [(int) $db->lastInsertId()]);
    check('cancelled scheduled evidence requeues instead of completing', (new App\Services\SitterQueueService($db))->classify($entryRow)['action'] === 'requeue');
    $entryRow['status'] = 'waiting'; $entryRow['scheduled_session_id'] = null;
    check('cancelled completed sitting cannot fulfil waiting request', (new App\Services\SitterQueueService($db))->classify($entryRow)['action'] === 'skip');
    actor($onlyCancelled);
    check('cancelled booking does not unlock names', !can_see_names());
    actor($facilitator);
    $db->execute("UPDATE users SET role = 'participant' WHERE id = ?", [$facilitator]);
    check('stale facilitator session cannot read contacts after demotion', request('GET', '/manage/users')->getStatus() === 403);
    $db->execute("UPDATE users SET role = 'facilitator' WHERE id = ?", [$facilitator]);
    // A cancellation waits for an in-flight upload and then sees its committed row.
    $racing = session('Concurrent upload', date('Y-m-d', strtotime('+20 days')), $facilitator);
    $pdo = $db->getPdo();
    $pdo->beginTransaction();
    $db->fetch("SELECT id FROM ld_sessions WHERE id = ? FOR UPDATE", [$racing]);
    $process = proc_open([PHP_BINARY, __FILE__, '--cancel-worker', '--database=' . $database, '--session=' . $racing, '--actor=' . $facilitator],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, LDR_ROOT);
    if (!is_resource($process)) throw new RuntimeException('Cannot start cancellation race check.');
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 8);
    try {
        if (trim((string) fgets($pipes[1])) !== 'READY') throw new RuntimeException('Cancellation worker did not start.');
        usleep(100000);
        check('cancellation waits for the upload session lock', proc_get_status($process)['running']);
        $db->execute("INSERT INTO ld_artworks (session_id, uploaded_by, file_path, visibility) VALUES (?, ?, 'race-fixture.jpg', 'public')", [$racing, $facilitator]);
        $pdo->commit();
        $reply = stream_get_contents($pipes[1]);
        check('cancellation rechecks uploads after the competing commit', str_contains($reply, '422:A session with uploaded photos cannot be cancelled.')
            && $db->fetchColumn("SELECT status FROM ld_sessions WHERE id = ?", [$racing]) === 'scheduled', $reply);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (proc_get_status($process)['running']) proc_terminate($process);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    }
    putenv('LDR_TEST_DATABASE=' . $database);
    $noteArgs = ['tools/session-notes.php', '--session=' . $past, '--actor=' . $facilitator, '--text=Fictional CLI note', '--request-key=cli-note-roundtrip-01'];
    $before = (int) $db->fetchColumn("SELECT COUNT(*) FROM ld_session_log");
    [$exit, $output] = cli($noteArgs);
    check('CLI notes default to a read-only preview', $exit === 0 && str_contains($output, 'DRY RUN') && (int) $db->fetchColumn("SELECT COUNT(*) FROM ld_session_log") === $before, $output);
    [$exit, $output] = cli([...$noteArgs, '--apply']);
    [$retryExit] = cli([...$noteArgs, '--apply']);
    check('CLI apply and retry save one verified note', $exit === 0 && $retryExit === 0 && str_contains($output, 'Verified entry')
        && (int) $db->fetchColumn("SELECT COUNT(*) FROM ld_session_log") === $before + 1, $output);
    $cliSession = session('CLI photo import', date('Y-m-d', strtotime('-5 days')), $facilitator);
    $dir = LDR_ROOT . '/storage/cache/' . $database;
    mkdir($dir);
    $source = $dir . '/fictional-drawing.png';
    $image = imagecreatetruecolor(20, 30);
    imagefill($image, 0, 0, imagecolorallocate($image, 48, 85, 137));
    imagepng($image, $source); imagedestroy($image);
    $importArgs = ['tools/import-session-photos.php', '--session=' . $cliSession, '--uploader=' . $facilitator, '--dir=' . $dir, '--pose-duration=10 min'];
    try {
        [$exit, $output] = cli([...$importArgs, '--dry-run']);
        check('CLI photo preview allocates no batch or artwork', $exit === 0 && str_contains($output, 'DRY')
            && !$db->fetchColumn("SELECT id FROM ld_upload_batches WHERE session_id = ?", [$cliSession]), $output);
        [$exit, $output] = cli($importArgs);
        [$retryExit] = cli($importArgs);
        check('CLI import and retry save one photo in one pose', $exit === 0 && $retryExit === 0
            && (int) $db->fetchColumn("SELECT COUNT(*) FROM ld_artworks WHERE session_id = ?", [$cliSession]) === 1, $output);
        [$exit, $output] = cli(['tools/import-session-photos.php', '--session=' . $cancelled, '--uploader=' . $facilitator, '--dir=' . $dir]);
        check('CLI import refuses cancelled sessions', $exit !== 0 && str_contains($output, 'not found'), $output);
    } finally {
        foreach ($db->fetchAll("SELECT file_path FROM ld_artworks WHERE session_id = ?", [$cliSession]) as $file) {
            if (preg_match('~\Asessions/' . $cliSession . '/[a-zA-Z0-9_.-]+\z~', $file['file_path'])) {
                $path = LDR_ROOT . '/public/assets/uploads/' . $file['file_path'];
                if (is_file($path)) unlink($path);
            }
        }
        unlink($source); rmdir($dir);
        putenv('LDR_TEST_DATABASE');
    }
} catch (Throwable $e) {
    check('unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
} finally {
    if (in_array('--keep', $argv, true)) {
        $state = compact('database', 'facilitator', 'otherFacilitator', 'artist', 'sitter', 'stub', 'withdrawn', 'past', 'future', 'cancelled');
        file_put_contents(LDR_ROOT . '/storage/cache/management-test.json', json_encode($state));
        echo 'Retained fictional test database: ' . $database . PHP_EOL;
    } else {
        $admin->exec("DROP DATABASE " . $database);
    }
}
echo "$passed passed; $failed failed.\n";
exit($failed ? 1 : 0);
