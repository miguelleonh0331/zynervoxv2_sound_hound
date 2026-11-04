-- MariaDB 10.6+. Idempotent. Marcador: audio recibido del contacto (dialplan v7), para repetir el AMD con otros parámetros sin llamar.
-- Run against zynervox_core after a dump: mysql zynervox_core < 020-amd-contact-audio.sql
ALTER TABLE `zynervox_bot_amd_audit` ADD COLUMN IF NOT EXISTS `contact_path` VARCHAR(512) NULL AFTER `audio_path`;
