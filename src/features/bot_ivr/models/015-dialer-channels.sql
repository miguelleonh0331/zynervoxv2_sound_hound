-- MariaDB 10.6+. Idempotent. Marcador: canales por lista (cambiables en caliente), máximo global del servidor y último código SIP del lead.
-- Run against zynervox_core after a dump: mysql zynervox_core < 015-dialer-channels.sql
-- The engine keeps open calls per list <= zynervox_bot_lists.max_channels and in total <= max_total_channels. The campaign max_channels is no longer used.
ALTER TABLE `zynervox_bot_lists`
  ADD COLUMN IF NOT EXISTS `max_channels` SMALLINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `zynervox_bot_lists`
  ADD CONSTRAINT IF NOT EXISTS `chk_list_max_channels` CHECK (`max_channels` BETWEEN 1 AND 500);

CREATE TABLE IF NOT EXISTS `zynervox_bot_dialer_settings` (
  `name` VARCHAR(40) CHARACTER SET ascii NOT NULL,
  `value` INT UNSIGNED NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `zynervox_bot_dialer_settings` (`name`, `value`) VALUES ('max_total_channels', 10);

-- Last final SIP code of the lead's calls (0 = no data / no provider answer). Written by finishCall (BotIVR).
ALTER TABLE `zynervox_bot_list`
  ADD COLUMN IF NOT EXISTS `last_sip_code` SMALLINT UNSIGNED NOT NULL DEFAULT 0;
