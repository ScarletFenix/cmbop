-- Personalised weekly new-site digest for advertisers.
-- Safe to re-run. App code is Schema-guarded so the site works before this runs.
-- Equivalent migration: 2026_11_08_100000_add_weekly_new_sites_digest
--
-- Does NOT send email. Does NOT enable unrelated reminders.
-- The feature default is ON in application config; this SQL only creates tables
-- and a one-time activation watermark so the historical catalogue is not emailed.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- sites.first_catalogued_at (first time a listing became catalogue-visible)
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'first_catalogued_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `first_catalogued_at` TIMESTAMP NULL DEFAULT NULL AFTER `active`',
  'SELECT ''sites.first_catalogued_at already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing rows are the historical catalogue. Stamp them from created_at so
-- reapprovals / updates never look like a first go-live.
UPDATE `sites`
SET `first_catalogued_at` = `created_at`
WHERE `first_catalogued_at` IS NULL
  AND `created_at` IS NOT NULL;

-- ---------------------------------------------------------------------------
-- weekly_new_sites_settings (activation watermark + key/value)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `weekly_new_sites_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(255) NOT NULL,
  `value` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `weekly_new_sites_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `weekly_new_sites_settings` (`key`, `value`, `created_at`, `updated_at`)
SELECT 'activated_at', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1 FROM `weekly_new_sites_settings` WHERE `key` = 'activated_at'
);

-- ---------------------------------------------------------------------------
-- weekly_new_sites_interests (niche + country pairs per advertiser)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `weekly_new_sites_interests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `niche` VARCHAR(191) NOT NULL,
  `country_code` VARCHAR(8) NOT NULL,
  `source` VARCHAR(16) NOT NULL DEFAULT 'inferred',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wns_interests_user_pair` (`user_id`, `niche`, `country_code`),
  KEY `wns_interests_user_enabled` (`user_id`, `enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- weekly_new_sites_runs (scheduler / dry-run batches)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `weekly_new_sites_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `week_key` VARCHAR(16) NOT NULL,
  `dry_run` TINYINT(1) NOT NULL DEFAULT 0,
  `status` VARCHAR(24) NOT NULL DEFAULT 'running',
  `matched_advertisers` INT UNSIGNED NOT NULL DEFAULT 0,
  `queued` INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_thin` INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_permission` INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_other` INT UNSIGNED NOT NULL DEFAULT 0,
  `notes` TEXT NULL,
  `started_at` TIMESTAMP NULL DEFAULT NULL,
  `finished_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `wns_runs_week` (`week_key`, `dry_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- weekly_new_sites_recipients (one email per advertiser per week)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `weekly_new_sites_recipients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `week_key` VARCHAR(16) NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'queued',
  `permission_basis` VARCHAR(40) NULL DEFAULT NULL,
  `site_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `match_pairs` JSON NULL,
  `dedupe_key` VARCHAR(191) NULL DEFAULT NULL,
  `skip_reason` VARCHAR(64) NULL DEFAULT NULL,
  `queued_at` TIMESTAMP NULL DEFAULT NULL,
  `sent_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wns_recipients_user_week` (`user_id`, `week_key`),
  KEY `wns_recipients_run` (`run_id`),
  KEY `wns_recipients_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- weekly_new_sites_sends (idempotent per advertiser + site)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `weekly_new_sites_sends` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `site_id` BIGINT UNSIGNED NOT NULL,
  `domain` VARCHAR(191) NULL DEFAULT NULL,
  `niche` VARCHAR(191) NULL DEFAULT NULL,
  `country_code` VARCHAR(8) NULL DEFAULT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'queued',
  `price_eur` DECIMAL(10,2) NULL DEFAULT NULL,
  `sent_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wns_sends_user_site` (`user_id`, `site_id`),
  KEY `wns_sends_user_domain` (`user_id`, `domain`),
  KEY `wns_sends_recipient` (`recipient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- weekly_new_sites_clicks (click log; not causal attribution)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `weekly_new_sites_clicks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `site_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `recipient_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `kind` VARCHAR(24) NOT NULL DEFAULT 'site',
  `destination_path` VARCHAR(512) NULL DEFAULT NULL,
  `clicked_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `wns_clicks_user_site` (`user_id`, `site_id`, `clicked_at`),
  KEY `wns_clicks_recipient` (`recipient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- weekly_new_sites_tokens (opaque signed-action tokens; no user id in URLs)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `weekly_new_sites_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash` VARCHAR(64) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `purpose` VARCHAR(24) NOT NULL,
  `payload` JSON NULL,
  `expires_at` TIMESTAMP NULL DEFAULT NULL,
  `used_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `weekly_new_sites_tokens_token_hash_unique` (`token_hash`),
  KEY `wns_tokens_user_purpose` (`user_id`, `purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
