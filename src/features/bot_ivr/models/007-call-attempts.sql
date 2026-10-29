CREATE TABLE IF NOT EXISTS zynervox_bot_call_attempts (
 attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 call_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 list_id BIGINT UNSIGNED NOT NULL,
 lead_id BIGINT UNSIGNED NOT NULL,
 phone VARCHAR(20) NOT NULL,
 status VARCHAR(6) NOT NULL DEFAULT 'CALL',
 dial_status VARCHAR(20) NOT NULL DEFAULT '',
 amd_status VARCHAR(20) NOT NULL DEFAULT '',
 amd_cause VARCHAR(160) NOT NULL DEFAULT '',
 hangup_cause INT NOT NULL DEFAULT 0,
 started_at DATETIME NOT NULL,
 finished_at DATETIME NULL,
 KEY idx_call_lead (lead_id,attempt_id),
 KEY idx_call_list (list_id,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
