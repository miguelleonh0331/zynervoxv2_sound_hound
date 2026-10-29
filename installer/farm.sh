#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VENDOR="$ROOT/src/features/farm/vendor"
WEB_ROOT="${WEB_ROOT:-/var/www/html/zynervox}"
WEB_GROUP="${WEB_GROUP:-www-data}"
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=www
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=root
INSTANCE="${FARM_INSTANCE:-zynervox-farm}"
PYTHON="${FARM_PYTHON:-$(command -v python3.11 || command -v python3)}"
SKIP_ANNEX="${FARM_SKIP_ANNEX:-0}"

[[ "$INSTANCE" =~ ^[a-z0-9][a-z0-9-]{2,40}$ ]] || { echo "FARM_INSTANCE inválido" >&2; exit 2; }
[[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
"$PYTHON" -c 'import sys; raise SystemExit(sys.version_info < (3, 10))' || { echo "Farm requiere Python 3.10+" >&2; exit 1; }
DEPS=(ss openssl systemctl ffmpeg curl sox)
[[ "$SKIP_ANNEX" == "1" ]] || DEPS+=(baresip)
for command in "${DEPS[@]}"; do
    command -v "$command" >/dev/null 2>&1 || { echo "Falta dependencia Farm: $command" >&2; exit 1; }
done

RUNTIME="/opt/$INSTANCE"
DATA="/var/lib/$INSTANCE"
LOG="/var/log/$INSTANCE"
CONFIG_ENV="/etc/zynervox/$INSTANCE.env"
CONFIG_PHP="/etc/zynervox/$INSTANCE.php"
WEB_DEST="$WEB_ROOT/modules/admin/farm_app"
ANNEX_SERVICE="$INSTANCE-annex.service"
CONTROL_SERVICE="$INSTANCE-control.service"

port_free() { ! ss -ltnH "sport = :$1" 2>/dev/null | grep -q .; }
choose_port() {
    local port
    for port in $(seq "$1" "$2"); do port_free "$port" && { echo "$port"; return; }; done
    echo "Sin puertos libres entre $1 y $2" >&2; exit 1
}

if [[ -f "$CONFIG_ENV" ]]; then
    # shellcheck disable=SC1090
    source "$CONFIG_ENV"
else
    FARM_ANNEX_PORT="${FARM_ANNEX_PORT:-$(choose_port 8811 8840)}"
    FARM_CONTROL_PORT="${FARM_CONTROL_PORT:-$(choose_port 8766 8800)}"
    install -d -o root -g root -m 0755 /etc/zynervox
    umask 077
    printf 'FARM_ANNEX_PORT=%q\nFARM_CONTROL_PORT=%q\nZYPAD_ASTERISK_HOST=\nTTS_API_URL=\n' "$FARM_ANNEX_PORT" "$FARM_CONTROL_PORT" > "$CONFIG_ENV"
fi

install -d -o root -g "$WEB_GROUP" -m 0750 "$WEB_DEST"
tar -C "$VENDOR" --exclude='./services' --exclude='./requirements.txt' --exclude='./zypad-pool.env.example' -cf - . | tar -C "$WEB_DEST" -xf -
chown -R root:"$WEB_GROUP" "$WEB_DEST"
find "$WEB_DEST" -type d -exec chmod 0750 {} +
find "$WEB_DEST" -type f -exec chmod 0640 {} +

install -d -o root -g root -m 0755 "$RUNTIME" "$DATA"
if [[ "$SKIP_ANNEX" != "1" ]]; then
    install -d -o root -g root -m 0755 "$RUNTIME/annex" "$DATA/annex"
fi
install -d -o root -g "$WEB_GROUP" -m 0750 "$LOG"
getent group "$INSTANCE" >/dev/null 2>&1 || groupadd --system "$INSTANCE"
id "$INSTANCE" >/dev/null 2>&1 || useradd --system --gid "$INSTANCE" --home-dir "$RUNTIME" --shell /usr/sbin/nologin "$INSTANCE"
# CONFIG_ENV nace root:root 0600 (umask 077 arriba). El control-plane corre
# como $INSTANCE (no root) y necesita escribirlo para persistir TTS_API_URL
# entre reinicios (ver set_tts_api_url en orchestrator.py). root sigue siendo
# dueno y puede escribir; $INSTANCE solo por el grupo.
chown root:"$INSTANCE" "$CONFIG_ENV"
chmod 0660 "$CONFIG_ENV"
install -d -o "$INSTANCE" -g "$INSTANCE" -m 0750 "$RUNTIME/control/secrets"
# control/ con grupo web (0750, solo traversal+listado, nada sensible vive
# directo ahi -- secrets/ adentro mantiene su propio dueno/permiso mas
# estricto): sin esto www-data no puede ni atravesar hacia proxy-accounts/
# aunque esa carpeta misma sea escribible para el. chown/chmod explicitos
# (no "install -d" otra vez): sobre un directorio ya existente no siempre
# reaplica dueno/grupo/permiso de forma confiable.
chown "$INSTANCE":"$WEB_GROUP" "$RUNTIME/control"
chmod 0750 "$RUNTIME/control"
# proxy-accounts: grupo web con escritura (0770), para que el panel pueda
# subir archivos de cuentas proxy sin SSH/FTP. El orquestador (orchestrator.py)
# ya vigila esta carpeta y recarga solo -- no hace falta avisarle del archivo
# nuevo por otra via.
install -d -o "$INSTANCE" -g "$INSTANCE" -m 0770 "$RUNTIME/control/proxy-accounts"
chown "$INSTANCE":"$WEB_GROUP" "$RUNTIME/control/proxy-accounts"
chmod 0770 "$RUNTIME/control/proxy-accounts"
install -d -o "$INSTANCE" -g "$WEB_GROUP" -m 0750 "$DATA/control"

if [[ "$SKIP_ANNEX" != "1" ]]; then
    install -m 0755 "$VENDOR/services/annex/zypad_annex" "$RUNTIME/annex/zypad_annex"
    install -m 0755 "$VENDOR/services/annex/zypad_annex_daemon.py" "$RUNTIME/annex/zypad_annex_daemon.py"
fi
for file in orchestrator.py pc_tts_worker.py pc_tts_worker_proxy.py; do
    install -o "$INSTANCE" -g "$INSTANCE" -m 0640 "$VENDOR/services/control-plane/$file" "$RUNTIME/control/$file"
done

if [[ ! -x "$RUNTIME/control/.venv/bin/python" ]]; then
    "$PYTHON" -m venv "$RUNTIME/control/.venv"
fi
"$RUNTIME/control/.venv/bin/pip" install --disable-pip-version-check -r "$VENDOR/requirements.txt"
chown -R "$INSTANCE":"$INSTANCE" "$RUNTIME/control"
chown "$INSTANCE":"$WEB_GROUP" "$DATA/control"

for secret in "$DATA/internal_token" "$DATA/control/control.token" "$RUNTIME/control/secrets/tts_jobs_token"; do
    [[ -s "$secret" ]] || openssl rand -hex 32 > "$secret"
done
touch "$LOG/audit.log"
chown root:"$WEB_GROUP" "$DATA/internal_token" "$LOG/audit.log"
chown "$INSTANCE":"$WEB_GROUP" "$DATA/control/control.token"
chown "$INSTANCE":"$INSTANCE" "$RUNTIME/control/secrets/tts_jobs_token"
chmod 0640 "$DATA/internal_token" "$DATA/control/control.token" "$RUNTIME/control/secrets/tts_jobs_token"
chmod 0660 "$LOG/audit.log"

cat > "$CONFIG_PHP" <<EOF
<?php
return [
    'annex_url' => 'http://127.0.0.1:${FARM_ANNEX_PORT}/',
    'control_url' => 'http://127.0.0.1:${FARM_CONTROL_PORT}',
    'internal_token' => '${DATA}/internal_token',
    'control_token' => '${DATA}/control/control.token',
    'audit_log' => '${LOG}/audit.log',
    'proxy_dir' => '${RUNTIME}/control/proxy-accounts',
];
EOF
chown root:"$WEB_GROUP" "$CONFIG_PHP"
chmod 0640 "$CONFIG_PHP"
cat > "$WEB_DEST/config.local.php" <<EOF
<?php
return require '${CONFIG_PHP}';
EOF
chown root:"$WEB_GROUP" "$WEB_DEST/config.local.php"
chmod 0640 "$WEB_DEST/config.local.php"

if [[ "$SKIP_ANNEX" != "1" ]]; then
cat > "/etc/systemd/system/$ANNEX_SERVICE" <<EOF
[Unit]
Description=Zynervox Farm annex daemon ($INSTANCE)
After=network.target

[Service]
Type=simple
EnvironmentFile=-$CONFIG_ENV
Environment=ZYPAD_ANNEX_PORT=$FARM_ANNEX_PORT
Environment=ZYPAD_ANNEX_SCRIPT=$RUNTIME/annex/zypad_annex
Environment=ZYPAD_PYTHON=$PYTHON
Environment=ZYPAD_DATA_DIR=$DATA/annex
Environment=ZYPAD_SERVICE_PREFIX=$INSTANCE-baresip-
Environment=ZYPAD_ENV_FILE=$CONFIG_ENV
ExecStart=$PYTHON $RUNTIME/annex/zypad_annex_daemon.py
Restart=always
RestartSec=3
User=root
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
EOF
fi

cat > "/etc/systemd/system/$CONTROL_SERVICE" <<EOF
[Unit]
Description=Zynervox Farm proxy control ($INSTANCE)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$INSTANCE
Group=$INSTANCE
WorkingDirectory=$RUNTIME/control
Environment=TTS_AUTH_TOKEN_FILE=$RUNTIME/control/secrets/tts_jobs_token
Environment=TTS_ENV_FILE=$CONFIG_ENV
Environment=PYTHONUNBUFFERED=1
EnvironmentFile=-$CONFIG_ENV
ExecStart=$RUNTIME/control/.venv/bin/python $RUNTIME/control/orchestrator.py --root $RUNTIME/control --data-dir $DATA/control --host 127.0.0.1 --port $FARM_CONTROL_PORT
Restart=on-failure
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$DATA/control $RUNTIME/control/proxy-accounts $CONFIG_ENV

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
if [[ "$SKIP_ANNEX" != "1" ]]; then
    systemctl enable --now "$ANNEX_SERVICE" "$CONTROL_SERVICE"
else
    systemctl enable --now "$CONTROL_SERVICE"
fi
if [[ "$SKIP_ANNEX" != "1" ]]; then
for _ in $(seq 1 30); do
    curl -fsS -X POST -H 'Content-Type: application/json' -d '{"action":"status"}' \
      "http://127.0.0.1:${FARM_ANNEX_PORT}/" >/dev/null 2>&1 && break
    sleep 1
done
curl -fsS -X POST -H 'Content-Type: application/json' -d '{"action":"status"}' \
  "http://127.0.0.1:${FARM_ANNEX_PORT}/" >/dev/null
fi
for _ in $(seq 1 30); do
    if [[ -s "$DATA/control/control.token" ]]; then
        token="$(cat "$DATA/control/control.token")"
        curl -fsS -H "X-Control-Token: $token" \
          "http://127.0.0.1:${FARM_CONTROL_PORT}/api/snapshot" >/dev/null 2>&1 && break
    fi
    sleep 1
done
token="$(cat "$DATA/control/control.token")"
curl -fsS -H "X-Control-Token: $token" \
  "http://127.0.0.1:${FARM_CONTROL_PORT}/api/snapshot" >/dev/null
if [[ "$SKIP_ANNEX" != "1" ]]; then
    echo "FARM_READY instance=$INSTANCE annex_port=$FARM_ANNEX_PORT control_port=$FARM_CONTROL_PORT"
else
    echo "FARM_READY (solo proxies, sin annex/baresip) instance=$INSTANCE control_port=$FARM_CONTROL_PORT"
fi
