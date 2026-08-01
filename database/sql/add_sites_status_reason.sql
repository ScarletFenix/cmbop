-- Hostinger / phpMyAdmin: add publisher-facing status reason columns on sites
-- Needed for Verify reject / Deactivate (reason shown in email + in-app bell).
-- Safe to re-run: skip any statement that errors with "Duplicate column".

ALTER TABLE `sites`
  ADD COLUMN `status_reason` TEXT NULL;

ALTER TABLE `sites`
  ADD COLUMN `status_reason_at` TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE `sites`
  ADD COLUMN `status_reason_by` BIGINT UNSIGNED NULL DEFAULT NULL;

-- Optional FK (ignore if it already exists or engines differ):
-- ALTER TABLE `sites`
--   ADD CONSTRAINT `sites_status_reason_by_foreign`
--   FOREIGN KEY (`status_reason_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
