#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$ROOT/installer/db-common.sh"
[[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
CORE_CONF="$CONFIG_DIR/zynervox-core.conf"
DB_NAME="${ZYNERVOX_CORE_DB:-zynervox_core}"
DB_USER="${ZYNERVOX_CORE_DB_USER:-zynervox_core}"
ADMIN_USER="${ZYNERVOX_CORE_ADMIN_USER:-admin}"
USERS_TABLE="${ZYNERVOX_CORE_USERS_TABLE:-zynervox_users}"
CONFIG_TABLE="${ZYNERVOX_CORE_CONFIG_TABLE:-ivr_deploy_config}"
[[ "$USERS_TABLE" =~ ^(zynervox_users|v2_zynervox_users)$ && "$CONFIG_TABLE" =~ ^(ivr_deploy_config|v2_ivr_deploy_config)$ ]] || exit 2

if ! command -v mysql >/dev/null 2>&1; then
  echo "AVISO: mysql no disponible; se omite zynervox_core (login propio)" >&2
  exit 0
fi

if [[ -f "$CORE_CONF" ]]; then
  [[ "$(config_value "$CORE_CONF" CORE_DB_port)" == "${ZYNERVOX_DB_PORT:-3306}" ]] || { echo "Core en otro puerto: aplicar esquemas en el servidor correspondiente" >&2; exit 1; }
  [[ "$(config_value "$CORE_CONF" CORE_DB_server)" =~ ^(127\.0\.0\.1|localhost)$ ]] || { echo "Core remoto: aplicar esquemas en el servidor correspondiente" >&2; exit 1; }
  DB_NAME="$(config_value "$CORE_CONF" CORE_DB_database)"
  DB_USER="$(config_value "$CORE_CONF" CORE_DB_user)"
  DB_PASSWORD="$(config_value "$CORE_CONF" CORE_DB_pass)"
else
  source "$ROOT/installer/load-db-password.sh"
  DB_PASSWORD="$ZYNERVOX_DB_PASSWORD"
fi
valid_db_identifier "$DB_NAME" && valid_db_identifier "$DB_USER" || exit 2
[[ "$ADMIN_USER" =~ ^[a-zA-Z0-9_.-]+$ ]] || exit 2
install -d -o root -g root -m 0755 "$CONFIG_DIR"
ADMIN_SECRET_FILE="$CONFIG_DIR/zynervox-core-admin.env"
desired_admin_password="${ZYNERVOX_CORE_ADMIN_PASSWORD:-}"
if [[ -f "$ADMIN_SECRET_FILE" ]]; then
  [[ ! -L "$ADMIN_SECRET_FILE" && "$(stat -c '%u:%a' "$ADMIN_SECRET_FILE")" == 0:600 ]] || { echo 'Archivo admin privado inseguro' >&2; exit 1; }
  source "$ADMIN_SECRET_FILE"
fi
ADMIN_PASSWORD="${ZYNERVOX_CORE_ADMIN_PASSWORD:-$(openssl rand -hex 16)}"
admin_temporary=''
if [[ "${ZYNERVOX_ISOLATED:-0}" == 1 ]]; then
  [[ "$USERS_TABLE" == v2_zynervox_users && "$CONFIG_TABLE" == v2_ivr_deploy_config && "$CONFIG_DIR" != /etc/zynervox && "$DB_USER" != zynervox_core && -n "$desired_admin_password" && "$desired_admin_password" != *$'\n'* ]] || { echo 'Reconciliacion admin rechazada: configuracion aislada/secreto requerido' >&2; exit 1; }
  ADMIN_PASSWORD="$desired_admin_password"
  admin_temporary="$(mktemp "$CONFIG_DIR/.admin-config.XXXXXX")"
  chmod 0600 "$admin_temporary"
  printf 'ZYNERVOX_CORE_ADMIN_USER=%q\nZYNERVOX_CORE_ADMIN_PASSWORD=%q\n' "$ADMIN_USER" "$ADMIN_PASSWORD" > "$admin_temporary"
  recovery_directory="$(mktemp -d "$CONFIG_DIR/.admin-recovery.XXXXXX")"
  chmod 0700 "$recovery_directory"
  [[ ! -f "$ADMIN_SECRET_FILE" ]] || cp -p "$ADMIN_SECRET_FILE" "$recovery_directory/admin-before.env"
fi
if [[ ! -f "$ADMIN_SECRET_FILE" && "${ZYNERVOX_ISOLATED:-0}" != 1 ]]; then
  (umask 077; printf 'ZYNERVOX_CORE_ADMIN_USER=%q\nZYNERVOX_CORE_ADMIN_PASSWORD=%q\n' "$ADMIN_USER" "$ADMIN_PASSWORD" > "$ADMIN_SECRET_FILE")
fi
password_sql="$(sql_value "$DB_PASSWORD")"
admin_password_sql="$(sql_value "$ADMIN_PASSWORD")"
mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$password_sql'; \
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$password_sql'; \
FLUSH PRIVILEGES;"

mysql "$DB_NAME" <<SQL
CREATE TABLE IF NOT EXISTS $USERS_TABLE (
  user varchar(60) NOT NULL PRIMARY KEY,
  pass varchar(255) NOT NULL,
  full_name varchar(100) NOT NULL DEFAULT 'Administrador',
  user_level int NOT NULL DEFAULT 9,
  active char(1) NOT NULL DEFAULT 'Y'
) ENGINE=InnoDB;
INSERT IGNORE INTO $USERS_TABLE (user, pass, full_name, user_level, active)
VALUES ('$ADMIN_USER', '$admin_password_sql', 'Administrador', 9, 'Y');
SQL
if [[ "${ZYNERVOX_ISOLATED:-0}" == 1 ]]; then
  [[ "$(mysql -N "$DB_NAME" -e "SELECT COUNT(*) FROM v2_zynervox_users WHERE user='$ADMIN_USER' AND user_level=9 AND active='Y'")" == 1 ]] || { echo 'Admin v2 inactivo o nivel distinto: no se modifica ni reactiva' >&2; exit 1; }
  (umask 077; mysql -N "$DB_NAME" -e "SELECT user,pass,user_level,active FROM v2_zynervox_users WHERE user='$ADMIN_USER'" > "$recovery_directory/user-before.txt")
  mysql "$DB_NAME" -e "UPDATE v2_zynervox_users SET pass='$admin_password_sql' WHERE user='$ADMIN_USER';"
  mv -f "$admin_temporary" "$ADMIN_SECRET_FILE"
  echo "V2_ADMIN_PASSWORD_RECONCILED user=$ADMIN_USER"
fi

apply_schema "$ROOT/database/init/003-ivr-deploy-config.sql"
if [[ "${ZYNERVOX_ISOLATED:-0}" == 1 ]]; then
  mysql "$DB_NAME" -e 'CREATE TABLE IF NOT EXISTS v2_access_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user VARCHAR(60), action VARCHAR(64), ip_address VARCHAR(64), details TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;'
  for host in localhost 127.0.0.1; do mysql -e "GRANT SELECT,INSERT ON \`$DB_NAME\`.v2_access_log TO '$DB_USER'@'$host';"; done
fi
for host in localhost 127.0.0.1; do
  for table in "$USERS_TABLE" "$CONFIG_TABLE"; do
    mysql -e "GRANT SELECT,INSERT,UPDATE,DELETE ON \`$DB_NAME\`.\`$table\` TO '$DB_USER'@'$host';"
  done
done
install -d -o root -g root -m 0755 "$CONFIG_DIR"
umask 077
if [[ ! -f "$CORE_CONF" ]]; then
cat > "$CORE_CONF" <<EOF
CORE_DB_server => 127.0.0.1
CORE_DB_database => $DB_NAME
CORE_DB_user => $DB_USER
CORE_DB_pass => $DB_PASSWORD
CORE_DB_port => ${ZYNERVOX_DB_PORT:-3306}
EOF
chown root:"$WEB_GROUP" "$CORE_CONF"
chmod 0640 "$CORE_CONF"
fi

RECEIVER_CONF="$CONFIG_DIR/zynervoxv2205-ivr-receiver.conf"
if [[ ! -f "$RECEIVER_CONF" ]]; then
  (umask 077; printf 'RECEIVER_TOKEN => %s\n' "$(openssl rand -hex 32)" > "$RECEIVER_CONF")
  chown root:"$WEB_GROUP" "$RECEIVER_CONF"
  chmod 0640 "$RECEIVER_CONF"
fi
receiver_token="$(config_value "$RECEIVER_CONF" RECEIVER_TOKEN)"
receiver_url="${ZYNERVOX_IVR_RECEIVER_URL:-http://127.0.0.1${URL_PATH:-/zynervox}/ivr_builder}"
mysql "$DB_NAME" <<SQL
UPDATE $CONFIG_TABLE SET asterisk_api_url='$(sql_value "$receiver_url")',
asterisk_api_token='$(sql_value "$receiver_token")'
WHERE id=1 AND asterisk_api_url='' AND asterisk_api_token='';
SQL

echo "ZYNERVOX_CORE_READY database=$DB_NAME admin_user=$ADMIN_USER admin_secret=$ADMIN_SECRET_FILE"
