-- Hostinger / phpMyAdmin: attribute order packages to advertiser campaigns (projects)
-- Safe to run once. Skip if `orders.project_id` already exists.

ALTER TABLE `orders`
  ADD COLUMN `project_id` bigint unsigned NULL DEFAULT NULL AFTER `user_id`,
  ADD INDEX `orders_user_id_project_id_index` (`user_id`, `project_id`),
  ADD CONSTRAINT `orders_project_id_foreign`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL;
