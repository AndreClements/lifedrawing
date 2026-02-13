-- Life Drawing: Artist stats (Strava-style progress metrics)
CREATE TABLE IF NOT EXISTS ld_artist_stats (
    user_id INT UNSIGNED PRIMARY KEY,
    total_sessions INT UNSIGNED NOT NULL DEFAULT 0,
    total_artworks INT UNSIGNED NOT NULL DEFAULT 0,
    current_streak INT UNSIGNED NOT NULL DEFAULT 0,
    longest_streak INT UNSIGNED NOT NULL DEFAULT 0,
    last_session_date DATE NULL,
    media_explored JSON NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
