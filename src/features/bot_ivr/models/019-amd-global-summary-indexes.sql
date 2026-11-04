-- MariaDB 10.6+. Idempotent. Marcador: AMD global con versiones, resumen por lista (sobrevive al borrado) e índices para dashboard y reportes.
-- Run against zynervox_core after a dump: mysql zynervox_core < 019-amd-global-summary-indexes.sql
-- No calls are made by this migration. Lists stay as they are (all stopped).

-- 1) Global AMD parameters, versioned. The current version id lives in zynervox_bot_dialer_settings ('amd_global_version').
-- A rollback creates a NEW version copying the old values, so the history stays linear. The per-campaign table (018) is kept as history and no longer used.
CREATE TABLE IF NOT EXISTS `zynervox_bot_amd_global_versions` (
  `version_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` VARCHAR(80) NOT NULL DEFAULT '',
  `note` VARCHAR(255) NOT NULL DEFAULT '',
  `from_version_id` BIGINT UNSIGNED NULL,
  `initial_silence` INT UNSIGNED NOT NULL,
  `greeting` INT UNSIGNED NOT NULL,
  `after_greeting_silence` INT UNSIGNED NOT NULL,
  `total_analysis_time` INT UNSIGNED NOT NULL,
  `minimum_word_length` INT UNSIGNED NOT NULL,
  `between_words_silence` INT UNSIGNED NOT NULL,
  `maximum_number_of_words` INT UNSIGNED NOT NULL,
  `silence_threshold` INT UNSIGNED NOT NULL,
  `maximum_word_length` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- First approved version (user, 04/11/2026): 4000,2500,800,8000,100,50,4,256,5000
INSERT INTO `zynervox_bot_amd_global_versions` (created_by, note, initial_silence, greeting, after_greeting_silence, total_analysis_time, minimum_word_length, between_words_silence, maximum_number_of_words, silence_threshold, maximum_word_length)
  SELECT 'migración 019', 'Primera versión aprobada por el usuario', 4000, 2500, 800, 8000, 100, 50, 4, 256, 5000 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `zynervox_bot_amd_global_versions`);
INSERT IGNORE INTO `zynervox_bot_dialer_settings` (`name`, `value`) SELECT 'amd_global_version', MIN(`version_id`) FROM `zynervox_bot_amd_global_versions`;
-- The audit keeps the effective values (already there) and now also the version used.
ALTER TABLE `zynervox_bot_amd_audit` ADD COLUMN IF NOT EXISTS `amd_version_id` BIGINT UNSIGNED NULL;

-- 2) One summary row per list. No FK on purpose: it must survive deleting the list or the campaign (history for reports).
-- Counters are cumulative and incremental: counted_attempt_id is the watermark of finished attempts already added.
-- TEST-% attempts are not counted; the AMD figures (human, machine, not_sure) count only attempts from amd_valid_from on (audio reached Asterisk).
-- state: running | stopped | finished | deleted.
CREATE TABLE IF NOT EXISTS `zynervox_bot_list_summary` (
  `list_id` BIGINT UNSIGNED NOT NULL,
  `campaign_id` BIGINT UNSIGNED NULL,
  `campaign_name` VARCHAR(120) NOT NULL DEFAULT '',
  `list_name` VARCHAR(120) NOT NULL DEFAULT '',
  `state` VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT 'stopped',
  `contacts` INT UNSIGNED NOT NULL DEFAULT 0,
  `not_called` INT UNSIGNED NOT NULL DEFAULT 0,
  `calls` INT UNSIGNED NOT NULL DEFAULT 0,
  `answered` INT UNSIGNED NOT NULL DEFAULT 0,
  `human` INT UNSIGNED NOT NULL DEFAULT 0,
  `machine` INT UNSIGNED NOT NULL DEFAULT 0,
  `not_sure` INT UNSIGNED NOT NULL DEFAULT 0,
  `unavailable` INT UNSIGNED NOT NULL DEFAULT 0,
  `no_answer` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed` INT UNSIGNED NOT NULL DEFAULT 0,
  `xfer_ok` INT UNSIGNED NOT NULL DEFAULT 0,
  `xfer_fail` INT UNSIGNED NOT NULL DEFAULT 0,
  `amd_invalid_calls` INT UNSIGNED NOT NULL DEFAULT 0,
  `contact_pct` DECIMAL(5,1) NOT NULL DEFAULT 0,
  `human_pct` DECIMAL(5,1) NOT NULL DEFAULT 0,
  `machine_pct` DECIMAL(5,1) NOT NULL DEFAULT 0,
  `unavailable_pct` DECIMAL(5,1) NOT NULL DEFAULT 0,
  `no_answer_pct` DECIMAL(5,1) NOT NULL DEFAULT 0,
  `first_call_at` DATETIME NULL,
  `last_call_at` DATETIME NULL,
  `duration_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `finished_at` DATETIME NULL,
  `deleted_at` DATETIME NULL,
  `counted_attempt_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`list_id`),
  KEY `idx_list_summary_campaign` (`campaign_id`, `state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Indexes for dashboard and reports (justified with EXPLAIN on 500,000 simulated attempts; see the marcador log).
ALTER TABLE `zynervox_bot_call_attempts`
  ADD INDEX IF NOT EXISTS `idx_call_open` (`finished_at`, `list_id`),
  ADD INDEX IF NOT EXISTS `idx_call_started` (`started_at`),
  ADD INDEX IF NOT EXISTS `idx_call_list_attempt` (`list_id`, `attempt_id`);
