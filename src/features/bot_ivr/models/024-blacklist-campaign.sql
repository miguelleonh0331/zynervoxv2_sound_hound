-- MariaDB 10.6+. Idempotent. Lista negra por campaña (sustituye a la global de 023).
-- Campaña: zynervox_bot_campaigns.blacklist_enabled (0 = No, por defecto: la campaña no consulta nada; 1 = Sí).
-- Fila de zynervox_blacklist: campaign_id = id de la campaña a la que aplica, o 0 = todas las campañas con la opción en Sí. Un mismo número puede estar una vez por campaña (y una vez en «todas»).
-- phone = últimos 9 dígitos. Se rellena solo por MySQL (a mano o con un crontab).
ALTER TABLE `zynervox_bot_campaigns` ADD COLUMN IF NOT EXISTS `blacklist_enabled` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `zynervox_blacklist` ADD COLUMN IF NOT EXISTS `campaign_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 FIRST;

-- La clave primaria pasa de (phone) a (campaign_id, phone), solo si aún no es así.
SET @pk := (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='zynervox_blacklist' AND INDEX_NAME='PRIMARY');
SET @sql := IF(@pk = 'campaign_id,phone', 'DO 0', 'ALTER TABLE `zynervox_blacklist` DROP PRIMARY KEY, ADD PRIMARY KEY (`campaign_id`, `phone`)');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
