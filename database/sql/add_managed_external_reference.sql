-- Hostinger / phpMyAdmin: external email/thread reference for Managed by SLB desk
-- Safe to re-run: ignore "Duplicate column name" errors.

ALTER TABLE `order_items` ADD COLUMN `managed_external_reference` varchar(500) NULL;
