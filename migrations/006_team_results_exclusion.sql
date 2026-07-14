SET @excluded_from_results_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'teams'
    AND COLUMN_NAME = 'excluded_from_results'
);

SET @excluded_from_results_sql = IF(
  @excluded_from_results_exists = 0,
  'ALTER TABLE teams ADD COLUMN excluded_from_results TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);

PREPARE excluded_from_results_stmt FROM @excluded_from_results_sql;
EXECUTE excluded_from_results_stmt;
DEALLOCATE PREPARE excluded_from_results_stmt;
