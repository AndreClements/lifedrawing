-- Life Drawing: make a pose a thing in its own right.
--
-- Until now a batch of drawings was identified only by pose_duration + pose_label,
-- so two poses that merely lasted the same time were indistinguishable. Session 291
-- ran Warm-up / 20 min / 1 hr / 20 min / 20 min; the last two are adjacent, the same
-- length and unlabelled, so nothing in the data said where one ended and the next
-- began. pose_index counts artworks, not poses, and cannot answer it either.
--
-- pose_number is the pose's ordinal within its session, 1-based, shared by every
-- drawing made during that pose. Set per import directory, since one directory is
-- one pose. NULL for everything not yet backfilled and for uploads that carry no
-- pose information at all.
--
-- Backfill is a separate step, not SQL: the real boundary between two same-length
-- poses is the gap between capture timestamps, which lives in the provenance
-- 'orig' filename rather than in this table. Run tools/backfill-pose-numbers.php
-- after this migration.
--
-- If this migration fails partway, MySQL will already have committed the ALTER
-- (DDL is implicit-commit) while the runner records nothing, so a re-run dies on
-- "Duplicate column name 'pose_number'". Recovery is one statement:
--   ALTER TABLE ld_artworks DROP COLUMN pose_number;
-- then run the migration again.
ALTER TABLE ld_artworks
    ADD COLUMN pose_number SMALLINT UNSIGNED NULL AFTER pose_index;

CREATE INDEX idx_artworks_session_pose ON ld_artworks (session_id, pose_number, pose_index);
