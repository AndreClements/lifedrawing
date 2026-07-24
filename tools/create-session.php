<?php

declare(strict_types=1);

/**
 * CLI Session Creator — for sessions the web form cannot express.
 *
 * The create form sets neither capacity nor the listing flags, so off-pattern
 * sessions (external venue, withheld capacity, sitters booked elsewhere) have
 * to be made here. Mirrors SessionController::store(): session row, facilitator
 * participant, provenance entry, and — with --notify — the new-session emails.
 *
 * Unlike store(), all four writes share one transaction. A half-created session
 * is worse than none: the duplicate guard would then refuse a clean retry.
 *
 * Usage:
 *   php tools/create-session.php --date=2026-08-07 --venue="..." [options]
 *   php tools/create-session.php ... --dry-run     # preview, writes nothing
 *   php tools/create-session.php ... --notify --yes
 *
 * And-Yet: the notification subject/summary/detail/footer are copied from
 * NotificationService::sessionCreated(), which cannot be called here — it
 * resolves route() through app('router'), and Kernel::boot() is private, so
 * CLI has no router. The two can drift; changing one means changing the other.
 * Likewise, provenance and queue rows are inserted directly rather than via
 * ProvenanceService/NotificationService, whose catch-and-continue contract
 * ("never break the main flow") would silently swallow exactly the failures
 * this transaction exists to catch.
 */

// defined() guard: the test tool requires this file after defining LDR_ROOT itself.
defined('LDR_ROOT') || define('LDR_ROOT', dirname(__DIR__));

// Load .env file if present
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

use App\Database\Connection;

/**
 * The transactional write. Everything before this is validation and preview.
 *
 * The session URL can only be built after the insert — the id comes from
 * AUTO_INCREMENT, and guessing it ahead of time is a race — so the queue rows
 * are composed in here, not by the caller.
 *
 * @param  array         $row        Column => value for ld_sessions
 * @param  array         $recipients Rows of id, display_name, email
 * @param  array         $email      subject, summary, detail (with {url}), footer
 * @param  callable|null $failpoint  Test-only: throws mid-transaction
 * @return array{id:int,participants:int,provenance:int,queued:int,url:string}
 */
function ldr_create_session_tx(
    Connection $db,
    array $row,
    array $recipients,
    array $email,
    ?callable $failpoint = null,
): array {
    return $db->transaction(function (Connection $db) use ($row, $recipients, $email, $failpoint) {
        $columns = implode(', ', array_keys($row));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        $db->execute(
            "INSERT INTO ld_sessions ({$columns}) VALUES ({$placeholders})",
            array_values($row)
        );
        $sessionId = (int) $db->lastInsertId();

        $url = rtrim(config('app.url'), '/') . '/sessions/'
             . hex_id($sessionId, session_title($row + ['id' => $sessionId]));

        $participants = $db->execute(
            "INSERT INTO ld_session_participants (session_id, user_id, role, attended)
             VALUES (?, ?, 'facilitator', 1)",
            [$sessionId, $row['facilitator_id']]
        );

        $provenance = $db->execute(
            "INSERT INTO provenance_log (user_id, action, entity_type, entity_id, context, ip_address)
             VALUES (?, 'session.create', 'session', ?, ?, 'cli')",
            [
                $row['facilitator_id'],
                $sessionId,
                json_encode([
                    'title'  => $row['title'],
                    'date'   => $row['session_date'],
                    'venue'  => $row['venue'],
                    'source' => 'tools/create-session.php',
                ]),
            ]
        );

        if ($failpoint) $failpoint();

        $queued = 0;
        foreach ($recipients as $user) {
            $queued += $db->execute(
                "INSERT INTO ld_notification_queue
                 (recipient_id, recipient_name, recipient_email, notification_type,
                  session_id, subject, summary, detail, footer)
                 VALUES (?, ?, ?, 'sessionCreated', ?, ?, ?, ?, ?)",
                [
                    (int) $user['id'],
                    $user['display_name'],
                    $user['email'],
                    $sessionId,
                    $email['subject'],
                    $email['summary'],
                    str_replace('{url}', $url, $email['detail']),
                    $email['footer'],
                ]
            );
        }

        return [
            'id'           => $sessionId,
            'participants' => $participants,
            'provenance'   => $provenance,
            'queued'       => $queued,
            'url'          => $url,
        ];
    });
}

// Loaded by the test tool? Stop here — everything below is the CLI itself.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== realpath(__FILE__)) {
    return;
}

// --- Parse CLI flags ---

const USAGE = <<<TXT
Usage: php tools/create-session.php [options]

Required:
  --date=YYYY-MM-DD          Session date
  --venue="..."              Venue name

Optional:
  --title="..."              Explicit title (default: rotating axiom)
  --subtitle="..."           Default: "Regular Session Format"
  --time=HH:MM               Start time (default: none)
  --duration=N               Minutes, 30-480 (default: 180)
  --capacity=N               1-255 (default: 7)
  --no-publish-capacity      Show "?" instead of the capacity number
  --booking-note="..."       Appended to the WhatsApp line, marked [1]
  --no-model-join            Close the public "Join as Model" route
  --model-sex=f|m            Single-figure sessions only
  --facilitator=N            User id, must be admin/facilitator (default: 1)
  --description-file=PATH    Description ("-" reads STDIN)
  --notify                   Queue new-session emails to opted-in users
  --dry-run                  Preview everything; write nothing
  --yes                      Skip the confirmation prompt

TXT;

function fail(string $message): never
{
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

$opts = [
    'date' => null, 'venue' => null, 'title' => null,
    'subtitle' => 'Regular Session Format', 'time' => null, 'duration' => '180',
    'capacity' => '7', 'booking-note' => null, 'model-sex' => null,
    'facilitator' => '1', 'description-file' => null,
];
$flags = ['no-publish-capacity' => false, 'no-model-join' => false,
          'notify' => false, 'dry-run' => false, 'yes' => false];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') { echo USAGE; exit(0); }

    if (preg_match('/^--([a-z-]+)=(.*)$/s', $arg, $m) && array_key_exists($m[1], $opts)) {
        $opts[$m[1]] = $m[2];
        continue;
    }
    if (str_starts_with($arg, '--') && array_key_exists(substr($arg, 2), $flags)) {
        $flags[substr($arg, 2)] = true;
        continue;
    }
    fwrite(STDERR, "Error: unknown or malformed argument '{$arg}'\n\n" . USAGE);
    exit(1);
}

// --- Validate ---

if (!$opts['date']) fail('--date is required.');
$parsed = DateTime::createFromFormat('Y-m-d', $opts['date']);
if (!$parsed || $parsed->format('Y-m-d') !== $opts['date']) {
    fail("--date must be an exact Y-m-d date, got '{$opts['date']}'.");
}

$time = null;
if ($opts['time'] !== null && $opts['time'] !== '') {
    foreach (['H:i:s', 'H:i'] as $format) {
        $t = DateTime::createFromFormat($format, $opts['time']);
        if ($t && $t->format($format) === $opts['time']) {
            $time = $t->format('H:i:s');
            break;
        }
    }
    if ($time === null) fail("--time must be H:i or H:i:s, got '{$opts['time']}'.");
}

$venue = trim((string) $opts['venue']);
if ($venue === '') fail('--venue is required and cannot be empty.');
if (mb_strlen($venue) > 200) fail('--venue exceeds 200 characters.');

$duration = (int) $opts['duration'];
if ($duration < 30 || $duration > 480) fail('--duration must be 30-480 minutes.');

$capacity = (int) $opts['capacity'];
if ($capacity < 1 || $capacity > 255) fail('--capacity must be 1-255 (TINYINT UNSIGNED).');

$title = $opts['title'] !== null ? trim($opts['title']) : null;
if ($title !== null && mb_strlen($title) > 200) fail('--title exceeds 200 characters.');

$subtitle = trim((string) $opts['subtitle']);
if (mb_strlen($subtitle) > 200) fail('--subtitle exceeds 200 characters.');

$bookingNote = $opts['booking-note'] !== null ? trim($opts['booking-note']) : null;
if ($bookingNote !== null && mb_strlen($bookingNote) > 255) fail('--booking-note exceeds 255 characters.');

$modelSex = $opts['model-sex'] !== null ? trim($opts['model-sex']) : null;
if ($modelSex !== null && $modelSex !== '' && !in_array($modelSex, ['f', 'm'], true)) {
    fail("--model-sex must be 'f' or 'm'.");
}
if ($modelSex === '') $modelSex = null;

$description = null;
if ($opts['description-file'] !== null) {
    $path = $opts['description-file'];
    $description = $path === '-' ? stream_get_contents(STDIN) : @file_get_contents($path);
    if ($description === false) fail("Cannot read description file '{$path}'.");
    $description = trim($description);
    if ($description === '') $description = null;
}

// --- Connect ---

$cfg = config('database');
$db = new Connection(
    host: $cfg['host'],
    database: $cfg['database'],
    username: $cfg['username'],
    password: $cfg['password'],
    port: (int) ($cfg['port'] ?? 3306),
);

$facilitatorId = (int) $opts['facilitator'];
$facilitator = $db->fetch(
    "SELECT id, display_name, role FROM users WHERE id = ?",
    [$facilitatorId]
);
if (!$facilitator) fail("Facilitator user {$facilitatorId} does not exist.");
if (!in_array($facilitator['role'], ['admin', 'facilitator'], true)) {
    fail("User {$facilitatorId} ({$facilitator['display_name']}) has role '{$facilitator['role']}', not admin/facilitator.");
}

// --- Duplicate guard (null-safe on time: NULL = NULL is never true in SQL) ---

$existing = $db->fetch(
    "SELECT id FROM ld_sessions
     WHERE session_date = ? AND start_time <=> ? AND venue = ? AND title <=> ?",
    [$opts['date'], $time, $venue, $title]
);
if ($existing) {
    echo "Session already exists (id {$existing['id']}) for {$opts['date']} at {$venue}. Nothing to do.\n";
    exit(0);
}

// --- Build the row ---

$row = [
    'title'              => $title,
    'subtitle'           => $subtitle ?: 'Regular Session Format',
    'session_date'       => $opts['date'],
    'start_time'         => $time,
    'duration_minutes'   => $duration,
    'venue'              => $venue,
    'description'        => $description,
    'facilitator_id'     => $facilitatorId,
    'model_sex'          => $modelSex,
    'max_capacity'       => $capacity,
    'capacity_published' => $flags['no-publish-capacity'] ? 0 : 1,
    'booking_note'       => $bookingNote,
    'model_join_enabled' => $flags['no-model-join'] ? 0 : 1,
    'status'             => 'scheduled',
];

// --- Recipients + email (copied from NotificationService::sessionCreated) ---

$recipients = [];
if ($flags['notify']) {
    $recipients = $db->fetchAll(
        "SELECT id, email, display_name FROM users
         WHERE notify_new_session = 1 AND id != ? AND email NOT LIKE '%.stub@local'",
        [$facilitatorId]
    );
}

$displayTitle = session_title($row + ['id' => 0]);
$baseUrl = rtrim(config('app.url'), '/');
$email = [
    'subject' => "New Session: {$displayTitle} — " . format_date($opts['date']),
    'summary' => "A new drawing session has been scheduled:\n\n{$displayTitle}\n"
               . format_date($opts['date']) . " at {$venue}",
    'detail'  => 'View details and join: {url}',
    'footer'  => "You're receiving this because you opted in to new session notifications.\n"
               . "Update your preferences: {$baseUrl}/profile/edit",
];

// --- Preview ---

echo "\n=== SESSION ===\n";
foreach ($row as $key => $value) {
    printf("  %-19s %s\n", $key, $value === null ? '(null)' : (string) $value);
}
echo "  " . str_pad('capacity shows as', 19) . ' ' . capacity_display($row) . "\n";
echo "  " . str_pad('title shows as', 19) . " {$displayTitle}\n";
echo "  " . str_pad('url will be', 19) . " {$baseUrl}/sessions/{hex_id}\n";

echo "\n=== NOTIFICATIONS ===\n";
if (!$flags['notify']) {
    echo "  Disabled (--notify not given). No emails will be queued.\n";
} else {
    echo "  Recipients: " . count($recipients)
       . " (notify_new_session=1, excluding user {$facilitatorId} and .stub@local)\n";
    echo "  Subject: {$email['subject']}\n";
    echo "  Summary: " . str_replace("\n", "\n           ", $email['summary']) . "\n";
    echo "  Detail:  " . str_replace('{url}', "{$baseUrl}/sessions/{hex_id}", $email['detail']) . "\n";
    echo "  Footer:  " . str_replace("\n", "\n           ", $email['footer']) . "\n";
    if ($recipients) {
        echo "  To: " . implode(', ', array_column($recipients, 'email')) . "\n";
    }
}

if ($flags['dry-run']) {
    echo "\nDry run — nothing written.\n";
    exit(0);
}

// --- Confirmation gate: the last point at which nothing has been written ---

if (!$flags['yes']) {
    if (!stream_isatty(STDIN)) {
        fail('Refusing to write without confirmation. Re-run with --yes (or --dry-run to preview).');
    }
    echo "\nCreate this session" . ($flags['notify'] ? ' and queue ' . count($recipients) . ' emails' : '') . "? [y/N] ";
    $answer = trim((string) fgets(STDIN));
    if (strtolower($answer) !== 'y') {
        echo "Aborted. Nothing written.\n";
        exit(0);
    }
}

// --- Write ---

try {
    $result = ldr_create_session_tx($db, $row, $recipients, $email);
} catch (Throwable $e) {
    fail('Transaction rolled back, nothing written: ' . $e->getMessage());
}

echo "\nCreated session {$result['id']}\n";
echo "  {$result['url']}\n";
echo "  participants: {$result['participants']}, provenance: {$result['provenance']}, queued: {$result['queued']}\n";
