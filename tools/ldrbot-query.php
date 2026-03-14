<?php

declare(strict_types=1);

/**
 * LDRBot Query — gather claimed artworks for feedback generation.
 *
 * Queries a session's artist-claimed artworks with metadata needed
 * for Claude to view images and write feedback. Outputs JSON to stdout.
 *
 * Usage:
 *   php tools/ldrbot-query.php                  — Yesterday's session
 *   php tools/ldrbot-query.php --date=2026-03-13 — Specific date
 *   php tools/ldrbot-query.php --session=297     — Specific session ID
 *
 * And-Yet: This tool only gathers data. The feedback itself is written
 * by a human-in-the-loop (Claude viewing each image). The tool does not
 * generate, evaluate, or post anything.
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

// --- LDRBot user ID (set after creating the account) ---

const LDRBOT_USER_ID = 256;

// --- DB connection ---

$cfg = config('database');
$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4",
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// --- Parse CLI flags ---

$targetDate = null;
$targetSession = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--date=')) {
        $targetDate = substr($arg, 7);
    }
    if (str_starts_with($arg, '--session=')) {
        $targetSession = (int) substr($arg, 10);
    }
}

// --- Find session ---

if ($targetSession) {
    $session = $pdo->prepare(
        "SELECT id, title, session_date, subtitle, model_sex
         FROM ld_sessions WHERE id = ?"
    );
    $session->execute([$targetSession]);
    $session = $session->fetch(PDO::FETCH_ASSOC);
} else {
    $date = $targetDate ?? date('Y-m-d', strtotime('-1 day'));
    $session = $pdo->prepare(
        "SELECT id, title, session_date, subtitle, model_sex
         FROM ld_sessions WHERE session_date = ? LIMIT 1"
    );
    $session->execute([$date]);
    $session = $session->fetch(PDO::FETCH_ASSOC);
}

if (!$session) {
    fwrite(STDERR, "No session found.\n");
    exit(1);
}

$sessionId = (int) $session['id'];

// Resolve title (axiom fallback for NULL titles)
$sessionTitle = $session['title'] ?? session_title($session);

// --- Build base URL for image links ---

$parsed = parse_url(config('app.url', 'http://localhost'));
$origin = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? 'localhost')
    . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
$basePath = rtrim($parsed['path'] ?? '', '/');
$uploadBase = $origin . $basePath . '/assets/uploads/';

// --- Fetch artist-claimed artworks ---

$stmt = $pdo->prepare(
    "SELECT a.id, a.pose_duration, a.pose_label, a.pose_index,
            a.file_path, a.web_path, a.thumbnail_path, a.created_at,
            ca.claimant_id as artist_id, ua.display_name as artist_name,
            (SELECT um.display_name
             FROM ld_claims cm
             JOIN users um ON cm.claimant_id = um.id
             WHERE cm.artwork_id = a.id AND cm.claim_type = 'model'
               AND cm.status = 'approved'
             LIMIT 1) as model_name,
            (SELECT COUNT(*) FROM ld_comments c WHERE c.artwork_id = a.id) as comment_count,
            (SELECT COUNT(*) FROM ld_comments c
             WHERE c.artwork_id = a.id AND c.user_id = ?) as bot_comment_count
     FROM ld_artworks a
     JOIN ld_claims ca ON ca.artwork_id = a.id
       AND ca.claim_type = 'artist' AND ca.status = 'approved'
     JOIN users ua ON ca.claimant_id = ua.id
     WHERE a.session_id = ?
     ORDER BY a.pose_index ASC, a.created_at ASC"
);
$stmt->execute([LDRBOT_USER_ID, $sessionId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    fwrite(STDERR, "No artist-claimed artworks found for session #{$sessionId}.\n");
    exit(1);
}

// --- Build output ---

$artworks = [];
$artistsWork = [];

foreach ($rows as $row) {
    $artId = (int) $row['id'];
    $artistId = (int) $row['artist_id'];

    // Track per-artist artwork IDs
    $artistsWork[$artistId][] = $artId;

    // Prefer web_path (2000px WebP) for AI viewing, fallback to file_path
    $imagePath = $row['web_path'] ?? $row['file_path'];
    $thumbPath = $row['thumbnail_path'] ?? $row['web_path'] ?? $row['file_path'];

    $artworks[] = [
        'id' => $artId,
        'hex_id' => dechex($artId),
        'pose_duration' => $row['pose_duration'] ? (int) $row['pose_duration'] : null,
        'pose_label' => $row['pose_label'],
        'pose_index' => $row['pose_index'] ? (int) $row['pose_index'] : null,
        'image_url' => $imagePath ? $uploadBase . $imagePath : null,
        'thumbnail_url' => $thumbPath ? $uploadBase . $thumbPath : null,
        'artist' => [
            'id' => $artistId,
            'name' => $row['artist_name'],
        ],
        'model_name' => $row['model_name'],
        'existing_comments' => (int) $row['comment_count'],
        'has_bot_comment' => (int) $row['bot_comment_count'] > 0,
        'created_at' => $row['created_at'],
    ];
}

$output = [
    'session' => [
        'id' => $sessionId,
        'date' => $session['session_date'],
        'title' => $sessionTitle,
        'subtitle' => $session['subtitle'],
        'model_sex' => $session['model_sex'],
    ],
    'artworks' => $artworks,
    'artists_session_work' => $artistsWork,
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
