<?php

/**
 * CLI: repair the sitter-queue backlog.
 *
 * Usage:
 *   php tools/fix-sitter-queue.php            # dry run — shows what it would do
 *   php tools/fix-sitter-queue.php --execute  # apply
 *
 * Why there is a backlog: sitters are booked through the add-participant
 * typeahead on the session page, which wrote only to ld_session_participants
 * and never touched ld_sitter_queue. So every sitter ever booked that way is
 * still sitting in the queue as 'waiting'. A second, smaller source is the old
 * "No specific session" scheduling option, which left scheduled_session_id NULL
 * where the auto-complete could never match it.
 *
 * The rules live in SitterQueueService::classify(), shared with the live sweep,
 * so this tool and the running site cannot disagree about what should happen.
 *
 * Two things this deliberately does NOT do:
 *
 *   - It sends no email. sitterSessionCompleted() delivers immediately rather
 *     than queueing, so replaying months of history would blast old sitters
 *     with "thanks for posing" notes about sessions they barely remember.
 *   - It does not complete anyone who has an upcoming booking, or who was
 *     marked a no-show. Preserving a future booking beats every other rule.
 *
 * Rerun-safe: classification depends only on current state, so a second
 * --execute run should propose nothing.
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

// classify() compares PHP dates against session dates. Kernel.php is the only
// place the app timezone is set, and this tool never builds one — so from the
// CLI "today" was the US server's date while the live sweep used SAST. Between
// midnight and 09:00 the tool and the site disagreed about the whole queue,
// which is exactly when this work gets done.
date_default_timezone_set('Africa/Johannesburg');

use App\Container;
use App\Database\Connection;
use App\Services\ProvenanceService;
use App\Services\SitterQueueService;

$cfg = config('database');
$db = new Connection(
    host: $cfg['host'],
    database: $cfg['database'],
    username: $cfg['username'],
    password: $cfg['password'],
    port: $cfg['port'] ?? 3306,
    charset: $cfg['charset'] ?? 'utf8mb4',
);

$container = Container::getInstance();
$container->singleton('db', fn() => $db);
$container->singleton('provenance', fn() => new ProvenanceService($db));

$execute = in_array('--execute', $argv, true);
$queue = new SitterQueueService($db);

$entries = $db->fetchAll(
    "SELECT q.*, u.display_name
     FROM ld_sitter_queue q
     JOIN users u ON u.id = q.user_id
     WHERE q.status IN ('waiting', 'scheduled')
     ORDER BY q.requested_at ASC"
);

echo "\n" . ($execute ? 'APPLYING' : 'DRY RUN') . " — sitter queue repair\n";
echo str_repeat('=', 78) . "\n";

if (empty($entries)) {
    echo "Queue is empty. Nothing to do.\n";
    exit(0);
}

$counts = ['complete' => 0, 'reschedule' => 0, 'requeue' => 0, 'skip' => 0];
$changed = 0;

printf("%-5s %-24s %-10s %-11s %s\n", 'ID', 'SITTER', 'STATUS', 'ACTION', 'WHY');
echo str_repeat('-', 78) . "\n";

foreach ($entries as $entry) {
    $decision = $queue->classify($entry);
    $action = $decision['action'];
    $counts[$action] = ($counts[$action] ?? 0) + 1;

    printf(
        "%-5d %-24s %-10s %-11s %s\n",
        (int) $entry['id'],
        mb_substr((string) $entry['display_name'], 0, 24),
        $entry['status'],
        $action,
        $decision['reason']
    );

    if ($execute && $action !== 'skip') {
        // notify: false — see the header. Old history must not generate mail.
        if ($queue->apply((int) $entry['id'], null, false)) {
            $changed++;
        }
    }
}

echo str_repeat('-', 78) . "\n";
printf(
    "complete: %d   reschedule: %d   requeue: %d   leave alone: %d\n",
    $counts['complete'] ?? 0,
    $counts['reschedule'] ?? 0,
    $counts['requeue'] ?? 0,
    $counts['skip'] ?? 0
);

if ($execute) {
    echo "Applied {$changed} change(s). No email was sent.\n";

    $rejoined = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM ld_sitter_queue
         WHERE status = 'waiting' AND requested_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
    );
    if ($rejoined > 0) {
        echo "{$rejoined} sitter(s) with auto-rejoin were put back at the end of the queue.\n";
    }

    echo "\nRun this again — it should now propose nothing but 'leave alone'.\n";
    echo "Once it does, turn on APP_SITTER_AUTO_COMPLETE so the live sweep takes over.\n";
} else {
    echo "\nDry run. Re-run with --execute to apply.\n";
}
