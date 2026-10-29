#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB_DIR="$ROOT/database"
ENV_FILE="$DB_DIR/.env"
SCHEMA="$DB_DIR/init/001-schema.sql"
RUNTIME_DIR="$DB_DIR/runtime"
COMPOSE=(docker compose --env-file "$ENV_FILE" -f "$DB_DIR/compose.yml")

usage() {
  echo "Uso: $0 init|up|status|credentials|install-config|backup <archivo>|restore <archivo>|down"
}

require_runtime() {
  command -v docker >/dev/null 2>&1 || { echo "Falta Docker" >&2; exit 1; }
  docker compose version >/dev/null 2>&1 || { echo "Falta Docker Compose" >&2; exit 1; }
}

generate_env() {
  [[ -f "$ENV_FILE" ]] && return
  command -v openssl >/dev/null 2>&1 || { echo "Falta openssl" >&2; exit 1; }
  local db_port=3307
  if command -v ss >/dev/null 2>&1; then
    while ss -ltnH | awk '{print $4}' | grep -qE "(^|:)${db_port}$"; do
      db_port=$((db_port + 1))
      [[ $db_port -le 3399 ]] || { echo "No hay puerto libre entre 3307 y 3399" >&2; exit 1; }
    done
  fi
  umask 077
  cat > "$ENV_FILE" <<EOF
DB_BIND=127.0.0.1
DB_PORT=$db_port
DB_NAME=asterisk
DB_USER=zynervox
DB_PASSWORD=$(openssl rand -hex 24)
DB_ROOT_PASSWORD=$(openssl rand -hex 24)
APP_ADMIN_USER=admin
APP_ADMIN_PASSWORD=$(openssl rand -hex 12)
EOF
}

load_env() {
  [[ -f "$ENV_FILE" ]] || { echo "Falta $ENV_FILE; ejecute init" >&2; exit 1; }
  set -a
  # El archivo es generado por este script con valores alfanuméricos.
  source "$ENV_FILE"
  set +a
  [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || { echo "DB_NAME inválido" >&2; exit 1; }
  [[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || { echo "DB_USER inválido" >&2; exit 1; }
  [[ "$DB_PORT" =~ ^[0-9]+$ ]] || { echo "DB_PORT inválido" >&2; exit 1; }
  [[ "$APP_ADMIN_USER" =~ ^[A-Za-z0-9_.-]+$ ]] || { echo "APP_ADMIN_USER inválido" >&2; exit 1; }
  [[ "$APP_ADMIN_PASSWORD" =~ ^[A-Za-z0-9]+$ ]] || { echo "APP_ADMIN_PASSWORD inválido" >&2; exit 1; }
}

wait_database() {
  for _ in $(seq 1 60); do
    if "${COMPOSE[@]}" exec -T mariadb mariadb-admin ping -h 127.0.0.1 -uroot -p"$DB_ROOT_PASSWORD" --silent >/dev/null 2>&1; then
      return
    fi
    sleep 2
  done
  echo "MariaDB no quedó disponible" >&2
  exit 1
}

write_runtime_config() {
  umask 077
  mkdir -p "$RUNTIME_DIR"
  cat > "$RUNTIME_DIR/astguiclient.conf" <<EOF
VARDB_server => 127.0.0.1
VARDB_database => $DB_NAME
VARDB_user => $DB_USER
VARDB_pass => $DB_PASSWORD
VARDB_port => $DB_PORT
EOF
}

initialize_database() {
  local exists
  exists="$("${COMPOSE[@]}" exec -T mariadb mariadb -N -uroot -p"$DB_ROOT_PASSWORD" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='zynervox_install_meta';")"
  if [[ "$exists" == "0" ]]; then
    "${COMPOSE[@]}" exec -T mariadb mariadb -uroot -p"$DB_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci;"
    { printf 'SET SESSION innodb_strict_mode=OFF;\n'; cat "$SCHEMA"; } | \
      "${COMPOSE[@]}" exec -T mariadb mariadb -uroot -p"$DB_ROOT_PASSWORD" "$DB_NAME"
    "${COMPOSE[@]}" exec -T mariadb mariadb -uroot -p"$DB_ROOT_PASSWORD" "$DB_NAME" <<SQL
CREATE TABLE zynervox_install_meta (
  schema_version varchar(20) NOT NULL,
  installed_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (schema_version)
) ENGINE=InnoDB;
INSERT INTO zynervox_install_meta (schema_version) VALUES ('2026.09');
INSERT INTO vicidial_user_groups (user_group, group_name, allowed_campaigns)
VALUES ('ADMIN', 'Administradores', '-ALL-CAMPAIGNS-');
INSERT INTO vicidial_users (user, pass, full_name, user_level, user_group, active)
VALUES ('$APP_ADMIN_USER', '$APP_ADMIN_PASSWORD', 'Administrador', 9, 'ADMIN', 'Y');
SQL
    echo "SCHEMA_IMPORTED tables=364"
  else
    echo "SCHEMA_ALREADY_INITIALIZED"
  fi
}

action="${1:-}"
case "$action" in
  init)
    require_runtime
    generate_env
    load_env
    "${COMPOSE[@]}" up -d
    wait_database
    initialize_database
    write_runtime_config
    echo "DATABASE_READY host=$DB_BIND port=$DB_PORT database=$DB_NAME"
    ;;
  up)
    require_runtime; load_env; "${COMPOSE[@]}" up -d; wait_database
    ;;
  status)
    require_runtime; load_env; "${COMPOSE[@]}" ps
    ;;
  credentials)
    load_env
    printf 'APP_ADMIN_USER=%s\nAPP_ADMIN_PASSWORD=%s\nDB_HOST=%s\nDB_PORT=%s\nDB_NAME=%s\nDB_USER=%s\n' \
      "$APP_ADMIN_USER" "$APP_ADMIN_PASSWORD" "$DB_BIND" "$DB_PORT" "$DB_NAME" "$DB_USER"
    ;;
  install-config)
    load_env; write_runtime_config
    sudo install -d -o root -g www-data -m 0750 /etc/zynervox
    sudo install -o root -g www-data -m 0640 "$RUNTIME_DIR/astguiclient.conf" /etc/zynervox/astguiclient.conf
    echo "CONFIG_INSTALLED /etc/zynervox/astguiclient.conf"
    ;;
  backup)
    require_runtime; load_env
    output="${2:-}"
    [[ -n "$output" ]] || { usage; exit 2; }
    umask 077
    "${COMPOSE[@]}" exec -T mariadb mariadb-dump -uroot -p"$DB_ROOT_PASSWORD" --lock-all-tables --routines --events --triggers "$DB_NAME" | gzip > "$output"
    ;;
  restore)
    require_runtime; load_env
    input="${2:-}"
    [[ -f "$input" ]] || { echo "Backup no encontrado: $input" >&2; exit 2; }
    gzip -dc "$input" | "${COMPOSE[@]}" exec -T mariadb mariadb -uroot -p"$DB_ROOT_PASSWORD" "$DB_NAME"
    ;;
  down)
    require_runtime; load_env; "${COMPOSE[@]}" down
    ;;
  *) usage; exit 2 ;;
esac
