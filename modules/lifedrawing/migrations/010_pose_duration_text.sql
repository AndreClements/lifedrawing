-- Change pose_duration from INT (seconds) to free-text VARCHAR.
-- Allows descriptions like "3 poses x 30 sec, x 1.5 min, x 5 min".
-- Existing integer values (seconds) are converted to readable strings first.

UPDATE ld_artworks SET pose_duration = CASE
    WHEN pose_duration IS NULL THEN NULL
    WHEN CAST(pose_duration AS UNSIGNED) < 60 THEN CONCAT(pose_duration, 's')
    WHEN CAST(pose_duration AS UNSIGNED) % 60 = 0 THEN CONCAT(CAST(pose_duration AS UNSIGNED) DIV 60, ' min')
    ELSE CONCAT(ROUND(CAST(pose_duration AS UNSIGNED) / 60, 1), ' min')
END WHERE pose_duration IS NOT NULL;

ALTER TABLE ld_artworks MODIFY COLUMN pose_duration VARCHAR(200) NULL;
