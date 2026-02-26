-- Life Drawing: sitter queue user fields
ALTER TABLE users
    ADD COLUMN whatsapp_number VARCHAR(20) NULL AFTER bio,
    ADD COLUMN sitter_pref_friday TINYINT(1) NOT NULL DEFAULT 0 AFTER whatsapp_number,
    ADD COLUMN sitter_pref_saturday TINYINT(1) NOT NULL DEFAULT 0 AFTER sitter_pref_friday,
    ADD COLUMN sitter_pref_sunday TINYINT(1) NOT NULL DEFAULT 0 AFTER sitter_pref_saturday,
    ADD COLUMN sitter_auto_rejoin TINYINT(1) NOT NULL DEFAULT 0 AFTER sitter_pref_sunday,
    ADD COLUMN sitter_notes TEXT NULL AFTER sitter_auto_rejoin;
