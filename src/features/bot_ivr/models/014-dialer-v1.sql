-- MariaDB 10.6+. Idempotent. Marcador v1: configuración por campaña, headers, reintentos, franjas y causas de cuelgue.
-- Run against zynervox_core after a dump: mysql zynervox_core < 014-dialer-v1.sql
-- dialer_state is 'stopped' by default: no campaign dials until the service starts it.
ALTER TABLE `zynervox_bot_campaigns`
  ADD COLUMN IF NOT EXISTS `amd_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `transfer_carrier_id` VARCHAR(80) CHARACTER SET ascii NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `transfer_destination` VARCHAR(40) CHARACTER SET ascii NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `call_origin` VARCHAR(20) CHARACTER SET ascii NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(40) CHARACTER SET ascii NOT NULL DEFAULT 'America/Lima',
  ADD COLUMN IF NOT EXISTS `dialer_state` VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT 'stopped',
  ADD COLUMN IF NOT EXISTS `dialer_changed_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `dialer_changed_by` VARCHAR(80) NOT NULL DEFAULT '';
-- max_channels is a free integer 1..500 (smallint unsigned already).
ALTER TABLE `zynervox_bot_campaigns`
  ADD CONSTRAINT IF NOT EXISTS `chk_campaign_max_channels` CHECK (`max_channels` BETWEEN 1 AND 500),
  ADD CONSTRAINT IF NOT EXISTS `chk_campaign_dialer_state` CHECK (`dialer_state` IN ('stopped','running'));

-- Header name -> lead field, defined by the campaign. lead_field: phone, customer_name or a key of the list's extra columns.
CREATE TABLE IF NOT EXISTS `zynervox_bot_campaign_headers` (
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `position` TINYINT UNSIGNED NOT NULL,
  `header_name` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `lead_field` VARCHAR(60) CHARACTER SET ascii NOT NULL,
  PRIMARY KEY (`campaign_id`, `position`),
  UNIQUE KEY `uq_campaign_header_name` (`campaign_id`, `header_name`),
  CONSTRAINT `fk_campaign_headers_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `zynervox_bot_campaigns` (`campaign_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A lead is dialed again when its status has an active rule, called_count < max_attempts and the delay has elapsed.
-- NEW is always dialable. Statuses without a rule are final.
CREATE TABLE IF NOT EXISTS `zynervox_bot_campaign_retry_rules` (
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `retry_delay_min` INT UNSIGNED NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`campaign_id`, `status`),
  CONSTRAINT `fk_campaign_retry_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `zynervox_bot_campaigns` (`campaign_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Call windows in the campaign timezone. weekday 1=Monday..7=Sunday (ISO). Without rows the campaign start/end_time applies when scheduled=1.
CREATE TABLE IF NOT EXISTS `zynervox_bot_campaign_schedules` (
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `weekday` TINYINT UNSIGNED NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  PRIMARY KEY (`campaign_id`, `weekday`, `start_time`),
  CONSTRAINT `fk_campaign_schedule_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `zynervox_bot_campaigns` (`campaign_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_campaign_schedule_day` CHECK (`weekday` BETWEEN 1 AND 7 AND `start_time` < `end_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hangup causes for every attempt. q850 stays in hangup_cause. cause_source: dialplan|ami|agi.
ALTER TABLE `zynervox_bot_call_attempts`
  ADD COLUMN IF NOT EXISTS `sip_cause` VARCHAR(120) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `cause_source` VARCHAR(10) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `disposition` VARCHAR(20) NOT NULL DEFAULT '';
ALTER TABLE `zynervox_bot_call_attempts`
  ADD INDEX IF NOT EXISTS `idx_call_disposition` (`campaign_id`, `disposition`, `started_at`);
