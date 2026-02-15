-- Life Drawing Randburg: Demo Seed Data
-- Run: mysql -u root lifedrawing < database/seeds/demo.sql
-- Or: php tools/seed.php

-- Users (password for all demo users: 'password123')
-- bcrypt hash of 'password123' with cost 12
SET @pw = '$2y$12$LJ3m4ys3UYkRh1gGOqP8/.vZXVHKJxKZiNI7C.Zc8NZPbJQKD2bxe';

INSERT INTO users (display_name, email, password_hash, role, consent_state, consent_granted_at, bio) VALUES
('André Clements',  'andre@example.com',  @pw, 'facilitator', 'granted', NOW(), 'Facilitator, artist, and occasional model. Every session is an experiment in shared attention.'),
('Sarah Ndlovu',    'sarah@example.com',  @pw, 'participant', 'granted', NOW(), 'Charcoal and ink. I draw to understand the weight of things.'),
('James van Wyk',   'james@example.com',  @pw, 'participant', 'granted', NOW(), 'Watercolour and graphite. Finding the gesture in stillness.'),
('Palesa Mokoena',  'palesa@example.com', @pw, 'participant', 'granted', NOW(), 'Model and artist — the circulation of roles teaches something new each time.'),
('David Nkosi',     'david@example.com',  @pw, 'participant', 'granted', NOW(), NULL);

-- Sessions
INSERT INTO ld_sessions (title, session_date, start_time, duration_minutes, venue, description, facilitator_id, status) VALUES
('Long Pose Friday',        '2026-01-10', '15:00', 210, 'Randburg Studio', 'Extended poses — 20, 30, and 40 minute. Bring your patience and your best media.', 1, 'completed'),
('Gesture Saturday',        '2026-01-11', '10:00', 210, 'Randburg Studio', 'Quick gesture drawings. 1, 2, and 5-minute poses to loosen up the hand.', 1, 'completed'),
('Mixed Pose Friday',       '2026-01-17', '15:00', 210, 'Randburg Studio', 'A mix of gesture and sustained poses. All levels welcome. Presence, curiosity, and respect.', 1, 'completed'),
('Charcoal Focus',          '2026-01-24', '15:00', 210, 'Randburg Studio', 'Charcoal only session. Vine, compressed, and willow provided.', 1, 'completed'),
('Saturday Open Session',   '2026-02-01', '10:00', 210, 'Randburg Studio', 'All media welcome. Come draw, come model, come watch. R350 or as near as is affordable.', 1, 'completed'),
('Ink & Brush',             '2026-02-14', '15:00', 210, 'Randburg Studio', 'India ink and brush. Embrace the irreversible mark.', 1, 'scheduled');

-- Session Participants
INSERT INTO ld_session_participants (session_id, user_id, role, attended) VALUES
-- Session 1: Long Pose Evening
(1, 1, 'facilitator', 1),
(1, 2, 'artist', 1),
(1, 3, 'artist', 1),
(1, 4, 'model', 1),
(1, 5, 'artist', 1),
-- Session 2: Gesture Drawing
(2, 1, 'facilitator', 1),
(2, 2, 'artist', 1),
(2, 3, 'artist', 1),
(2, 4, 'artist', 1),
-- Session 3: Mixed Pose
(3, 1, 'facilitator', 1),
(3, 2, 'artist', 1),
(3, 4, 'model', 1),
(3, 5, 'artist', 1),
-- Session 4: Charcoal Focus
(4, 1, 'facilitator', 1),
(4, 2, 'artist', 1),
(4, 3, 'artist', 1),
(4, 4, 'artist', 1),
(4, 5, 'artist', 1),
-- Session 5: February Open
(5, 1, 'facilitator', 1),
(5, 2, 'artist', 1),
(5, 3, 'model', 1),
(5, 4, 'artist', 1);

-- Note: No artworks or claims in demo seed. These require actual uploaded images.
-- The stats will be computed from session participation data.

-- Compute initial stats
-- (The refresh-stats.php tool will compute these properly. These are baseline values.)
INSERT INTO ld_artist_stats (user_id, total_sessions, total_artworks, current_streak, longest_streak, last_session_date, media_explored) VALUES
(1, 5, 0, 5, 5, '2026-02-07', '[]'),
(2, 5, 0, 5, 5, '2026-02-07', '[]'),
(3, 3, 0, 2, 2, '2026-02-07', '[]'),
(4, 5, 0, 5, 5, '2026-02-07', '[]'),
(5, 3, 0, 2, 2, '2026-01-31', '[]');
