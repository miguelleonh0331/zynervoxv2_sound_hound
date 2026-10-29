-- MariaDB 10.6+. Idempotent. SIP headers per lead for the transfer to the destination trunk.
-- Child table (not JSON on the lead): UNIQUE (lead_id,header_name) enforces no duplicates,
-- rows are queryable by header, and ON DELETE CASCADE clears them when a list upload replaces leads.
-- The list stores which CSV columns are headers: {"csv_column":"X-Header-Name"}.
ALTER TABLE `zynervox_bot_lists`
  ADD COLUMN IF NOT EXISTS `sip_header_map` TEXT NULL DEFAULT NULL;
CREATE TABLE IF NOT EXISTS `zynervox_bot_lead_headers` (
  `lead_id` BIGINT UNSIGNED NOT NULL,
  `position` TINYINT UNSIGNED NOT NULL,
  `header_name` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `header_value` VARCHAR(1024) NOT NULL,
  PRIMARY KEY (`lead_id`, `header_name`),
  KEY `idx_lead_header_position` (`lead_id`, `position`),
  CONSTRAINT `fk_lead_headers_lead` FOREIGN KEY (`lead_id`)
    REFERENCES `zynervox_bot_list` (`lead_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
