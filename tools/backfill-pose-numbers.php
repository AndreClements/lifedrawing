<?php

declare(strict_types=1);

/**
 * Assign ld_artworks.pose_number for artwork imported before migration 022.
 *
 * A pose is a run of consecutive artworks (by pose_index) that share their
 * pose_duration and pose_label. That alone is not enough: two poses of the same
 * length, back to back and unlabelled, look like one run. Session 291 ran
 * Warm-up / 20 min / 1 hr / 20 min / 20 min and the last two merged.
 *
 * The boundary that does exist is the clock. Photographs of one pose's drawings are
 * taken in a burst of a minute or two; the next pose is half an hour later. That
 * capture time is in the phone's filename, recorded in the 'orig' field of the
 * artwork.upload provenance row, so a run is split further wherever consecutive
 * captures are more than GAP_SECONDS apart.
 *
 * Deliberately conservative in two ways:
 *
 *   - A time gap only ever SPLITS a run, never merges across a metadata change.
 *   - Runs carrying no pose metadata at all are never split on time. Those are
 *     flat imports that have always displayed as a single block, and splitting
 *     them would silently rearrange old sessions to no one's benefit.
 *
 * Usage:
 *   php tools/backfill-pose-numbers.php --dry-run
 *   php tools/backfill-pose-numbers.php --dry-run --session=291
 *   php tools/backfill-pose-numbers.php
 *   php tools/backfill-pose-numbers.php --force     # also renumber rows already set
 */

define('LDR_ROOT', getcwd());
date_default_timezone_set('Africa/Johannesburg');

const GAP_SECONDS = 300; // 5 minutes — bursts span ~1-2 min, pose gaps 20+ min

$envFile = LDR_ROOT . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = $_SERVER[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}

require LDR_ROOT . '/vendor/autoload.php';

$dryRun = in_array('--dry-run', $argv, true);
$force  = in_array('--force', $argv, true);
$only   = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--session=')) $only = (int) substr($arg, 10);
}

$cfg = config('database');
$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4",
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$cols = $pdo->query("SHOW COLUMNS FROM ld_artworks LIKE 'pose_number'")->fetchAll();
if ($cols === []) {
    fwrite(STDERR, "ld_artworks.pose_number does not exist — run migration 022 first.\n");
    exit(1);
}

// Capture time per artwork, from the phone filename recorded at import.
$captureAt = [];
$pv = $pdo->query("SELECT pl.entity_id, pl.context FROM provenance_log pl
                   WHERE pl.action = 'artwork.upload' AND pl.entity_type = 'artwork'");
foreach ($pv as $row) {
    $ctx = json_decode((string) $row['context'], true);
    if (!is_array($ctx) || empty($ctx['orig'])) continue;
    if (!preg_match('/(\d{8})_(\d{6})/', (string) $ctx['orig'], $m)) continue;
    $t = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2]);
    if ($t !== false) $captureAt[(int) $row['entity_id']] = $t->getTimestamp();
}

$sessionSql = "SELECT DISTINCT session_id FROM ld_artworks";
if ($only !== null) $sessionSql .= " WHERE session_id = " . $only;
$sessionSql .= " ORDER BY session_id";
$sessions = $pdo->query($sessionSql)->fetchAll(PDO::FETCH_COLUMN);

$upd = $pdo->prepare("UPDATE ld_artworks SET pose_number = ? WHERE id = ?");

$totalSessions = 0; $totalRows = 0; $totalPoses = 0; $timeSplits = 0; $skippedSet = 0;

foreach ($sessions as $sessionId) {
    $rows = $pdo->query(
        "SELECT id, pose_index, pose_duration, pose_label, pose_number
         FROM ld_artworks WHERE session_id = {$sessionId}
         ORDER BY pose_index ASC, created_at ASC, id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) continue;

    if (!$force) {
        $already = array_filter($rows, fn(array $r): bool => $r['pose_number'] !== null);
        if (count($already) === count($rows)) { $skippedSet++; continue; }
    }

    $poseNo = 0;
    $lastKey = null;
    $lastAt = null;
    $assign = [];
    $splitsHere = 0;

    foreach ($rows as $r) {
        $key     = ($r['pose_duration'] ?? '') . '|' . ($r['pose_label'] ?? '');
        $hasMeta = ($r['pose_duration'] ?? '') !== '' || ($r['pose_label'] ?? '') !== '';
        $at      = $captureAt[(int) $r['id']] ?? null;

        $newPose = ($key !== $lastKey);

        // Same metadata, but a long gap since the previous shot — a different pose.
        if (!$newPose && $hasMeta && $at !== null && $lastAt !== null && ($at - $lastAt) > GAP_SECONDS) {
            $newPose = true;
            $splitsHere++;
            $timeSplits++;
        }

        if ($newPose) $poseNo++;

        $assign[(int) $r['id']] = $poseNo;
        $lastKey = $key;
        if ($at !== null) $lastAt = $at;
    }

    $changed = 0;
    foreach ($rows as $r) {
        if ((int) ($r['pose_number'] ?? 0) !== $assign[(int) $r['id']]) $changed++;
    }

    printf("  session %-5s rows=%-4s poses=%-3s time-splits=%-3s changed=%s%s",
        $sessionId, count($rows), $poseNo, $splitsHere, $changed, PHP_EOL);

    if ($splitsHere > 0) {
        $byPose = [];
        foreach ($rows as $r) $byPose[$assign[(int) $r['id']]][] = $r;
        foreach ($byPose as $n => $group) {
            $first = $captureAt[(int) $group[0]['id']] ?? null;
            $last  = $captureAt[(int) $group[count($group) - 1]['id']] ?? null;
            printf("      pose %-3s %-10s n=%-3s %s%s", $n, (string) $group[0]['pose_duration'], count($group),
                $first !== null ? date('H:i:s', $first) . ' -> ' . date('H:i:s', (int) $last) : '', PHP_EOL);
        }
    }

    if (!$dryRun) {
        $pdo->beginTransaction();
        try {
            foreach ($assign as $id => $n) $upd->execute([$n, $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            echo "      ERROR: " . $e->getMessage() . PHP_EOL;
            continue;
        }
    }

    $totalSessions++;
    $totalRows += count($rows);
    $totalPoses += $poseNo;
}

echo PHP_EOL . "sessions={$totalSessions}  rows={$totalRows}  poses={$totalPoses}  split-on-time={$timeSplits}";
if ($skippedSet > 0) echo "  already-numbered-sessions-skipped={$skippedSet}";
echo $dryRun ? "  (DRY RUN — nothing written)" : "";
echo PHP_EOL;
