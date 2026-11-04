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

# Estado: logs con transcripciones, solo el usuario del servicio.
install -d -o "$service_user" -g "$service_user" -m 0700 "$state" "$state/logs"
restorecon -RF "$state"

# Retencion de logs: 14 dias.
printf 'e %s/logs - - - 14d\n' "$state" > /etc/tmpfiles.d/zypad-bridge.conf
chmod 0644 /etc/tmpfiles.d/zypad-bridge.conf

install -m 0644 -o root -g root "$source_root/src/features/zypad/zypad-bridge.service" /etc/systemd/system/zypad-bridge.service
# Catalogo real: programa de MarcadorBuilder (lee zynervox_bot_zypad_words); el servicio guarda su copia en catalog.json.
install -d -m 0755 /etc/systemd/system/zypad-bridge.service.d
printf '[Service]\nEnvironment="CATALOG_CMD=sudo -n -u apache php /var/www/html/zynervoxv2/marcador/zypad_catalog.php"\n' > /etc/systemd/system/zypad-bridge.service.d/20-catalog.conf
restorecon -RF /etc/systemd/system/zypad-bridge.service /etc/systemd/system/zypad-bridge.service.d
systemctl daemon-reload
systemctl enable zypad-bridge.service
echo ZYPAD_BRIDGE_INSTALLED
