-- Config "cluster-ready" del IVR Builder: a que BD se escriben los flujos y
-- a que servidor Asterisk se publica el JSON. Vive en la BD "core"
-- (zynervox_core), que es la unica siempre disponible (login admin), para
-- evitar el problema del huevo y la gallina (no se puede guardar "a que BD
-- conectarse" dentro de esa misma BD que todavia no se conoce).
-- Singleton: una sola fila, id=1 (alcance global, no multi-cliente por ahora).

CREATE TABLE IF NOT EXISTS ivr_deploy_config (
  id TINYINT UNSIGNED NOT NULL DEFAULT 1,
  db_host VARCHAR(255) NOT NULL DEFAULT '',
  db_port INT UNSIGNED NOT NULL DEFAULT 3306,
  db_name VARCHAR(100) NOT NULL DEFAULT '',
  db_user VARCHAR(100) NOT NULL DEFAULT '',
  db_pass VARCHAR(255) NOT NULL DEFAULT '',
  db_tested_at DATETIME NULL,
  asterisk_api_url VARCHAR(500) NOT NULL DEFAULT '',
  asterisk_api_token VARCHAR(255) NOT NULL DEFAULT '',
  asterisk_tested_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ivr_deploy_config (id) VALUES (1);
