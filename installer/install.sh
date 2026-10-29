#!/usr/bin/env bash
set -euo pipefail
trap 'install_status=$?; printf "ERROR instalacion: linea=%s codigo=%s\n" "$LINENO" "$install_status" >&2; exit "$install_status"' ERR

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$ROOT/installer/platform.sh"
platform_defaults zynervox
if [[ "$WEB_ROOT" == */zynervoxv2 && "${ZYNERVOX_ISOLATED:-0}" != 1 ]]; then source "$ROOT/installer/isolated-env.sh"; fi
ASTERISK_ROOT="${ASTERISK_ROOT:-/etc/asterisk/synervox}"
URL_PATH="${URL_PATH:-/zynervox}"
APPLY_MIGRATIONS=0
DRY_RUN=0
SKIP_PACKAGES=0
WITH_WHATSAPP=0
WITH_FARM=0
WITH_STT_PROVIDERS=0
WITH_ZYNERDESK=0
WITH_ZYPAD=0
INSTALL_DOCKER=0
SELECTED_MODULE=''
expect_module=0
FARM_INSTANCE="${FARM_INSTANCE:-zynervox-farm}"
STT_INSTANCE="${STT_INSTANCE:-zynervox-stt}"

for arg in "$@"; do
  if [[ "$expect_module" == 1 ]]; then [[ -n "$arg" ]] || exit 2; SELECTED_MODULE="$arg"; expect_module=0; continue; fi
  case "$arg" in
    --module) expect_module=1 ;;
    --module=*) SELECTED_MODULE="${arg#--module=}"; [[ -n "$SELECTED_MODULE" ]] || exit 2 ;;
    --apply-migrations) APPLY_MIGRATIONS=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --skip-packages) SKIP_PACKAGES=1 ;;
    --with-whatsapp) WITH_WHATSAPP=1 ;;
    --with-farm) WITH_FARM=1 ;;
    --with-stt-providers) WITH_STT_PROVIDERS=1 ;;
    --with-zynerdesk) WITH_ZYNERDESK=1 ;;
    --with-zypad) WITH_ZYPAD=1 ;;
    --install-docker) INSTALL_DOCKER=1 ;;
    *) echo "Argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done
[[ "$expect_module" == 0 ]] || { echo 'Falta nombre de modulo' >&2; exit 2; }
if [[ -n "$SELECTED_MODULE" ]]; then
  [[ "$SELECTED_MODULE" == carriers && "${ZYNERVOX_ISOLATED:-0}" == 1 ]] || { echo 'Modulo selectivo no soportado; disponible: carriers (v2 aislado)' >&2; exit 2; }
  [[ "$APPLY_MIGRATIONS$WITH_WHATSAPP$WITH_FARM$WITH_STT_PROVIDERS$WITH_ZYNERDESK$WITH_ZYPAD$INSTALL_DOCKER" == 0000000 ]] || { echo 'No mezclar --module con instalacion completa/opcionales' >&2; exit 2; }
  if [[ "$DRY_RUN" == 1 ]]; then echo "DRY_RUN module=carriers web=$WEB_ROOT database=zynervox_core no_full_install=1"; exit 0; fi
  exec bash "$ROOT/installer/carriers.sh"
fi

[[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
WEB_GROUP="${WEB_GROUP:-www-data}"
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=www
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=root
DB_CONFIG=/etc/astguiclient.conf
[[ -f /etc/zynervox/astguiclient.conf ]] && DB_CONFIG=/etc/zynervox/astguiclient.conf

if [[ $DRY_RUN -eq 1 ]]; then
  [[ -f "$DB_CONFIG" ]] && integration=available || integration=partial
  echo "DRY_RUN web=$WEB_ROOT asterisk=$ASTERISK_ROOT url=$URL_PATH migrations=$APPLY_MIGRATIONS integration=$integration skip_packages=$SKIP_PACKAGES whatsapp=$WITH_WHATSAPP farm=$WITH_FARM stt_providers=$WITH_STT_PROVIDERS zynerdesk=$WITH_ZYNERDESK zypad=$WITH_ZYPAD install_docker=$INSTALL_DOCKER os=${ID:-unknown} web_group=$WEB_GROUP"
  exit 0
fi
if [[ "${ZYNERVOX_ISOLATED:-0}" == 1 && ( "$APPLY_MIGRATIONS" == 1 || "$WITH_FARM" == 1 || "$WITH_WHATSAPP" == 1 || "$WITH_ZYNERDESK" == 1 || "$WITH_ZYPAD" == 1 || "$WITH_STT_PROVIDERS" == 1 ) ]]; then
  echo "Modulos productivos/migraciones deshabilitados en despliegue v2 aislado" >&2
  exit 2
fi

# La web siempre se instala antes de comprobar integraciones opcionales.
bash "$ROOT/installer/deploy-web.sh" "$ROOT/app/web" "$WEB_ROOT"
printf '[%s] Aplicando permisos web\n' "$(date +%T)"
chown -R root:"$WEB_GROUP" "$WEB_ROOT"
find "$WEB_ROOT" -type d -exec chmod 0750 {} +
find "$WEB_ROOT" -type f -exec chmod 0640 {} +
echo "WEB_INSTALLED root=$WEB_ROOT"
if [[ "${ZYNERVOX_ISOLATED:-0}" == 1 ]]; then
  bash "$ROOT/installer/deployment-marker.sh"
  bash "$ROOT/installer/isolated-apache.sh"
fi

# runtime/ es la UNICA carpeta bajo el webroot donde los procesos PHP
# (www-data) necesitan poder escribir en caliente (ej. DevChecklist.php,
# ServerInfo.php: estado/notas persistidas en disco, no en BD). El resto del
# webroot queda 0750/0640 (el grupo solo puede leer) a propósito; esta es la
# excepción explícita y documentada, en vez de un `chmod` manual hecho a
# mano en cada servidor (eso es justo lo que este bloque reemplaza: antes
# había que crearla por fuera del instalador, server por server).
install -d -o root -g "$WEB_GROUP" -m 0770 "$WEB_ROOT/runtime"
echo "RUNTIME_DIR_READY path=$WEB_ROOT/runtime"

# Login administrativo y esquemas propios, sin modificar datos VICIdial.
printf '[%s] Configurando base Core\n' "$(date +%T)"
bash "$ROOT/installer/zynervox-core.sh"
printf '[%s] Configurando base IVR/Bot\n' "$(date +%T)"
bash "$ROOT/installer/ivr-builder-bot-db.sh"
if [[ "${ZYNERVOX_ISOLATED:-0}" == 1 ]]; then bash "$ROOT/installer/carriers.sh"; fi

if [[ $SKIP_PACKAGES -eq 0 ]]; then
  case "${ID:-}" in
    ubuntu|debian)
      export DEBIAN_FRONTEND=noninteractive
      apt-get update && apt-get install -y apache2 php libapache2-mod-php php-curl php-mbstring php-mysql \
        php-xml php-zip python3 python3-pymysql python3-num2words || \
        echo "AVISO: archivos web instalados; no se pudieron completar dependencias" >&2
      ;;
    *) echo "Sistema ${ID:-desconocido}: use --skip-packages o instale dependencias manualmente" >&2; exit 1 ;;
  esac
else
  echo "AVISO: instalación de paquetes omitida por --skip-packages" >&2
fi

if [[ $INSTALL_DOCKER -eq 1 ]]; then
  if ! command -v docker >/dev/null 2>&1; then
    case "${ID:-}" in
      ubuntu|debian)
        export DEBIAN_FRONTEND=noninteractive
        apt-get update && apt-get install -y docker.io docker-compose-v2
        ;;
      opensuse*|sles)
        if ! zypper --non-interactive --no-refresh install --no-recommends docker docker-compose; then
          command -v docker >/dev/null 2>&1 && command -v docker-compose >/dev/null 2>&1 || exit 1
          echo "AVISO: zypper informó repositorios inválidos, pero Docker y Compose quedaron instalados" >&2
        fi
        ;;
      *) echo "Instalación automática de Docker no soportada en ${ID:-desconocido}" >&2; exit 1 ;;
    esac
  fi
  if [[ "${ID:-}" == opensuse* || "${ID:-}" == sles ]]; then
    runc_version=1.5.2
    runc_sha256=599f6f94ff8c5057241eff0d54c3c74f95c34935b6457b33fe545defc61e9488
    runc_path=/usr/local/sbin/zynervox-runc
    if [[ ! -x "$runc_path" ]] || [[ "$(sha256sum "$runc_path" | awk '{print $1}')" != "$runc_sha256" ]]; then
      runc_temp="$(mktemp)"
      trap 'rm -f "$runc_temp"' EXIT
      curl -fsSL "https://github.com/opencontainers/runc/releases/download/v${runc_version}/runc.amd64" -o "$runc_temp"
      printf '%s  %s\n' "$runc_sha256" "$runc_temp" | sha256sum -c -
      install -o root -g root -m 0755 "$runc_temp" "$runc_path"
      rm -f "$runc_temp"
      trap - EXIT
    fi
    "$runc_path" --version >/dev/null 2>&1 && "$runc_path" features >/dev/null 2>&1 || {
      echo "Runtime OCI runc incompatible con el sistema" >&2; exit 1;
    }
    install -d -o root -g root -m 0755 /etc/docker
    [[ ! -f /etc/docker/daemon.json || -f /etc/docker/daemon.json.pre-zynervox ]] || \
      cp -a /etc/docker/daemon.json /etc/docker/daemon.json.pre-zynervox
    python3 - /etc/docker/daemon.json <<'PY'
import json
import os
import sys

path = sys.argv[1]
config = {}
if os.path.exists(path):
    with open(path, encoding="utf-8") as handle:
        config = json.load(handle)
    config.setdefault("runtimes", {})["vicibox-runc"] = {"path": "/usr/local/sbin/zynervox-runc"}
config["default-runtime"] = "vicibox-runc"
temporary = path + ".zynervox.tmp"
with open(temporary, "w", encoding="utf-8") as handle:
    json.dump(config, handle, indent=2, sort_keys=True)
    handle.write("\n")
os.replace(temporary, path)
PY
  fi
  systemctl enable --now docker
  systemctl restart docker
fi

if [[ "${ZYNERVOX_ISOLATED:-0}" != 1 && -f "$DB_CONFIG" ]] && command -v asterisk >/dev/null 2>&1 && command -v mysql >/dev/null 2>&1; then
  install -d -o root -g "$WEB_GROUP" -m 0750 "$ASTERISK_ROOT"
  cp -a "$ROOT/asterisk/synervox/." "$ASTERISK_ROOT/"
  chown -R root:"$WEB_GROUP" "$ASTERISK_ROOT"
  find "$ASTERISK_ROOT" -type d -exec chmod 0750 {} +
  find "$ASTERISK_ROOT" -type f -exec chmod 0640 {} +
  find "$ASTERISK_ROOT" -type f -name '*.py' -exec chmod 0750 {} +
else
  echo "AVISO: VICIdial/Asterisk no disponible; se omite integración telefónica" >&2
fi

# Bajo DocumentRoot no hace falta crear ni habilitar un Alias.
if [[ "$WEB_ROOT" != "${WEB_DOCUMENT_ROOT:-/var/www/html}${URL_PATH}" ]] && command -v a2enconf >/dev/null 2>&1; then
  apache_conf=/etc/apache2/conf-available/zynervox.conf
  sed -e "s|__URL_PATH__|$URL_PATH|g" -e "s|__WEB_ROOT__|$WEB_ROOT|g" \
    "$ROOT/installer/apache-zynervox.conf.template" > "$apache_conf"
  a2enconf zynervox >/dev/null
fi

if [[ $APPLY_MIGRATIONS -eq 1 ]]; then
  if [[ -f "$DB_CONFIG" ]]; then
    php "$ROOT/installer/migrate.php" "$WEB_ROOT" "$ASTERISK_ROOT/modules/migrations"
  else
    echo "AVISO: migraciones omitidas; falta configuración de base" >&2
  fi
fi

# Los módulos son opcionales e independientes entre sí: el fallo de uno no debe
# abortar la instalación de los demás, que es lo que ocurría bajo `set -e` (un
# host sin Python 3.10 dejaba a Farm abortando y Zynerdesk sin instalar). Cada
# módulo se ejecuta aislado y su resultado se acumula para el resumen final.
MODULES_OK=()
MODULES_FAILED=()

run_module() {
  local label="$1"
  shift
  local status=0
  "$@" || status=$?
  if [[ $status -eq 0 ]]; then
    MODULES_OK+=("$label")
  else
    MODULES_FAILED+=("$label (exit $status)")
    echo "AVISO: el módulo $label no se completó (exit $status); la instalación continúa" >&2
  fi
}

install_macelioai_tts() {
  WEB_ROOT="$WEB_ROOT" WEB_GROUP="$WEB_GROUP" bash "$ROOT/installer/macelioai-tts.sh"
}
install_whatsapp() {
  bash "$ROOT/installer/whatsapp.sh" init && bash "$ROOT/installer/whatsapp.sh" install-proxy
}
install_farm() {
  WEB_ROOT="$WEB_ROOT" WEB_GROUP="$WEB_GROUP" bash "$ROOT/installer/farm.sh"
}
install_stt_providers() {
  WEB_ROOT="$WEB_ROOT" WEB_GROUP="$WEB_GROUP" bash "$ROOT/installer/stt-providers.sh"
}
install_zynerdesk() {
  bash "$ROOT/installer/zynerdesk.sh" init \
    && bash "$ROOT/installer/zynerdesk.sh" install-proxy \
    && bash "$ROOT/installer/zynerdesk.sh" install-control
}
install_zypad() {
  WEB_GROUP="$WEB_GROUP" bash "$ROOT/installer/zypad.sh"
}

# Macelioai TTS (motor local "Marcelo IA" del IVR Builder) no es opcional:
# siempre corre, igual que zynervox-core.sh, porque ivr_builder siempre se
# despliega como parte de app/web. Va por run_module (no por `||`) para que
# quede en el resumen final igual que los demás módulos.
run_module "Macelioai TTS" install_macelioai_tts

# Con `if` y no con `[[ ... ]] && ...`: bajo `set -e` una condición falsa al
# final de una lista AND devuelve 1 y abortaría la instalación.
if [[ $WITH_WHATSAPP -eq 1 ]]; then run_module "WhatsApp" install_whatsapp; fi
if [[ $WITH_FARM -eq 1 ]]; then run_module "Farm" install_farm; fi
if [[ $WITH_STT_PROVIDERS -eq 1 ]]; then run_module "Stt Providers" install_stt_providers; fi
if [[ $WITH_ZYNERDESK -eq 1 ]]; then run_module "Zynerdesk" install_zynerdesk; fi
if [[ $WITH_ZYPAD -eq 1 ]]; then run_module "Zypad" install_zypad; fi

if [[ "${ID_LIKE:-}" == *rhel* && "${ZYNERVOX_ISOLATED:-0}" == 1 ]]; then
  CONFIG_DIR="${ZYNERVOX_CONFIG_DIR:-/etc/zynervox/zynervoxv2}" bash "$ROOT/installer/rocky-selinux.sh"
fi

apache_control=apache2ctl
apache_service=apache2
if [[ "${ID_LIKE:-}" == *rhel* ]]; then apache_control=httpd; apache_service=httpd; fi
if command -v "$apache_control" >/dev/null 2>&1; then
  if "$apache_control" -t; then
    systemctl reload "$apache_service"
  else
    echo "AVISO: Apache tiene errores previos; archivos web conservados sin recargar" >&2
  fi
fi
# El diagnóstico no debe cortar la ejecución antes del resumen: su resultado se
# reporta ahí junto con el resto.
check_status=0
WEB_ROOT="$WEB_ROOT" CHECK_FARM="$WITH_FARM" CHECK_STT_PROVIDERS="$WITH_STT_PROVIDERS" CHECK_WHATSAPP="$WITH_WHATSAPP" CHECK_ZYNERDESK="$WITH_ZYNERDESK" \
  CHECK_ZYPAD="$WITH_ZYPAD" \
  FARM_INSTANCE="$FARM_INSTANCE" STT_INSTANCE="$STT_INSTANCE" bash "$ROOT/installer/check.sh" || check_status=$?

# --- Resumen de instalación -------------------------------------------------
# Las credenciales se piden a cada módulo que sí quedó instalado. Se imprimen
# una sola vez, aquí, para que el operador pueda guardarlas: no se escriben en
# ningún log del instalador.
echo
echo "================ RESUMEN DE INSTALACIÓN ================"
echo "Web:  $WEB_ROOT"
echo "Ruta: $URL_PATH"

echo
echo "-- Módulos instalados --"
if [[ ${#MODULES_OK[@]} -eq 0 ]]; then
  echo "  (ninguno)"
else
  printf '  OK  %s\n' "${MODULES_OK[@]}"
fi

echo
echo "-- Credenciales generadas --"
echo "  Salida sensible: no compartir ni redirigir a logs publicos."
bash "$ROOT/installer/credentials.sh"
for module in "${MODULES_OK[@]+"${MODULES_OK[@]}"}"; do
  case "$module" in
    WhatsApp)
      bash "$ROOT/installer/whatsapp.sh" credentials 2>/dev/null | sed 's/^/  /' || true ;;
    Zynerdesk)
      bash "$ROOT/installer/zynerdesk.sh" credentials 2>/dev/null | sed 's/^/  /' || true ;;
    Farm)
      echo "  FARM_TOKENS=/var/lib/${FARM_INSTANCE}/internal_token (y control/control.token)" ;;
    "Stt Providers")
      echo "  STT_CONFIG=/etc/zynervox/${STT_INSTANCE}.env (incluye STT_DB_PASSWORD)" ;;
    Zypad)
      echo "  ZYPAD_CONFIG=/etc/zynervox/zynervox-zypad.env (incluye ZYPAD_API_KEY, ZYPAD_ADMIN_PASSWORD); también visible en Servicios > Zypad del panel" ;;
  esac
done

if [[ ${#MODULES_FAILED[@]} -gt 0 ]]; then
  echo
  echo "-- Errores a parchar --"
  printf '  FALLO  %s\n' "${MODULES_FAILED[@]}"
  echo
  echo "  El resto de la instalación se completó. Para reintentar solo el módulo"
  echo "  afectado, corrija la causa y vuelva a ejecutar su script:"
  echo "    sudo bash $ROOT/installer/<modulo>.sh init"
  echo "  Requisitos frecuentes: Farm necesita Python 3.10+; WhatsApp y"
  echo "  Zynerdesk necesitan Node.js 18+, npm y MySQL; Zypad necesita Docker"
  echo "  (reinstale con --install-docker)."
fi

if [[ $check_status -ne 0 ]]; then
  echo
  echo "-- Diagnóstico --"
  echo "  check.sh devolvió fallos (exit $check_status). Revise su salida arriba."
fi

echo
if [[ ${#MODULES_FAILED[@]} -eq 0 && $check_status -eq 0 ]]; then
  echo "INSTALACION_OK web=$WEB_ROOT url_path=$URL_PATH"
  echo "========================================================"
else
  echo "INSTALACION_INCOMPLETA web=$WEB_ROOT url_path=$URL_PATH modulos_fallidos=${#MODULES_FAILED[@]}"
  echo "========================================================"
  exit 1
fi
