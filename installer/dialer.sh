#!/usr/bin/env bash
# Instala y HABILITA el motor de marcacion (zynervox-dialer.service). NO lo arranca, ni lo reinicia, ni lo para:
# reiniciar el motor con llamadas abiertas las afecta, y arrancarlo es una decision aparte (systemctl start zynervox-dialer).
# Uso (root):  dialer.sh            instala la unidad y la habilita
#              dialer.sh --check    solo comprueba; no cambia nada (sale 1 si no esta instalada y habilitada)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
unit_source="$ROOT/asterisk/synervox/modules/bot_ivr/zynervox-dialer.service"
unit_target=/etc/systemd/system/zynervox-dialer.service
check=0
case "${1:-}" in
  '') ;;
  --check) check=1 ;;
  *) echo 'Uso: dialer.sh [--check]' >&2; exit 2 ;;
esac
[[ -f "$unit_source" ]] || { echo "Falta la unidad: $unit_source" >&2; exit 1; }

# La unidad fija usuario y ruta de la web; se comprueban antes de instalar.
unit_user="$(sed -n 's/^User=//p' "$unit_source" | head -1)"
unit_script="$(sed -n 's/^ExecStart=\/usr\/bin\/php //p' "$unit_source" | head -1)"
problems=0
getent passwd "$unit_user" >/dev/null || { echo "FALTA el usuario de la unidad: $unit_user" >&2; problems=1; }
command -v php >/dev/null || { echo 'FALTA php' >&2; problems=1; }
[[ -f "$unit_script" ]] || { echo "AVISO: aun no existe $unit_script (publicar la web antes de arrancar el motor)" >&2; }

state_installed=no; state_enabled=no
[[ -f "$unit_target" ]] && cmp -s "$unit_source" "$unit_target" && state_installed=yes
[[ "$(systemctl is-enabled zynervox-dialer.service 2>/dev/null || true)" == enabled ]] && state_enabled=yes
echo "DIALER unidad_instalada=$state_installed habilitada=$state_enabled activa=$(systemctl is-active zynervox-dialer.service 2>/dev/null || true)"
if [[ $check -eq 1 ]]; then
  [[ $problems -eq 0 && "$state_installed" == yes && "$state_enabled" == yes ]]
  exit $?
fi

[[ $EUID -eq 0 ]] || { echo 'Ejecutar como root' >&2; exit 1; }
[[ $problems -eq 0 ]] || exit 1
if [[ "$state_installed" == no ]]; then
  install -m 0644 -o root -g root "$unit_source" "$unit_target"
  command -v restorecon >/dev/null && restorecon -F "$unit_target"
  systemctl daemon-reload
fi
[[ "$state_enabled" == yes ]] || systemctl enable zynervox-dialer.service
echo 'DIALER_UNIT_READY (habilitada, sin arrancar)'
