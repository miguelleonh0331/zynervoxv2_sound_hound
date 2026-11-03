-- MariaDB 10.6+. Idempotent. Marcador: acción por resultado AMD y reintentos por código SIP final, por campaña.
-- Run against zynervox_core after a dump: mysql zynervox_core < 016-dialer-amd-sip-rules.sql
-- The old zynervox_bot_campaign_retry_rules (by lead status) is kept as is for rollback; the engine stops reading it.
-- Rules are stored per EXACT code/result: grouping 486/600 or deciding NOTSURE/TOOLONG changes the screen, not this model.

-- AMD result -> action. result is AMDCAUSE when it is TOOLONG/LONGGREETING/INITIALSILENCE/MAXWORDS/MAXWORDLENGTH, else AMDSTATUS (MACHINE/HUMAN/NOTSURE).
-- action: transfer | hangup | retry. max_attempts and retry_delay_min only count with retry. Missing row = the service default.
CREATE TABLE IF NOT EXISTS `zynervox_bot_campaign_amd_actions` (
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `result` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `action` VARCHAR(10) CHARACTER SET ascii NOT NULL,
  `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `retry_delay_min` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`campaign_id`, `result`),
  CONSTRAINT `fk_campaign_amd_actions_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `zynervox_bot_campaigns` (`campaign_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_campaign_amd_action` CHECK (`action` IN ('transfer','hangup','retry'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Final SIP code -> retry. sip_code 0 = no provider answer. 200 is decided by AMD, so it has no rule.
CREATE TABLE IF NOT EXISTS `zynervox_bot_campaign_sip_rules` (
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `sip_code` SMALLINT UNSIGNED NOT NULL,
  `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `retry_delay_min` INT UNSIGNED NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`campaign_id`, `sip_code`),
  CONSTRAINT `fk_campaign_sip_rules_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `zynervox_bot_campaigns` (`campaign_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_campaign_sip_code` CHECK (`sip_code` = 0 OR `sip_code` BETWEEN 300 AND 699)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate the current rules (only once per campaign, never over rows already edited):
-- B (busy) -> 486, 600; NA (no answer) -> 408, 480, 487 and 0; AA (answering machine) -> AMD MACHINE retry.
INSERT IGNORE INTO `zynervox_bot_campaign_sip_rules` (campaign_id, sip_code, max_attempts, retry_delay_min, active)
  SELECT r.campaign_id, c.code, r.max_attempts, r.retry_delay_min, r.active
  FROM `zynervox_bot_campaign_retry_rules` r
  JOIN (SELECT 'B' s, 486 code UNION ALL SELECT 'B', 600 UNION ALL SELECT 'NA', 0 UNION ALL SELECT 'NA', 408 UNION ALL SELECT 'NA', 480 UNION ALL SELECT 'NA', 487) c ON c.s = r.status;
INSERT IGNORE INTO `zynervox_bot_campaign_amd_actions` (campaign_id, result, action, max_attempts, retry_delay_min)
  SELECT r.campaign_id, 'MACHINE', 'retry', r.max_attempts, r.retry_delay_min FROM `zynervox_bot_campaign_retry_rules` r WHERE r.status = 'AA' AND r.active = 1;
