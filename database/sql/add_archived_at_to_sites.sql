-- Soft-archive for publisher sites (hide from catalog without deleting).
-- Safe to re-run.

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sites'
    AND COLUMN_NAME = 'archived_at'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `archived_at` TIMESTAMP NULL DEFAULT NULL, ADD INDEX `sites_archived_at_index` (`archived_at`)',
  'SELECT ''sites.archived_at already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
