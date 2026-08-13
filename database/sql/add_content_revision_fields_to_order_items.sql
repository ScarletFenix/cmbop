-- Hostinger / phpMyAdmin: publisher content-revision fields on order_items
-- Fixes: Unknown column 'content_revision_requested' in 'where clause'
-- Safe to re-run: ignore "Duplicate column name" errors.

ALTER TABLE `order_items` ADD COLUMN `content_revision_requested` varchar(10) NULL DEFAULT 'no';
ALTER TABLE `order_items` ADD COLUMN `content_revision_requested_at` timestamp NULL;
ALTER TABLE `order_items` ADD COLUMN `content_revision_reason` text NULL;
ALTER TABLE `order_items` ADD COLUMN `content_revision_resolved_at` timestamp NULL;
