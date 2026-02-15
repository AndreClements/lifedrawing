-- Life Drawing: Sessions
CREATE TABLE IF NOT EXISTS ld_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    session_date DATE NOT NULL,
    start_time TIME NULL,
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 180,
    venue VARCHAR(200) NOT NULL DEFAULT 'Randburg',
    description TEXT NULL,
    facilitator_id INT UNSIGNED NULL,
    status ENUM('scheduled','active','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    pose_config JSON NULL,
    session_notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date (session_date),
    INDEX idx_status (status),
    FOREIGN KEY (facilitator_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Life Drawing: Session participants
CREATE TABLE IF NOT EXISTS ld_session_participants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('model','artist','facilitator','observer') NOT NULL,
    attended BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_session_user_role (session_id, user_id, role),
    FOREIGN KEY (session_id) REFERENCES ld_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
