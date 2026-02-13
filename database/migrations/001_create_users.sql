-- Core: Users table (consent-aware)
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','facilitator','participant','observer') NOT NULL DEFAULT 'participant',
    consent_state ENUM('pending','granted','withdrawn') NOT NULL DEFAULT 'pending',
    consent_granted_at DATETIME NULL,
    consent_withdrawn_at DATETIME NULL,
    bio TEXT NULL,
    avatar_path VARCHAR(500) NULL,
    api_token VARCHAR(64) NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_api_token (api_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
