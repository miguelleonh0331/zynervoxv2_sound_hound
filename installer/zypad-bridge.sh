#!/usr/bin/env bash
# Instala el servicio zypad-bridge (puente AudioSocket de Asterisk hacia zypad). No lo arranca.
# Uso (root): zypad-bridge.sh <raiz de la fuente> <fichero con ZYPAD_URL y ZYPAD_API_KEY>
set -euo pipefail
source_root="${1:?source root required}"
secret_file="${2:?fichero con ZYPAD_URL y ZYPAD_API_KEY required}"
service_user="${ZYPAD_SERVICE_USER:-codex-web}"
state=/var/lib/zypad-bridge
[[ $EUID -eq 0 ]] || { echo 'Requiere root' >&2; exit 2; }

# Clave: dueño root, grupo del servicio, 640. No se imprime.
install -d -o root -g "$service_user" -m 0750 /etc/zypad
install -o root -g "$service_user" -m 0640 "$secret_file" /etc/zypad/bridge.env

# Estado: el padre es traversable (0711) para que asterisk llegue a result/; los logs llevan transcripciones y quedan 0700.
install -d -o "$service_user" -g "$service_user" -m 0711 "$state"
install -d -o "$service_user" -g "$service_user" -m 0700 "$state/logs"
install -d -o "$service_user" -g "$service_user" -m 2770 "$state/result"
if id asterisk >/dev/null 2>&1; then
  setfacl -m u:asterisk:rwx,d:u:asterisk:rwX "$state/result"
fi
# asterisk_t solo conoce zynervox_runtime_t; result/ es lo unico que lee el dialplan.
semanage fcontext -a -t zynervox_runtime_t "$state/result(/.*)?" 2>/dev/null || semanage fcontext -m -t zynervox_runtime_t "$state/result(/.*)?"
restorecon -RF "$state"

# Retencion de logs: 14 dias.
printf 'e %s/logs - - - 14d\n' "$state" > /etc/tmpfiles.d/zypad-bridge.conf
chmod 0644 /etc/tmpfiles.d/zypad-bridge.conf

install -m 0644 -o root -g root "$source_root/src/features/zypad/zypad-bridge.service" /etc/systemd/system/zypad-bridge.service
install -d -m 0755 /etc/systemd/system/zypad-bridge.service.d
printf '[Service]\nStateDirectoryMode=0711\n' > /etc/systemd/system/zypad-bridge.service.d/10-installer.conf
restorecon -RF /etc/systemd/system/zypad-bridge.service /etc/systemd/system/zypad-bridge.service.d
systemctl daemon-reload
systemctl enable zypad-bridge.service
echo ZYPAD_BRIDGE_INSTALLED
