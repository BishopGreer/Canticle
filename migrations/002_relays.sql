-- Migration 002: Relay subscriptions
-- Run with: php artisan.php migrate

CREATE TABLE IF NOT EXISTS relays (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  url        VARCHAR(512) NOT NULL UNIQUE COMMENT 'Base URL of the relay (e.g. https://relay.fedi.buzz)',
  actor_url  VARCHAR(512) NOT NULL        COMMENT 'Actor URL used for Follow/Undo activities',
  status     ENUM('pending','active','rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
