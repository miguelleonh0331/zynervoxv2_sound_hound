-- MariaDB 10.6+. Idempotent. Listas de prueba (pantalla Test): una lista de 1 contacto que hace una sola llamada, sin reintentos.
ALTER TABLE `zynervox_bot_lists` ADD COLUMN IF NOT EXISTS `is_test` TINYINT(1) NOT NULL DEFAULT 0;
