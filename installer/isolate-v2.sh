#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[[ $EUID -eq 0 ]] || exit 1
source "$ROOT/installer/platform.sh"
platform_defaults zynervoxv2
source "$ROOT/installer/isolated-env.sh"
export ZYNERVOX_CORE_CONFIG_FILE="$ZYNERVOX_CONFIG_DIR/zynervox-core.conf"
export ZYNERVOX_BOT_IVR_CONFIG_FILE="$ZYNERVOX_CONFIG_DIR/zynervoxv2205-bot_ivr.conf"
[[ "$ZYNERVOX_CONFIG_DIR" != /etc/zynervox && "$BOT_IVR_DB_USER" != zynervox_bot_ivr && "$ZYNERVOX_CORE_DB_USER" != zynervox_core && "$ASTERISK_ROOT" != /etc/asterisk/synervox ]] || exit 2
printf '[%s] Preparando v2 aislado; no se copian datos productivos\n' "$(date +%T)"
bash "$ROOT/installer/zynervox-core.sh"
bash "$ROOT/installer/ivr-builder-bot-db.sh"
bash "$ROOT/installer/deploy-web.sh" "$ROOT/app/web" "$WEB_ROOT"
chown -R root:"$WEB_GROUP" "$WEB_ROOT"
find "$WEB_ROOT" -path "$WEB_ROOT/venvs" -prune -o -type d -exec chmod 0750 {} +
find "$WEB_ROOT" -path "$WEB_ROOT/venvs" -prune -o -type f -exec chmod 0640 {} +
install -d -o root -g "$WEB_GROUP" -m 0770 "$WEB_ROOT/runtime"
bash "$ROOT/installer/deployment-marker.sh"
echo 'V2_ISOLATED_READY: login, campañas e IVR separados; marcacion legacy bloqueada'
