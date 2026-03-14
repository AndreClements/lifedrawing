<?php

declare(strict_types=1);

/**
 * CLI Notification Flusher.
 *
 * Finds queued notifications (ld_notification_queue) where the oldest
 * pending notification for a recipient is >= 5 minutes old, then:
 *   - Single notification: sends as standalone email
 *   - Multiple notifications: merges into a digest email
 *
 * Designed to run as a cron job every 2 minutes.
 * Uses flock to prevent overlapping cron runs.
 *
 * Usage:
 *   php tools/flush_notifications.php
 */

define('LDR_ROOT', dirname(__DIR__));

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

// --- Flock: prevent overlapping cron runs ---

$lockFile = LDR_ROOT . '/storage/flush_notifications.lock';
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0755, true);
}
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

// --- DB connection (direct PDO — no Container needed for CLI) ---

$cfg = config('database');
$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4",
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// --- Mail service ---

$mailCfg = config('mail');
$mail = new \App\Services\MailService(
    host: $mailCfg['host'],
    port: $mailCfg['port'],
    username: $mailCfg['username'],
    password: $mailCfg['password'],
    fromAddress: $mailCfg['from_address'],
    fromName: $mailCfg['from_name'],
    encryption: $mailCfg['encryption'],
);

// --- Preferences link for digest footer ---

$parsed = parse_url(config('app.url', 'http://localhost'));
$baseUrl = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? 'localhost')
    . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
$basePath = rtrim($parsed['path'] ?? '', '/');
$prefsLink = $baseUrl . $basePath . '/profile/edit';

// --- Type labels for digest rendering ---

const TYPE_LABELS = [
    'sessionCreated'    => 'NEW SESSION',
    'claimResolved'     => 'CLAIM UPDATE',
    'claimSubmitted'    => 'NEW CLAIM',
    'stubClaimed'       => 'STUB CLAIMED',
    'sitterQueueJoined' => 'SITTER QUEUE',
    'artworkCommented'  => 'NEW COMMENT',
];

// --- Find recipients ready to flush ---

$readyRecipients = $pdo->query(
    "SELECT recipient_id, recipient_email, MIN(created_at) as oldest
     FROM ld_notification_queue
     WHERE sent_at IS NULL
     GROUP BY recipient_id, recipient_email
     HAVING oldest <= NOW() - INTERVAL 5 MINUTE"
)->fetchAll(PDO::FETCH_ASSOC);

if (empty($readyRecipients)) {
    logLine("No notifications to flush.");
    cleanup($pdo);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

logLine(count($readyRecipients) . " recipient(s) ready to flush.");

$sent = 0;
$failed = 0;

$fetchStmt = $pdo->prepare(
    "SELECT * FROM ld_notification_queue
     WHERE sent_at IS NULL AND recipient_id = ?
     ORDER BY created_at ASC"
);

$markStmt = $pdo->prepare(
    "UPDATE ld_notification_queue
     SET sent_at = NOW(), batch_id = ?
     WHERE id = ?"
);

$resetStmt = $pdo->prepare(
    "UPDATE ld_notification_queue
     SET sent_at = NULL, batch_id = NULL
     WHERE batch_id = ?"
);

foreach ($readyRecipients as $recipient) {
    $recipientId = (int) $recipient['recipient_id'];
    $fetchStmt->execute([$recipientId]);
    $notifications = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($notifications)) continue;

    $batchId = bin2hex(random_bytes(18));
    $recipientName = $notifications[0]['recipient_name'];
    $recipientEmail = $notifications[0]['recipient_email'];
    $count = count($notifications);

    // Mark as sent BEFORE sending (prevents duplicates on crash)
    foreach ($notifications as $n) {
        $markStmt->execute([$batchId, (int) $n['id']]);
    }

    if ($count === 1) {
        // Standalone: send with original subject, structured body
        $n = $notifications[0];
        $body = renderStandalone($recipientName, $n['summary'], $n['detail'], $n['footer']);
        $subject = $n['subject'];
    } else {
        // Digest: merge all notifications
        $body = renderDigest($recipientName, $notifications, $prefsLink);
        $subject = "Life Drawing Randburg: {$count} updates";
    }

    $ok = $mail->send($recipientEmail, $subject, $body);

    if ($ok) {
        $sent += $count;
        logLine("  OK: {$count} notification(s) to {$recipientEmail} (batch {$batchId})");
    } else {
        // Reset sent_at so they retry next run
        $resetStmt->execute([$batchId]);
        $failed += $count;
        logLine("  FAIL: SMTP failed for {$recipientEmail}, will retry (batch {$batchId})");
    }
}

logLine("Done: {$sent} sent, {$failed} failed.");

// --- Cleanup old sent notifications ---
cleanup($pdo);

// Release lock
flock($lock, LOCK_UN);
fclose($lock);

// --- Render functions ---

function renderStandalone(string $name, string $summary, ?string $detail, ?string $footer): string
{
    $body = "Hi {$name},\n\n{$summary}";

    if ($detail) {
        $body .= "\n\n{$detail}";
    }

    $body .= "\n\n— Life Drawing Randburg";

    if ($footer) {
        $body .= "\n\n{$footer}";
    }

    return $body;
}

function renderDigest(string $name, array $notifications, string $prefsLink): string
{
    // Group by notification_type for readability
    $grouped = [];
    foreach ($notifications as $n) {
        $type = $n['notification_type'];
        $grouped[$type][] = $n;
    }

    $body = "Hi {$name},\n\n"
        . "Here's a summary of recent activity:\n";

    foreach ($grouped as $type => $items) {
        $label = TYPE_LABELS[$type] ?? strtoupper($type);
        foreach ($items as $item) {
            $body .= "\n---\n";
            $body .= "[{$label}] {$item['summary']}";
            if ($item['detail']) {
                $body .= "\n{$item['detail']}";
            }
            $body .= "\n";
        }
    }

    $body .= "\n---\n\n— Life Drawing Randburg\n\n"
        . "Update your notification preferences: {$prefsLink}";

    return $body;
}

function cleanup(PDO $pdo): void
{
    $deleted = $pdo->exec(
        "DELETE FROM ld_notification_queue
         WHERE sent_at IS NOT NULL AND sent_at < NOW() - INTERVAL 30 DAY"
    );
    if ($deleted > 0) {
        logLine("Cleanup: removed {$deleted} old notification(s).");
    }
}

function logLine(string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] {$message}\n";
}
