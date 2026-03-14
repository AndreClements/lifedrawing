<?php

/**
 * Cleanup expired remember tokens.
 *
 * Run via cron (daily is sufficient):
 *   0 3 * * * php /path/to/tools/cleanup_tokens.php
 */

declare(strict_types=1);

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

$cfg = config('database');
$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset={$cfg['charset']}",
    $cfg['username'],
    $cfg['password'],
);

$stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE expires_at < NOW()");
$stmt->execute();
$deleted = $stmt->rowCount();

if ($deleted > 0) {
    echo date('Y-m-d H:i:s') . " Cleaned up {$deleted} expired remember token(s).\n";
}
