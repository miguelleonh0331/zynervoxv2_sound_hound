#!/usr/bin/env bash
set -euo pipefail

REPOSITORY_URL="${ZYNERVOX_REPOSITORY_URL:-https://github.com/miguelleonh0331/zynervoxv2.git}"
REF="${ZYNERVOX_REF:-main}"
SOURCE_DIR="${ZYNERVOX_SOURCE_DIR:-/opt/zynervoxv2-deploy-test-source}"
WEB_ROOT="${WEB_ROOT:-/var/www/html/zynervoxv2-deploy-test}"
URL_PATH="${URL_PATH:-/zynervoxv2-deploy-test}"
ASTERISK_ROOT="${ASTERISK_ROOT:-/etc/asterisk/synervox-deploy-test}"
RESUME="${ZYNERVOX_RESUME:-0}"

[[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
command -v git >/dev/null 2>&1 || { echo "Falta git" >&2; exit 1; }
if [[ -e "$SOURCE_DIR" || -e "$WEB_ROOT" ]]; then
  [[ "$RESUME" == 1 ]] || { echo "La instalación ya existe; use ZYNERVOX_RESUME=1 para reanudar" >&2; exit 1; }
  [[ -d "$SOURCE_DIR/.git" ]] || { echo "La ruta fuente no es un clon Git válido: $SOURCE_DIR" >&2; exit 1; }
  git -C "$SOURCE_DIR" fetch origin --tags
  if git -C "$SOURCE_DIR" rev-parse -q --verify "refs/tags/$REF" >/dev/null; then
    git -C "$SOURCE_DIR" checkout --detach "refs/tags/$REF"
  else
    git -C "$SOURCE_DIR" fetch origin "$REF:refs/remotes/origin/$REF"
    git -C "$SOURCE_DIR" checkout -B "$REF" "origin/$REF"
  fi
else
  git clone --depth 1 --branch "$REF" "$REPOSITORY_URL" "$SOURCE_DIR"
fi

export WEB_ROOT URL_PATH ASTERISK_ROOT
export WHATSAPP_BASE_PATH_OVERRIDE="${WHATSAPP_BASE_PATH_OVERRIDE:-${URL_PATH}-whatsapp}"
export WHATSAPP_COMPOSE_PROJECT_OVERRIDE="${WHATSAPP_COMPOSE_PROJECT_OVERRIDE:-zynervoxv2-deploy-test}"
export FARM_INSTANCE="${FARM_INSTANCE_OVERRIDE:-zynervoxv2-deploy-test-farm}"
export STT_INSTANCE="${STT_INSTANCE_OVERRIDE:-zynervoxv2-deploy-test-stt}"
export ZYNERDESK_BASE_PATH_OVERRIDE="${ZYNERDESK_BASE_PATH_OVERRIDE:-${URL_PATH}-zynerdesk}"
export ZYNERDESK_COMPOSE_PROJECT_OVERRIDE="${ZYNERDESK_COMPOSE_PROJECT_OVERRIDE:-zynervoxv2-deploy-test-zynerdesk}"

exec "$SOURCE_DIR/installer/install.sh" --skip-packages --install-docker --with-whatsapp --with-farm --with-stt-providers --with-zynerdesk
