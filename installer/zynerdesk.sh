#!/usr/bin/env bash
# Ciclo de vida nativo de Zynerdesk (Synervox Remoteo). Sin Docker ni Compose:
# proceso Node administrado por systemd, BD en el MySQL nativo del host.
# Ver ADR-0016 (docs/DECISIONS.md) para el porque.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VENDOR="$ROOT/src/features/zynerdesk/vendor"
WEB_GROUP="${WEB_GROUP:-www-data}"
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=www
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=root

INSTANCE="${ZYNERDESK_INSTANCE:-zynervox-zynerdesk}"
[[ "$INSTANCE" =~ ^[a-z0-9][a-z0-9-]{2,40}$ ]] || { echo "ZYNERDESK_INSTANCE inválido" >&2; exit 2; }

NODE_BIN_SRC="${ZYNERDESK_NODE:-$(command -v node || true)}"
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
LOG="/var/log/$INSTANCE"
SERVICE="$INSTANCE.service"

usage() { echo "Uso: $0 init|upgrade [backup.sql.gz]|up|status|credentials|install-proxy|remove-proxy|install-control|backup <archivo>|restore <archivo>|down"; }

require_runtime() {
  local missing=()
  [[ -n "$NODE_BIN_SRC" ]] || missing+=(node)
  for cmd in npm mysql openssl curl ss; do
    command -v "$cmd" >/dev/null 2>&1 || missing+=("$cmd")
  done
  if [[ ${#missing[@]} -gt 0 ]]; then
    echo "Falta dependencia Zynerdesk: ${missing[*]}" >&2
    exit 1
  fi
  "$NODE_BIN_SRC" -e 'process.exit(process.versions.node.split(".")[0] < 18 ? 1 : 0)' \
    || { echo "Zynerdesk requiere Node 18+" >&2; exit 1; }
}

choose_port() {
  local port=4100
  while ss -ltnH | awk '{print $4}' | grep -qE "(^|:)${port}\$"; do
    port=$((port + 1))
    [[ $port -le 4199 ]] || { echo "No hay puerto libre entre 4100 y 4199" >&2; exit 1; }
  done
  echo "$port"
}

generate_env() {
  [[ -f "$CONFIG_ENV" ]] && return
  local activity_token="${ACTIVITY_INGEST_TOKEN:-}"
  if [[ -z "$activity_token" ]]; then
    activity_token="$(openssl rand -hex 32)"
  fi
  [[ "$activity_token" =~ ^[A-Fa-f0-9]{64}$ ]] || {
    echo "ACTIVITY_INGEST_TOKEN debe contener el token estatico de produccion (64 hex)" >&2
    exit 2
  }
  install -d -o root -g root -m 0755 /etc/zynervox
  local port; port="$(choose_port)"
  local base_path="${ZYNERDESK_BASE_PATH_OVERRIDE:-/zynerdesk}"
  umask 077
  cat > "$CONFIG_ENV" <<EOF
HOST=127.0.0.1
PORT=$port
BASE_PATH=$base_path
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=syner_remoteo
DB_USER=syner_remoteo
DB_PASS=$(openssl rand -hex 24)
INITIAL_ADMIN_USER=admin
INITIAL_ADMIN_PASSWORD=$(openssl rand -hex 12)
ZYNERVOX_SSO_SECRET=$(openssl rand -hex 32)
ACTIVITY_INGEST_TOKEN=$activity_token
COOKIE_SECURE=0
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
  [[ "${ACTIVITY_INGEST_TOKEN:-}" =~ ^[A-Fa-f0-9]{64}$ ]] || {
    echo "ACTIVITY_INGEST_TOKEN ausente o invalido en $CONFIG_ENV" >&2
    exit 1
  }
}

ensure_database() {
  # DROP + CREATE siempre: garantiza estructura 100% limpia en cada init.
  # El esquema lo aplica scripts/start.js solo, en el arranque (tabla
  # schema_migrations), sobre la BD ya vacía.
  # Grant en 'localhost' Y '127.0.0.1': mismo motivo que whatsapp.sh --
  # DB_HOST del .env es 127.0.0.1 (TCP), y con "skip-name-resolve" activo
  # en MariaDB (tuning tipico VICIdial/VICIbox) el grant de 'localhost' no
  # cubre esa conexion.
  mysql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; \
CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; \
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; \
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; \
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS'; \
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS'; \
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1'; \
FLUSH PRIVILEGES;"
}

ensure_system_user() {
  getent group "$INSTANCE" >/dev/null 2>&1 || groupadd --system "$INSTANCE"
  id "$INSTANCE" >/dev/null 2>&1 || \
    useradd --system --gid "$INSTANCE" --home-dir "$RUNTIME" --shell /usr/sbin/nologin "$INSTANCE"
}

install_code() {
  local target="${1:-$RUNTIME}"
  install -d -o root -g root -m 0755 "$target"
  tar -C "$VENDOR" -cf - . | tar -C "$target" -xf -
  (cd "$target" && npm ci --omit=dev --no-audit --no-fund)

  chown -R root:root "$target"
  find "$target" -type d -exec chmod 0755 {} +
  find "$target" -type f -exec chmod 0644 {} +

  # Copia propia del binario de Node, nunca la ruta original: evita depender
  # de permisos fuera de nuestro control (ej. node bajo /root, 0700, ilegible
  # para el usuario de servicio sin importar el hardening de systemd).
  install -d -o root -g root -m 0755 "$target/.bin"
  install -m 0755 -o root -g root "$NODE_BIN_SRC" "$target/.bin/node"

  install -d -o root -g "$WEB_GROUP" -m 0750 "$LOG"
}

write_service() {
  cat > "/etc/systemd/system/$SERVICE" <<EOF
[Unit]
Description=Zynervox Zynerdesk (Synervox Remoteo nativo, $INSTANCE)
After=network-online.target mysql.service
Wants=network-online.target

[Service]
Type=simple
User=$INSTANCE
Group=$INSTANCE
WorkingDirectory=$RUNTIME
EnvironmentFile=$CONFIG_ENV
ExecStart=$NODE_BIN $RUNTIME/scripts/start.js
Restart=on-failure
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
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
    curl -fsS "http://127.0.0.1:${PORT}/login.html" >/dev/null 2>&1 && return
    sleep 2
  done
  echo "Zynerdesk no quedó disponible" >&2
  journalctl -u "$SERVICE" --no-pager -n 30 >&2 || true
  return 1
}

backup_database() {
  local output="$1"
  local backup_dir
  backup_dir="$(dirname "$output")"
  [[ -d "$backup_dir" ]] || install -d -o root -g root -m 0700 "$backup_dir"
  umask 077
  MYSQL_PWD="$DB_PASS" mysqldump -h 127.0.0.1 -u "$DB_USER" \
    --single-transaction --routines --events --triggers "$DB_NAME" | gzip > "$output"
  echo "ZYNERDESK_BACKUP_READY file=$output"
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
    write_service
    # restart (no enable --now): tras el DROP+CREATE de la BD, start.js debe
    # arrancar de cero para recrear el superadmin inicial; --now no reinicia
    # un servicio ya activo en reinstalaciones.
    systemctl enable "$SERVICE"
    systemctl restart "$SERVICE"
    wait_app
    echo "ZYNERDESK_READY host=127.0.0.1 port=$PORT path=$BASE_PATH"
    ;;
  upgrade)
    [[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
    require_runtime
    load_env
    [[ -d "$RUNTIME" ]] || { echo "Runtime no encontrado: $RUNTIME; ejecute init" >&2; exit 1; }
    timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
    backup_file="${2:-/var/backups/zynervox/${INSTANCE}-${timestamp}.sql.gz}"
    stage="${RUNTIME}.upgrade.$$"
    previous="${RUNTIME}.previous.${timestamp}"
    failed="${RUNTIME}.failed.${timestamp}"
    backup_database "$backup_file"
    install_code "$stage"
    systemctl stop "$SERVICE"
    mv "$RUNTIME" "$previous"
    mv "$stage" "$RUNTIME"
    if systemctl restart "$SERVICE" && wait_app; then
      echo "ZYNERDESK_UPGRADE_OK service=$SERVICE backup=$backup_file previous=$previous"
    else
      systemctl stop "$SERVICE" || true
      mv "$RUNTIME" "$failed"
      mv "$previous" "$RUNTIME"
      systemctl restart "$SERVICE"
      wait_app || true
      echo "ZYNERDESK_UPGRADE_ROLLBACK service=$SERVICE failed=$failed" >&2
      exit 1
    fi
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
    printf 'ZYNERDESK_ADMIN_USER=%s\nZYNERDESK_ADMIN_PASSWORD=%s\nZYNERDESK_PATH=%s\n' \
      "$INITIAL_ADMIN_USER" "$INITIAL_ADMIN_PASSWORD" "$BASE_PATH"
    ;;
  install-proxy)
    [[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
    load_env
    command -v a2enmod >/dev/null 2>&1 && a2enmod proxy proxy_http proxy_wstunnel headers >/dev/null
    proxy_slug="$(printf '%s' "$BASE_PATH" | tr -c 'A-Za-z0-9' '-' | sed 's/^-*//;s/-*$//')"
    proxy_name="zynervox-zynerdesk-${proxy_slug:-default}"
    if command -v a2enconf >/dev/null 2>&1; then
      proxy_file="/etc/apache2/conf-available/${proxy_name}.conf"
    elif [[ -d /etc/apache2/conf.d ]]; then
      proxy_file="/etc/apache2/conf.d/${proxy_name}.conf"
    else
      echo "No se encontró directorio de configuración Apache" >&2; exit 1
    fi
    sed -e "s|__BASE_PATH__|$BASE_PATH|g" -e "s|__PORT__|$PORT|g" \
      "$ROOT/installer/apache-zynerdesk.conf.template" > "$proxy_file"
    command -v a2enconf >/dev/null 2>&1 && a2enconf "$proxy_name" >/dev/null
    apache2ctl configtest
    systemctl reload apache2
    install -d -o root -g "$WEB_GROUP" -m 0750 /etc/zynervox
    printf 'ZYNERDESK_BASE_PATH=%s\nZYNERDESK_PORT=%s\nZYNERVOX_SSO_SECRET=%s\n' \
      "$BASE_PATH" "$PORT" "$ZYNERVOX_SSO_SECRET" > /etc/zynervox/zynerdesk.conf
    chown root:"$WEB_GROUP" /etc/zynervox/zynerdesk.conf
    chmod 0640 /etc/zynervox/zynerdesk.conf
    echo "ZYNERDESK_PROXY_READY path=$BASE_PATH port=$PORT"
    ;;
  remove-proxy)
    [[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
    load_env
    proxy_slug="$(printf '%s' "$BASE_PATH" | tr -c 'A-Za-z0-9' '-' | sed 's/^-*//;s/-*$//')"
    proxy_name="zynervox-zynerdesk-${proxy_slug:-default}"
    if command -v a2disconf >/dev/null 2>&1; then
      a2disconf "$proxy_name" >/dev/null 2>&1 || true
      rm -f "/etc/apache2/conf-available/${proxy_name}.conf"
    elif [[ -d /etc/apache2/conf.d ]]; then
      rm -f "/etc/apache2/conf.d/${proxy_name}.conf"
    fi
    if command -v apache2ctl >/dev/null 2>&1; then
      rm -f /etc/zynervox/zynerdesk.conf
      apache2ctl configtest
      systemctl reload apache2
    fi
    echo "ZYNERDESK_PROXY_REMOVED"
    ;;
  install-control)
    # Control-daemon para que el panel web pueda parar/iniciar el servicio
    # ($SERVICE) sin usar sudo: en hosts con Apache endurecido
    # (ProtectSystem=full en el unit systemd), /usr queda montado "nosuid"
    # dentro del sandbox del servicio, lo que inutiliza el bit setuid de
    # /usr/bin/sudo para cualquier hijo de Apache (confirmado en
    # docker_converxa; ver el mismo fix en installer/zypad.sh). El daemon
    # corre como root desde que systemd lo arranca -- no escala privilegios
    # en caliente -- asi que el hardening de Apache no lo afecta.
    [[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
    # Prioriza python3.11: algunos hosts (ej. openSUSE VICIbox) traen de
    # fabrica un python3 generico muy antiguo (3.6, sin "from __future__
    # import annotations") junto a un python3.11 real instalado aparte.
    # Candidatos por ruta absoluta ademas de PATH: bajo `sudo` en algunos
    # hosts (visto en mirmidon) el PATH efectivo no incluye /usr/local/bin.
    PYTHON="$(command -v python3.11 || command -v /usr/local/bin/python3.11 || command -v /usr/bin/python3.11 || command -v python3)"
    [[ -n "$PYTHON" ]] || { echo "install-control requiere python3" >&2; exit 1; }
    CONTROL_RUNTIME="/opt/$INSTANCE-control"
    CONTROL_TOKEN_FILE="/etc/zynervox/$INSTANCE-control.token"
    CONTROL_PORT_FILE="/etc/zynervox/$INSTANCE-control.port"
    CONTROL_SERVICE="$INSTANCE-control.service"
    CONTROL_CONF="/etc/zynervox/zynerdesk-control.php"

    control_port_free() { ! ss -ltnH "sport = :$1" 2>/dev/null | grep -q .; }
    choose_control_port() {
      local port
      for port in $(seq 8880 8900); do control_port_free "$port" && { echo "$port"; return; }; done
      echo "Sin puertos libres entre 8880 y 8900" >&2; exit 1
    }

    install -d -o root -g root -m 0755 "$CONTROL_RUNTIME"
    if [[ ! -s "$CONTROL_TOKEN_FILE" ]]; then
      umask 077
      openssl rand -hex 32 > "$CONTROL_TOKEN_FILE"
    fi
    chown root:root "$CONTROL_TOKEN_FILE"
    chmod 0400 "$CONTROL_TOKEN_FILE"
    CONTROL_TOKEN="$(cat "$CONTROL_TOKEN_FILE")"

    if [[ -s "$CONTROL_PORT_FILE" ]]; then
      CONTROL_PORT="$(cat "$CONTROL_PORT_FILE")"
    else
      CONTROL_PORT="$(choose_control_port)"
      echo "$CONTROL_PORT" > "$CONTROL_PORT_FILE"
    fi

    cat > "$CONTROL_RUNTIME/control.py" <<'PY'
#!/usr/bin/env python3
"""Control-daemon generico de systemd: start/stop/status de UNA unidad fija,
por HTTP en 127.0.0.1, autenticado con token. Corre como root via systemd
para poder controlar otro servicio sin sudo (ver installer/zynerdesk.sh y
installer/zypad.sh para el porque)."""
from __future__ import annotations

import argparse
import hmac
import json
import subprocess
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

UNIT = ""
TOKEN = ""


def systemctl(*args: str) -> tuple[bool, str]:
    try:
        proc = subprocess.run(
            ["/usr/bin/systemctl", *args],
            capture_output=True, text=True, timeout=20,
        )
        return proc.returncode == 0, (proc.stdout + proc.stderr).strip()
    except Exception as exc:  # noqa: BLE001 - se reporta al panel, no se oculta
        return False, str(exc)


def status() -> dict:
    ok, out = systemctl("is-active", UNIT)
    return {"ok": True, "running": ok and out.strip() == "active"}


class Handler(BaseHTTPRequestHandler):
    def _authorized(self) -> bool:
        return hmac.compare_digest(self.headers.get("X-Control-Token", ""), TOKEN)

    def _json(self, payload: dict, status_code: int = 200) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:
        if not self._authorized():
            self._json({"ok": False, "error": "unauthorized"}, 403)
            return
        if self.path.rstrip("/") == "/status":
            self._json(status())
        else:
            self._json({"ok": False, "error": "not found"}, 404)

    def do_POST(self) -> None:
        if not self._authorized():
            self._json({"ok": False, "error": "unauthorized"}, 403)
            return
        action = self.path.rstrip("/").lstrip("/")
        if action in ("start", "stop"):
            ok, out = systemctl(action, UNIT)
            self._json({"ok": ok, "detail": out})
        else:
            self._json({"ok": False, "error": "not found"}, 404)

    def log_message(self, fmt: str, *args) -> None:  # silencia access log a stderr
        return


def main() -> None:
    global UNIT, TOKEN
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, required=True)
    parser.add_argument("--unit", required=True)
    parser.add_argument("--token-file", required=True)
    args = parser.parse_args()
    UNIT = args.unit
    with open(args.token_file, encoding="utf-8") as fh:
        TOKEN = fh.read().strip()
    ThreadingHTTPServer(("127.0.0.1", args.port), Handler).serve_forever()


if __name__ == "__main__":
    main()
PY
    chown root:root "$CONTROL_RUNTIME/control.py"
    chmod 0500 "$CONTROL_RUNTIME/control.py"

    cat > "/etc/systemd/system/$CONTROL_SERVICE" <<EOF
[Unit]
Description=Zynervox Zynerdesk control daemon ($INSTANCE, start/stop sin sudo)
After=network.target

[Service]
Type=simple
ExecStart=$PYTHON $CONTROL_RUNTIME/control.py --port $CONTROL_PORT --unit $SERVICE --token-file $CONTROL_TOKEN_FILE
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    # "restart" y no "enable --now": ver el mismo fix/motivo en zypad.sh.
    systemctl enable "$CONTROL_SERVICE"
    systemctl restart "$CONTROL_SERVICE"

    for _ in $(seq 1 15); do
      curl -fsS -H "X-Control-Token: $CONTROL_TOKEN" "http://127.0.0.1:${CONTROL_PORT}/status" >/dev/null 2>&1 && break
      sleep 1
    done
    curl -fsS -H "X-Control-Token: $CONTROL_TOKEN" "http://127.0.0.1:${CONTROL_PORT}/status" >/dev/null

    install -d -o root -g "$WEB_GROUP" -m 0750 /etc/zynervox
    cat > "$CONTROL_CONF" <<EOF
<?php
return [
    'service' => '$SERVICE',
    'control_port' => $CONTROL_PORT,
    'control_token' => '$CONTROL_TOKEN',
];
EOF
    chown root:"$WEB_GROUP" "$CONTROL_CONF"
    chmod 0640 "$CONTROL_CONF"

    echo "ZYNERDESK_CONTROL_READY service=$SERVICE port=$CONTROL_PORT"
    ;;
  backup)
    load_env
    output="${2:-}"
    [[ -n "$output" ]] || { usage; exit 2; }
    backup_database "$output"
    ;;
  restore)
    load_env
    input="${2:-}"
    [[ -f "$input" ]] || { echo "Backup no encontrado: $input" >&2; exit 2; }
    gzip -dc "$input" | MYSQL_PWD="$DB_PASS" mysql -h 127.0.0.1 -u "$DB_USER" "$DB_NAME"
    echo "ZYNERDESK_RESTORE_OK file=$input"
    ;;
  down)
    [[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
    load_env
    systemctl stop "$SERVICE"
    ;;
  *) usage; exit 2 ;;
esac
