SET @auto_approve_teams_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'games'
    AND COLUMN_NAME = 'auto_approve_teams'
);

SET @auto_approve_teams_sql = IF(
  @auto_approve_teams_exists = 0,
  'ALTER TABLE games ADD COLUMN auto_approve_teams TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);

PREPARE auto_approve_teams_stmt FROM @auto_approve_teams_sql;
EXECUTE auto_approve_teams_stmt;
DEALLOCATE PREPARE auto_approve_teams_stmt;
