-- Make session title optional (most sessions are just day + date + model)
ALTER TABLE ld_sessions MODIFY title VARCHAR(200) NULL;
