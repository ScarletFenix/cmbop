USE u841082722_cmbop_db;

-- Hostinger phpMyAdmin / mysql client. Safe to re-run.
-- Link insertion product: publisher listing fields + order-item product type
-- and optional paid Link guarantee add-on (10% of item total).
-- Equivalent migration: 2026_11_12_120000_add_link_insertion_product

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- sites: link insertion listing
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'accepts_link_insertions'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `accepts_link_insertions` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''sites.accepts_link_insertions already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'listing_link_insertion_price'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `listing_link_insertion_price` DECIMAL(10,2) NULL DEFAULT NULL',
  'SELECT ''sites.listing_link_insertion_price already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'link_insertion_price'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `link_insertion_price` DECIMAL(10,2) NULL DEFAULT NULL',
  'SELECT ''sites.link_insertion_price already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'link_insertion_min_article_age_days'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `link_insertion_min_article_age_days` INT UNSIGNED NULL DEFAULT NULL',
  'SELECT ''sites.link_insertion_min_article_age_days already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'link_insertion_allow_high_traffic'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `link_insertion_allow_high_traffic` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''sites.link_insertion_allow_high_traffic already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'link_insertion_link_type'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `link_insertion_link_type` VARCHAR(20) NOT NULL DEFAULT ''dofollow''',
  'SELECT ''sites.link_insertion_link_type already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'link_insertion_delivery_days'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `link_insertion_delivery_days` INT UNSIGNED NOT NULL DEFAULT 7',
  'SELECT ''sites.link_insertion_delivery_days already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- order_items: product type + insertion brief + item-level Link guarantee
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'product_type'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `product_type` VARCHAR(32) NOT NULL DEFAULT ''guest_post''',
  'SELECT ''order_items.product_type already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'preferred_article_url'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `preferred_article_url` VARCHAR(1000) NULL DEFAULT NULL',
  'SELECT ''order_items.preferred_article_url already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'insertion_sentence_same'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `insertion_sentence_same` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''order_items.insertion_sentence_same already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'insertion_sentence_new'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `insertion_sentence_new` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''order_items.insertion_sentence_new already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'insertion_sentence_new_text'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `insertion_sentence_new_text` TEXT NULL',
  'SELECT ''order_items.insertion_sentence_new_text already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'link_guarantee'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `link_guarantee` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''order_items.link_guarantee already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'link_guarantee_fee'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `link_guarantee_fee` DECIMAL(10,2) NOT NULL DEFAULT 0',
  'SELECT ''order_items.link_guarantee_fee already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'link_guarantee_covered_amount'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `link_guarantee_covered_amount` DECIMAL(10,2) NOT NULL DEFAULT 0',
  'SELECT ''order_items.link_guarantee_covered_amount already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
