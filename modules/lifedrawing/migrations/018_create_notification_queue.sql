-- Life Drawing: notification queue for email batching/digest
CREATE TABLE IF NOT EXISTS ld_notification_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_id INT UNSIGNED NOT NULL,
    recipient_name VARCHAR(255) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    notification_type VARCHAR(50) NOT NULL,
    session_id INT UNSIGNED NULL,
    subject VARCHAR(255) NOT NULL,
    summary TEXT NOT NULL,
    detail TEXT NULL,
    footer TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    batch_id CHAR(36) NULL,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES ld_sessions(id) ON DELETE SET NULL,
    INDEX idx_pending (sent_at, recipient_id, created_at),
    INDEX idx_cleanup (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
