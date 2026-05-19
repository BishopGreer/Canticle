-- Migration 003: Remote content retention & pruning
-- Adds a prune_log table so admins can see when pruning ran and what was removed.
-- Inserts default retention settings into the key/value settings table.
-- Run with: php artisan.php migrate

-- ── Prune log ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `prune_log` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ran_at`              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `statuses_removed`    INT UNSIGNED    NOT NULL DEFAULT 0,
  `media_files_removed` INT UNSIGNED    NOT NULL DEFAULT 0,
  `media_bytes_freed`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `actors_removed`      INT UNSIGNED    NOT NULL DEFAULT 0,
  `cutoff_days`         SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  `notes`               TEXT            NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Default retention settings ────────────────────────────────────────────────
-- remote_status_max_days  : delete remote statuses older than this many days
--                           (0 = keep forever)
-- remote_actor_max_days   : delete cached remote actor profiles with no recent
--                           activity and no local followers (0 = keep forever)
INSERT IGNORE INTO `settings` (`key_`, `value`) VALUES
  ('remote_status_max_days', '90'),
  ('remote_actor_max_days',  '180');

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('003_remote_content_retention.sql');
