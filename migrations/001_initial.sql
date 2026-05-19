-- Canticle initial database schema
-- MariaDB 10.4+ / MySQL 8+
-- Character set: utf8mb4 throughout for full emoji + Unicode support

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ── Users ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`          VARCHAR(64)     NOT NULL,
  `email`             VARCHAR(255)    NOT NULL,
  `password_hash`     VARCHAR(255)    NOT NULL,
  `display_name`      VARCHAR(128)    NOT NULL DEFAULT '',
  `bio`               TEXT            NOT NULL DEFAULT '',
  `avatar`            VARCHAR(512)    NOT NULL DEFAULT '',
  `header`            VARCHAR(512)    NOT NULL DEFAULT '',
  `locked`            TINYINT(1)      NOT NULL DEFAULT 0,  -- requires follow approval
  `bot`               TINYINT(1)      NOT NULL DEFAULT 0,
  `discoverable`      TINYINT(1)      NOT NULL DEFAULT 1,
  `role`              ENUM('user','moderator','admin') NOT NULL DEFAULT 'user',
  `suspended`         TINYINT(1)      NOT NULL DEFAULT 0,
  `email_verified`    TINYINT(1)      NOT NULL DEFAULT 0,
  `email_verify_token` VARCHAR(128)   NULL DEFAULT NULL,
  -- ActivityPub keypair
  `private_key`       TEXT            NULL,
  `public_key`        TEXT            NOT NULL DEFAULT '',
  -- Counts (denormalised for speed)
  `statuses_count`    INT UNSIGNED    NOT NULL DEFAULT 0,
  `following_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `followers_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Remote actors (federated users) ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `remote_actors` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uri`               VARCHAR(2048)   NOT NULL,   -- canonical AP URI
  `username`          VARCHAR(128)    NOT NULL,
  `domain`            VARCHAR(255)    NOT NULL,
  `display_name`      VARCHAR(255)    NOT NULL DEFAULT '',
  `bio`               TEXT            NOT NULL DEFAULT '',
  `avatar`            VARCHAR(512)    NOT NULL DEFAULT '',
  `header`            VARCHAR(512)    NOT NULL DEFAULT '',
  `locked`            TINYINT(1)      NOT NULL DEFAULT 0,
  `bot`               TINYINT(1)      NOT NULL DEFAULT 0,
  `public_key`        TEXT            NOT NULL DEFAULT '',
  `public_key_id`     VARCHAR(2048)   NOT NULL DEFAULT '',
  `inbox_url`         VARCHAR(2048)   NOT NULL DEFAULT '',
  `outbox_url`        VARCHAR(2048)   NOT NULL DEFAULT '',
  `shared_inbox_url`  VARCHAR(2048)   NOT NULL DEFAULT '',
  `followers_url`     VARCHAR(2048)   NOT NULL DEFAULT '',
  `following_url`     VARCHAR(2048)   NOT NULL DEFAULT '',
  `raw_json`          MEDIUMTEXT      NULL,
  `followers_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `following_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `statuses_count`    INT UNSIGNED    NOT NULL DEFAULT 0,
  `fetched_at`        DATETIME        NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_uri` (`uri`(512)),
  KEY `idx_domain` (`domain`),
  KEY `idx_username_domain` (`username`, `domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Statuses ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `statuses` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- author: one of local_user_id XOR remote_actor_id
  `local_user_id`     BIGINT UNSIGNED NULL,
  `remote_actor_id`   BIGINT UNSIGNED NULL,
  `uri`               VARCHAR(2048)   NOT NULL,   -- canonical AP URI
  `url`               VARCHAR(2048)   NOT NULL DEFAULT '',
  `reply_to_id`       BIGINT UNSIGNED NULL,
  `reply_to_uri`      VARCHAR(2048)   NULL,
  `reblog_of_id`      BIGINT UNSIGNED NULL,       -- local boost
  `content`           MEDIUMTEXT      NOT NULL DEFAULT '',
  `content_warning`   VARCHAR(512)    NOT NULL DEFAULT '',
  `visibility`        ENUM('public','unlisted','private','direct') NOT NULL DEFAULT 'public',
  `language`          VARCHAR(8)      NOT NULL DEFAULT 'en',
  `sensitive`         TINYINT(1)      NOT NULL DEFAULT 0,
  `poll_id`           BIGINT UNSIGNED NULL,
  `replies_count`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `reblogs_count`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `favourites_count`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `edited_at`         DATETIME        NULL,
  `deleted_at`        DATETIME        NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_uri` (`uri`(512)),
  KEY `idx_local_user` (`local_user_id`, `created_at`),
  KEY `idx_remote_actor` (`remote_actor_id`, `created_at`),
  KEY `idx_reply_to` (`reply_to_id`),
  KEY `idx_public_timeline` (`visibility`, `deleted_at`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Media attachments ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `media` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `local_user_id`     BIGINT UNSIGNED NULL,
  `status_id`         BIGINT UNSIGNED NULL,
  `type`              ENUM('image','video','gifv','audio','unknown') NOT NULL DEFAULT 'image',
  `file_path`         VARCHAR(512)    NOT NULL DEFAULT '',
  `file_name`         VARCHAR(255)    NOT NULL DEFAULT '',
  `mime_type`         VARCHAR(128)    NOT NULL DEFAULT '',
  `file_size`         INT UNSIGNED    NOT NULL DEFAULT 0,
  `width`             SMALLINT UNSIGNED NULL,
  `height`            SMALLINT UNSIGNED NULL,
  `blurhash`          VARCHAR(128)    NOT NULL DEFAULT '',
  `description`       TEXT            NOT NULL DEFAULT '',   -- alt text
  `remote_url`        VARCHAR(2048)   NOT NULL DEFAULT '',
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status_id`),
  KEY `idx_user` (`local_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Polls ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `polls` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status_id`         BIGINT UNSIGNED NULL,
  `multiple`          TINYINT(1)      NOT NULL DEFAULT 0,
  `votes_count`       INT UNSIGNED    NOT NULL DEFAULT 0,
  `voters_count`      INT UNSIGNED    NOT NULL DEFAULT 0,
  `expires_at`        DATETIME        NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_options` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `poll_id`           BIGINT UNSIGNED NOT NULL,
  `position`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `title`             VARCHAR(255)    NOT NULL,
  `votes_count`       INT UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_poll` (`poll_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_votes` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `poll_id`           BIGINT UNSIGNED NOT NULL,
  `poll_option_id`    BIGINT UNSIGNED NOT NULL,
  `local_user_id`     BIGINT UNSIGNED NULL,
  `remote_actor_id`   BIGINT UNSIGNED NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vote` (`poll_id`, `poll_option_id`, `local_user_id`, `remote_actor_id`),
  KEY `idx_poll` (`poll_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Follows ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `follows` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- follower
  `follower_local_id`  BIGINT UNSIGNED NULL,
  `follower_remote_id` BIGINT UNSIGNED NULL,
  -- followee
  `followee_local_id`  BIGINT UNSIGNED NULL,
  `followee_remote_id` BIGINT UNSIGNED NULL,
  `state`             ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'accepted',
  `uri`               VARCHAR(2048)   NULL,   -- AP Follow activity URI
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_follower_local`  (`follower_local_id`),
  KEY `idx_follower_remote` (`follower_remote_id`),
  KEY `idx_followee_local`  (`followee_local_id`),
  KEY `idx_followee_remote` (`followee_remote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Favourites ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `favourites` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status_id`         BIGINT UNSIGNED NOT NULL,
  `local_user_id`     BIGINT UNSIGNED NULL,
  `remote_actor_id`   BIGINT UNSIGNED NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fav` (`status_id`, `local_user_id`, `remote_actor_id`),
  KEY `idx_user` (`local_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Blocks & Mutes ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `blocks` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `blocker_id`        BIGINT UNSIGNED NOT NULL,  -- local user
  `blocked_local_id`  BIGINT UNSIGNED NULL,
  `blocked_remote_id` BIGINT UNSIGNED NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_block_local`  (`blocker_id`, `blocked_local_id`),
  UNIQUE KEY `uq_block_remote` (`blocker_id`, `blocked_remote_id`),
  KEY `idx_blocker` (`blocker_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mutes` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `muter_id`          BIGINT UNSIGNED NOT NULL,  -- local user
  `muted_local_id`    BIGINT UNSIGNED NULL,
  `muted_remote_id`   BIGINT UNSIGNED NULL,
  `notifications`     TINYINT(1)      NOT NULL DEFAULT 1,  -- also mute notifications
  `expires_at`        DATETIME        NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mute_local`  (`muter_id`, `muted_local_id`),
  UNIQUE KEY `uq_mute_remote` (`muter_id`, `muted_remote_id`),
  KEY `idx_muter` (`muter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Federated instances ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `instances` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain`            VARCHAR(255)    NOT NULL,
  `software`          VARCHAR(128)    NOT NULL DEFAULT '',
  `version`           VARCHAR(64)     NOT NULL DEFAULT '',
  `status`            ENUM('allowed','blocked','silenced') NOT NULL DEFAULT 'allowed',
  `block_reason`      TEXT            NULL,
  `info_json`         MEDIUMTEXT      NULL,
  `last_seen`         DATETIME        NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Notifications ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           BIGINT UNSIGNED NOT NULL,   -- local recipient
  `type`              ENUM('mention','status','reblog','follow','follow_request','favourite','poll','update','admin.sign_up','admin.report') NOT NULL,
  `from_local_id`     BIGINT UNSIGNED NULL,
  `from_remote_id`    BIGINT UNSIGNED NULL,
  `status_id`         BIGINT UNSIGNED NULL,
  `read_at`           DATETIME        NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `read_at`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── OAuth applications ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `oauth_apps` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`              VARCHAR(255)    NOT NULL,
  `client_id`         VARCHAR(64)     NOT NULL,
  `client_secret`     VARCHAR(64)     NOT NULL,
  `redirect_uris`     TEXT            NOT NULL,
  `scopes`            VARCHAR(512)    NOT NULL DEFAULT 'read',
  `website`           VARCHAR(512)    NOT NULL DEFAULT '',
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oauth_tokens` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           BIGINT UNSIGNED NOT NULL,
  `app_id`            BIGINT UNSIGNED NOT NULL,
  `access_token`      VARCHAR(128)    NOT NULL,
  `refresh_token`     VARCHAR(128)    NULL,
  `scopes`            VARCHAR(512)    NOT NULL DEFAULT 'read',
  `revoked`           TINYINT(1)      NOT NULL DEFAULT 0,
  `expires_at`        DATETIME        NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_access_token` (`access_token`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oauth_codes` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           BIGINT UNSIGNED NOT NULL,
  `app_id`            BIGINT UNSIGNED NOT NULL,
  `code`              VARCHAR(128)    NOT NULL,
  `scopes`            VARCHAR(512)    NOT NULL DEFAULT 'read',
  `redirect_uri`      VARCHAR(512)    NOT NULL DEFAULT '',
  `used`              TINYINT(1)      NOT NULL DEFAULT 0,
  `expires_at`        DATETIME        NOT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Queue (async job delivery) ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `queue_jobs` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue`             VARCHAR(64)     NOT NULL DEFAULT 'default',
  `payload`           MEDIUMTEXT      NOT NULL,
  `attempts`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `reserved_at`       DATETIME        NULL,
  `available_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_queue_available` (`queue`, `reserved_at`, `available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_failed` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue`             VARCHAR(64)     NOT NULL DEFAULT 'default',
  `payload`           MEDIUMTEXT      NOT NULL,
  `exception`         TEXT            NOT NULL,
  `failed_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Rate limit buckets ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `key_`              VARCHAR(128)    NOT NULL,
  `tokens`            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `last_refill`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`key_`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Instance settings (key/value) ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `settings` (
  `key_`              VARCHAR(128)    NOT NULL,
  `value`             MEDIUMTEXT      NULL,
  PRIMARY KEY (`key_`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Hashtags ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `hashtags` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`              VARCHAR(128)    NOT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `status_hashtags` (
  `status_id`         BIGINT UNSIGNED NOT NULL,
  `hashtag_id`        BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`status_id`, `hashtag_id`),
  KEY `idx_hashtag` (`hashtag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Mentions ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mentions` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status_id`         BIGINT UNSIGNED NOT NULL,
  `local_user_id`     BIGINT UNSIGNED NULL,
  `remote_actor_id`   BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status_id`),
  KEY `idx_local_user` (`local_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Status edits ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `status_edits` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status_id`         BIGINT UNSIGNED NOT NULL,
  `content`           MEDIUMTEXT      NOT NULL,
  `content_warning`   VARCHAR(512)    NOT NULL DEFAULT '',
  `sensitive`         TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Migrations tracking ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `migrations` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename`          VARCHAR(255) NOT NULL,
  `ran_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('001_initial.sql');
