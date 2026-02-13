-- Life Drawing: Claims (artists/models claim artworks)
CREATE TABLE IF NOT EXISTS ld_claims (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    artwork_id INT UNSIGNED NOT NULL,
    claimant_id INT UNSIGNED NOT NULL,
    claim_type ENUM('artist','model') NOT NULL,
    status ENUM('pending','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
    approved_by INT UNSIGNED NULL,
    claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    UNIQUE KEY uk_artwork_claimant_type (artwork_id, claimant_id, claim_type),
    FOREIGN KEY (artwork_id) REFERENCES ld_artworks(id) ON DELETE CASCADE,
    FOREIGN KEY (claimant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
