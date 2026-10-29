-- MariaDB 10.6+. Run against the configured Bot IVR database.
-- Example: mysql zynervox_core < 005-lead-dialer-fields.sql
-- NEW: not yet attempted. The future engine owns status transitions.
-- Attempts count dial attempts; last_call_at/next_call_at must be written in UTC.
-- DDL implicitly commits. No call engine or concurrent claim mechanism here.
ALTER TABLE `zynervox_bot_list`
  ADD COLUMN IF NOT EXISTS `status` VARCHAR(6) NOT NULL DEFAULT 'NEW',
  ADD COLUMN IF NOT EXISTS `called_count` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `last_call_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `next_call_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `zynervox_bot_list`
  ADD INDEX IF NOT EXISTS `idx_bot_list_dial_queue` (`list_id`, `status`, `next_call_at`, `lead_id`);
