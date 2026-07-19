<?php

declare(strict_types=1);

/**
 * One-time setup: create LDRBot user account.
 * Run on production, then delete this file.
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

$cfg = config('database');
$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4",
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Check if LDRBot already exists
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute(['ldrbot@lifedrawing.andresclements.com']);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    echo "LDRBot already exists with id={$existing['id']}\n";
} else {
    $deadHash = '$2y$10$DEAD.HASH.LDRBOT.CANNOT.LOGIN.EVER.000000000000000000';
    $stmt = $pdo->prepare(
        "INSERT INTO users (display_name, email, password_hash, role, consent_state, consent_granted_at)
         VALUES ('LDRBot', 'ldrbot@lifedrawing.andresclements.com', ?, 'participant', 'granted', NOW())"
    );
    $stmt->execute([$deadHash]);
    echo "Created LDRBot with id=" . $pdo->lastInsertId() . "\n";
}
