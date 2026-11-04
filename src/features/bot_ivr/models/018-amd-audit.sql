-- MariaDB 10.6+. Idempotent. Marcador: parámetros AMD por campaña y auditoría AMD por llamada contestada (con STT posterior).
-- Run against zynervox_core after a dump: mysql zynervox_core < 018-amd-audit.sql
-- No calls are made by this migration.

-- The 9 AMD() arguments, per campaign. Initial values = the current ones (amd.conf plus totalAnalysisTime 8000).
-- Order of AMD(): initialSilence,greeting,afterGreetingSilence,totalAnalysisTime,minimumWordLength,betweenWordsSilence,maximumNumberOfWords,silenceThreshold,maximumWordLength.
CREATE TABLE IF NOT EXISTS `zynervox_bot_campaign_amd_params` (
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `initial_silence` INT UNSIGNED NOT NULL DEFAULT 2500,
  `greeting` INT UNSIGNED NOT NULL DEFAULT 1500,
  `after_greeting_silence` INT UNSIGNED NOT NULL DEFAULT 800,
  `total_analysis_time` INT UNSIGNED NOT NULL DEFAULT 8000,
  `minimum_word_length` INT UNSIGNED NOT NULL DEFAULT 100,
  `between_words_silence` INT UNSIGNED NOT NULL DEFAULT 50,
  `maximum_number_of_words` INT UNSIGNED NOT NULL DEFAULT 2,
  `silence_threshold` INT UNSIGNED NOT NULL DEFAULT 256,
  `maximum_word_length` INT UNSIGNED NOT NULL DEFAULT 5000,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`campaign_id`),
  CONSTRAINT `fk_campaign_amd_params_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `zynervox_bot_campaigns` (`campaign_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `zynervox_bot_campaign_amd_params` (`campaign_id`) SELECT `campaign_id` FROM `zynervox_bot_campaigns`;

-- One row per call launched with AMD; rows of calls that were never answered are removed by the sync step.
-- audit_state: open (launched) -> ready (answered, result copied) ; stt_status: pending | done | error | no_audio.
-- Parameters are a snapshot of what the call used. No FK to attempts/campaigns: the history must survive deleting lists and campaigns.
CREATE TABLE IF NOT EXISTS `zynervox_bot_amd_audit` (
  `audit_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `call_id` VARCHAR(80) CHARACTER SET ascii NOT NULL,
  `attempt_id` BIGINT UNSIGNED NULL,
  `campaign_id` BIGINT UNSIGNED NULL,
  `list_id` BIGINT UNSIGNED NULL,
  `lead_id` BIGINT UNSIGNED NULL,
  `audit_state` VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT 'open',
  `amd_status` VARCHAR(20) CHARACTER SET ascii NOT NULL DEFAULT '',
  `amd_cause` VARCHAR(160) CHARACTER SET ascii NOT NULL DEFAULT '',
  `decided_ms` INT UNSIGNED NULL,
  `initial_silence` INT UNSIGNED NOT NULL,
  `greeting` INT UNSIGNED NOT NULL,
  `after_greeting_silence` INT UNSIGNED NOT NULL,
  `total_analysis_time` INT UNSIGNED NOT NULL,
  `minimum_word_length` INT UNSIGNED NOT NULL,
  `between_words_silence` INT UNSIGNED NOT NULL,
  `maximum_number_of_words` INT UNSIGNED NOT NULL,
  `silence_threshold` INT UNSIGNED NOT NULL,
  `maximum_word_length` INT UNSIGNED NOT NULL,
  `audio_path` VARCHAR(512) NULL,
  `audio_seconds` DECIMAL(8,2) NULL,
  `stt_status` VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT 'pending',
  `stt_text` MEDIUMTEXT NULL,
  `stt_engine` VARCHAR(60) NOT NULL DEFAULT '',
  `stt_at` DATETIME NULL,
  `stt_error` VARCHAR(255) NOT NULL DEFAULT '',
  `reviewed_class` VARCHAR(20) CHARACTER SET ascii NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_id`),
  UNIQUE KEY `uq_amd_audit_call` (`call_id`),
  KEY `idx_amd_audit_stt` (`stt_status`, `audit_state`),
  KEY `idx_amd_audit_campaign` (`campaign_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
