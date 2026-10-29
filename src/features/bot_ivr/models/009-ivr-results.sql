CREATE TABLE IF NOT EXISTS zynervox_bot_ivr_results (
    result_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    call_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    node_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    list_id BIGINT UNSIGNED NOT NULL,
    lead_id BIGINT UNSIGNED NOT NULL,
    result VARCHAR(80) NOT NULL DEFAULT '',
    fields_json MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (result_id),
    UNIQUE KEY uq_ivr_result_call_node (call_id,node_id),
    KEY idx_ivr_result_list (list_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE zynervox_bot_ivr_results
 MODIFY COLUMN call_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 MODIFY COLUMN node_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
