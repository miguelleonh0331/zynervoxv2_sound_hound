#!/usr/bin/env bash
set -euo pipefail

# Zypad: detector de preanswer (STT + reglas) empaquetado en Docker
# (miguelleonh0331/zypad-vosk). A diferencia de farm/whatsapp/zynerdesk/stt
# (nativos, systemd), este modulo SI requiere Docker porque asi se distribuye
# el servicio. Se instala una sola vez por VPS (sin $INSTANCE variable): el
# nombre de contenedor y el puerto son fijos a proposito (ver zynervox-deploy
# para el porque).
#
# El puerto se publica en la IP LAN del host (no solo localhost): otros VPS
# de la misma red le mandan audio para transcribir. La API key se genera aqui
# y se muestra en el panel web (Servicios > Zypad) para copiarla a esos otros
# servidores.
#
# Control start/stop/stats desde el panel web: NO usa sudo. En hosts con
# Apache endurecido (ProtectSystem=full en el unit systemd) /usr queda
# montado con la bandera "nosuid" dentro del sandbox del servicio, lo que
# inutiliza el bit setuid de /usr/bin/sudo para cualquier hijo de Apache --
# sudo -n siempre deniega ahi, sin importar la regla sudoers (se verifico en
# docker_converxa: file_exists() de /etc/sudoers.d/* ya da false desde
# dentro de Apache). En vez de eso se instala un control-daemon systemd
# (corre como root desde el arranque, sin necesitar escalar privilegios) que
# expone start/stop/status por HTTP en 127.0.0.1 con un token; el panel PHP
# le habla igual que a los control-plane de farm/zynerdesk.

INSTANCE="zynervox-zypad"
IMAGE="${ZYPAD_IMAGE:-miguelleonh0331/zypad-vosk:0.5.0}"
PORT="${ZYPAD_PORT:-8767}"
DATA_DIR="/var/lib/$INSTANCE/data"
RUNTIME_DIR="/opt/$INSTANCE"
CONFIG_ENV="/etc/zynervox/$INSTANCE.env"
CONFIG_PHP="/etc/zynervox/$INSTANCE.php"
CONTROL_TOKEN_FILE="/etc/zynervox/$INSTANCE-control.token"
CONTROL_SERVICE="$INSTANCE-control.service"
WEB_GROUP="${WEB_GROUP:-www-data}"
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=www
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=root

[[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
command -v docker >/dev/null 2>&1 || { echo "Zypad requiere Docker instalado (reinstale con --install-docker)" >&2; exit 1; }
# Prioriza python3.11: algunos hosts (ej. openSUSE VICIbox) traen de fabrica
# un python3 generico muy antiguo (3.6, sin "from __future__ import
# annotations") junto a un python3.11 real instalado aparte. Mismo patron
# que installer/farm.sh.
# Candidatos por ruta absoluta ademas de PATH: bajo `sudo` en algunos hosts
# (visto en mirmidon) el PATH efectivo no incluye /usr/local/bin -- donde
# vive el python3.11 real -- y "command -v python3.11" sin PATH no lo
# encuentra aunque exista.
PYTHON="$(command -v python3.11 || command -v /usr/local/bin/python3.11 || command -v /usr/bin/python3.11 || command -v python3)"
[[ -n "$PYTHON" ]] || { echo "Zypad requiere python3 para el control-daemon" >&2; exit 1; }

# Prioriza la IP de la red privada real entre servidores. Dos casos vistos:
# - Hosts con VLAN/VPC privada del proveedor (ej. docker_converxa): una IP
#   RFC1918 real en la interfaz fisica (eth0/ens*).
# - Hosts sin esa VLAN (ej. mirmidon): la "LAN" entre servidores es una
#   malla Tailscale (interfaz "tailscale0", rango 100.64.0.0/10 CGNAT), y
#   "hostname -I" por rango RFC1918 a secas se confunde con los bridges
#   internos de Docker (docker0/br-*, SIEMPRE en 172.16-31.x aunque no
#   tengan nada que ver con otros servidores). Por eso se filtra por nombre
#   de interfaz, no solo por rango numerico.
# Si no hay ninguna, cae a 0.0.0.0 con aviso explicito -- mismo riesgo ya
# documentado para el zypad-vosk de docker_converxa.
detect_bind_ip() {
    local iface cidr ip
    while read -r iface cidr; do
        case "$iface" in
            tailscale*) echo "${cidr%%/*}"; return ;;
        esac
    done < <(ip -4 -o addr show 2>/dev/null | awk '{print $2, $4}')
    while read -r iface cidr; do
        case "$iface" in
            docker*|br-*|veth*|virbr*|lo) continue ;;
        esac
        ip="${cidr%%/*}"
        case "$ip" in
            10.*|172.1[6-9].*|172.2[0-9].*|172.3[0-1].*|192.168.*) echo "$ip"; return ;;
        esac
    done < <(ip -4 -o addr show 2>/dev/null | awk '{print $2, $4}')
    echo ""
}

port_free() { ! ss -ltnH "sport = :$1" 2>/dev/null | grep -q .; }
choose_port() {
    local port
    for port in $(seq "$1" "$2"); do port_free "$port" && { echo "$port"; return; }; done
    echo "Sin puertos libres entre $1 y $2" >&2; exit 1
}

install -d -o root -g root -m 0755 /etc/zynervox
install -d -o root -g root -m 0755 "$DATA_DIR"
install -d -o root -g root -m 0755 "$RUNTIME_DIR"

# Semilla de categorias: sin este archivo el servicio crashea al arrancar
# (RuleStore::snapshot() se llama en el lifespan de FastAPI y revienta si no
# puede leer el JSON). Se usa el set ya validado contra audio real en
# docker_converxa (sesion zypad-vosk 0.5.0): sin terminos genericos que
# generen falsos positivos.
if [[ ! -f "$DATA_DIR/categories.json" ]]; then
    cat > "$DATA_DIR/categories.json" <<'JSON'
{
  "casilla": [
    "buzon",
    "buzon de voz",
    "casilla",
    "casilla de voz",
    "deje su mensaje",
    "despues del tono",
    "mensaje"
  ],
  "numero_no_existe": [
    "no existe",
    "no exite",
    "numero no existe",
    "numero no corresponde"
  ],
  "sin_servicio": [
    "fuera de servicio",
    "sin servicio",
    "temporalmente fuera de servicio"
  ],
  "ocupado": [
    "linea ocupada",
    "ocupado"
  ]
}
JSON
fi

if [[ -f "$CONFIG_ENV" ]]; then
    # shellcheck disable=SC1090
    source "$CONFIG_ENV"
else
    ZYPAD_API_KEY="$(openssl rand -hex 24)"
    ZYPAD_ADMIN_USER="admin"
    ZYPAD_ADMIN_PASSWORD="$(openssl rand -hex 12)"
    ZYPAD_BIND_IP="${ZYPAD_BIND_IP:-$(detect_bind_ip)}"
    if [[ -z "$ZYPAD_BIND_IP" ]]; then
        ZYPAD_BIND_IP="0.0.0.0"
        echo "AVISO: no se detectó IP LAN privada; Zypad publicará el puerto $PORT en 0.0.0.0 (todas las interfaces, incluida la pública si el host tiene una). Revise firewall/seguridad." >&2
    fi
    ZYPAD_CONTROL_PORT="${ZYPAD_CONTROL_PORT:-$(choose_port 8850 8870)}"
    umask 077
    printf 'ZYPAD_API_KEY=%q\nZYPAD_ADMIN_USER=%q\nZYPAD_ADMIN_PASSWORD=%q\nZYPAD_BIND_IP=%q\nZYPAD_PORT=%q\nZYPAD_CONTROL_PORT=%q\n' \
        "$ZYPAD_API_KEY" "$ZYPAD_ADMIN_USER" "$ZYPAD_ADMIN_PASSWORD" "$ZYPAD_BIND_IP" "$PORT" "$ZYPAD_CONTROL_PORT" > "$CONFIG_ENV"
fi
# shellcheck disable=SC1090
source "$CONFIG_ENV"

if [[ ! -s "$CONTROL_TOKEN_FILE" ]]; then
    umask 077
    openssl rand -hex 32 > "$CONTROL_TOKEN_FILE"
fi
CONTROL_TOKEN="$(cat "$CONTROL_TOKEN_FILE")"
chown root:root "$CONTROL_TOKEN_FILE"
chmod 0400 "$CONTROL_TOKEN_FILE"

docker image inspect "$IMAGE" >/dev/null 2>&1 || docker pull "$IMAGE"

if ! docker inspect "$INSTANCE" >/dev/null 2>&1; then
    docker run -d --name "$INSTANCE" --restart unless-stopped \
        -p "${ZYPAD_BIND_IP}:${ZYPAD_PORT}:8767" \
        -e "VOSK_API_KEY=${ZYPAD_API_KEY}" \
        -e "ADMIN_USER=${ZYPAD_ADMIN_USER}" \
        -e "ADMIN_PASSWORD=${ZYPAD_ADMIN_PASSWORD}" \
        -e "MAX_AUDIO_BYTES=10485760" \
        -e "MAX_CONCURRENT=2" \
        -v "${DATA_DIR}:/app/data" \
        "$IMAGE" >/dev/null
fi

# Control-daemon: corre como root desde que systemd lo arranca (no escala
# privilegios en caliente), asi que ProtectSystem/nosuid no lo afecta. Expone
# start/stop/status del contenedor fijo por HTTP en 127.0.0.1.
cat > "$RUNTIME_DIR/control.py" <<'PY'
#!/usr/bin/env python3
"""Control-daemon de Zypad: start/stop/status del contenedor Docker fijo,
por HTTP en 127.0.0.1, autenticado con token. Corre como root via systemd
para poder llamar a `docker` sin sudo (ver installer/zypad.sh para el
porque: el hardening de Apache -ProtectSystem=full- aplica "nosuid" a /usr,
lo que inutiliza el bit setuid de /usr/bin/sudo para cualquier hijo de
Apache)."""
from __future__ import annotations

import argparse
import hmac
import json
import re
import subprocess
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

CONTAINER = ""
TOKEN = ""

STATS_RE = re.compile(
    r"^\S+\s+\S+\s+([\d.]+%)\s+(\S+\s*/\s*\S+)\s+([\d.]+%)\s+(\S+\s*/\s*\S+)\s+(\S+\s*/\s*\S+)\s+(\d+)\s*$"
)


def docker(*args: str) -> tuple[bool, str]:
    try:
        proc = subprocess.run(
            ["/usr/bin/docker", *args],
            capture_output=True, text=True, timeout=15,
        )
        return proc.returncode == 0, (proc.stdout + proc.stderr).strip()
    except Exception as exc:  # noqa: BLE001 - se reporta al panel, no se oculta
        return False, str(exc)


def status() -> dict:
    ok, out = docker("inspect", "-f", "{{.State.Running}}", CONTAINER)
    running = ok and out.strip() == "true"
    stats = None
    if running:
        sok, sout = docker("stats", "--no-stream", CONTAINER)
        if sok:
            lines = [line for line in sout.splitlines() if line.strip()]
            if lines:
                m = STATS_RE.match(lines[-1])
                if m:
                    stats = {
                        "cpu": m.group(1),
                        "mem": m.group(2),
                        "mem_perc": m.group(3),
                        "net": m.group(4),
                        "block": m.group(5),
                        "pids": m.group(6),
                    }
    return {"ok": True, "running": running, "stats": stats}


class Handler(BaseHTTPRequestHandler):
    def _authorized(self) -> bool:
        supplied = self.headers.get("X-Control-Token", "")
        return hmac.compare_digest(supplied, TOKEN)

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
            ok, out = docker(action, CONTAINER)
            self._json({"ok": ok, "detail": out})
        else:
            self._json({"ok": False, "error": "not found"}, 404)

    def log_message(self, fmt: str, *args) -> None:  # silencia access log a stderr
        return


def main() -> None:
    global CONTAINER, TOKEN
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, required=True)
    parser.add_argument("--container", required=True)
    parser.add_argument("--token-file", required=True)
    args = parser.parse_args()
    CONTAINER = args.container
    with open(args.token_file, encoding="utf-8") as fh:
        TOKEN = fh.read().strip()
    ThreadingHTTPServer(("127.0.0.1", args.port), Handler).serve_forever()


if __name__ == "__main__":
    main()
PY
chown root:root "$RUNTIME_DIR/control.py"
chmod 0500 "$RUNTIME_DIR/control.py"

cat > "/etc/systemd/system/$CONTROL_SERVICE" <<EOF
[Unit]
Description=Zynervox Zypad control daemon (start/stop/stats de Docker sin sudo)
After=docker.service network.target
Requires=docker.service

[Service]
Type=simple
ExecStart=$PYTHON $RUNTIME_DIR/control.py --port $ZYPAD_CONTROL_PORT --container $INSTANCE --token-file $CONTROL_TOKEN_FILE
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
# "restart" y no "enable --now": si el servicio ya estaba activo de una
# corrida anterior, "enable --now" no lo reinicia y se queda corriendo con
# el ExecStart viejo (puerto/python anteriores) aunque el .service en disco
# ya diga otra cosa -- bug real encontrado reinstalando en mirmidon.
systemctl enable "$CONTROL_SERVICE"
systemctl restart "$CONTROL_SERVICE"

for _ in $(seq 1 15); do
    curl -fsS -H "X-Control-Token: $CONTROL_TOKEN" "http://127.0.0.1:${ZYPAD_CONTROL_PORT}/status" >/dev/null 2>&1 && break
    sleep 1
done
curl -fsS -H "X-Control-Token: $CONTROL_TOKEN" "http://127.0.0.1:${ZYPAD_CONTROL_PORT}/status" >/dev/null

cat > "$CONFIG_PHP" <<EOF
<?php
return [
    'container' => '$INSTANCE',
    'bind_ip' => '$ZYPAD_BIND_IP',
    'port' => $ZYPAD_PORT,
    'api_key' => '$ZYPAD_API_KEY',
    'admin_user' => '$ZYPAD_ADMIN_USER',
    'admin_password' => '$ZYPAD_ADMIN_PASSWORD',
    'control_port' => $ZYPAD_CONTROL_PORT,
    'control_token' => '$CONTROL_TOKEN',
];
EOF
chown root:"$WEB_GROUP" "$CONFIG_PHP"
chmod 0640 "$CONFIG_PHP"

echo "ZYPAD_READY instance=$INSTANCE bind=${ZYPAD_BIND_IP}:${ZYPAD_PORT} control_port=$ZYPAD_CONTROL_PORT image=$IMAGE"
