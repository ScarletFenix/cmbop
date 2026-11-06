-- Hostinger / phpMyAdmin: Managed by SLB email-managed fulfilment columns on order_items
-- Safe to re-run: ignore "Duplicate column name" errors.

ALTER TABLE `order_items` ADD COLUMN `fulfilment_source` varchar(32) NOT NULL DEFAULT 'publisher';
ALTER TABLE `order_items` ADD COLUMN `managed_assignee_id` bigint unsigned NULL;
ALTER TABLE `order_items` ADD COLUMN `managed_internal_status` varchar(40) NULL;
ALTER TABLE `order_items` ADD COLUMN `managed_notes` text NULL;
ALTER TABLE `order_items` ADD COLUMN `managed_marked_at` timestamp NULL;
ALTER TABLE `order_items` ADD COLUMN `managed_completed_by` bigint unsigned NULL;
