-- Add pseudonym for public-facing display.
-- Logged-out visitors see pseudonym (if set). Community members see display_name + pseudonym subtitle.
ALTER TABLE users ADD COLUMN pseudonym VARCHAR(100) NULL AFTER bio;
