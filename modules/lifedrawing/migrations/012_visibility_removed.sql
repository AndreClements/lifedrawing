-- Add 'removed' to visibility ENUM for soft-delete support.
-- Without this, GalleryController::destroy() fails on MySQL strict mode.
ALTER TABLE ld_artworks
    MODIFY COLUMN visibility ENUM('session','claimed','public','private','removed') NOT NULL DEFAULT 'session';
