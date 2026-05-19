-- Migration 005: Federation block reasons
-- Adds separate public and private reason columns to the instances table
-- so admins can record an internal note alongside any public-facing explanation.
-- The existing block_reason column is kept and copied into public_reason.

ALTER TABLE `instances`
  ADD COLUMN `public_reason`  TEXT NULL DEFAULT NULL AFTER `block_reason`,
  ADD COLUMN `private_reason` TEXT NULL DEFAULT NULL AFTER `public_reason`;

-- Seed public_reason from the existing block_reason column
UPDATE `instances` SET `public_reason` = `block_reason` WHERE `block_reason` IS NOT NULL AND `block_reason` != '';

INSERT IGNORE INTO `migrations` (`filename`) VALUES ('005_federation_block_reasons.sql');
