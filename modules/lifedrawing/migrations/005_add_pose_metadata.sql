-- Add pose metadata to artworks for batch organisation.
-- Facilitators upload per batch: warmups (60s), sustained (20min), etc.
-- All artworks in a single upload share the same duration and label.

ALTER TABLE ld_artworks
    ADD COLUMN pose_duration INT UNSIGNED NULL AFTER pose_index,
    ADD COLUMN pose_label VARCHAR(100) NULL AFTER pose_duration;
