-- Hostinger / phpMyAdmin: homepage promotions + social offers
-- Required for catalog Site Details to show Homepage promotions and Social.
-- Safe to re-run: ignore "Duplicate column name" errors in phpMyAdmin.
-- Equivalent migration: 2026_08_12_120000_add_homepage_social_placement_to_sites_and_order_items

SET NAMES utf8mb4;

ALTER TABLE `sites`
  ADD COLUMN `homepage_placement_prices` json NULL DEFAULT NULL AFTER `sensitive_prices`;

ALTER TABLE `sites`
  ADD COLUMN `social_promotion` json NULL DEFAULT NULL AFTER `homepage_placement_prices`;

ALTER TABLE `order_items`
  ADD COLUMN `homepage_days` smallint unsigned NULL DEFAULT NULL AFTER `additional_price`;

ALTER TABLE `order_items`
  ADD COLUMN `homepage_price` decimal(10,2) NOT NULL DEFAULT 0 AFTER `homepage_days`;

ALTER TABLE `order_items`
  ADD COLUMN `social_channels` json NULL DEFAULT NULL AFTER `homepage_price`;

ALTER TABLE `order_items`
  ADD COLUMN `social_post_urls` json NULL DEFAULT NULL AFTER `social_channels`;
