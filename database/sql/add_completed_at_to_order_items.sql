-- Add order_items.completed_at for stable publisher earnings dating.
-- Safe to re-run: skips if the column already exists.

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'order_items'
    AND COLUMN_NAME = 'completed_at'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `completed_at` TIMESTAMP NULL DEFAULT NULL, ADD INDEX `order_items_completed_at_index` (`completed_at`)',
  'SELECT ''order_items.completed_at already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill completed orders that are missing a stamp.
UPDATE `order_items` oi
INNER JOIN `orders` o ON o.id = oi.order_id
SET oi.completed_at = o.updated_at
WHERE oi.completed_at IS NULL
  AND o.status = 'completed';
