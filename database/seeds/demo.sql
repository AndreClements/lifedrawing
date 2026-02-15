-- Life Drawing Randburg: Seed Data
-- Run: mysql -u root lifedrawing < database/seeds/demo.sql
-- Or: php tools/seed.php

-- André (facilitator) — password: 'password123'
-- bcrypt hash of 'password123' with cost 12
SET @pw = '$2y$12$LJ3m4ys3UYkRh1gGOqP8/.vZXVHKJxKZiNI7C.Zc8NZPbJQKD2bxe';

INSERT INTO users (display_name, email, password_hash, role, consent_state, consent_granted_at, bio) VALUES
('André Clements', 'andre@example.com', @pw, 'facilitator', 'granted', NOW(),
 'Facilitator, artist, and occasional model. Every session is an experiment in shared attention.');

-- Sessions — real schedule Feb–Apr 2026
-- model_sex: 'f' = female, 'm' = male, NULL = unspecified
INSERT INTO ld_sessions (title, session_date, start_time, duration_minutes, venue, facilitator_id, model_sex, status) VALUES
(NULL, '2026-02-13', '15:00', 210, 'Randburg Studio', 1, 'f', 'completed'),
(NULL, '2026-02-14', '10:00', 210, 'Randburg Studio', 1, 'f', 'completed'),
(NULL, '2026-02-27', '15:00', 210, 'Randburg Studio', 1, 'm', 'scheduled'),
(NULL, '2026-02-28', '10:00', 210, 'Randburg Studio', 1, 'f', 'scheduled'),
(NULL, '2026-03-01', '10:00', 210, 'Randburg Studio', 1, 'f', 'scheduled'),
(NULL, '2026-03-13', '15:00', 210, 'Randburg Studio', 1, 'f', 'scheduled'),
(NULL, '2026-03-14', '10:00', 210, 'Randburg Studio', 1, 'm', 'scheduled'),
(NULL, '2026-03-27', '15:00', 210, 'Randburg Studio', 1, 'f', 'scheduled'),
(NULL, '2026-03-28', '10:00', 210, 'Randburg Studio', 1, 'f', 'scheduled'),
(NULL, '2026-03-29', '10:00', 210, 'Randburg Studio', 1, 'm', 'scheduled'),
(NULL, '2026-04-10', '15:00', 210, 'Randburg Studio', 1, 'm', 'scheduled'),
(NULL, '2026-04-11', '10:00', 210, 'Randburg Studio', 1, 'f', 'scheduled'),
(NULL, '2026-04-12', '10:00', 210, 'Randburg Studio', 1, 'f', 'scheduled'),
(NULL, '2026-04-24', '15:00', 210, 'Randburg Studio', 1, 'f', 'scheduled'),
(NULL, '2026-04-25', '10:00', 210, 'Randburg Studio', 1, 'm', 'scheduled'),
(NULL, '2026-04-26', '10:00', 210, 'Randburg Studio', 1, 'f', 'scheduled');

-- André as facilitator for all sessions
INSERT INTO ld_session_participants (session_id, user_id, role, attended) VALUES
(1, 1, 'facilitator', 1),
(2, 1, 'facilitator', 1),
(3, 1, 'facilitator', 0),
(4, 1, 'facilitator', 0),
(5, 1, 'facilitator', 0),
(6, 1, 'facilitator', 0),
(7, 1, 'facilitator', 0),
(8, 1, 'facilitator', 0),
(9, 1, 'facilitator', 0),
(10, 1, 'facilitator', 0),
(11, 1, 'facilitator', 0),
(12, 1, 'facilitator', 0),
(13, 1, 'facilitator', 0),
(14, 1, 'facilitator', 0),
(15, 1, 'facilitator', 0),
(16, 1, 'facilitator', 0);

-- Baseline stats for André
INSERT INTO ld_artist_stats (user_id, total_sessions, total_artworks, current_streak, longest_streak, last_session_date, media_explored) VALUES
(1, 2, 0, 2, 2, '2026-02-14', '[]');
