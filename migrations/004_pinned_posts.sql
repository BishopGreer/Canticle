-- Migration 004: Pinned posts
-- Adds a pinned_at timestamp to statuses so local users can pin posts to
-- the top of their profile. NULL means not pinned, non-NULL means pinned.

ALTER TABLE `statuses`
  ADD COLUMN `pinned_at` DATETIME NULL DEFAULT NULL AFTER `deleted_at`;

ALTER TABLE `statuses`
  ADD INDEX `idx_pinned` (`local_user_id`, `pinned_at`);

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('004_pinned_posts.sql');
