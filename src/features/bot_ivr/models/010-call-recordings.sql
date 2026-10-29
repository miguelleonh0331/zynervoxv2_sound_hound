ALTER TABLE zynervox_bot_call_attempts
 ADD COLUMN IF NOT EXISTS campaign_id BIGINT UNSIGNED NULL,
 ADD COLUMN IF NOT EXISTS flow_id BIGINT UNSIGNED NULL,
 ADD COLUMN IF NOT EXISTS flow_code VARCHAR(2) NULL,
 ADD COLUMN IF NOT EXISTS flow_name VARCHAR(100) NULL,
 ADD COLUMN IF NOT EXISTS flow_sha256 CHAR(64) NULL,
 ADD COLUMN IF NOT EXISTS ivr_result VARCHAR(20) NULL,
 ADD COLUMN IF NOT EXISTS recording_path VARCHAR(512) NULL,
 ADD COLUMN IF NOT EXISTS recording_status VARCHAR(20) NOT NULL DEFAULT 'none',
 ADD COLUMN IF NOT EXISTS recording_bytes BIGINT UNSIGNED NULL,
 ADD COLUMN IF NOT EXISTS recording_finalized_at DATETIME NULL,
 ADD INDEX IF NOT EXISTS idx_call_campaign_date (campaign_id,started_at),
 ADD INDEX IF NOT EXISTS idx_call_flow_date (flow_id,started_at);
UPDATE zynervox_bot_call_attempts a JOIN zynervox_bot_lists p ON p.list_id=a.list_id
 SET a.campaign_id=p.campaign_id WHERE a.campaign_id IS NULL;
