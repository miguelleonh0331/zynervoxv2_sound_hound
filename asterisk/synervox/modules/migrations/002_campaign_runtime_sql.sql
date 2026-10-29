CREATE TABLE IF NOT EXISTS synervox_campaign_processes (
 campaign_id BIGINT UNSIGNED NOT NULL,
 process_type VARCHAR(20) NOT NULL,
 pid INT UNSIGNED NULL,
 state VARCHAR(20) NOT NULL DEFAULT 'idle',
 requested_workers SMALLINT UNSIGNED NULL,
 requested_channels SMALLINT UNSIGNED NULL,
 provider VARCHAR(32) NULL,
 origin VARCHAR(32) NULL,
 stop_requested_at DATETIME NULL,
 started_at DATETIME NULL,
 heartbeat_at DATETIME NULL,
 finished_at DATETIME NULL,
 last_error VARCHAR(500) NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (campaign_id,process_type),
 KEY idx_campaign_process_state (state,process_type),
 CONSTRAINT fk_campaign_process_campaign FOREIGN KEY (campaign_id) REFERENCES synervox_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS synervox_campaign_audio_builds (
 campaign_id BIGINT UNSIGNED NOT NULL,
 provider VARCHAR(32) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 flow_sha256 CHAR(64) NOT NULL DEFAULT '',
 queue_sha256 CHAR(64) NOT NULL DEFAULT '',
 total INT UNSIGNED NOT NULL DEFAULT 0,
 generated INT UNSIGNED NOT NULL DEFAULT 0,
 reused INT UNSIGNED NOT NULL DEFAULT 0,
 runtime_only INT UNSIGNED NOT NULL DEFAULT 0,
 error_message VARCHAR(500) NULL,
 started_at DATETIME NULL,
 completed_at DATETIME NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (campaign_id),
 CONSTRAINT fk_audio_build_campaign FOREIGN KEY (campaign_id) REFERENCES synervox_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS synervox_campaign_audio_files (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 campaign_id BIGINT UNSIGNED NOT NULL,
 queue_id BIGINT UNSIGNED NULL,
 audio_kind VARCHAR(24) NOT NULL DEFAULT 'tts',
 provider VARCHAR(32) NOT NULL,
 audio_hash CHAR(64) NOT NULL,
 file_path VARCHAR(500) NOT NULL,
 byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 error_message VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uq_campaign_audio (campaign_id,audio_kind,audio_hash),
 KEY idx_campaign_audio_queue (queue_id),
 CONSTRAINT fk_audio_file_campaign FOREIGN KEY (campaign_id) REFERENCES synervox_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS synervox_campaign_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 campaign_id BIGINT UNSIGNED NOT NULL,
 process_type VARCHAR(20) NULL,
 level VARCHAR(12) NOT NULL DEFAULT 'info',
 event_type VARCHAR(40) NOT NULL,
 message TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 KEY idx_campaign_events (campaign_id,id),
 CONSTRAINT fk_campaign_event_campaign FOREIGN KEY (campaign_id) REFERENCES synervox_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS synervox_bot_agents (
 extension VARCHAR(20) NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (extension),
 KEY idx_bot_agents_active (active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
