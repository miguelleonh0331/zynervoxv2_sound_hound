-- Empty Bot IVR schema; never imports demo data or credentials.
CREATE DATABASE IF NOT EXISTS `zynervox` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `zynervox`;

CREATE TABLE IF NOT EXISTS `synervox_campaigns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL DEFAULT '',
  `flow_code` char(2) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `clients_count` bigint unsigned NOT NULL DEFAULT 0,
  `desired_channels` int(11) DEFAULT NULL,
  `tts_provider` varchar(32) DEFAULT NULL,
  `call_origin` varchar(32) NOT NULL DEFAULT 'sipp_2006',
  `scheduled` tinyint(1) NOT NULL DEFAULT 0,
  `start_time` time NOT NULL DEFAULT '08:00:00',
  `end_time` time NOT NULL DEFAULT '17:45:00',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_synervox_campaigns_status` (`status`),
  KEY `idx_synervox_campaigns_flow` (`flow_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `carsa_initial_survey` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` varchar(80) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `customer_name` varchar(160) NOT NULL,
  `amount_raw` varchar(40) DEFAULT NULL,
  `amount` varchar(40) NOT NULL,
  `store_address_raw` varchar(255) DEFAULT NULL,
  `store_address` varchar(255) NOT NULL,
  `status` varchar(120) NOT NULL DEFAULT 'pending',
  `last_error` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `audio_status` varchar(20) NOT NULL DEFAULT 'pending',
  `audio_name` varchar(120) DEFAULT NULL,
  `tts_provider` varchar(40) DEFAULT NULL,
  `audio_error` text DEFAULT NULL,
  `dial_number` varchar(32) DEFAULT NULL,
  `assigned_agent` varchar(16) DEFAULT NULL,
  `reserved_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `campaign_id` bigint(20) unsigned DEFAULT NULL,
  `extra_json` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_carsa_initial_queue_batch` (`batch_id`,`id`),
  KEY `idx_carsa_initial_queue_status` (`status`,`id`),
  KEY `idx_isq_campaign_id` (`campaign_id`),
  KEY `idx_isq_campaign_status` (`campaign_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ivr_call_results` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint(20) unsigned DEFAULT NULL,
  `queue_id` bigint(20) unsigned DEFAULT NULL,
  `call_id` varchar(80) NOT NULL,
  `phone` varchar(32) NOT NULL DEFAULT '',
  `flow_code` char(2) NOT NULL DEFAULT '01',
  `flow_name` varchar(100) NOT NULL DEFAULT '',
  `previous_node_id` varchar(64) NOT NULL DEFAULT '',
  `hangup_node_id` varchar(64) NOT NULL DEFAULT '',
  `result_label` varchar(120) NOT NULL DEFAULT '',
  `end_reason` varchar(40) DEFAULT NULL,
  `variables_json` longtext NOT NULL,
  `recording_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_ivr_call_results_call_id` (`call_id`),
  KEY `idx_ivr_call_results_phone` (`phone`),
  KEY `idx_ivr_call_results_created_at` (`created_at`),
  KEY `idx_ivr_call_results_result_label` (`result_label`),
  KEY `idx_ivr_call_results_campaign_id` (`campaign_id`),
  KEY `idx_ivr_call_results_queue_id` (`queue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `carsa_campaign_state_summary` (
  `campaign_id` bigint(20) unsigned NOT NULL,
  `audio_status` varchar(20) NOT NULL DEFAULT 'pending',
  `campaign_status` varchar(20) NOT NULL DEFAULT 'idle',
  `last_zypad` tinyint(1) NOT NULL DEFAULT 0,
  `total_contacts` int(10) unsigned NOT NULL DEFAULT 0,
  `pending_contacts` int(10) unsigned NOT NULL DEFAULT 0,
  `active_contacts` int(10) unsigned NOT NULL DEFAULT 0,
  `completed_contacts` int(10) unsigned NOT NULL DEFAULT 0,
  `failed_contacts` int(10) unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`campaign_id`),
  KEY `idx_campaign_summary_audio` (`audio_status`),
  KEY `idx_campaign_summary_campaign` (`campaign_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `synervox_campaign_processes` (
  `campaign_id` bigint(20) unsigned NOT NULL,
  `process_type` varchar(20) NOT NULL,
  `pid` int(10) unsigned DEFAULT NULL,
  `state` varchar(20) NOT NULL DEFAULT 'idle',
  `requested_workers` smallint(5) unsigned DEFAULT NULL,
  `requested_channels` smallint(5) unsigned DEFAULT NULL,
  `provider` varchar(32) DEFAULT NULL,
  `origin` varchar(32) DEFAULT NULL,
  `stop_requested_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `heartbeat_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`campaign_id`,`process_type`),
  KEY `idx_campaign_process_state` (`state`,`process_type`),
  CONSTRAINT `fk_campaign_process_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `synervox_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `synervox_campaign_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint(20) unsigned NOT NULL,
  `process_type` varchar(20) DEFAULT NULL,
  `level` varchar(12) NOT NULL DEFAULT 'info',
  `event_type` varchar(40) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_campaign_events` (`campaign_id`,`id`),
  CONSTRAINT `fk_campaign_event_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `synervox_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `synervox_campaign_audio_builds` (
  `campaign_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(32) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `flow_sha256` char(64) NOT NULL DEFAULT '',
  `queue_sha256` char(64) NOT NULL DEFAULT '',
  `total` int(10) unsigned NOT NULL DEFAULT 0,
  `generated` int(10) unsigned NOT NULL DEFAULT 0,
  `reused` int(10) unsigned NOT NULL DEFAULT 0,
  `runtime_only` int(10) unsigned NOT NULL DEFAULT 0,
  `error_message` varchar(500) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`campaign_id`),
  CONSTRAINT `fk_audio_build_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `synervox_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `synervox_campaign_audio_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint(20) unsigned NOT NULL,
  `queue_id` bigint(20) unsigned DEFAULT NULL,
  `audio_kind` varchar(24) NOT NULL DEFAULT 'tts',
  `provider` varchar(32) NOT NULL,
  `audio_hash` char(64) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `byte_size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `error_message` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_campaign_audio` (`campaign_id`,`audio_kind`,`audio_hash`),
  KEY `idx_campaign_audio_queue` (`queue_id`),
  CONSTRAINT `fk_audio_file_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `synervox_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `synervox_call_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `call_uuid` char(36) NOT NULL,
  `campaign_id` bigint(20) unsigned NOT NULL,
  `queue_id` bigint(20) unsigned NOT NULL,
  `flow_code` char(2) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `dial_number` varchar(32) NOT NULL,
  `origin` varchar(32) NOT NULL,
  `agent_extension` varchar(20) DEFAULT NULL,
  `asterisk_uniqueid` varchar(64) DEFAULT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'reserved',
  `current_node_id` varchar(120) DEFAULT NULL,
  `end_reason` varchar(80) DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  `reserved_at` datetime NOT NULL DEFAULT current_timestamp(),
  `started_at` datetime DEFAULT NULL,
  `answered_at` datetime DEFAULT NULL,
  `heartbeat_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_call_run_uuid` (`call_uuid`),
  KEY `idx_call_run_campaign_status` (`campaign_id`,`status`,`id`),
  KEY `idx_call_run_queue` (`queue_id`,`id`),
  KEY `idx_call_run_uniqueid` (`asterisk_uniqueid`),
  CONSTRAINT `fk_call_run_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `synervox_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `synervox_call_variables` (
  `call_id` bigint(20) unsigned NOT NULL,
  `variable_key` varchar(120) NOT NULL,
  `variable_value` text NOT NULL,
  `source` varchar(24) NOT NULL DEFAULT 'campaign',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`call_id`,`variable_key`),
  CONSTRAINT `fk_call_variable_run` FOREIGN KEY (`call_id`) REFERENCES `synervox_call_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `synervox_call_node_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `call_id` bigint(20) unsigned NOT NULL,
  `node_id` varchar(120) DEFAULT NULL,
  `event_type` varchar(40) NOT NULL,
  `event_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_call_node_timeline` (`call_id`,`id`),
  CONSTRAINT `fk_call_node_event_run` FOREIGN KEY (`call_id`) REFERENCES `synervox_call_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
