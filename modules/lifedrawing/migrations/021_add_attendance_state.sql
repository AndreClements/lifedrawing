-- Life Drawing: record whether a booking was actually honoured.
--
-- `attended` has never been written by any web path. It is set only by the CSV
-- backfill, the seeders, and the auto-added facilitator row. So for anything
-- booked through the site a booking and an attendance were indistinguishable,
-- and there was no way to record a no-show.
--
-- Three states:
--   booked   — not recorded either way. The default, and what every web booking
--              has always effectively been.
--   attended — confirmed present. Today that means the historical import.
--   no_show  — explicitly marked absent by the facilitator.
--
-- IMPORTANT for anything reading this column: statistics exclude 'no_show'
-- rather than requiring 'attended'. Requiring 'attended' would erase the
-- attendance history of every person who ever booked through the site, because
-- all of those rows carry attended = 0.
--
-- `attended` is deliberately kept for now. Un-marking a no-show has to restore
-- the state the row had before, and the legacy column is that record. Dropping
-- it is a later migration, once nothing depends on it.
-- If this migration fails partway, MySQL will already have committed the
-- ALTER (DDL is implicit-commit) while the runner records nothing, so a re-run
-- dies on "Duplicate column name 'attendance'". Recovery is one statement:
--   ALTER TABLE ld_session_participants DROP COLUMN attendance;
-- then run the migration again.
ALTER TABLE ld_session_participants
    ADD COLUMN attendance ENUM('booked','attended','no_show')
        NOT NULL DEFAULT 'booked' AFTER attended;

UPDATE ld_session_participants SET attendance = 'attended' WHERE attended = 1;
