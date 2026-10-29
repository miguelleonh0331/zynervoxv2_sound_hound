#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VENDOR="$ROOT/src/features/stt_providers/vendor"
WEB_ROOT="${WEB_ROOT:-/var/www/html/zynervox}"
WEB_GROUP="${WEB_GROUP:-www-data}"
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=www
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=root
INSTANCE="${STT_INSTANCE:-zynervox-stt}"
source "$ROOT/installer/db-common.sh"
DB_NAME="${STT_DB_NAME:-${ZYNERVOX_CORE_DB:-zynervox_core}}"
if [[ -f "$CONFIG_DIR/zynervox-core.conf" && -z "${STT_DB_NAME:-}" ]]; then
  DB_NAME="$(config_value "$CONFIG_DIR/zynervox-core.conf" CORE_DB_database)"
fi
DB_USER="${STT_DB_USER:-zynervox_stt}"
CONFIG_ENV="$CONFIG_DIR/$INSTANCE.env"
CONFIG_PHP="$CONFIG_DIR/$INSTANCE.php"
WEB_DEST="$WEB_ROOT/modules/admin/stt_providers_app"

[[ "$INSTANCE" =~ ^[a-z0-9][a-z0-9-]{2,40}$ ]] || { echo "STT_INSTANCE inválido" >&2; exit 2; }
[[ "$DB_NAME" =~ ^[a-zA-Z0-9_]+$ && "$DB_USER" =~ ^[a-zA-Z0-9_]+$ ]] || { echo "Nombre de base o usuario inválido" >&2; exit 2; }
[[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }

install -d -o root -g root -m 0755 "$CONFIG_DIR"
if [[ -f "$CONFIG_ENV" ]]; then
    # shellcheck disable=SC1090
    source "$CONFIG_ENV"
else
    STT_DB_PASSWORD="$(openssl rand -hex 24)"
    umask 077
    printf 'STT_DB_NAME=%q\nSTT_DB_USER=%q\nSTT_DB_PASSWORD=%q\n' "$DB_NAME" "$DB_USER" "$STT_DB_PASSWORD" > "$CONFIG_ENV"
    # shellcheck disable=SC1090
    source "$CONFIG_ENV"
fi

valid_db_identifier "$STT_DB_NAME" && valid_db_identifier "$STT_DB_USER" || exit 2
password_sql="$(sql_value "$STT_DB_PASSWORD")"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$STT_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$STT_DB_USER'@'localhost' IDENTIFIED BY '$password_sql';
CREATE USER IF NOT EXISTS '$STT_DB_USER'@'127.0.0.1' IDENTIFIED BY '$password_sql';
SQL
mysql "$STT_DB_NAME" < "$VENDOR/schema.sql"
for table_name in deepgram_profiles carsa_stt_api_keys carsa_stt_api_keys_account carsa_stt_api_keys_account_map; do
  mysql <<SQL
GRANT SELECT,INSERT,UPDATE,DELETE ON \`$STT_DB_NAME\`.\`$table_name\` TO '$STT_DB_USER'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON \`$STT_DB_NAME\`.\`$table_name\` TO '$STT_DB_USER'@'127.0.0.1';
SQL
done

install -d -o root -g "$WEB_GROUP" -m 0750 "$WEB_DEST"
tar -C "$VENDOR" --exclude='./config/db.php' -cf - . | tar -C "$WEB_DEST" -xf -
chown -R root:"$WEB_GROUP" "$WEB_DEST"
find "$WEB_DEST" -type d -exec chmod 0750 {} +
find "$WEB_DEST" -type f -exec chmod 0640 {} +

cat > "$CONFIG_PHP" <<EOF
<?php
return [
    'host' => '127.0.0.1',
    'port' => ${ZYNERVOX_DB_PORT:-3306},
    'database' => '$STT_DB_NAME',
    'user' => '$STT_DB_USER',
    'password' => '$STT_DB_PASSWORD',
    'charset' => 'utf8mb4',
];
EOF
chown root:"$WEB_GROUP" "$CONFIG_PHP"
chmod 0640 "$CONFIG_PHP"
cat > "$WEB_DEST/config/db.php" <<EOF
<?php
return require '$CONFIG_PHP';
EOF
chown root:"$WEB_GROUP" "$WEB_DEST/config/db.php"
chmod 0640 "$WEB_DEST/config/db.php"

echo "STT_PROVIDERS_READY instance=$INSTANCE database=$STT_DB_NAME"
