-- Life Drawing: off-pattern session support.
-- capacity_published=0 renders capacity as "?" (max_capacity stays the internal planning value).
-- booking_note, when set, appends session-specific schedule information to the
-- session's WhatsApp line (marked [1], with a shared footnote at the export's foot).
-- model_join_enabled=0 closes public model-joining (sitters booked off-platform).
ALTER TABLE ld_sessions
    ADD COLUMN capacity_published BOOLEAN NOT NULL DEFAULT 1 AFTER max_capacity,
    ADD COLUMN booking_note VARCHAR(255) NULL AFTER capacity_published,
    ADD COLUMN model_join_enabled BOOLEAN NOT NULL DEFAULT 1 AFTER booking_note;
