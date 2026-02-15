-- Life Drawing: Artworks (uploaded snapshots)
CREATE TABLE IF NOT EXISTS ld_artworks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    thumbnail_path VARCHAR(500) NULL,
    caption TEXT NULL,
    media_type VARCHAR(50) NOT NULL DEFAULT 'photograph',
    pose_index INT UNSIGNED NULL,
    visibility ENUM('session','claimed','public','private') NOT NULL DEFAULT 'session',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id),
    INDEX idx_visibility (visibility),
    FOREIGN KEY (session_id) REFERENCES ld_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
