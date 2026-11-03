-- MariaDB 10.6+. Idempotent. Marcador: estado iniciada/detenida por lista.
-- Run against zynervox_core after a dump: mysql zynervox_core < 017-dialer-list-state.sql
-- A list is dialed only when it is active (zynervox_bot_lists.active=1) AND started (dialer_state='running') AND its campaign is running.
-- Every existing list stays 'stopped' after this migration: nothing dials until someone starts it.
ALTER TABLE `zynervox_bot_lists`
  ADD COLUMN IF NOT EXISTS `dialer_state` VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT 'stopped',
  ADD COLUMN IF NOT EXISTS `dialer_changed_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `dialer_changed_by` VARCHAR(80) NOT NULL DEFAULT '';
ALTER TABLE `zynervox_bot_lists`
  ADD CONSTRAINT IF NOT EXISTS `chk_list_dialer_state` CHECK (`dialer_state` IN ('stopped','running'));
-- Engine heartbeat (unix seconds) lives in zynervox_bot_dialer_settings as 'engine_heartbeat'; the dashboard uses it to tell "engine off".
