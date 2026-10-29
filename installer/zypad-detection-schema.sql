-- Esquema de la base de datos de deteccion pre-answer consumida por el
-- proyecto zynervox-pbx (mirmidon: watcher.py, zynervox_agent_register.agi,
-- zynervox_agent_stop.agi, log_preanswer_cut.py). NO es parte del
-- contenedor zypad-vosk (installer/zypad.sh) -- ese modulo es un servicio
-- STT generico y agnostico de quien lo consume. Esta base vive en el MISMO
-- host que zypad-vosk (zynervox-zypad, puerto 8767) por decision operativa
-- (ver docs/DEPLOYMENT.md / zynervox-deploy), para minimizar saltos de red
-- entre deteccion y su estado.
--
-- Reconstruido el 2026-10-06 tras la caida del servidor original
-- (zentynel/cloud-peru, 172.16.10.18) que lo alojaba. Schema inferido del
-- codigo real (no existia dump), ver zynervox-deploy.md para el detalle de
-- la reconstruccion.
--
-- Uso: mysql < zypad-detection-schema.sql  (como root o usuario con
-- privilegios de creacion de BD/usuario)
--
-- Variable a reemplazar antes de ejecutar: <VARDB_PASS>

CREATE DATABASE IF NOT EXISTS zynervox CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zynervox.zynervox_calls (
  call_id              VARCHAR(64)  NOT NULL,
  channel              VARCHAR(191) NOT NULL DEFAULT '',
  numero               VARCHAR(32)  NOT NULL DEFAULT '',
  cliente_wav          VARCHAR(512) NOT NULL DEFAULT '',
  status               VARCHAR(32)  NOT NULL DEFAULT 'pendiente',
  clasificacion        VARCHAR(64)  NULL,
  texto_detectado      TEXT         NULL,
  ultima_transcripcion TEXT         NULL,
  ami_response         VARCHAR(255) NULL,
  registered_at        DATETIME(3)  NOT NULL,
  answered_at          DATETIME(3)  NULL,
  finished_at          DATETIME(3)  NULL,
  hangup_at            DATETIME(3)  NULL,
  PRIMARY KEY (call_id),
  KEY idx_status (status),
  KEY idx_registered_at (registered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuario consumido por /etc/zynervox_astguiclient.conf (VARDB_*) en el
-- servidor Asterisk (mirmidon). El host del GRANT debe cubrir la IP de
-- origen REAL que ve el servidor MySQL al conectar mirmidon -- confirmado
-- en la reconstruccion de 2026-10-06 que esa IP es la publica de mirmidon
-- (209.17.220.5), no la de LAN privada 172.16.10.x -- verificar con
-- `SHOW PROCESSLIST` o el error "Host 'X' is not allowed to connect" la
-- primera vez.
CREATE USER IF NOT EXISTS 'zynervox_app'@'172.16.10.%' IDENTIFIED BY '<VARDB_PASS>';
GRANT SELECT, INSERT, UPDATE, DELETE ON zynervox.* TO 'zynervox_app'@'172.16.10.%';

CREATE USER IF NOT EXISTS 'zynervox_app'@'209.17.220.5' IDENTIFIED BY '<VARDB_PASS>';
GRANT SELECT, INSERT, UPDATE, DELETE ON zynervox.* TO 'zynervox_app'@'209.17.220.5';

FLUSH PRIVILEGES;

-- Post-requisito manual (no cubierto por este script): MariaDB debe
-- escuchar en la IP LAN del host, no solo loopback. Editar
-- bind-address en /etc/mysql/mariadb.conf.d/50-server.cnf (Debian/Ubuntu)
-- de 127.0.0.1 a la IP LAN real, y `systemctl restart mariadb`.
