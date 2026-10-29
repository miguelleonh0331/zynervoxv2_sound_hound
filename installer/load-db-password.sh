#!/usr/bin/env bash
DB_PASSWORD_FILE="${ZYNERVOX_DB_PASSWORD_FILE:-/root/.zynervox-db-password}"
if [[ -L "$DB_PASSWORD_FILE" || ( -e "$DB_PASSWORD_FILE" && ! -f "$DB_PASSWORD_FILE" ) ]]; then
  echo "Archivo privado de clave MySQL invalido" >&2
  return 1
fi
if [[ -f "$DB_PASSWORD_FILE" ]]; then
  [[ "$(stat -c '%u:%a' -- "$DB_PASSWORD_FILE")" == 0:600 ]] || return 1
  ZYNERVOX_DB_PASSWORD="$(<"$DB_PASSWORD_FILE")"
else
  ZYNERVOX_DB_PASSWORD="${ZYNERVOX_DB_PASSWORD:-$(openssl rand -hex 24)}"
  [[ -n "$ZYNERVOX_DB_PASSWORD" && "$ZYNERVOX_DB_PASSWORD" != *$'\n'* ]] || return 1
  install -d -m 0700 "$(dirname "$DB_PASSWORD_FILE")"
  (umask 077; printf '%s\n' "$ZYNERVOX_DB_PASSWORD" > "$DB_PASSWORD_FILE")
  chown root:root "$DB_PASSWORD_FILE"
  chmod 0600 "$DB_PASSWORD_FILE"
fi
[[ -n "$ZYNERVOX_DB_PASSWORD" ]] || return 1
export ZYNERVOX_DB_PASSWORD
