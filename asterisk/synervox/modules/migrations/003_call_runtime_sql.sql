CREATE TABLE IF NOT EXISTS synervox_call_runs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 call_uuid CHAR(36) NOT NULL,
 campaign_id BIGINT UNSIGNED NOT NULL,
 queue_id BIGINT UNSIGNED NOT NULL,
 flow_code CHAR(2) NOT NULL,
 phone VARCHAR(20) NOT NULL,
 dial_number VARCHAR(32) NOT NULL,
 origin VARCHAR(32) NOT NULL,
 agent_extension VARCHAR(20) NULL,
 asterisk_uniqueid VARCHAR(64) NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'reserved',
 current_node_id VARCHAR(120) NULL,
 end_reason VARCHAR(80) NULL,
 last_error VARCHAR(500) NULL,
 reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 started_at DATETIME NULL,
 answered_at DATETIME NULL,
 heartbeat_at DATETIME NULL,
 completed_at DATETIME NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uq_call_run_uuid (call_uuid),
 KEY idx_call_run_campaign_status (campaign_id,status,id),
 KEY idx_call_run_queue (queue_id,id),
 KEY idx_call_run_uniqueid (asterisk_uniqueid),
 CONSTRAINT fk_call_run_campaign FOREIGN KEY (campaign_id) REFERENCES synervox_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS synervox_call_variables (
 call_id BIGINT UNSIGNED NOT NULL,
 variable_key VARCHAR(120) NOT NULL,
 variable_value TEXT NOT NULL,
 source VARCHAR(24) NOT NULL DEFAULT 'campaign',
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (call_id,variable_key),
 CONSTRAINT fk_call_variable_run FOREIGN KEY (call_id) REFERENCES synervox_call_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS synervox_call_node_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 call_id BIGINT UNSIGNED NOT NULL,
 node_id VARCHAR(120) NULL,
 event_type VARCHAR(40) NOT NULL,
 event_value TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 KEY idx_call_node_timeline (call_id,id),
 CONSTRAINT fk_call_node_event_run FOREIGN KEY (call_id) REFERENCES synervox_call_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
