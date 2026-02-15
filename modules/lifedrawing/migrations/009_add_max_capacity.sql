-- Session capacity: default 7, minimum 3 to proceed
ALTER TABLE ld_sessions ADD COLUMN max_capacity TINYINT UNSIGNED NOT NULL DEFAULT 7 AFTER model_sex;
