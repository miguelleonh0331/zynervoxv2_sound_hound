#!/usr/bin/env bash
export ZYNERVOX_ISOLATED=1
export ZYNERVOX_CONFIG_DIR="${ZYNERVOX_CONFIG_DIR:-/etc/zynervox/zynervoxv2}"
export ZYNERVOX_CORE_DB="${ZYNERVOX_CORE_DB:-zynervox_core}"
export ZYNERVOX_CORE_DB_USER="${ZYNERVOX_CORE_DB_USER:-zynervoxv2_core}"
export ZYNERVOX_CORE_USERS_TABLE=v2_zynervox_users
export ZYNERVOX_CORE_CONFIG_TABLE=v2_ivr_deploy_config
export BOT_IVR_DB_USER="${BOT_IVR_DB_USER:-zynervoxv2_bot_ivr}"
bot_password_file="$ZYNERVOX_CONFIG_DIR/bot-password"
if [[ -z "${BOT_IVR_DB_PASSWORD:-}" && -f "$bot_password_file" ]]; then
  [[ ! -L "$bot_password_file" && "$(stat -c '%u:%a' "$bot_password_file")" == 0:600 ]] || { echo 'Archivo privado Bot inseguro' >&2; return 1; }
  export BOT_IVR_DB_PASSWORD="$(<"$bot_password_file")"
fi
export ZYNERVOX_DB_PASSWORD_FILE="${ZYNERVOX_DB_PASSWORD_FILE:-$ZYNERVOX_CONFIG_DIR/core-password}"
export ZYNERVOX_CORE_ADMIN_PASSWORD="${ZYNERVOX_CORE_ADMIN_PASSWORD:-${BOT_IVR_DB_PASSWORD:-}}"
export BOT_IVR_LEGACY_CONFIG="$ZYNERVOX_CONFIG_DIR/no-legacy.json"
export ASTERISK_ROOT="${ASTERISK_ROOT:-/var/lib/zynervoxv2/asterisk}"
