ALTER TABLE ld_session_participants
    ADD COLUMN tentative BOOLEAN NOT NULL DEFAULT FALSE AFTER attended;
