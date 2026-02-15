ALTER TABLE users ADD COLUMN remember_token VARCHAR(64) NULL AFTER password_hash;
