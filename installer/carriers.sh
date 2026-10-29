#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[[ $EUID -eq 0 && "${ZYNERVOX_ISOLATED:-0}" == 1 ]] || { echo 'Carriers requiere despliegue v2 aislado como root' >&2; exit 2; }
source "$ROOT/installer/db-common.sh"
CORE_CONF="$CONFIG_DIR/zynervox-core.conf"
[[ -f "$CORE_CONF" && -f "$WEB_ROOT/config/deployment.php" ]] || { echo 'Instale primero la base web v2 aislada' >&2; exit 1; }
DB_NAME="$(config_value "$CORE_CONF" CORE_DB_database)"
DB_USER="$(config_value "$CORE_CONF" CORE_DB_user)"
[[ "$DB_NAME" == zynervox_core && "$DB_USER" == zynervoxv2_core && "$CONFIG_DIR" != /etc/zynervox ]] || { echo 'Destino Carriers no aislado; no se modifica' >&2; exit 2; }
[[ "$(config_value "$CORE_CONF" CORE_DB_server)" =~ ^(localhost|127\.0\.0\.1)$ && "$(config_value "$CORE_CONF" CORE_DB_port)" == "${ZYNERVOX_DB_PORT:-3306}" ]] || { echo 'Carriers requiere Core MySQL local en el puerto configurado' >&2; exit 2; }
command -v rsync >/dev/null
php /dev/stdin "$WEB_ROOT" "$CONFIG_DIR" "$ASTERISK_ROOT" <<'PHP'
<?php
require $argv[1] . '/includes/Database.php';
if (!\Config\Config::deployment('isolated', false) || \Config\Config::deployment('directory') !== $argv[2] || \Config\Config::deployment('runtime') !== $argv[3]) exit(1);
if (\Includes\Database::getCoreInstance()->query('SELECT DATABASE()')->fetchColumn() !== 'zynervox_core') exit(1);
PHP
runtime="$(realpath -m "$ASTERISK_ROOT")"
[[ "$runtime" == "$ASTERISK_ROOT" && "$runtime" != / && "$runtime" != /etc && "$runtime" != /var && "$runtime" != /var/lib && "$runtime" != /etc/asterisk* && "$runtime" != /var/lib/asterisk* ]] || { echo 'Runtime compartido/invalido; no se modifica' >&2; exit 2; }
[[ "$(realpath -m "$runtime/modules/asterisk")" == "$runtime/modules/asterisk" ]] || { echo 'Runtime con enlace no permitido' >&2; exit 2; }
for file in pjsip-zynervoxv2.conf extensions-zynervoxv2.conf sip-zynervoxv2-annexos.conf pjsip-zynervoxv2-annexos.conf; do [[ ! -L "$runtime/modules/asterisk/$file" ]] || exit 2; done
[[ "$(mysql -N -e "SELECT COUNT(*) FROM mysql.user WHERE User='$DB_USER' AND Host IN ('localhost','127.0.0.1')")" == 2 ]] || { echo 'Falta cuenta Core aislada existente' >&2; exit 1; }
for file in includes/Carriers.php includes/DialplanOrigins.php includes/Dialplans.php includes/Phones.php includes/IsolatedGate.php modules/admin/carriers.php modules/admin/dialplan.php modules/admin/sidebar.php modules/admin/phones.php; do
  [[ -f "$ROOT/app/web/$file" ]] || exit 1
  [[ "$(realpath -m "$WEB_ROOT/$file")" == "$(realpath "$WEB_ROOT")/$file" ]] || { echo 'Destino web con enlace no permitido' >&2; exit 2; }
done
printf '[%s] Carriers: preparando tabla propia y permisos\n' "$(date +%T)"
mysql "$DB_NAME" <<'SQL'
CREATE TABLE IF NOT EXISTS v2_carriers (
  carrier_id VARCHAR(60) NOT NULL PRIMARY KEY,
  carrier_name VARCHAR(100) NOT NULL,
  template_id VARCHAR(20) NOT NULL DEFAULT 'CUSTOM',
  protocol VARCHAR(20) NOT NULL DEFAULT 'PJSIP',
  account_entry MEDIUMTEXT NOT NULL,
  dialplan_entry MEDIUMTEXT NOT NULL,
  server_ip VARCHAR(100) NOT NULL,
  active CHAR(1) NOT NULL DEFAULT 'Y',
  carrier_description TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
mysql "$DB_NAME" < "$ROOT/src/features/core/models/phones-v2.sql"
mysql "$DB_NAME" < "$ROOT/src/features/core/models/dial-origins-v2.sql"
mysql "$DB_NAME" < "$ROOT/src/features/core/models/dialplans-v2.sql"
for host in localhost 127.0.0.1; do
  mysql -e "GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.v2_phones TO '$DB_USER'@'$host';"
  mysql -e "GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.v2_carriers TO '$DB_USER'@'$host';"
  mysql -e "GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.v2_dial_origins TO '$DB_USER'@'$host';"
  mysql -e "GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.v2_dialplans TO '$DB_USER'@'$host';"
done
install -d -o root -g "$WEB_GROUP" -m 0770 "$runtime/modules/asterisk"
for file in includes/Carriers.php includes/DialplanOrigins.php includes/Dialplans.php includes/Phones.php includes/IsolatedGate.php modules/admin/carriers.php modules/admin/dialplan.php modules/admin/sidebar.php modules/admin/phones.php; do
  rsync -rt --itemize-changes "$ROOT/app/web/$file" "$WEB_ROOT/$file"
  chown root:"$WEB_GROUP" "$WEB_ROOT/$file"
  chmod 0640 "$WEB_ROOT/$file"
done
for file in pjsip-zynervoxv2.conf extensions-zynervoxv2.conf sip-zynervoxv2-annexos.conf pjsip-zynervoxv2-annexos.conf; do
  [[ ! -L "$runtime/modules/asterisk/$file" ]] || exit 2
  if [[ ! -e "$runtime/modules/asterisk/$file" ]]; then
    install -o root -g "$WEB_GROUP" -m 0640 /dev/null "$runtime/modules/asterisk/$file"
  fi
done
php "$ROOT/installer/migrate-dialplans.php" "$WEB_ROOT"
for file in pjsip-zynervoxv2.conf extensions-zynervoxv2.conf sip-zynervoxv2-annexos.conf pjsip-zynervoxv2-annexos.conf; do
  chown root:"$WEB_GROUP" "$runtime/modules/asterisk/$file"
  chmod 0640 "$runtime/modules/asterisk/$file"
done
echo "MODULE_INSTALLED module=carriers database=$DB_NAME table=v2_carriers"
echo "CARRIERS_CONFIG_DIR=$runtime/modules/asterisk"
echo 'ACTIVACION_PENDIENTE: no se agregan includes, sudoers ni se recarga Asterisk'
