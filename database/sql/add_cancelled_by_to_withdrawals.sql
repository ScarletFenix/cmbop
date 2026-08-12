-- Hostinger / phpMyAdmin: distinguish publisher self-cancel vs admin reject
-- Safe to re-run: ignore "Duplicate column name" errors.

ALTER TABLE `withdrawals` ADD COLUMN `cancelled_by` varchar(20) NULL;
ALTER TABLE `withdrawals` ADD COLUMN `cancelled_at` timestamp NULL;
