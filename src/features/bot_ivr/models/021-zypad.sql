-- MariaDB 10.6+. Idempotent. Marcador: tipo de marcación (AMD / zypad sombra / zypad corte), catálogo de palabras y resultado homologado.
-- Run against zynervox_core after a dump: mysql zynervox_core < 021-zypad.sql
-- dial_mode is deduced from campaigns.call_origin (7308/7309 AMD, 7314/7315 ZYPAD_SOMBRA, 7312 ZYPAD_CORTE) and stamped by the engine; '' = not stamped yet.
CREATE TABLE IF NOT EXISTS `zynervox_bot_zypad_words` (
  `word_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `word` VARCHAR(80) NOT NULL,
  `category` VARCHAR(30) CHARACTER SET ascii NOT NULL DEFAULT 'casilla_voz',
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
  `updated_by` VARCHAR(80) NOT NULL DEFAULT '',
  PRIMARY KEY (`word_id`),
  UNIQUE KEY `uq_zypad_word` (`word`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `zynervox_bot_zypad_words` (`word_id`,`word`,`category`,`active`,`updated_by`) VALUES (1,'mensaje','casilla_voz',1,'migración 021');

ALTER TABLE `zynervox_bot_call_attempts`
  ADD COLUMN IF NOT EXISTS `dial_mode` VARCHAR(14) CHARACTER SET ascii NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `detectado_por` VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `momento` VARCHAR(4) CHARACTER SET ascii NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `palabra` VARCHAR(80) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `segundo` DECIMAL(7,2) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `corte_por` VARCHAR(8) CHARACTER SET ascii NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `preanswer_audio` VARCHAR(2) CHARACTER SET ascii NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `zypad_call_id` VARCHAR(80) CHARACTER SET ascii NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `result_final` VARCHAR(12) AS (IF(`amd_status`='MACHINE' OR `corte_por`='ZYPAD','CASILLA_VOZ','')) VIRTUAL;
-- Every attempt made before this migration went through the AMD dialplan.
UPDATE `zynervox_bot_call_attempts` SET `dial_mode`='AMD' WHERE `dial_mode`='';
-- Only the report filter by dial mode justifies an index.
ALTER TABLE `zynervox_bot_call_attempts` ADD INDEX IF NOT EXISTS `idx_call_dialmode` (`dial_mode`, `started_at`);
