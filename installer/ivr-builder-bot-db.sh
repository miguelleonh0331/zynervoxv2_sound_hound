#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$ROOT/installer/db-common.sh"
[[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
command -v mysql >/dev/null 2>&1 || { echo "AVISO: mysql no disponible; se omite Bot IVR DB" >&2; exit 0; }
if [[ "${ZYNERVOX_ISOLATED:-0}" == 1 ]]; then install -d -o root -g "$WEB_GROUP" -m 2770 "$CONFIG_DIR/pbx"; fi
CORE_CONF="$CONFIG_DIR/zynervox-core.conf"
[[ -f "$CORE_CONF" ]] || { echo "Instalar zynervox-core primero" >&2; exit 1; }
CONFIG_FILE="$CONFIG_DIR/zynervoxv2205-bot_ivr.conf"
DB_NAME="$(config_value "$CORE_CONF" CORE_DB_database)"
DB_HOST=127.0.0.1
DB_USER="${BOT_IVR_DB_USER:-zynervox_bot_ivr}"
expected_user="$DB_USER"
reset_password=0
if [[ -f "$CONFIG_FILE" ]]; then
  [[ "$(config_value "$CONFIG_FILE" BOT_IVR_DB_server)" =~ ^(127\.0\.0\.1|localhost)$ && "$(config_value "$CONFIG_FILE" BOT_IVR_DB_port)" == "${ZYNERVOX_DB_PORT:-3306}" ]] || { echo "Bot IVR remoto: aplicar esquemas en el servidor correspondiente" >&2; exit 1; }
  DB_NAME="$(config_value "$CONFIG_FILE" BOT_IVR_DB_database)"
  DB_HOST="$(config_value "$CONFIG_FILE" BOT_IVR_DB_server)"
  DB_USER="$(config_value "$CONFIG_FILE" BOT_IVR_DB_user)"
  DB_PASSWORD="$(config_value "$CONFIG_FILE" BOT_IVR_DB_pass)"
else
  if [[ "${ZYNERVOX_ISOLATED:-0}" == 1 && -z "${BOT_IVR_DB_PASSWORD:-}" ]]; then
    echo "Nueva cuenta Bot aislada: preparar BOT_IVR_DB_PASSWORD o archivo privado $CONFIG_DIR/bot-password" >&2
    exit 1
  fi
  LEGACY_CONFIG="${BOT_IVR_LEGACY_CONFIG:-/etc/asterisk/synervox/secrets/bot_ivr_db.json}"
  if [[ -f "$LEGACY_CONFIG" ]]; then
    echo "Bot IVR conserva configuracion legacy; requiere migracion explicita antes de centralizar" >&2
    exit 1
  fi
  DB_PASSWORD="${BOT_IVR_DB_PASSWORD:-$(openssl rand -hex 24)}"
fi
valid_db_identifier "$DB_NAME" && valid_db_identifier "$DB_USER" || exit 2
if [[ "${ZYNERVOX_ISOLATED:-0}" == 1 ]]; then
  [[ "$DB_USER" == "$expected_user" && "$DB_USER" != zynervox_bot_ivr && "$CONFIG_DIR" != /etc/zynervox && "${ZYNERVOX_CORE_CONFIG_TABLE:-}" == v2_ivr_deploy_config && "$DB_NAME" == "$(config_value "$CORE_CONF" CORE_DB_database)" ]] || { echo 'Reconciliacion rechazada: cuenta/configuracion no aislada' >&2; exit 1; }
  [[ -n "${BOT_IVR_DB_PASSWORD:-}" && "$BOT_IVR_DB_PASSWORD" != *$'\n'* ]] || { echo 'Falta clave privada deseada para cuenta Bot v2' >&2; exit 1; }
  compatible_row="$(mysql -N "$DB_NAME" -e "SELECT COUNT(*) FROM v2_ivr_deploy_config WHERE id=1 AND (db_host='' OR (db_user='$DB_USER' AND db_name='$DB_NAME' AND db_host IN ('localhost','127.0.0.1') AND db_port=${ZYNERVOX_DB_PORT:-3306}))")"
  [[ "$compatible_row" == 1 ]] || { echo 'Conexion central distinta; no se cambia la contraseña' >&2; exit 1; }
  DB_PASSWORD="$BOT_IVR_DB_PASSWORD"
  reset_password=1
fi
password_sql="$(sql_value "$DB_PASSWORD")"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$password_sql';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$password_sql';
SQL
schemas=("$ROOT/database/init/002-bot_ivr_flows_zynervox.sql" "$ROOT/database/init/004-bot_ivr_flows-list_id.sql")
for schema in 001-zynervox.sql 002-campaign-lists.sql 003-campaign-details.sql 004-list-flow.sql 005-lead-dialer-fields.sql 006-list-dial-prefix.sql 007-call-attempts.sql 008-call-audit.sql 009-ivr-results.sql 010-call-recordings.sql 011-ivr-status.sql 012-dial-runs.sql; do
  schemas+=("$ROOT/src/features/bot_ivr/models/$schema")
done
for schema in "${schemas[@]}"; do apply_schema "$schema"; done
while IFS= read -r table_name; do
  valid_db_identifier "$table_name" || exit 2
  mysql <<SQL
GRANT SELECT,INSERT,UPDATE,DELETE ON \`$DB_NAME\`.\`$table_name\` TO '$DB_USER'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON \`$DB_NAME\`.\`$table_name\` TO '$DB_USER'@'127.0.0.1';
SQL
done < <(grep -hE '^CREATE TABLE IF NOT EXISTS' "${schemas[@]}" | sed -E 's/^CREATE TABLE IF NOT EXISTS[[:space:]]+`?([a-zA-Z0-9_]+).*/\1/' | sort -u)
if [[ ! -f "$CONFIG_FILE" || "$reset_password" == 1 ]]; then
  config_temporary="$(mktemp "$CONFIG_DIR/.bot-config.XXXXXX")"
  (umask 077; cat > "$config_temporary" <<EOF
BOT_IVR_DB_server => $DB_HOST
BOT_IVR_DB_database => $DB_NAME
BOT_IVR_DB_user => $DB_USER
BOT_IVR_DB_pass => $DB_PASSWORD
BOT_IVR_DB_port => ${ZYNERVOX_DB_PORT:-3306}
EOF
  )
  chown root:"$WEB_GROUP" "$config_temporary"
  chmod 0640 "$config_temporary"
  if [[ "$reset_password" == 1 ]]; then
    recovery_directory="$(mktemp -d "$CONFIG_DIR/.bot-recovery.XXXXXX")"
    chmod 0700 "$recovery_directory"
    [[ ! -f "$CONFIG_FILE" ]] || cp -p "$CONFIG_FILE" "$recovery_directory/bootstrap-before.conf"
    (umask 077; mysql -N -e "SHOW CREATE USER '$DB_USER'@'localhost'; SHOW CREATE USER '$DB_USER'@'127.0.0.1';" > "$recovery_directory/users-before.txt")
    (umask 077; mysql -N "$DB_NAME" -e 'SELECT * FROM v2_ivr_deploy_config WHERE id=1' > "$recovery_directory/deployment-before.txt")
    trap 'echo "Fallo reconciliando credenciales; revisar recuperacion privada: $recovery_directory" >&2' ERR
    mysql <<SQL
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$password_sql';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$password_sql';
SQL
    mysql "$DB_NAME" -e "UPDATE v2_ivr_deploy_config SET db_pass='$password_sql' WHERE id=1 AND db_user='$DB_USER' AND db_name='$DB_NAME';"
  fi
  mv -f "$config_temporary" "$CONFIG_FILE"
fi
core_db="$(config_value "$CORE_CONF" CORE_DB_database)"
mysql "$core_db" <<SQL
UPDATE ${ZYNERVOX_CORE_CONFIG_TABLE:-ivr_deploy_config} SET db_host='127.0.0.1',db_port=${ZYNERVOX_DB_PORT:-3306},db_name='$DB_NAME',
db_user='$DB_USER',db_pass='$password_sql' WHERE id=1 AND db_host='';
SQL
if [[ "$reset_password" == 1 ]]; then
  echo "BOT_IVR_PASSWORD_RECONCILED user=$DB_USER (MySQL/bootstrap/configuracion central)"
fi
echo "IVR_BUILDER_DB_READY database=$DB_NAME user=$DB_USER"

# CLI AGI must be executable when deployed from source.
for agi in call_tracking_agi.php ivr_engine_agi.php; do
  if [[ -f "$WEB_ROOT/bot_ivr/$agi" ]]; then
    chown root:"$WEB_GROUP" "$WEB_ROOT/bot_ivr/$agi"
    chmod 0750 "$WEB_ROOT/bot_ivr/$agi"
  fi
done

if [[ "${ZYNERVOX_ISOLATED:-0}" == 1 ]]; then bash "$ROOT/installer/call-audit.sh"; bash "$ROOT/installer/list-call-worker.sh" "$ROOT" "${ASTERISK_ROOT:-/var/lib/zynervoxv2/asterisk}"; fi
if [[ "${ZYNERVOX_ISOLATED:-0}" == 1 ]]; then
  install -o root -g "$WEB_GROUP" -m 0640 "$ROOT/asterisk/synervox/modules/bot_ivr/ivr_node_io.py" "$ASTERISK_ROOT/modules/bot_ivr/ivr_node_io.py"
fi
