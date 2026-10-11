<?php

declare(strict_types=1);

/**
 * Private session notes for assisted uploads. Preview is the default.
 * php tools/session-notes.php --session=123 --actor=1 --text="Loo break during pose 3"
 * php tools/session-notes.php --session=123 --actor=1 --kind=break --participant=456 --minutes=5 --text="Loo break" --request-key=YOUR_STABLE_REQUEST_KEY --apply
 * php tools/session-notes.php --session=123 --actor=1 --attendance=no_show --participant=456 --request-key=YOUR_STABLE_REQUEST_KEY --apply
 * php tools/session-notes.php --session=123 --actor=1 --list
 */
define('LDR_ROOT', dirname(__DIR__));
require LDR_ROOT . '/vendor/autoload.php';
foreach (is_file(LDR_ROOT . '/.env') ? file(LDR_ROOT . '/.env', FILE_IGNORE_NEW_LINES) : [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) continue;
    [$key, $value] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
}
date_default_timezone_set('Africa/Johannesburg');
require __DIR__ . '/local-test-environment.php';
$options = getopt('', ['session:', 'actor:', 'text:', 'participant:', 'kind:', 'minutes:', 'pose:', 'attendance:', 'request-key:', 'apply', 'dry-run', 'list', 'help']);
if (isset($options['help']) || !isset($options['session'], $options['actor'])) {
    echo "Usage: php tools/session-notes.php --session=ID --actor=ID --text=\"note\" [--participant=PARTICIPATION_ID --kind=break --minutes=5 --pose=3]\n";
    echo "Attendance: --attendance=no_show|restore --participant=PARTICIPATION_ID\n";
    echo "Preview by default. To save: --request-key=STABLE_KEY_AT_LEAST_16_CHARS --apply. Read: --list\n";
    exit(isset($options['help']) ? 0 : 1);
}
try {
    $cfg = config('database');
    $db = new \App\Database\Connection($cfg['host'], $cfg['database'], $cfg['username'], $cfg['password'], $cfg['port']);
    $notes = new \App\Services\SessionNotesService($db);
    $sessionId = (int) $options['session'];
    $actorId = (int) $options['actor'];
    if (isset($options['list'])) {
        echo json_encode($notes->listing($sessionId, $actorId), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }
    $data = ['kind' => $options['kind'] ?? 'note', 'text' => $options['text'] ?? '',
        'participant_id' => $options['participant'] ?? null, 'minutes' => $options['minutes'] ?? null, 'pose_number' => $options['pose'] ?? null];
    $action = $options['attendance'] ?? null;
    $preview = $notes->preview($sessionId, $actorId, $data, $action);
    if (!isset($options['apply']) || isset($options['dry-run'])) {
        echo "DRY RUN; nothing saved.\n" . json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }
    $key = \App\Services\SessionNotesService::requestKey($options['request-key'] ?? '');
    if ($action !== null) {
        $result = $notes->attendance($sessionId, $actorId, (int) $data['participant_id'], $action, $preview['from'], $key);
        echo ($result['warning'] ?? 'Attendance saved.') . PHP_EOL;
    } else {
        $result = $notes->create($sessionId, $actorId, $data, $key);
        echo $result['deleted_at'] !== null ? "This request was already saved and subsequently removed; it has not been recreated.\n" : 'Note #' . $result['id'] . " saved.\n";
    }
    $saved = $db->fetch("SELECT id, kind, deleted_at FROM ld_session_log WHERE session_id = ? AND request_key = ?", [$sessionId, $key]);
    if (!$saved) throw new RuntimeException('Could not verify the saved entry.');
    echo "Verified entry #" . $saved['id'] . ".\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
