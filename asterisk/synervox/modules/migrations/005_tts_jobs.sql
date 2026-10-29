CREATE TABLE IF NOT EXISTS tts_jobs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 job_hash CHAR(64) NOT NULL,
 text_content MEDIUMTEXT NOT NULL,
 lang VARCHAR(10) NOT NULL DEFAULT 'es',
 speed DECIMAL(3,1) NOT NULL DEFAULT 1.3,
 status ENUM('pending','claimed','done','failed') NOT NULL DEFAULT 'pending',
 claimed_by VARCHAR(80) NULL,
 claimed_at DATETIME NULL,
 completed_at DATETIME NULL,
 error VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uq_tts_job_hash (job_hash),
 KEY idx_tts_jobs_claim (status,claimed_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

