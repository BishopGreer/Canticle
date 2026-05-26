-- 006_inbox_safety.sql
-- Federation health: tombstones and delivery failure tracking

-- Tombstones: record URIs of remote objects that have been deleted so that
-- if a late-arriving Create/Announce for the same URI shows up after a Delete,
-- we ignore it.  Rows are pruned by the nightly pruner after 7 days.
CREATE TABLE IF NOT EXISTS tombstones (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uri        VARCHAR(2048) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tombstones_uri        (uri(512)),
    INDEX idx_tombstones_created_at (created_at)
);

-- Track delivery outcomes per remote domain.
-- delivery_failures: consecutive failed attempts (reset to 0 on success).
-- last_delivery_at:  timestamp of the most recent attempt (success or fail).
ALTER TABLE instances
    ADD COLUMN IF NOT EXISTS delivery_failures INT      NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS last_delivery_at  DATETIME     NULL;
