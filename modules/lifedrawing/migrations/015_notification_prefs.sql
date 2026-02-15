ALTER TABLE users
    ADD COLUMN notify_new_session TINYINT(1) NOT NULL DEFAULT 0 AFTER consent_withdrawn_at,
    ADD COLUMN notify_session_cancelled TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_new_session,
    ADD COLUMN notify_claim_resolved TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_session_cancelled,
    ADD COLUMN notify_comment TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_claim_resolved;
