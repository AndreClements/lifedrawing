<?php

/**
 * CLI: Import historical session data from the Google Sheets CSV export.
 *
 * Usage: php tools/import-csv.php [path-to-csv]
 *
 * Creates stub user accounts for all participants (no login — placeholder email).
 * Creates sessions with participants from the bookings column.
 * André (user 1) is facilitator for all sessions.
 * Refreshes all stats after import.
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

$dryRun = in_array('--dry-run', $argv, true);

$db = null;
if (!$dryRun) {
    $config = require LDR_ROOT . '/config/database.php';
    $db = new App\Database\Connection(
        host: $config['host'],
        database: $config['database'],
        username: $config['username'],
        password: $config['password'],
    );
}
$csvPath = null;
foreach ($argv as $i => $arg) {
    if ($i > 0 && $arg !== '--dry-run') {
        $csvPath = $arg;
        break;
    }
}
$csvPath = $csvPath ?? LDR_ROOT . '/docs/Lifedrawing log - Sheet1.csv';
if (!file_exists($csvPath)) {
    echo "CSV file not found: {$csvPath}\n";
    exit(1);
}

// Dead password hash — stub accounts can't log in
$stubHash = '$2y$12$STUB.ACCOUNT.CANNOT.LOGIN.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

// Parse CSV
$rows = [];
$handle = fopen($csvPath, 'r');
$header = null;
while (($line = fgetcsv($handle)) !== false) {
    $rows[] = $line;
}
fclose($handle);

// Column indices (from header row 3):
// 2=Day, 3=date, 4=gender, 5=model, 6=Label, 7=Photos, 8=Notes, 11=bookings
$COL_DAY = 2;
$COL_DATE = 3;
$COL_SEX = 4;
$COL_MODEL = 5;
$COL_NOTES = 8;
$COL_BOOKINGS = 11;

// Name normalization map (handle common variations)
$nameMap = [
    'andré c' => 'André Clements',
    'andrec' => 'André Clements',
    'andré' => 'André Clements',
    'andré vh' => 'André vH',
    'andrevh' => 'André vH',
    'sadé-s' => 'Sadé',
    'sadé' => 'Sadé',
    'sade' => 'Sadé',
    'sade-s' => 'Sadé',
    'tyler b' => 'Tyler B',
    'tylerb' => 'Tyler B',
    'tyler-b' => 'Tyler B',
    'tyler-o' => 'Tyler O',
    'tyler o' => 'Tyler O',
    'johan-b' => 'Johan B',
    'johan b' => 'Johan B',
    'andrew b' => 'Andrew B',
    'andrew b ?' => 'Andrew B',
    'miss bloss' => 'Miss Bloss',
    'miss oh' => 'Miss Oh',
    'nkuli ' => 'Nkuli',
    'nkuli' => 'Nkuli',
    'thuli' => 'Thuli',
    'thulisile' => 'Thulisile',
    'loreål' => 'Loreål',
    'loreal' => 'Loreål',
    'lal' => 'Lalage',
    'lalalge' => 'Lalage',
    'lalagé' => 'Lalage',
    'ellie' => 'Elli',
    'elli' => 'Elli',
    'cesney' => 'Chesney',
    'chesney' => 'Chesney',
    'zandie' => 'Zandie',
    'zandi' => 'Zandie',
    'zandri' => 'Zandie',
    'nics' => 'Nics',
    'nicola' => 'Nicola',
    'tim (ct)' => 'Tim',
    'tim' => 'Tim',
    'sandra ?' => 'Sandra',
    'zandie ?' => 'Zandie',
    'ofentse?' => 'Ofentse',
    'karabo' => 'Karabo',
    'kayla?' => 'Kayla',
    'callyne?' => 'Callyne',
    'kevin?' => 'Kevin',
    'cat\'s mom' => 'Cat\'s Mom',
    'jude/judith' => 'Jude',
    'lucia/zuki' => 'Lucia',
    'hellen' => 'Helen',
    'helen' => 'Helen',
    'ru-anne' => 'Ru-Ann',
    'ru-ann' => 'Ru-Ann',
    'callyne (andré)' => 'Callyne',
    'lourens & petrus' => null, // skip dual — handled specially
    'heinrich corienne' => null, // skip dual — handled specially

    // Data artifacts / non-person entries
    '9 jan' => null,
    'am' => null,
    'th' => null,
    'tbc' => null,
    'cancelled' => null,
    'postponed' => null,

    // Capacity notation
    'annette 7/7' => 'Annette',
    'david 3/7' => 'David',
    'kevin 2/7' => 'Kevin',
    'myra 7/7' => 'Myra',

    // Missing variants
    'andréc' => 'André Clements',
    'anette' => 'Annette',
    'catherina' => 'Catharina',
    'yamillah' => 'Yamilah',
    'luci' => 'Lucia',

    // Annotated names
    'alex (m) sundays' => 'Alex',
    'ofentse (ah m)' => 'Ofentse',

    // Special characters
    'corniël' => 'Corniël',
    'marie-louise' => 'Marie-Louise',

    // Full names
    'genevieve rathbone' => 'Genevieve Rathbone',
    'shera goldberg' => 'Shera Goldberg',
];

function normalizeName(string $raw): ?string
{
    global $nameMap;
    $name = trim($raw, " \t\n\r?");
    if ($name === '' || $name === '?') return null;

    $lower = mb_strtolower($name);
    if (array_key_exists($lower, $nameMap)) {
        return $nameMap[$lower];
    }

    // Strip capacity notation (e.g. "Name 3/7" → "Name")
    $stripped = preg_replace('/\s+\d+\/\d+$/', '', $name);
    if ($stripped !== $name) {
        $lower2 = mb_strtolower($stripped);
        if (array_key_exists($lower2, $nameMap)) {
            return $nameMap[$lower2];
        }
        return mb_convert_case($stripped, MB_CASE_TITLE);
    }

    // Title case the name
    return mb_convert_case($name, MB_CASE_TITLE);
}

// Track users by normalized name => user_id
$userCache = [];
$andreId = null;

function getOrCreateUser(string $name): int
{
    global $db, $stubHash, $userCache, $andreId;

    if ($name === 'André Clements') {
        if ($andreId === null) {
            $andreId = (int) $db->fetchColumn("SELECT id FROM users WHERE display_name = 'André Clements' LIMIT 1");
        }
        return $andreId;
    }

    if (isset($userCache[$name])) {
        return $userCache[$name];
    }

    // Check if exists
    $existing = $db->fetchColumn(
        "SELECT id FROM users WHERE display_name = ? LIMIT 1",
        [$name]
    );

    if ($existing) {
        $userCache[$name] = (int) $existing;
        return (int) $existing;
    }

    // Create stub account
    $slug = preg_replace('/[^a-z0-9]+/', '.', strtolower(trim($name)));
    $email = $slug . '.stub@local';

    // Ensure unique email
    $counter = 0;
    $tryEmail = $email;
    while ($db->fetchColumn("SELECT 1 FROM users WHERE email = ?", [$tryEmail])) {
        $counter++;
        $tryEmail = $slug . $counter . '.stub@local';
    }

    $db->execute(
        "INSERT INTO users (display_name, email, password_hash, role, consent_state, consent_granted_at)
         VALUES (?, ?, ?, 'participant', 'granted', NOW())",
        [$name, $tryEmail, $stubHash]
    );

    $id = (int) $db->lastInsertId();
    $userCache[$name] = $id;
    return $id;
}

// Parse sessions from CSV
$sessions = [];
$skipped = 0;
$cancelled = 0;

foreach ($rows as $i => $row) {
    if ($i < 3) continue; // Skip header rows

    $date = trim($row[$COL_DATE] ?? '');
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

    $day = trim($row[$COL_DAY] ?? '');
    $sex = trim($row[$COL_SEX] ?? '');
    $model = trim($row[$COL_MODEL] ?? '');
    $notes = trim($row[$COL_NOTES] ?? '');
    $bookings = trim($row[$COL_BOOKINGS] ?? '');

    // Skip special rows
    if (stripos($sex, 'Ex.') !== false) { $skipped++; continue; } // Exhibition rows

    // Determine status
    $isCancelled = (stripos($model, 'cancel') !== false || stripos($model, 'postpone') !== false);
    $isCompleted = strtotime($date) < strtotime('today');

    if ($isCancelled) {
        $status = 'cancelled';
        $cancelled++;
    } elseif ($isCompleted) {
        $status = 'completed';
    } else {
        $status = 'scheduled';
    }

    // Model sex
    $modelSex = null;
    if ($sex === 'f') $modelSex = 'f';
    elseif ($sex === 'm' || $sex === 'm ') $modelSex = 'm';
    elseif ($sex === 'mf') $modelSex = 'f'; // dual model session, default f

    // Start time based on day
    $startTime = ($day === 'Fri') ? '15:00' : '10:00';

    // Parse model name
    $modelName = normalizeName($model);

    // Parse bookings (comma-separated artist names)
    $artistNames = [];
    if ($bookings !== '' && stripos($bookings, 'cancel') === false) {
        $parts = explode(',', $bookings);
        foreach ($parts as $part) {
            $n = normalizeName($part);
            if ($n !== null) {
                $artistNames[] = $n;
            }
        }
    }

    // Handle dual models ("Lourens & Petrus", "Heinrich Corienne")
    $modelNames = [];
    $dualModelPatterns = ['&', 'heinrich corienne'];
    $isDualModel = false;
    if ($model) {
        if (str_contains($model, '&')) {
            $isDualModel = true;
            $dualParts = explode('&', $model);
            foreach ($dualParts as $dp) {
                $n = normalizeName($dp);
                if ($n) $modelNames[] = $n;
            }
        } elseif (mb_strtolower(trim($model, " \t\n\r?")) === 'heinrich corienne') {
            $isDualModel = true;
            $modelNames[] = 'Heinrich';
            $modelNames[] = 'Corienne';
        }
    }
    if (!$isDualModel && $modelName && !$isCancelled) {
        $modelNames[] = $modelName;
    }

    $sessions[] = [
        'date' => $date,
        'day' => $day,
        'start_time' => $startTime,
        'model_sex' => $modelSex,
        'model_names' => $modelNames,
        'artist_names' => $artistNames,
        'notes' => $notes,
        'status' => $status,
    ];
}

echo "Parsed " . count($sessions) . " sessions ({$cancelled} cancelled, {$skipped} skipped)\n";

// Collect unique participant names for dry-run summary
$allNames = [];
foreach ($sessions as $s) {
    foreach ($s['model_names'] as $n) $allNames[$n] = ($allNames[$n] ?? 0) + 1;
    foreach ($s['artist_names'] as $n) $allNames[$n] = ($allNames[$n] ?? 0) + 1;
}
arsort($allNames);
echo "Unique participants: " . count($allNames) . "\n";

if ($dryRun) {
    echo "\n--- DRY RUN — no database changes ---\n\n";
    echo "Top participants:\n";
    $i = 0;
    foreach ($allNames as $name => $count) {
        echo "  {$name}: {$count} sessions\n";
        if (++$i >= 30) { echo "  ... and " . (count($allNames) - 30) . " more\n"; break; }
    }
    echo "\nPass without --dry-run to import.\n";
    exit(0);
}

// Import sessions
$db->execute("SET FOREIGN_KEY_CHECKS = 0");

// Clear existing data
echo "Clearing existing session data...\n";
$db->execute("DELETE FROM ld_artist_stats");
$db->execute("DELETE FROM ld_session_participants");
$db->execute("DELETE FROM ld_sessions");

$db->execute("SET FOREIGN_KEY_CHECKS = 1");

$importedSessions = 0;
$importedParticipants = 0;

foreach ($sessions as $s) {
    // Insert session
    $db->execute(
        "INSERT INTO ld_sessions (title, session_date, start_time, duration_minutes, venue, facilitator_id, model_sex, max_capacity, status)
         VALUES (NULL, ?, ?, 210, 'Randburg Studio', ?, ?, 7, ?)",
        [
            $s['date'],
            $s['start_time'],
            getOrCreateUser('André Clements'),
            $s['model_sex'],
            $s['status'],
        ]
    );
    $sessionId = (int) $db->lastInsertId();
    $importedSessions++;

    // Add André as facilitator
    $andreUserId = getOrCreateUser('André Clements');
    $db->execute(
        "INSERT INTO ld_session_participants (session_id, user_id, role, attended)
         VALUES (?, ?, 'facilitator', ?)",
        [$sessionId, $andreUserId, $s['status'] === 'completed' ? 1 : 0]
    );
    $importedParticipants++;

    // Add models
    foreach ($s['model_names'] as $modelName) {
        $modelUserId = getOrCreateUser($modelName);
        if ($modelUserId !== $andreUserId) {
            $db->execute(
                "INSERT IGNORE INTO ld_session_participants (session_id, user_id, role, attended)
                 VALUES (?, ?, 'model', ?)",
                [$sessionId, $modelUserId, $s['status'] === 'completed' ? 1 : 0]
            );
            $importedParticipants++;
        } else {
            // André modeling — add as model role too
            $db->execute(
                "INSERT IGNORE INTO ld_session_participants (session_id, user_id, role, attended)
                 VALUES (?, ?, 'model', ?)",
                [$sessionId, $andreUserId, $s['status'] === 'completed' ? 1 : 0]
            );
            $importedParticipants++;
        }
    }

    // Add artists
    foreach ($s['artist_names'] as $artistName) {
        $artistUserId = getOrCreateUser($artistName);
        $db->execute(
            "INSERT IGNORE INTO ld_session_participants (session_id, user_id, role, attended)
             VALUES (?, ?, 'artist', ?)",
            [$artistUserId !== $andreUserId ? $sessionId : $sessionId,
             $artistUserId,
             $s['status'] === 'completed' ? 1 : 0]
        );
        $importedParticipants++;
    }
}

echo "Imported {$importedSessions} sessions, {$importedParticipants} participants\n";

// Count users
$userCount = (int) $db->fetchColumn("SELECT COUNT(*) FROM users");
echo "Total users: {$userCount}\n";

// Refresh all stats
echo "Refreshing stats...\n";
$stats = new App\Services\StatsService($db);
$stats->refreshAll();

echo "Import complete.\n";
