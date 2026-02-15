-- Add model sex indicator to sessions
-- Practical info for figure study: 'f' (female), 'm' (male), NULL (unspecified)
ALTER TABLE ld_sessions ADD COLUMN model_sex CHAR(1) NULL AFTER facilitator_id;
