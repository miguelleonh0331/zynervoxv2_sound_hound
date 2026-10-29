CREATE TABLE IF NOT EXISTS zynervox_bot_dial_runs (
 run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 list_id BIGINT UNSIGNED NOT NULL,
 campaign_id BIGINT UNSIGNED NOT NULL,
 state VARCHAR(16) NOT NULL,
 channels SMALLINT UNSIGNED NOT NULL,
 dial_prefix VARCHAR(20) NOT NULL,
 flow_id BIGINT UNSIGNED NOT NULL,
 config_json TEXT NOT NULL,
 created_by VARCHAR(80) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 updated_at DATETIME(6) NOT NULL,
 finished_at DATETIME(6) NULL,
 error VARCHAR(255) NOT NULL DEFAULT '',
 active_list_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN state IN ('starting','running','stopping') THEN list_id ELSE NULL END) STORED,
 UNIQUE KEY uq_dial_run_active_list (active_list_id),
 KEY idx_dial_run_list_date (list_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE zynervox_bot_call_attempts
 ADD COLUMN IF NOT EXISTS dial_run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD INDEX IF NOT EXISTS idx_attempt_dial_run (dial_run_id,lead_id);
