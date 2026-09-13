-- Life Drawing: separate regularity from intensity.
--
-- longest_streak counted consecutive ISO calendar weeks, which the schedule makes
-- unreachable: sessions run fortnightly, so of 108 adjacent session-week pairs in the
-- whole history only 16 are consecutive weeks. 245 of 250 people were stuck on 1, and
-- someone with 88 sessions over four years read as never having been regular.
--
-- Two measures now, and they answer different questions:
--
--   streak       a run of WEEKENDS THE VENUE RAN where the person attended at least one
--                session. Skipping a weekend the venue was closed costs nothing, so this
--                survives the schedule changing cadence. Regularity.
--
--   superstreak  a run of consecutive SESSIONS, in the order they were held. Coming to
--                all of a Friday/Saturday/Sunday weekend makes ONE run of length 3 --
--                the length is in sessions, not weekends, and three in a row need not
--                sit inside a single weekend. Missing a session ends it. Intensity.
--
-- Each has a LENGTH (longest_*) and a COUNT (*_count) and they are different numbers.
--
-- Counts as well as bests: a run of 2 or more counts as one streak, so the number of
-- times someone has strung a run together is visible, not just their record. The
-- threshold lives in StatsService::STREAK_MIN_RUN and is meant to be adjusted; changing
-- it needs only a refresh-stats run, not a migration, because the bests stored here are
-- raw run lengths.
--
-- current_streak and longest_streak keep their names and are recomputed against the new
-- weekend definition. Run tools/refresh-stats.php after this migration or every row
-- still holds the old calendar-week numbers.
--
-- If this migration fails partway, MySQL will already have committed the ALTER (DDL is
-- implicit-commit) while the runner records nothing, so a re-run dies on "Duplicate
-- column name". Recovery:
--   ALTER TABLE ld_artist_stats DROP COLUMN streak_count, DROP COLUMN current_superstreak,
--     DROP COLUMN longest_superstreak, DROP COLUMN superstreak_count;
-- then run the migration again.
ALTER TABLE ld_artist_stats
    ADD COLUMN streak_count        SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER longest_streak,
    ADD COLUMN current_superstreak SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER streak_count,
    ADD COLUMN longest_superstreak SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER current_superstreak,
    ADD COLUMN superstreak_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER longest_superstreak;
