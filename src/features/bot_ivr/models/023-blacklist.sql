-- MariaDB 10.6+. Idempotent. Lista negra global (no depende de campañas ni listas): los dialplan la consultan antes de llamar y el motor antes de marcar.
-- phone = últimos 9 dígitos del número (igual que los contactos). Por ahora se rellena a mano en la base; la pantalla vendrá después.
CREATE TABLE IF NOT EXISTS `zynervox_blacklist` (
  `phone` CHAR(9) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reason` VARCHAR(160) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
  `created_by` VARCHAR(80) NOT NULL DEFAULT '',
  PRIMARY KEY (`phone`),
  CONSTRAINT `chk_blacklist_phone` CHECK (`phone` REGEXP '^[0-9]{9}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Distribución por contacto: nueva categoría «Bloqueado».
ALTER TABLE `zynervox_bot_list_summary` ADD COLUMN IF NOT EXISTS `dist_bloqueado` INT UNSIGNED NOT NULL DEFAULT 0;
