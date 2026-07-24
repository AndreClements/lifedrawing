<?php

declare(strict_types=1);

/**
 * Tests for create-session.php and the session listing flags (migration 019).
 *
 * Run against the LOCAL dev database only — it writes and deletes rows.
 * Requires migration 019 applied.
 *
 * Usage: php tools/test-create-session.php
 *
 * Two surfaces, because the code has two:
 *   - subprocess  — dry-run and duplicate detection happen before the
 *                   transaction function, so they can only be tested by
 *                   running the CLI as shipped
 *   - direct      — the transaction itself, including its rollback failpoint
 *
 * Every row created carries a marker title so teardown can find it. Teardown
 * deletes queue and provenance rows FIRST: ld_notification_queue.session_id is
 * ON DELETE SET NULL (migration 018), so dropping sessions first would strand
 * its rows beyond lookup.
 */

define('LDR_ROOT', dirname(__DIR__));

$envFile = LDR_ROOT . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = $_SERVER[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

require LDR_ROOT . '/vendor/autoload.php';
require LDR_ROOT . '/tools/create-session.php';   // main-guard keeps the CLI inert

use App\Database\Connection;

const MARKER = '__TEST_CS__';

$cfg = config('database');
$db = new Connection(
    host: $cfg['host'],
    database: $cfg['database'],
    username: $cfg['username'],
    password: $cfg['password'],
    port: (int) ($cfg['port'] ?? 3306),
);

$passed = 0;
$failed = 0;
$createdIds = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  {$name}\n";
    } else {
        $failed++;
        echo "  FAIL  {$name}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

function counts(Connection $db): array
{
    return [
        'sessions'     => (int) $db->fetchColumn("SELECT COUNT(*) FROM ld_sessions"),
        'participants' => (int) $db->fetchColumn("SELECT COUNT(*) FROM ld_session_participants"),
        'provenance'   => (int) $db->fetchColumn("SELECT COUNT(*) FROM provenance_log"),
        'queue'        => (int) $db->fetchColumn("SELECT COUNT(*) FROM ld_notification_queue"),
    ];
}

// Verify migration 019 is applied before anything else.
$cols = array_column($db->fetchAll("SHOW COLUMNS FROM ld_sessions"), 'Field');
foreach (['capacity_published', 'booking_note', 'model_join_enabled'] as $needed) {
    if (!in_array($needed, $cols, true)) {
        fwrite(STDERR, "Migration 019 not applied (missing '{$needed}'). Run: php tools/migrate.php run\n");
        exit(1);
    }
}

$facilitator = $db->fetch(
    "SELECT id FROM users WHERE role IN ('admin','facilitator') ORDER BY id ASC LIMIT 1"
);
if (!$facilitator) {
    fwrite(STDERR, "No admin/facilitator user in the local DB. Run: php tools/seed.php\n");
    exit(1);
}
$facilitatorId = (int) $facilitator['id'];
$marker = MARKER . ' ' . getmypid();
$baseline = counts($db);

$makeRow = function (array $overrides = []) use ($facilitatorId, $marker): array {
    return array_merge([
        'title'              => $marker,
        'subtitle'           => 'Test Session Format',
        'session_date'       => '2099-01-01',
        'start_time'         => '10:00:00',
        'duration_minutes'   => 210,
        'venue'              => 'Test Venue',
        'description'        => null,
        'facilitator_id'     => $facilitatorId,
        'model_sex'          => null,
        'max_capacity'       => 10,
        'capacity_published' => 1,
        'booking_note'       => null,
        'model_join_enabled' => 1,
        'status'             => 'scheduled',
    ], $overrides);
};

$email = [
    'subject' => 'Test subject',
    'summary' => 'Test summary',
    'detail'  => 'View details and join: {url}',
    'footer'  => 'Test footer',
];

try {
    echo "\n1. capacity_display — published (legacy default)\n";
    check('int 1 shows the number', capacity_display(['capacity_published' => 1, 'max_capacity' => 7]) === '7');
    check('string "1" shows the number', capacity_display(['capacity_published' => '1', 'max_capacity' => '7']) === '7');
    check('column absent (pre-migration row) shows the number', capacity_display(['max_capacity' => 12]) === '12');

    echo "\n2. capacity_display — unpublished\n";
    check('int 0 shows ?', capacity_display(['capacity_published' => 0, 'max_capacity' => 10]) === '?');
    check('string "0" shows ?', capacity_display(['capacity_published' => '0', 'max_capacity' => '10']) === '?');
    check('model_join_open defaults open', model_join_open(['max_capacity' => 7]) === true);
    check('model_join_open respects 0', model_join_open(['model_join_enabled' => 0]) === false);
    check('model_join_open respects "0"', model_join_open(['model_join_enabled' => '0']) === false);

    echo "\n3. (subprocess) --dry-run writes nothing\n";
    $before = counts($db);
    $cmd = sprintf(
        'php %s --date=2099-02-02 --venue=%s --title=%s --notify --dry-run',
        escapeshellarg(LDR_ROOT . '/tools/create-session.php'),
        escapeshellarg('Test Venue'),
        escapeshellarg($marker . ' dryrun')
    );
    exec($cmd, $out, $exitCode);
    $output = implode("\n", $out);
    check('exits 0', $exitCode === 0, "exit {$exitCode}");
    check('no rows written', counts($db) === $before);
    check('previews subject', str_contains($output, 'Subject:'));
    check('previews summary', str_contains($output, 'Summary:'));
    check('previews detail', str_contains($output, 'Detail:'));
    check('previews footer', str_contains($output, 'Footer:'));
    check('previews recipient count', str_contains($output, 'Recipients:'));
    check('link shown as {hex_id} placeholder', str_contains($output, '/sessions/{hex_id}'));

    echo "\n4. (direct) successful create is complete and atomic\n";
    $before = counts($db);
    $recipients = [['id' => $facilitatorId, 'display_name' => 'Test', 'email' => 'test@example.com']];
    $result = ldr_create_session_tx($db, $makeRow(), $recipients, $email);
    $createdIds[] = $result['id'];
    $after = counts($db);
    check('1 session', $after['sessions'] - $before['sessions'] === 1);
    check('1 participant', $after['participants'] - $before['participants'] === 1);
    check('1 provenance row', $after['provenance'] - $before['provenance'] === 1);
    check('1 queue row', $after['queue'] - $before['queue'] === 1);
    $queued = $db->fetch("SELECT detail FROM ld_notification_queue WHERE session_id = ?", [$result['id']]);
    check('queue detail carries the exact post-insert URL',
        $queued && str_contains($queued['detail'], $result['url']) && !str_contains($queued['detail'], '{url}'),
        $queued['detail'] ?? '(no row)');
    $prov = $db->fetch(
        "SELECT action, entity_type FROM provenance_log WHERE entity_id = ? AND entity_type = 'session'",
        [$result['id']]
    );
    check('provenance action is session.create', ($prov['action'] ?? '') === 'session.create');

    echo "\n5. (direct) failure mid-transaction rolls everything back\n";
    $before = counts($db);
    $threw = false;
    try {
        // Fires after session + participant + provenance, before the queue rows —
        // the only position that proves those three are inside the transaction.
        ldr_create_session_tx(
            $db,
            $makeRow(['session_date' => '2099-03-03']),
            $recipients,
            $email,
            fn() => throw new RuntimeException('failpoint')
        );
    } catch (RuntimeException $e) {
        $threw = $e->getMessage() === 'failpoint';
    }
    check('exception propagates', $threw);
    check('all four tables unchanged', counts($db) === $before, json_encode(counts($db)));

    echo "\n6. (subprocess) duplicate is a no-op\n";
    $before = counts($db);
    $cmd = sprintf(
        'php %s --date=2099-01-01 --time=10:00 --venue=%s --title=%s --yes',
        escapeshellarg(LDR_ROOT . '/tools/create-session.php'),
        escapeshellarg('Test Venue'),
        escapeshellarg($marker)
    );
    $out = [];
    exec($cmd, $out, $exitCode);
    $output = implode("\n", $out);
    check('exits 0', $exitCode === 0, "exit {$exitCode}");
    check('reports the existing id', str_contains($output, "id {$createdIds[0]}"), $output);
    check('no rows written', counts($db) === $before);

    echo "\n7. (direct) link construction\n";
    $expected = rtrim(config('app.url'), '/') . '/sessions/' . hex_id($createdIds[0], $marker);
    check('matches {APP_URL}/sessions/{hex_id}', $result['url'] === $expected, "{$result['url']} vs {$expected}");

    echo "\n8. whatsapp_schedule rendering\n";
    $plain = [
        'id' => 1, 'title' => 'Plain', 'session_date' => '2026-08-01',
        'model_sex' => 'f', 'max_capacity' => 7, 'capacity_published' => 1, 'booking_note' => null,
    ];
    $noted = [
        'id' => 2, 'title' => 'Workshop', 'session_date' => '2026-08-07',
        'model_sex' => null, 'max_capacity' => 10, 'capacity_published' => 0, 'booking_note' => 'R150',
    ];
    $withoutNote = whatsapp_schedule([$plain], []);
    $withNote = whatsapp_schedule([$plain, $noted], []);

    check('unnoted session renders as before', str_contains($withoutNote, 'Sat 01 Aug _Plain_ (f) 0/7'));
    check('no footnote when nothing is marked', !str_contains($withoutNote, '[1]'));
    check('noted line carries the note and marker', str_contains($withNote, 'Fri 07 Aug _Workshop_ () 0/? — R150 [1]'));
    check('footnote appended at the foot', str_ends_with($withNote, "\n\n[1] Bookings via other channels also."));
    check('footnote marker is not an asterisk', !str_contains($withNote, 'R150 *'));
    check('unnoted line unchanged by a noted neighbour', str_contains($withNote, 'Sat 01 Aug _Plain_ (f) 0/7'));

    // Ordering: same date, different times — start_time then id must break the tie.
    $ids = [];
    foreach ([['2099-04-04', '15:00:00'], ['2099-04-04', '10:00:00']] as [$d, $t]) {
        ldr_create_session_tx($db, $makeRow(['session_date' => $d, 'start_time' => $t]), [], $email);
        $ids[] = (int) $db->lastInsertId();
    }
    $createdIds = array_merge($createdIds, $ids);
    $ordered = $db->fetchAll(
        "SELECT start_time FROM ld_sessions WHERE session_date = '2099-04-04' AND title = ?
         ORDER BY session_date ASC, start_time ASC, id ASC",
        [$marker]
    );
    check('same-date sessions order by start_time',
        array_column($ordered, 'start_time') === ['10:00:00', '15:00:00'],
        json_encode(array_column($ordered, 'start_time')));

} finally {
    // Queue/provenance first — the queue FK nulls session_id on session delete.
    $markerIds = array_column(
        $db->fetchAll("SELECT id FROM ld_sessions WHERE title LIKE ?", [$marker . '%']),
        'id'
    );
    $allIds = array_values(array_unique(array_merge($createdIds, array_map('intval', $markerIds))));

    if ($allIds) {
        $in = implode(',', array_fill(0, count($allIds), '?'));
        $db->execute("DELETE FROM ld_notification_queue WHERE session_id IN ({$in})", $allIds);
        $db->execute(
            "DELETE FROM provenance_log WHERE entity_type = 'session' AND entity_id IN ({$in})",
            $allIds
        );
        $db->execute("DELETE FROM ld_sessions WHERE id IN ({$in})", $allIds);
    }

    $final = counts($db);
    echo "\nTeardown: removed " . count($allIds) . " test session(s)\n";
    check('database returned to baseline', $final === $baseline,
        json_encode(['baseline' => $baseline, 'final' => $final]));

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed > 0 ? 1 : 0);
}
