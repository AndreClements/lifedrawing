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
 * Streak logic: a run of weekends the venue ran where at least one session was attended.
 * Superstreak logic: a run of consecutive sessions held. See calculateStreaks().
 */
final class StatsService
{
    /** A run of this many or more counts as one streak. Meant to be adjustable. */
    private const STREAK_MIN_RUN = 2;

    /** @var array<string,int>|null */
    private ?array $weekendOrder = null;

    /** @var array<int,int>|null */
    private ?array $sessionOrder = null;

    public function __construct(
        private readonly Connection $db,
    ) {}

    /** Refresh stats for a single user. Call after claim approval, session join, etc. */
    public function refreshUser(int $userId): void
    {
        $today = date('Y-m-d');

        // Attendance means sessions that have actually happened. Counting every
        // participation row let a booking for a future session inflate the total
        // and the streak before the person had drawn a line.
        $totalSessions = (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT sp.session_id)
             FROM ld_session_participants sp
             JOIN ld_sessions s ON s.id = sp.session_id
             WHERE sp.user_id = ? AND s.session_date <= ?
               AND sp.attendance != 'no_show'",
            [$userId, $today]
        );

        // Joined to ld_artworks: without it a deleted artwork stayed in the
        // total forever, and refreshing the cache just recomputed the same
        // wrong number.
        $totalArtworks = (int) $this->db->fetchColumn(
            "SELECT COUNT(*)
             FROM ld_claims c
             JOIN ld_artworks a ON a.id = c.artwork_id
             WHERE c.claimant_id = ? AND c.status = 'approved'
               AND a.visibility NOT IN ('removed', 'private')",
            [$userId]
        );

        // Same rule, or "last attended" reports a session not yet held.
        $lastDate = $this->db->fetchColumn(
            "SELECT MAX(s.session_date)
             FROM ld_sessions s
             JOIN ld_session_participants sp ON sp.session_id = s.id
             WHERE sp.user_id = ? AND s.session_date <= ?
               AND sp.attendance != 'no_show'",
            [$userId, $today]
        );

        // Streaks (consecutive weekends the venue ran) and superstreaks (consecutive
        // sessions held) — regularity and intensity, counted separately.
        [$current, $longest, $streakCount, $superCurrent, $superLongest, $superCount]
            = $this->calculateStreaks($userId);

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
                     longest_streak = ?, streak_count = ?, current_superstreak = ?,
                     longest_superstreak = ?, superstreak_count = ?,
                     last_session_date = ?, media_explored = ?
                 WHERE user_id = ?",
                [$totalSessions, $totalArtworks, $current, $longest, $streakCount,
                 $superCurrent, $superLongest, $superCount, $lastDate, json_encode($media), $userId]
            );
        } else {
            $this->db->execute(
                "INSERT INTO ld_artist_stats (user_id, total_sessions, total_artworks,
                     current_streak, longest_streak, streak_count, current_superstreak,
                     longest_superstreak, superstreak_count, last_session_date, media_explored)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$userId, $totalSessions, $totalArtworks, $current, $longest, $streakCount,
                 $superCurrent, $superLongest, $superCount, $lastDate, json_encode($media)]
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
     * Calculate streaks and superstreaks.
     *
     * Two measures, because regularity and intensity are different things:
     *
     *   streak       a run of WEEKENDS THE VENUE RAN where this person attended at least
     *                one session. Weeks with no session cost nothing, so it does not
     *                punish anyone for the schedule's own gaps.
     *   superstreak  a run of consecutive SESSIONS, in the order they were held. Coming
     *                to all of a Fri/Sat/Sun weekend makes ONE run of length 3 — the
     *                length is counted in sessions, not in weekends, and three in a row
     *                need not sit inside a single weekend. Missing a session ends the run.
     *
     * Both return a LENGTH (how long the best run was) and a COUNT (how many runs of at
     * least STREAK_MIN_RUN there have been). They are different numbers; anything that
     * displays them has to say which it is showing.
     *
     * The old measure counted consecutive ISO calendar weeks, which the fortnightly
     * schedule made unreachable — 245 of 250 people sat on 1 — and it did arithmetic on
     * YEARWEEK values as `year * 52 + week`, which silently under-counts across a
     * 53-week ISO year (2026 is one: 2026-12-28 to 2027-01-04 came out as 0, not 1).
     * Indexing into the venue's own list of weekends removes both problems at once:
     * nothing here does arithmetic on week numbers.
     *
     * The rule is "not marked no_show", NOT "attendance = 'attended'". Nothing has ever
     * written 'attended' for a web booking, so requiring it would wipe the attendance
     * history of everyone who booked through the site.
     *
     * @return array{0:int,1:int,2:int,3:int,4:int,5:int}
     *         [streakCurrent, streakLongest, streakCount,
     *          superCurrent, superLongest, superCount]
     */
    private function calculateStreaks(int $userId): array
    {
        $weekendAt = $this->weekendOrder();
        $sessionAt = $this->sessionOrder();

        if ($weekendAt === [] || $sessionAt === []) {
            return [0, 0, 0, 0, 0, 0];
        }

        $attended = $this->db->fetchAll(
            "SELECT s.id, YEARWEEK(s.session_date, 1) AS yw
             FROM ld_sessions s
             JOIN ld_session_participants sp ON sp.session_id = s.id
             WHERE sp.user_id = ? AND s.session_date <= ?
               AND sp.attendance != 'no_show'",
            [$userId, date('Y-m-d')]
        );

        $weekendIdx = [];
        $sessionIdx = [];
        foreach ($attended as $row) {
            $yw = (string) $row['yw'];
            $id = (int) $row['id'];
            if (isset($weekendAt[$yw])) {
                $weekendIdx[] = $weekendAt[$yw];
            }
            if (isset($sessionAt[$id])) {
                $sessionIdx[] = $sessionAt[$id];
            }
        }

        [$sc, $sl, $sn] = self::summarise(self::runLengths($weekendIdx, count($weekendAt) - 1));
        [$pc, $pl, $pn] = self::summarise(self::runLengths($sessionIdx, count($sessionAt) - 1));

        return [$sc, $sl, $sn, $pc, $pl, $pn];
    }

    /**
     * Every run length, in order, plus whether the last one is still live.
     *
     * Returns raw lengths including 1s — the STREAK_MIN_RUN threshold is applied by
     * callers, so adjusting it never means reinterpreting anything already computed.
     *
     * A run is only "current" if it reaches the most recent opportunity there was — the
     * latest weekend the venue ran, or the latest session held. Otherwise it is over,
     * however recently it ended.
     *
     * @param list<int> $positions
     * @return array{runs: list<int>, current: int}
     */
    private static function runLengths(array $positions, int $lastPossible): array
    {
        $positions = array_values(array_unique($positions));
        sort($positions);

        $n = count($positions);
        if ($n === 0) {
            return ['runs' => [], 'current' => 0];
        }

        $runs = [];
        $run = 1;
        for ($i = 1; $i < $n; $i++) {
            if ($positions[$i] === $positions[$i - 1] + 1) {
                $run++;
                continue;
            }
            $runs[] = $run;
            $run = 1;
        }
        $runs[] = $run;

        return [
            'runs'    => $runs,
            'current' => ($positions[$n - 1] === $lastPossible) ? $run : 0,
        ];
    }

    /**
     * Summarise run lengths the way the stats table stores them.
     *
     * @param array{runs: list<int>, current: int} $lengths
     * @return array{0:int,1:int,2:int} [current, longest, count]
     */
    private static function summarise(array $lengths): array
    {
        $runs = $lengths['runs'];
        if ($runs === []) {
            return [0, 0, 0];
        }

        $qualifying = array_filter($runs, static fn(int $r): bool => $r >= self::STREAK_MIN_RUN);

        return [$lengths['current'], max($runs), count($qualifying)];
    }

    /**
     * The run lengths behind someone's streak numbers, for their profile page.
     *
     * Computed live rather than stored: it is three cheap queries on a page nobody
     * hammers, and it means any further stat is arithmetic over these arrays instead of
     * another column. Averages cover only runs that qualify as a streak — averaging in
     * every single unrepeated visit would drag every number towards 1 and say nothing.
     *
     * @return array{
     *     streak: array{runs: list<int>, longest: int, count: int, current: int, average: float|null, completed: int},
     *     superstreak: array{runs: list<int>, longest: int, count: int, current: int, average: float|null, completed: int}
     * }
     */
    public function runBreakdown(int $userId): array
    {
        $weekendAt = $this->weekendOrder();
        $sessionAt = $this->sessionOrder();

        $empty = ['runs' => [], 'longest' => 0, 'count' => 0, 'current' => 0, 'average' => null, 'completed' => 0];
        if ($weekendAt === [] || $sessionAt === []) {
            return ['streak' => $empty, 'superstreak' => $empty];
        }

        $attended = $this->db->fetchAll(
            "SELECT s.id, YEARWEEK(s.session_date, 1) AS yw
             FROM ld_sessions s
             JOIN ld_session_participants sp ON sp.session_id = s.id
             WHERE sp.user_id = ? AND s.session_date <= ?
               AND sp.attendance != 'no_show'",
            [$userId, date('Y-m-d')]
        );

        $weekendIdx = [];
        $sessionIdx = [];
        foreach ($attended as $row) {
            $yw = (string) $row['yw'];
            $id = (int) $row['id'];
            if (isset($weekendAt[$yw])) {
                $weekendIdx[] = $weekendAt[$yw];
            }
            if (isset($sessionAt[$id])) {
                $sessionIdx[] = $sessionAt[$id];
            }
        }

        return [
            'streak'      => self::describe(self::runLengths($weekendIdx, count($weekendAt) - 1)),
            'superstreak' => self::describe(self::runLengths($sessionIdx, count($sessionAt) - 1)),
        ];
    }

    /**
     * The average covers COMPLETED runs only, with the live one held out.
     *
     * A run in progress is still growing. Averaging it in would drop someone's average
     * the moment they start again after a gap — punishing exactly the behaviour the
     * statistic exists to encourage. Null until there is a completed run to average, so
     * the page can print a dash rather than a misleading zero.
     *
     * @param array{runs: list<int>, current: int} $lengths
     * @return array{runs: list<int>, longest: int, count: int, current: int,
     *               average: float|null, completed: int}
     */
    private static function describe(array $lengths): array
    {
        $runs    = $lengths['runs'];
        $current = $lengths['current'];

        $completed = $runs;
        if ($current > 0 && $completed !== []) {
            array_pop($completed); // the trailing run is the live one
        }

        $qualifies = static fn(int $r): bool => $r >= self::STREAK_MIN_RUN;
        $counted   = array_values(array_filter($runs, $qualifies));
        $done      = array_values(array_filter($completed, $qualifies));

        return [
            'runs'      => $runs,
            'longest'   => $runs === [] ? 0 : max($runs),
            'count'     => count($counted),
            'current'   => $current,
            'average'   => $done === [] ? null : array_sum($done) / count($done),
            'completed' => count($done),
        ];
    }

    /**
     * Every weekend the venue ran something, oldest first: YEARWEEK => position.
     *
     * Memoised per instance because refreshAll() asks once per user and the answer
     * cannot change inside a single run.
     *
     * @return array<string,int>
     */
    private function weekendOrder(): array
    {
        if ($this->weekendOrder !== null) {
            return $this->weekendOrder;
        }

        $rows = $this->db->fetchAll(
            "SELECT DISTINCT YEARWEEK(session_date, 1) AS yw
             FROM ld_sessions WHERE session_date <= ? ORDER BY yw ASC",
            [date('Y-m-d')]
        );

        $order = [];
        foreach ($rows as $i => $row) {
            $order[(string) $row['yw']] = $i;
        }

        return $this->weekendOrder = $order;
    }

    /**
     * Every session already held, oldest first: session id => position.
     *
     * @return array<int,int>
     */
    private function sessionOrder(): array
    {
        if ($this->sessionOrder !== null) {
            return $this->sessionOrder;
        }

        $rows = $this->db->fetchAll(
            "SELECT id FROM ld_sessions WHERE session_date <= ?
             ORDER BY session_date ASC, id ASC",
            [date('Y-m-d')]
        );

        $order = [];
        foreach ($rows as $i => $row) {
            $order[(int) $row['id']] = $i;
        }

        return $this->sessionOrder = $order;
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
             WHERE c.claimant_id = ? AND c.status = 'approved' AND a.caption IS NOT NULL
               AND a.visibility NOT IN ('removed', 'private')",
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

        // Recent session timeline (GROUP_CONCAT avoids duplicates for multi-role sessions)
        $timeline = $this->db->fetchAll(
            "SELECT s.id, s.title, s.session_date, s.venue, s.duration_minutes,
                    GROUP_CONCAT(sp.role ORDER BY sp.role SEPARATOR ', ') as role,
                    (SELECT COUNT(*) FROM ld_artworks a WHERE a.session_id = s.id) as artwork_count,
                    (SELECT COUNT(*) FROM ld_claims c
                     JOIN ld_artworks a2 ON c.artwork_id = a2.id
                     WHERE a2.session_id = s.id AND c.claimant_id = ? AND c.status = 'approved') as my_claimed
             FROM ld_sessions s
             JOIN ld_session_participants sp ON sp.session_id = s.id
             WHERE sp.user_id = ? AND s.session_date <= ?
               AND sp.attendance != 'no_show'
             GROUP BY s.id
             ORDER BY s.session_date DESC
             LIMIT 10",
            [$userId, $userId, date('Y-m-d')]
        );

        // Sessions they have booked but not yet been to. These used to sort to
        // the top of the list above, which is headed "Recent Sessions" - so a
        // booking for next month read as the most recent thing they had done.
        $upcoming = $this->db->fetchAll(
            "SELECT s.id, s.title, s.session_date, s.start_time, s.venue, s.status,
                    GROUP_CONCAT(sp.role ORDER BY sp.role SEPARATOR ', ') as role
             FROM ld_sessions s
             JOIN ld_session_participants sp ON sp.session_id = s.id
             WHERE sp.user_id = ? AND s.session_date >= ?
               AND s.status != 'cancelled'
             GROUP BY s.id
             ORDER BY s.session_date ASC",
            [$userId, date('Y-m-d')]
        );

        // Shown only to this person and to the facilitator. It is a fact, not a
        // reprimand, and it never appears on a public profile.
        $noShows = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM ld_session_participants
             WHERE user_id = ? AND attendance = 'no_show'",
            [$userId]
        );

        // Weekly activity for the last 12 weeks (heatmap data).
        //
        // Same two filters as the streak query, or the dashboard contradicts
        // itself: a week lights up in the heatmap while contributing nothing to
        // the streak printed beside it. Future bookings had no upper bound here,
        // and no-shows were counted as activity.
        $weeklyActivity = $this->db->fetchAll(
            "SELECT YEARWEEK(s.session_date, 1) as yw,
                    COUNT(DISTINCT s.id) as sessions,
                    MIN(s.session_date) as week_start
             FROM ld_sessions s
             JOIN ld_session_participants sp ON sp.session_id = s.id
             WHERE sp.user_id = ?
               AND s.session_date >= DATE_SUB(?, INTERVAL 12 WEEK)
               AND s.session_date <= ?
               AND sp.attendance != 'no_show'
             GROUP BY yw
             ORDER BY yw",
            [$userId, date('Y-m-d'), date('Y-m-d')]
        );

        // Build full 12-week grid (including empty weeks)
        $weekGrid = $this->buildWeekGrid($weeklyActivity);

        // Role distribution. Same filters as everything else on this page, or
        // the roles bar keeps counting a session the streak and the total have
        // already excluded.
        $roles = $this->db->fetchAll(
            "SELECT sp.role, COUNT(*) as count
             FROM ld_session_participants sp
             JOIN ld_sessions s ON s.id = sp.session_id
             WHERE sp.user_id = ? AND s.session_date <= ?
               AND sp.attendance != 'no_show'
             GROUP BY sp.role
             ORDER BY count DESC",
            [$userId, date('Y-m-d')]
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
            'upcoming' => $upcoming,
            'noShows' => $noShows,
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

        // Streak milestones — consecutive weekends we met. Labels name the run and its
        // unit: the old ones named calendar spans ("Monthly Regular") that a fortnightly
        // schedule made untrue, and a bare "Four in a Row" does not say four of what.
        $streakThresholds = [
            2  => 'Two Weekends in a Row',
            4  => 'Four Weekends in a Row',
            8  => 'Eight Weekends in a Row',
            12 => 'Twelve Weekends in a Row',
        ];
        foreach ($streakThresholds as $threshold => $label) {
            $milestones[] = [
                'label' => $label,
                'achieved' => $streak >= $threshold,
                'progress' => min(100, (int) ($streak / $threshold * 100)),
                'category' => 'streaks',
            ];
        }

        // Superstreak milestones — consecutive sessions. Counted in sessions, not
        // weekends: three in a row need not be one Fri/Sat/Sun, and six need not be two,
        // so labelling them "Whole Weekend" claimed something the number cannot show.
        $super = (int) ($stats['longest_superstreak'] ?? 0);
        $superThresholds = [
            2  => 'Two Sessions in a Row',
            3  => 'Three Sessions in a Row',
            6  => 'Six Sessions in a Row',
            10 => 'Ten Sessions in a Row',
        ];
        foreach ($superThresholds as $threshold => $label) {
            $milestones[] = [
                'label' => $label,
                'achieved' => $super >= $threshold,
                'progress' => min(100, (int) ($super / $threshold * 100)),
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
