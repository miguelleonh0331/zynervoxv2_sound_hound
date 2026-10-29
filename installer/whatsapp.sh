#!/usr/bin/env bash
# Ciclo de vida nativo de Zynerwaba (modulo whatsapp). Sin Docker ni Compose:
# proceso Node administrado por systemd, BD en el MySQL nativo del host.
# Ver docs/TAREA_WHATSAPP_NATIVO.md y ADR-0016 (docs/DECISIONS.md) para el porque.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VENDOR="$ROOT/src/features/whatsapp/vendor"
WEB_GROUP="${WEB_GROUP:-www-data}"
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=www
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=root

INSTANCE="${WHATSAPP_INSTANCE:-zynervox-whatsapp}"
[[ "$INSTANCE" =~ ^[a-z0-9][a-z0-9-]{2,40}$ ]] || { echo "WHATSAPP_INSTANCE inválido" >&2; exit 2; }

NODE_BIN_SRC="${WHATSAPP_NODE:-$(command -v node || true)}"
# Resolver symlinks (ej. /usr/local/bin/node -> /root/.hermes/node/bin/node,
# comun en instalaciones via nvm u otros gestores bajo el home de root). No
# basta con ProtectHome=true: /root suele ser 0700 root:root, asi que
# ningun usuario de servicio puede atravesarlo sin importar el hardening de
# systemd. Se copia el binario real a $RUNTIME/.bin (ver install_code) y el
# servicio ejecuta esa copia, nunca la ruta original.
[[ -n "$NODE_BIN_SRC" ]] && NODE_BIN_SRC="$(readlink -f "$NODE_BIN_SRC")"
CONFIG_ENV="/etc/zynervox/$INSTANCE.env"
RUNTIME="/opt/$INSTANCE"
NODE_BIN="$RUNTIME/.bin/node"
DATA="/var/lib/$INSTANCE"
LOG="/var/log/$INSTANCE"
SERVICE="$INSTANCE.service"

usage() { echo "Uso: $0 init|up|status|credentials|install-proxy|remove-proxy|backup <archivo>|restore <archivo>|down"; }

require_runtime() {
  local missing=()
  [[ -n "$NODE_BIN_SRC" ]] || missing+=(node)
  for cmd in npm mysql openssl curl ss; do
    command -v "$cmd" >/dev/null 2>&1 || missing+=("$cmd")
  done
  if [[ ${#missing[@]} -gt 0 ]]; then
    echo "Falta dependencia Whatsapp: ${missing[*]}" >&2
    exit 1
  fi
  "$NODE_BIN_SRC" -e 'process.exit(process.versions.node.split(".")[0] < 18 ? 1 : 0)' \
    || { echo "Whatsapp requiere Node 18+" >&2; exit 1; }
}

choose_port() {
  local port=3022
  while ss -ltnH | awk '{print $4}' | grep -qE "(^|:)${port}\$"; do
    port=$((port + 1))
    [[ $port -le 3099 ]] || { echo "No hay puerto libre entre 3022 y 3099" >&2; exit 1; }
  done
  echo "$port"
}

generate_env() {
  [[ -f "$CONFIG_ENV" ]] && return
  install -d -o root -g root -m 0755 /etc/zynervox
  local port; port="$(choose_port)"
  local base_path="${WHATSAPP_BASE_PATH_OVERRIDE:-/zynerwabav2}"
  umask 077
  cat > "$CONFIG_ENV" <<EOF
HOST=127.0.0.1
PORT=$port
BASE_PATH=$base_path
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=zynerwabav2
DB_USER=zynerwabav2
DB_PASSWORD=$(openssl rand -hex 24)
INITIAL_ADMIN_USER=admin
INITIAL_ADMIN_PASSWORD=$(openssl rand -hex 12)
SESSION_SECRET=$(openssl rand -hex 32)
CREDENTIALS_KEY=$(openssl rand -hex 32)
ZYNERVOX_SSO_SECRET=$(openssl rand -hex 32)
ZYNERVOX_EMPRESA_ID=1
EOF
}

load_env() {
  [[ -f "$CONFIG_ENV" ]] || { echo "Falta $CONFIG_ENV; ejecute init" >&2; exit 1; }
  set -a
  # shellcheck disable=SC1090
  source "$CONFIG_ENV"
  set +a
  [[ "$PORT" =~ ^[0-9]+$ ]] || { echo "PORT inválido" >&2; exit 1; }
  [[ "$BASE_PATH" =~ ^/[A-Za-z0-9._/-]+$ ]] || { echo "BASE_PATH inválido" >&2; exit 1; }
  [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || { echo "DB_NAME inválido" >&2; exit 1; }
  [[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || { echo "DB_USER inválido" >&2; exit 1; }
}

ensure_database() {
  # DROP + CREATE siempre: garantiza estructura 100% limpia en cada init,
  # sin depender de que el schema sea idempotente (tiene CREATE INDEX sin
  # IF NOT EXISTS, que falla con "Duplicate key name" si se reimporta sobre
  # una BD ya migrada).
  # Grant en 'localhost' Y '127.0.0.1': DB_HOST del .env generado es
  # 127.0.0.1 (conexion TCP), pero con "skip-name-resolve" activo en
  # MariaDB (tuning tipico de VICIdial/VICIbox, visto en mirmidon) el
  # servidor NO resuelve 127.0.0.1 a "localhost" -- exige coincidencia
  # exacta de host, y el grant de 'localhost' no cubre la conexion TCP.
  # Encontrado reinstalando en mirmidon: "Access denied... (using
  # password: YES)" aunque la password generada era la correcta.
  mysql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; \
CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD'; \
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD'; \
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; \
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD'; \
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD'; \
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1'; \
FLUSH PRIVILEGES;"
  MYSQL_PWD="$DB_PASSWORD" mysql -u "$DB_USER" "$DB_NAME" < "$ROOT/whatsapp/init/001-schema.sql"
}

ensure_system_user() {
  getent group "$INSTANCE" >/dev/null 2>&1 || groupadd --system "$INSTANCE"
  id "$INSTANCE" >/dev/null 2>&1 || \
    useradd --system --gid "$INSTANCE" --home-dir "$RUNTIME" --shell /usr/sbin/nologin "$INSTANCE"
}

install_code() {
  install -d -o root -g root -m 0755 "$RUNTIME"
  install -d -o root -g root -m 0755 "$DATA" "$DATA/media"

  tar -C "$VENDOR" --exclude='./data' -cf - . | tar -C "$RUNTIME" -xf -

  # El codigo vendorizado calcula su directorio de adjuntos de forma relativa
  # (DB_PATH_MEDIA en src/shared/config.js), no por variable de entorno.
  # Se resuelve con un enlace hacia el directorio persistente real, para que
  # ProtectSystem=strict pueda dejar /opt en solo lectura y solo abrir $DATA.
  rm -rf "$RUNTIME/data"
  ln -s "$DATA" "$RUNTIME/data"

  (cd "$RUNTIME" && npm ci --omit=dev --no-audit --no-fund)

  chown -R root:root "$RUNTIME"
  chown "$INSTANCE":"$INSTANCE" "$DATA" "$DATA/media"
  find "$RUNTIME" -type d -exec chmod 0755 {} +
  find "$RUNTIME" -type f -exec chmod 0644 {} +
  chmod 0750 "$DATA" "$DATA/media"

  # Copia propia del binario de Node, nunca la ruta original: evita depender
  # de permisos fuera de nuestro control (ej. node bajo /root, 0700, ilegible
  # para el usuario de servicio sin importar el hardening de systemd).
  install -d -o root -g root -m 0755 "$RUNTIME/.bin"
  install -m 0755 -o root -g root "$NODE_BIN_SRC" "$NODE_BIN"

  install -d -o root -g "$WEB_GROUP" -m 0750 "$LOG"
}

write_service() {
  cat > "/etc/systemd/system/$SERVICE" <<EOF
[Unit]
Description=Zynervox WhatsApp (Zynerwaba nativo, $INSTANCE)
After=network-online.target mysql.service
Wants=network-online.target

[Service]
Type=simple
User=$INSTANCE
Group=$INSTANCE
WorkingDirectory=$RUNTIME
EnvironmentFile=$CONFIG_ENV
ExecStart=$NODE_BIN $RUNTIME/server.js
Restart=on-failure
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$DATA
StandardOutput=append:$LOG/service.log
StandardError=append:$LOG/service.log

[Install]
WantedBy=multi-user.target
EOF
  touch "$LOG/service.log"
  chown "$INSTANCE":"$WEB_GROUP" "$LOG/service.log"
  chmod 0640 "$LOG/service.log"
  systemctl daemon-reload
}

wait_app() {
  for _ in $(seq 1 60); do
    curl -fsS "http://127.0.0.1:${PORT}${BASE_PATH}/" >/dev/null 2>&1 && return
    sleep 2
  done
  echo "Zynerwaba no quedó disponible" >&2
  journalctl -u "$SERVICE" --no-pager -n 30 >&2 || true
  exit 1
}

write_sync_admin_script() {
  cat > "$RUNTIME/.whatsapp_sync_admin.js" <<'EOF'
const bcrypt = require("bcryptjs");
const mysql = require("mysql2/promise");
(async () => {
  const connection = await mysql.createConnection({
    host: process.env.DB_HOST,
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_NAME,
  });
  const hash = bcrypt.hashSync(String(process.env.INITIAL_ADMIN_PASSWORD), 10);
  const [result] = await connection.execute(
    "UPDATE users SET password_hash=?, active=1 WHERE username=? AND role='superadmin'",
    [hash, process.env.INITIAL_ADMIN_USER]
  );
  await connection.end();
  if (result.affectedRows !== 1) throw new Error("superadmin rows updated: " + result.affectedRows);
  console.log("WHATSAPP_ADMIN_SYNCED");
})().catch((error) => { console.error(error.message); process.exit(1); });
EOF
  chown "$INSTANCE":"$INSTANCE" "$RUNTIME/.whatsapp_sync_admin.js"
  chmod 0640 "$RUNTIME/.whatsapp_sync_admin.js"
}

action="${1:-}"
case "$action" in
  init)
    [[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
    require_runtime
    generate_env
    load_env
    ensure_database
    ensure_system_user
    install_code
    write_sync_admin_script
    write_service
    # restart (no enable --now): en reinstalaciones el servicio ya puede estar
    # activo; --now no reinicia un activo, y tras el DROP+CREATE de la BD el
    # proceso Node debe arrancar de cero para recrear el superadmin inicial.
    systemctl enable "$SERVICE"
    systemctl restart "$SERVICE"
    wait_app
    env -i DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_USER="$DB_USER" DB_PASSWORD="$DB_PASSWORD" DB_NAME="$DB_NAME" \
      INITIAL_ADMIN_USER="$INITIAL_ADMIN_USER" INITIAL_ADMIN_PASSWORD="$INITIAL_ADMIN_PASSWORD" \
      "$NODE_BIN" "$RUNTIME/.whatsapp_sync_admin.js"
    echo "WHATSAPP_READY host=127.0.0.1 port=$PORT path=$BASE_PATH"
    ;;
  up)
    [[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
    load_env
    systemctl start "$SERVICE"
    wait_app
    ;;
  status)
    load_env
    systemctl is-active "$SERVICE" || true
    systemctl status "$SERVICE" --no-pager -l || true
    ;;
  credentials)
    load_env
    printf 'WHATSAPP_ADMIN_USER=%s\nWHATSAPP_ADMIN_PASSWORD=%s\nWHATSAPP_PATH=%s\n' \
      "$INITIAL_ADMIN_USER" "$INITIAL_ADMIN_PASSWORD" "$BASE_PATH"
    ;;
  install-proxy)
    [[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
    load_env
    command -v a2enmod >/dev/null 2>&1 && a2enmod proxy proxy_http headers >/dev/null
    proxy_slug="$(printf '%s' "$BASE_PATH" | tr -c 'A-Za-z0-9' '-' | sed 's/^-*//;s/-*$//')"
    proxy_name="zynervox-whatsapp-${proxy_slug:-default}"
    if command -v a2enconf >/dev/null 2>&1; then
      proxy_file="/etc/apache2/conf-available/${proxy_name}.conf"
    elif [[ -d /etc/apache2/conf.d ]]; then
      proxy_file="/etc/apache2/conf.d/${proxy_name}.conf"
    else
      echo "No se encontró directorio de configuración Apache" >&2; exit 1
    fi
    sed -e "s|__BASE_PATH__|$BASE_PATH|g" -e "s|__PORT__|$PORT|g" \
      "$ROOT/installer/apache-whatsapp.conf.template" > "$proxy_file"
    command -v a2enconf >/dev/null 2>&1 && a2enconf "$proxy_name" >/dev/null
    apache2ctl configtest
    systemctl reload apache2
    install -d -o root -g "$WEB_GROUP" -m 0750 /etc/zynervox
    printf 'WHATSAPP_BASE_PATH=%s\nZYNERVOX_SSO_SECRET=%s\nZYNERVOX_EMPRESA_ID=%s\n' \
      "$BASE_PATH" "$ZYNERVOX_SSO_SECRET" "$ZYNERVOX_EMPRESA_ID" > /etc/zynervox/whatsapp.conf
    chown root:"$WEB_GROUP" /etc/zynervox/whatsapp.conf
    chmod 0640 /etc/zynervox/whatsapp.conf
    echo "WHATSAPP_PROXY_READY path=$BASE_PATH port=$PORT"
    ;;
  remove-proxy)
    [[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
    load_env
    proxy_slug="$(printf '%s' "$BASE_PATH" | tr -c 'A-Za-z0-9' '-' | sed 's/^-*//;s/-*$//')"
    proxy_name="zynervox-whatsapp-${proxy_slug:-default}"
    if command -v a2disconf >/dev/null 2>&1; then
      a2disconf "$proxy_name" >/dev/null 2>&1 || true
      rm -f "/etc/apache2/conf-available/${proxy_name}.conf"
    elif [[ -d /etc/apache2/conf.d ]]; then
      rm -f "/etc/apache2/conf.d/${proxy_name}.conf"
    fi
    if command -v apache2ctl >/dev/null 2>&1; then
      rm -f /etc/zynervox/whatsapp.conf
      apache2ctl configtest
      systemctl reload apache2
    fi
    echo "WHATSAPP_PROXY_REMOVED"
    ;;
  backup)
    load_env
    output="${2:-}"
    [[ -n "$output" ]] || { usage; exit 2; }
    umask 077
    MYSQL_PWD="$DB_PASSWORD" mysqldump -h 127.0.0.1 -u "$DB_USER" \
      --single-transaction --routines --events --triggers "$DB_NAME" | gzip > "$output"
    echo "WHATSAPP_BACKUP_READY file=$output"
    ;;
  restore)
    load_env
    input="${2:-}"
    [[ -f "$input" ]] || { echo "Backup no encontrado: $input" >&2; exit 2; }
    gzip -dc "$input" | MYSQL_PWD="$DB_PASSWORD" mysql -h 127.0.0.1 -u "$DB_USER" "$DB_NAME"
    echo "WHATSAPP_RESTORE_OK file=$input"
    ;;
  down)
    [[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
    load_env
    systemctl stop "$SERVICE"
    ;;
  *) usage; exit 2 ;;
esac
