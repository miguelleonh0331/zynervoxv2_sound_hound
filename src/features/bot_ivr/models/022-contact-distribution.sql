-- MariaDB 10.6+. Idempotent. Marcador: distribución por contacto guardada en el resumen por lista (contrato "Distribución por contacto").
-- Each contact counts once, by the result of its last attempt. The engine refreshes these columns with the rest of the summary; they survive deleting the list.
ALTER TABLE `zynervox_bot_list_summary`
  ADD COLUMN IF NOT EXISTS `dist_humano` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `dist_casilla_voz` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `dist_no_seguro` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `dist_contestada_sin_amd` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `dist_no_disponible` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `dist_sin_respuesta` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `dist_ocupado` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `dist_errores` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `dist_en_llamada` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `dist_sin_llamar` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `dist_at` DATETIME NULL DEFAULT NULL;
