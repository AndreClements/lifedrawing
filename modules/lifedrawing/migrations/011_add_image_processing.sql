-- Add web-display path and processing timestamp for image pipeline.
-- web_path: mid-size WebP for detail pages (2000px longest side).
-- processed_at: NULL means "needs processing", set after all derivatives complete.

ALTER TABLE ld_artworks
    ADD COLUMN web_path VARCHAR(500) NULL AFTER thumbnail_path,
    ADD COLUMN processed_at DATETIME NULL AFTER web_path;

-- Reset existing thumbnails so the new pipeline reprocesses everything
-- (applies EXIF rotation, generates WebP versions, caps originals).
UPDATE ld_artworks SET thumbnail_path = NULL WHERE thumbnail_path IS NOT NULL;
