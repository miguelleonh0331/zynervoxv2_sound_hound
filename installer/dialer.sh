#!/usr/bin/env bash
# Instala y HABILITA el motor de marcacion (zynervox-dialer.service). NO lo arranca, ni lo reinicia, ni lo para:
# reiniciar el motor con llamadas abiertas las afecta, y arrancarlo es una decision aparte (systemctl start zynervox-dialer).
# La unidad del repo se genera con el usuario, el grupo y la ruta web de este servidor.
# Uso (root):  dialer.sh            instala la unidad y la habilita
#              dialer.sh --check    solo comprueba; no cambia nada (sale 1 si no esta instalada y habilitada)
# Variables:   WEB_ROOT (por defecto /var/www/html/zynervoxv2), WEB_GROUP (por defecto el del sistema, apache en Rocky),
#              WEB_USER (por defecto igual que WEB_GROUP)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$ROOT/installer/platform.sh"
platform_defaults zynervoxv2
WEB_USER="${WEB_USER:-$WEB_GROUP}"
[[ "$WEB_ROOT" =~ ^/[a-zA-Z0-9_./-]+$ && "$WEB_USER" =~ ^[a-z_][a-z0-9_-]*$ && "$WEB_GROUP" =~ ^[a-z_][a-z0-9_-]*$ ]] || { echo 'WEB_ROOT, WEB_USER o WEB_GROUP invalidos' >&2; exit 2; }
unit_source="$ROOT/asterisk/synervox/modules/bot_ivr/zynervox-dialer.service"
unit_target=/etc/systemd/system/zynervox-dialer.service
check=0
case "${1:-}" in
  '') ;;
  --check) check=1 ;;
  *) echo 'Uso: dialer.sh [--check]' >&2; exit 2 ;;
esac
[[ -f "$unit_source" ]] || { echo "Falta la unidad: $unit_source" >&2; exit 1; }

# Unidad generada: solo cambian User=, Group= y la ruta de campaign_engine.php; el resto es la del repo.
unit_rendered="$(mktemp)"
trap 'rm -f "$unit_rendered"' EXIT
sed -e "s|^User=.*|User=$WEB_USER|" -e "s|^Group=.*|Group=$WEB_GROUP|" \
    -e "s|^\(ExecStart=/usr/bin/php \).*|\1$WEB_ROOT/bot_ivr/campaign_engine.php|" "$unit_source" > "$unit_rendered"
grep -q "^ExecStart=/usr/bin/php $WEB_ROOT/bot_ivr/campaign_engine.php$" "$unit_rendered" || { echo 'No se pudo generar la unidad (ExecStart)' >&2; exit 1; }

# Requisitos para poder ARRANCAR (no para instalar): se avisan, no bloquean.
problems=0
getent passwd "$WEB_USER" >/dev/null || { echo "FALTA el usuario del motor: $WEB_USER" >&2; problems=1; }
getent group "$WEB_GROUP" >/dev/null || { echo "FALTA el grupo del motor: $WEB_GROUP" >&2; problems=1; }
command -v php >/dev/null || { echo 'FALTA php' >&2; problems=1; }
[[ -f "$WEB_ROOT/bot_ivr/campaign_engine.php" ]] || echo "AVISO: aun no existe $WEB_ROOT/bot_ivr/campaign_engine.php (publicar la web antes de arrancar el motor)" >&2
[[ -d /var/lib/zynervoxv2/asterisk/recordings/marcador ]] || echo 'AVISO: falta /var/lib/zynervoxv2/asterisk/recordings/marcador (lo crea list-call-worker.sh)' >&2

state_installed=no; state_enabled=no
[[ -f "$unit_target" ]] && cmp -s "$unit_rendered" "$unit_target" && state_installed=yes
[[ "$(systemctl is-enabled zynervox-dialer.service 2>/dev/null || true)" == enabled ]] && state_enabled=yes
echo "DIALER unidad_instalada=$state_installed habilitada=$state_enabled activa=$(systemctl is-active zynervox-dialer.service 2>/dev/null || true) usuario=$WEB_USER grupo=$WEB_GROUP web=$WEB_ROOT"
if [[ $check -eq 1 ]]; then
  [[ $problems -eq 0 && "$state_installed" == yes && "$state_enabled" == yes ]]
  exit $?
fi

[[ $EUID -eq 0 ]] || { echo 'Ejecutar como root' >&2; exit 1; }
[[ $problems -eq 0 ]] || exit 1
if [[ "$state_installed" == no ]]; then
  install -m 0644 -o root -g root "$unit_rendered" "$unit_target"
  command -v restorecon >/dev/null && restorecon -F "$unit_target"
  systemctl daemon-reload
fi
[[ "$state_enabled" == yes ]] || systemctl enable zynervox-dialer.service
echo 'DIALER_UNIT_READY (habilitada, sin arrancar)'
echo "Comprobar sin arrancar: cd $WEB_ROOT/bot_ivr && sudo -u $WEB_USER php campaign_engine.php --dry-run"
