USE `zynervox`;

CREATE TABLE IF NOT EXISTS zynervox_bot_campaigns (
  campaign_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  scheduled TINYINT(1) NOT NULL DEFAULT 0,
  start_time TIME NOT NULL DEFAULT '08:00:00',
  end_time TIME NOT NULL DEFAULT '17:45:00',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (campaign_id),
  CHECK (active IN (0,1)), CHECK (scheduled IN (0,1)),
  CHECK (start_time >= '00:00:00' AND start_time < '24:00:00'),
  CHECK (end_time >= '00:00:00' AND end_time < '24:00:00')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zynervox_bot_lists (
  list_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (list_id),
  KEY idx_bot_lists_campaign_active (campaign_id, active),
  CONSTRAINT fk_bot_lists_campaign FOREIGN KEY (campaign_id)
    REFERENCES zynervox_bot_campaigns (campaign_id) ON DELETE RESTRICT,
  CHECK (active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zynervox_bot_list (
  lead_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  list_id BIGINT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL,
  customer_name VARCHAR(160) NOT NULL DEFAULT '',
  extra_json LONGTEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (lead_id),
  KEY idx_bot_list_list_phone (list_id, phone),
  CONSTRAINT fk_bot_list_list FOREIGN KEY (list_id)
    REFERENCES zynervox_bot_lists (list_id) ON DELETE RESTRICT,
  CHECK (extra_json IS NULL OR JSON_VALID(extra_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
