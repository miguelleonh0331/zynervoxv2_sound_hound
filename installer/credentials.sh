#!/usr/bin/env bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
config_directory="${ZYNERVOX_CONFIG_DIR:-/etc/zynervox/zynervoxv2}"
config_field() {
  awk -v key="$2" '$1 == key && $2 == "=>" {sub(/^[^=]*=>[[:space:]]*/, ""); print; exit}' "$1"
}
echo
echo '-- Acceso administrador Zynervox --'
admin_file="$config_directory/zynervox-core-admin.env"
if [[ -f "$admin_file" && ! -L "$admin_file" && "$(stat -c '%u:%a' "$admin_file")" == 0:600 ]]; then
  unset ZYNERVOX_CORE_ADMIN_PASSWORD ZYNERVOX_CORE_ADMIN_USER
  source "$admin_file"
  printf '  Usuario inicial: %s\n' "${ZYNERVOX_CORE_ADMIN_USER:-admin}"
  printf '  Contrasena inicial guardada: %s\n' "${ZYNERVOX_CORE_ADMIN_PASSWORD:-no disponible}"
  echo '  Si cambio el acceso posteriormente, estos valores iniciales pueden no estar vigentes.'
else
  echo '  Credenciales iniciales no disponibles o archivo privado inseguro.'
fi
for database_type in core bot_ivr; do
  case "$database_type" in
    core) database_file="$config_directory/zynervox-core.conf"; prefix=CORE_DB ;;
    bot_ivr) database_file="$config_directory/zynervoxv2205-bot_ivr.conf"; prefix=BOT_IVR_DB ;;
  esac
  echo
  printf -- '-- Base de datos bootstrap: %s --\n' "$database_type"
  if [[ -f "$database_file" ]]; then
    printf '  Host: %s\n  Puerto: %s\n  Base: %s\n  Usuario: %s\n  Contrasena: %s\n  Archivo: %s\n' \
      "$(config_field "$database_file" "${prefix}_server")" "$(config_field "$database_file" "${prefix}_port")" \
      "$(config_field "$database_file" "${prefix}_database")" "$(config_field "$database_file" "${prefix}_user")" \
      "$(config_field "$database_file" "${prefix}_pass")" "$database_file"
  else
    echo '  Configuracion no disponible.'
  fi
done
echo '  Servicios > Base de datos puede contener una conexion distinta del bootstrap.'
echo
echo '-- IVR Builder: token del receptor Asterisk --'
receiver_file="$config_directory/zynervoxv2205-ivr-receiver.conf"
if [[ -f "$receiver_file" ]]; then
  printf '  Token: %s\n  Archivo: %s\n' "$(config_field "$receiver_file" RECEIVER_TOKEN)" "$receiver_file"
  printf '  URL inicial propuesta: %s\n' "${ZYNERVOX_IVR_RECEIVER_URL:-http://127.0.0.1${URL_PATH:-/zynervoxv2}/ivr_builder}"
  echo '  El instalador prellena URL/token solo si ambos campos centrales estan vacios.'
  echo '  Verifique la configuracion vigente en el engranaje del IVR Builder.'
else
  echo '  Token local no disponible.'
fi
echo '  Salida sensible: no compartir ni guardar en logs publicos.'
