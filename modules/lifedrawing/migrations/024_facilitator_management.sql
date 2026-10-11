-- Additive management data. Existing sessions, participation and images remain.
ALTER TABLE ld_sessions
    ADD COLUMN cancelled_from_status VARCHAR(20) NULL,
    ADD COLUMN cancelled_at DATETIME NULL,
    ADD COLUMN cancelled_by INT UNSIGNED NULL,
    ADD COLUMN cancellation_reason VARCHAR(2000) NULL,
    MODIFY updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6);

CREATE TABLE ld_session_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NULL,
    kind ENUM('note','late','break','left_early','pose_change','attendance') NOT NULL DEFAULT 'note',
    text TEXT NULL,
    minutes SMALLINT UNSIGNED NULL,
    pose_number SMALLINT UNSIGNED NULL,
    attendance_before VARCHAR(20) NULL,
    attendance_after VARCHAR(20) NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    UNIQUE KEY uk_session_log_request (session_id, request_key),
    INDEX idx_session_log (session_id, created_at, id),
    FOREIGN KEY (session_id) REFERENCES ld_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES ld_session_participants(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ld_upload_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pose_number SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_upload_request (session_id, request_key),
    FOREIGN KEY (session_id) REFERENCES ld_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ld_artworks
    ADD COLUMN upload_batch_id INT UNSIGNED NULL,
    ADD COLUMN upload_file_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    ADD UNIQUE KEY uk_upload_file (upload_batch_id, upload_file_key),
    ADD FOREIGN KEY (upload_batch_id) REFERENCES ld_upload_batches(id) ON DELETE SET NULL;
