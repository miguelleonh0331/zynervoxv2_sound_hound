USE `zynervox`;

ALTER TABLE zynervox_bot_lists
  ADD COLUMN IF NOT EXISTS id_flujo BIGINT UNSIGNED NULL AFTER campaign_id;
