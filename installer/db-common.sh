#!/usr/bin/env bash
CONFIG_DIR="${ZYNERVOX_CONFIG_DIR:-/etc/zynervox}"
if command -v mysql >/dev/null 2>&1; then
  actual_mysql_port="$(mysql -N -e 'SELECT @@port')" || exit 1
  [[ "$actual_mysql_port" == "${ZYNERVOX_DB_PORT:-3306}" ]] || { echo "Destino MySQL no coincide con el puerto configurado; no se aplican cambios" >&2; exit 1; }
fi
WEB_GROUP="${WEB_GROUP:-www-data}"
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=www
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=root
config_value() {
  awk -v key="$2" '$1 == key && $2 == "=>" {sub(/^[^=]*=>[[:space:]]*/, ""); print; exit}' "$1"
}
sql_value() {
  local value="$1"
  value="${value//\\/\\\\}"
  value="${value//\'/\'\'}"
  printf '%s' "$value"
}
valid_db_identifier() {
  [[ "$1" =~ ^[a-zA-Z0-9_]+$ ]]
}
apply_schema() {
  [[ -f "$1" ]] || { echo "Falta esquema: $1" >&2; return 1; }
  python3 "$ROOT/installer/apply-schema.py" "$DB_NAME" "$1"
}
