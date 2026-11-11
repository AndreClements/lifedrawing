-- Life Drawing: a waiting list for full sessions.
--
-- Until now a full session had no waiting list, so the facilitator used the
-- 'observer' role to mean "waiting". That row was a real booking to every reader
-- of ld_session_participants: stats counted it as attendance once the date
-- passed, it unlocked real names site-wide, and it showed as a booking.
--
-- So the waiting list is its own table, not a state on bookings. About twenty
-- queries count every participant row with no state filter; a 'waitlisted'
-- state would have needed a new filter in each of them, and any one missed
-- would quietly treat a waiting person as booked. Nothing existing reads this
-- table, which is the point. Same reasoning as ld_sitter_queue.
--
-- Places are artist places, so there is no role column. Queue order is id.
-- A person is never both waiting and booked as an artist for the same
-- session; WaitlistService enforces that under the session row lock.
CREATE TABLE IF NOT EXISTS ld_session_waitlist (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    added_by INT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uk_waitlist_session_user (session_id, user_id),
    KEY idx_waitlist_session_order (session_id, id),
    FOREIGN KEY (session_id) REFERENCES ld_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- When a waiting person was moved into a place. Written from PHP in the app
-- timezone (Africa/Johannesburg), never by MySQL NOW(): the server clock runs
-- about nine hours behind SAST, and this value is compared against session
-- start times to decide whether the late-cancellation note applies.
-- No existing reader filters on it.
--
-- If this migration fails partway, MySQL will already have committed any DDL
-- that ran (DDL is implicit-commit) while the runner records nothing, so a
-- re-run dies on "Duplicate column name 'promoted_at'". Recovery:
--   ALTER TABLE ld_session_participants DROP COLUMN promoted_at;
-- then run the migration again. The CREATE above is IF NOT EXISTS.
ALTER TABLE ld_session_participants
    ADD COLUMN promoted_at DATETIME NULL AFTER tentative;
