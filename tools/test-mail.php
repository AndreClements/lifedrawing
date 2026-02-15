<?php

/**
 * CLI: Test SMTP email delivery via MailService (HTML + logo).
 *
 * Usage: php tools/test-mail.php recipient@example.com
 */

declare(strict_types=1);

define('LDR_ROOT', dirname(__DIR__));

require LDR_ROOT . '/vendor/autoload.php';

// Load .env for CLI context
if (file_exists(LDR_ROOT . '/.env')) {
    $lines = file(LDR_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (str_contains($line, '=')) {
            putenv($line);
            [$key, $val] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val, '"\'');
        }
    }
}

$to = $argv[1] ?? null;
if (!$to) {
    echo "Usage: php tools/test-mail.php recipient@example.com\n";
    exit(1);
}

$cfg = require LDR_ROOT . '/config/mail.php';
$mailer = new App\Services\MailService(
    host: $cfg['host'],
    port: $cfg['port'],
    username: $cfg['username'],
    password: $cfg['password'],
    fromAddress: $cfg['from_address'],
    fromName: $cfg['from_name'],
    encryption: $cfg['encryption'],
);

$body = "If you are reading this, SMTP is working.\n\nThis email uses the HTML template with the octagram logo.\n\nSent from: " . ($_ENV['APP_URL'] ?? 'unknown') . "\nTime: " . date('Y-m-d H:i:s T');

$ok = $mailer->send($to, 'SMTP Test — Life Drawing Randburg', $body);
echo $ok ? "SUCCESS: Email sent to {$to}\n" : "FAILED: Check error log\n";
