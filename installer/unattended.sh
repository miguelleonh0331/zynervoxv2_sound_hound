#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
trap 'install_status=$?; printf "ERROR instalador desatendido: linea=%s codigo=%s\n" "$LINENO" "$install_status" >&2; exit "$install_status"' ERR
printf '[%s] Iniciando instalador desatendido\n' "$(date +%T)"
update_source=0
module_only=0
install_args=(--skip-packages)
for argument in "$@"; do
  case "$argument" in
    --module|--module=*) module_only=1; install_args+=("$argument") ;;
    --update-source) update_source=1 ;;
    --fresh) echo "Reinstalacion destructiva no soportada; se conservan datos y configuracion" >&2; exit 2 ;;
    --help) echo "Uso: unattended.sh [--update-source] [--module carriers] [opciones de install.sh]"; exit 0 ;;
    *) install_args+=("$argument") ;;
  esac
done
if [[ "$update_source" == 1 ]]; then
  [[ "$(git -C "$ROOT" branch --show-current)" == main ]] || { echo "La fuente debe estar en main" >&2; exit 1; }
  [[ -z "$(git -C "$ROOT" status --porcelain)" ]] || { echo "Fuente con cambios pendientes; no se actualiza" >&2; exit 1; }
  git -C "$ROOT" fetch origin main
  git -C "$ROOT" merge --ff-only origin/main
  exec bash "$ROOT/installer/unattended.sh" "${install_args[@]}"
fi
source "$ROOT/installer/platform.sh"
platform_defaults zynervoxv2
source "$ROOT/installer/isolated-env.sh"
printf '[%s] Plataforma=%s destino=%s URL=%s\n' "$(date +%T)" "${ID:-unknown}" "$WEB_ROOT" "$URL_PATH"
export ASTERISK_ROOT="${ASTERISK_ROOT:-/etc/asterisk/synervox}"
export WHATSAPP_INSTANCE="${WHATSAPP_INSTANCE:-zynervoxv2-whatsapp}"
export FARM_INSTANCE="${FARM_INSTANCE:-zynervoxv2-farm}"
export STT_INSTANCE="${STT_INSTANCE:-zynervoxv2-stt}"
export ZYNERDESK_INSTANCE="${ZYNERDESK_INSTANCE:-zynervoxv2-zynerdesk}"
export WHATSAPP_BASE_PATH_OVERRIDE="${WHATSAPP_BASE_PATH_OVERRIDE:-/zynerwabav2}"
export ZYNERDESK_BASE_PATH_OVERRIDE="${ZYNERDESK_BASE_PATH_OVERRIDE:-/zynerdesk}"
if [[ "$module_only" == 0 && ! " ${install_args[*]} " =~ " --dry-run " ]]; then
  printf '[%s] Preparando credenciales privadas\n' "$(date +%T)"
  source "$ROOT/installer/load-db-password.sh"
fi
bash "$ROOT/installer/install.sh" "${install_args[@]}"
