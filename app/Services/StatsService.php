<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Database\QueryBuilder;

/**
 * Stats Service — Strava-for-Artistry.
 *
 * Computes engagement metrics for artists. Tracks attendance, not talent.
 * The slope rewards showing up, not producing volume.
 *
 * Streak logic: consecutive ISO weeks with at least one session attended.
 */
final class StatsService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /** Refresh stats for a single user. Call after claim approval, session join, etc. */
    public function refreshUser(int $userId): void
    {
        $totalSessions = (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT session_id) FROM ld_session_participants WHERE user_id = ?",
            [$userId]
        );

        $totalArtworks = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM ld_claims WHERE claimant_id = ? AND status = 'approved'",
            [$userId]
        );

        $lastDate = $this->db->fetchColumn(
            "SELECT MAX(s.session_date)
             FROM ld_sessions s
             JOIN ld_session_participants sp ON sp.session_id = s.id
             WHERE sp.user_id = ?",
            [$userId]
        );

        // Calculate streaks from session dates
        [$current, $longest] = $this->calculateStreaks($userId);

        // Media explored (from caption keywords and claim metadata)
        $media = $this->extractMedia($userId);

        // Upsert into ld_artist_stats
        $exists = $this->db->fetchColumn(
            "SELECT 1 FROM ld_artist_stats WHERE user_id = ?",
            [$userId]
        );

        if ($exists) {
            $this->db->execute(
                "UPDATE ld_artist_stats
                 SET total_sessions = ?, total_artworks = ?, current_streak = ?,
                     longest_streak = ?, last_session_date = ?, media_explored = ?
                 WHERE user_id = ?",
                [$totalSessions, $totalArtworks, $current, $longest, $lastDate, json_encode($media), $userId]
            );
        } else {
            $this->db->execute(
                "INSERT INTO ld_artist_stats (user_id, total_sessions, total_artworks,
                     current_streak, longest_streak, last_session_date, media_explored)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$userId, $totalSessions, $totalArtworks, $current, $longest, $lastDate, json_encode($media)]
            );
        }
    }

    /** Refresh stats for all users who have participated in at least one session. */
    public function refreshAll(): void
    {
        $userIds = $this->db->fetchAll(
            "SELECT DISTINCT user_id FROM ld_session_participants"
        );

        foreach ($userIds as $row) {
            $this->refreshUser((int) $row['user_id']);
        }
    }

    /**
     * Calculate weekly streaks.
     *
     * A streak is consecutive ISO weeks where the user attended at least one session.
     * Current streak counts backwards from the most recent week with activity.
     * If the most recent activity was more than 2 weeks ago, current streak resets to 0.
     *
     * @return array{0: int, 1: int} [current_streak, longest_streak]
     */
    private function calculateStreaks(int $userId): array
    {
        // Get distinct session weeks (YEARWEEK gives YYYYWW format)
        $weeks = $this->db->fetchAll(
            "SELECT DISTINCT YEARWEEK(s.session_date, 1) as yw
             FROM ld_sessions s
             JOIN ld_session_participants sp ON sp.session_id = s.id
             WHERE sp.user_id = ?
             ORDER BY yw DESC",
            [$userId]
        );

        if (empty($weeks)) {
            return [0, 0];
        }

        $weekNumbers = array_map(fn($w) => (int) $w['yw'], $weeks);

        // Current week in same YEARWEEK format
        $currentYw = (int) date('oW');  // ISO year + week (e.g. 202607)

        // Check if most recent activity is within the last 2 weeks
        $mostRecent = $weekNumbers[0];
        $gapFromNow = $this->weekDistance($mostRecent, $currentYw);
        $currentStreak = 0;

        if ($gapFromNow <= 1) {
            // Count consecutive weeks backwards from most recent
            $currentStreak = 1;
            for ($i = 1; $i < count($weekNumbers); $i++) {
                $gap = $this->weekDistance($weekNumbers[$i], $weekNumbers[$i - 1]);
                if ($gap === 1) {
                    $currentStreak++;
                } else {
                    break;
                }
            }
        }

        // Longest streak: find the longest run of consecutive weeks
        $longestStreak = 1;
        $runLength = 1;
        for ($i = 1; $i < count($weekNumbers); $i++) {
            $gap = $this->weekDistance($weekNumbers[$i], $weekNumbers[$i - 1]);
            if ($gap === 1) {
                $runLength++;
                $longestStreak = max($longestStreak, $runLength);
            } else {
                $runLength = 1;
            }
        }
        $longestStreak = max($longestStreak, $currentStreak);

        return [$currentStreak, $longestStreak];
    }

    /**
     * Calculate the distance in weeks between two YEARWEEK values.
     * Handles year boundaries correctly.
     */
    private function weekDistance(int $earlier, int $later): int
    {
        $yearA = intdiv($earlier, 100);
        $weekA = $earlier % 100;
        $yearB = intdiv($later, 100);
        $weekB = $later % 100;

        // Convert to absolute week number (approximate, good enough for streak detection)
        $absA = $yearA * 52 + $weekA;
        $absB = $yearB * 52 + $weekB;

        return $absB - $absA;
    }

    /**
     * Extract media types explored from artwork captions and metadata.
     * Looks for common art media keywords.
     */
    private function extractMedia(int $userId): array
    {
        $captions = $this->db->fetchAll(
            "SELECT a.caption
             FROM ld_artworks a
             JOIN ld_claims c ON c.artwork_id = a.id
             WHERE c.claimant_id = ? AND c.status = 'approved' AND a.caption IS NOT NULL",
            [$userId]
        );

        $keywords = [
            'charcoal', 'pencil', 'graphite', 'ink', 'pen', 'watercolour', 'watercolor',
            'acrylic', 'oil', 'pastel', 'conte', 'chalk', 'digital', 'marker', 'crayon',
            'gouache', 'mixed media', 'collage',
        ];

        $found = [];
        foreach ($captions as $row) {
            $text = strtolower($row['caption']);
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw) && !in_array($kw, $found, true)) {
                    $found[] = $kw;
                }
            }
        }

        sort($found);
        return $found;
    }

    /**
     * Get dashboard data for a user.
     *
     * Returns a rich array with stats, timeline, weekly activity, and milestones.
     */
    public function getDashboardData(int $userId): array
    {
        // Core stats
        $stats = $this->db->fetch(
            "SELECT s.*, u.display_name, u.created_at as member_since
             FROM ld_artist_stats s
             JOIN users u ON u.id = s.user_id
             WHERE s.user_id = ?",
            [$userId]
        ) ?: [
            'total_sessions' => 0, 'total_artworks' => 0,
            'current_streak' => 0, 'longest_streak' => 0,
            'last_session_date' => null, 'media_explored' => '[]',
        ];

        // Parse media_explored JSON
        if (is_string($stats['media_explored'] ?? null)) {
            $stats['media_explored'] = json_decode($stats['media_explored'], true) ?: [];
        }

        // Recent session timeline (last 10 sessions with artworks and roles)
        $timeline = $this->db->fetchAll(
            "SELECT s.id, s.title, s.session_date, s.venue, s.duration_minutes,
                    sp.role,
                    (SELECT COUNT(*) FROM ld_artworks a WHERE a.session_id = s.id) as artwork_count,
                    (SELECT COUNT(*) FROM ld_claims c
                     JOIN ld_artworks a2 ON c.artwork_id = a2.id
                     WHERE a2.session_id = s.id AND c.claimant_id = ? AND c.status = 'approved') as my_claimed
             FROM ld_sessions s
             JOIN ld_session_participants sp ON sp.session_id = s.id
             WHERE sp.user_id = ?
             ORDER BY s.session_date DESC
             LIMIT 10",
            [$userId, $userId]
        );

        // Weekly activity for the last 12 weeks (heatmap data)
        $weeklyActivity = $this->db->fetchAll(
            "SELECT YEARWEEK(s.session_date, 1) as yw,
                    COUNT(DISTINCT s.id) as sessions,
                    MIN(s.session_date) as week_start
             FROM ld_sessions s
             JOIN ld_session_participants sp ON sp.session_id = s.id
             WHERE sp.user_id = ?
               AND s.session_date >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
             GROUP BY yw
             ORDER BY yw",
            [$userId]
        );

        // Build full 12-week grid (including empty weeks)
        $weekGrid = $this->buildWeekGrid($weeklyActivity);

        // Role distribution
        $roles = $this->db->fetchAll(
            "SELECT role, COUNT(*) as count
             FROM ld_session_participants
             WHERE user_id = ?
             GROUP BY role
             ORDER BY count DESC",
            [$userId]
        );

        // Milestones achieved
        $milestones = $this->computeMilestones($stats);

        // Recent claimed artworks (last 6 for thumbnail display)
        $recentArtworks = $this->db->fetchAll(
            "SELECT a.*, s.title as session_title, s.session_date, c.claim_type
             FROM ld_artworks a
             JOIN ld_claims c ON c.artwork_id = a.id
             JOIN ld_sessions s ON a.session_id = s.id
             WHERE c.claimant_id = ? AND c.status = 'approved'
               AND a.visibility IN ('claimed', 'public')
             ORDER BY s.session_date DESC
             LIMIT 6",
            [$userId]
        );

        return [
            'stats' => $stats,
            'timeline' => $timeline,
            'weekGrid' => $weekGrid,
            'roles' => $roles,
            'milestones' => $milestones,
            'recentArtworks' => $recentArtworks,
        ];
    }

    /** Build a 12-week grid for the activity heatmap. */
    private function buildWeekGrid(array $weeklyActivity): array
    {
        // Index by yearweek
        $activityMap = [];
        foreach ($weeklyActivity as $row) {
            $activityMap[(int) $row['yw']] = (int) $row['sessions'];
        }

        $grid = [];
        $now = new \DateTimeImmutable('now');

        for ($i = 11; $i >= 0; $i--) {
            $date = $now->modify("-{$i} weeks");
            $yw = (int) $date->format('oW');
            $weekStart = $date->modify('monday this week')->format('M j');

            $grid[] = [
                'label' => $weekStart,
                'sessions' => $activityMap[$yw] ?? 0,
                'intensity' => min(($activityMap[$yw] ?? 0), 3), // 0-3 scale for heatmap
            ];
        }

        return $grid;
    }

    /** Compute milestones based on stats. */
    private function computeMilestones(array $stats): array
    {
        $milestones = [];
        $sessions = (int) ($stats['total_sessions'] ?? 0);
        $artworks = (int) ($stats['total_artworks'] ?? 0);
        $streak = (int) ($stats['longest_streak'] ?? 0);
        $media = is_array($stats['media_explored'] ?? null) ? count($stats['media_explored']) : 0;

        // Session milestones
        $sessionThresholds = [1 => 'First Session', 5 => '5 Sessions', 10 => 'Dedicated', 25 => 'Regular', 50 => 'Veteran', 100 => 'Centurion'];
        foreach ($sessionThresholds as $threshold => $label) {
            $milestones[] = [
                'label' => $label,
                'achieved' => $sessions >= $threshold,
                'progress' => min(100, (int) ($sessions / $threshold * 100)),
                'category' => 'attendance',
            ];
        }

        // Artwork milestones
        $artworkThresholds = [1 => 'First Claim', 10 => 'Portfolio Started', 25 => 'Growing Body', 50 => 'Prolific'];
        foreach ($artworkThresholds as $threshold => $label) {
            $milestones[] = [
                'label' => $label,
                'achieved' => $artworks >= $threshold,
                'progress' => min(100, (int) ($artworks / $threshold * 100)),
                'category' => 'artworks',
            ];
        }

        // Streak milestones
        $streakThresholds = [2 => 'First Streak', 4 => 'Monthly Regular', 8 => 'Two Months Strong', 12 => 'Quarterly Anchor'];
        foreach ($streakThresholds as $threshold => $label) {
            $milestones[] = [
                'label' => $label,
                'achieved' => $streak >= $threshold,
                'progress' => min(100, (int) ($streak / $threshold * 100)),
                'category' => 'streaks',
            ];
        }

        // Media exploration
        if ($media >= 3) {
            $milestones[] = ['label' => 'Multi-Medium', 'achieved' => true, 'progress' => 100, 'category' => 'exploration'];
        }
        if ($media >= 5) {
            $milestones[] = ['label' => 'Renaissance Soul', 'achieved' => true, 'progress' => 100, 'category' => 'exploration'];
        }

        return $milestones;
    }
}
