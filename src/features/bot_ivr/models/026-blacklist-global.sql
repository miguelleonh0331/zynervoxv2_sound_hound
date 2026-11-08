-- MariaDB 10.6+. Idempotent. Blacklist en dos niveles.
-- GLOBAL: listas con nombre (por cliente) en zynervox_blacklist_lists; sus números en zynervox_blacklist_global. Cada campaña elige UNA (zynervox_bot_campaigns.global_blacklist_id) o ninguna.
--   Solo se consulta en la CARGA de una base (importLeads): los contactos que están en la global quedan «Bloqueado». Nunca al marcar.
-- REGULAR: zynervox_blacklist (023/024), por campaña o campaign_id 0 = todas, consultada al marcar por el motor y el dialplan.
-- Auditoría: zynervox_blacklist_audit (altas, retiradas y vaciados hechos por el servicio, de ambas listas).
-- phone = últimos 9 dígitos. Las cargas masivas las hace el usuario por MySQL (no pasan por la auditoría).
CREATE TABLE IF NOT EXISTS `zynervox_blacklist_lists` (
  `list_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
  `created_by` VARCHAR(80) NOT NULL DEFAULT '',
  PRIMARY KEY (`list_id`),
  UNIQUE KEY `uq_blacklist_lists_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `zynervox_blacklist_global` (
  `list_id` BIGINT UNSIGNED NOT NULL,
  `phone` CHAR(9) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reason` VARCHAR(160) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
  `created_by` VARCHAR(80) NOT NULL DEFAULT '',
  PRIMARY KEY (`list_id`, `phone`),
  CONSTRAINT `chk_blacklist_global_phone` CHECK (`phone` REGEXP '^[0-9]{9}$'),
  CONSTRAINT `fk_blacklist_global_list` FOREIGN KEY (`list_id`) REFERENCES `zynervox_blacklist_lists` (`list_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `zynervox_blacklist_audit` (
  `audit_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `at` DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
  `actor` VARCHAR(80) NOT NULL DEFAULT '',
  `action` VARCHAR(20) NOT NULL,
  `scope` VARCHAR(10) NOT NULL,
  `list_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `list_name` VARCHAR(80) NOT NULL DEFAULT '',
  `campaign_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `phone` CHAR(9) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  `rows_affected` INT UNSIGNED NOT NULL DEFAULT 0,
  `detail` VARCHAR(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`audit_id`),
  KEY `idx_blacklist_audit_at` (`at`),
  KEY `idx_blacklist_audit_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `zynervox_bot_campaigns` ADD COLUMN IF NOT EXISTS `global_blacklist_id` BIGINT UNSIGNED NULL DEFAULT NULL;
