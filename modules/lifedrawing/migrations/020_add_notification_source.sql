-- Life Drawing: give queued notifications a traceable source.
--
-- Until now ld_notification_queue carried only session_id, so a notification
-- could not be tied back to the artwork or claim that produced it. That made
-- cancellation impossible: deleting an artwork, or withdrawing a pending claim,
-- left its queued email to go out minutes later pointing at a dead page. Two
-- claims by one person on different artworks in the same session were entirely
-- indistinguishable, because claimSubmitted writes a generic pending-claims link.
--
-- source_type is 'artwork' or 'claim'; source_id is that row's id.
--
-- Rows queued before this migration keep NULL and cannot be targeted. They are
-- cleared by a one-off cutover purge during the deploy window (see the plan),
-- because they do not reliably age out on their own: cleanup() only removes
-- rows already sent, and a row that keeps failing is never sent.
ALTER TABLE ld_notification_queue
    ADD COLUMN source_type VARCHAR(32) NULL AFTER session_id,
    ADD COLUMN source_id INT UNSIGNED NULL AFTER source_type,
    ADD INDEX idx_source (source_type, source_id);
