#!/usr/bin/env bash
# Instala el motor de audio local "Marcelo IA" del IVR Builder: un venv
# Python dedicado con gTTS, usado por generate_audio.php vía
# services/tts/generate_macelioai_wav.py (gTTS -> ffmpeg -> sox).
#
# No es opcional/flaggeado: el IVR Builder siempre se despliega como parte
# de app/web, asi que este venv debe existir siempre que exista WEB_ROOT.
# Se ejecuta vía run_module en install.sh: su fallo no aborta el resto de
# la instalacion, solo queda reportado en el resumen final.
set -euo pipefail

WEB_ROOT="${WEB_ROOT:-/var/www/html/zynervox}"
WEB_GROUP="${WEB_GROUP:-www-data}"
VENV_DIR="$WEB_ROOT/venvs/gtts_env"

command -v python3 >/dev/null 2>&1 || { echo "FALTA python3 (requerido por macelioai/gTTS)" >&2; exit 1; }
command -v ffmpeg  >/dev/null 2>&1 || { echo "FALTA ffmpeg (requerido por macelioai/gTTS)" >&2; exit 1; }
command -v sox     >/dev/null 2>&1 || { echo "FALTA sox (requerido por macelioai/gTTS)" >&2; exit 1; }

# El padre venvs/ lo crea "python3 -m venv" como efecto colateral si no
# existe, heredando el umask del proceso (0077 bajo unattended.sh) -> queda
# root:root 0700, sin traversal para www-data, y generate_audio.php reporta
# "Proveedor macelioai no instalado" aunque los archivos SI existan adentro.
# Se fija ANTES de crear el venv para que el umask no lo vuelva a restringir.
install -d -o root -g "$WEB_GROUP" -m 0750 "$WEB_ROOT/venvs"

if [[ ! -d "$VENV_DIR" ]]; then
  python3 -m venv "$VENV_DIR"
fi

# El WEB_INSTALLED de install.sh aplica un chmod recursivo a todo WEB_ROOT
# (incluye venvs/, que vive adentro) y lo deja todo en 0640 sin +x. Si el
# venv ya existia de una instalacion previa, sus binarios (pip, activate,
# etc.) quedan sin ejecutar. Por eso los permisos se normalizan ANTES de
# usar pip, no solo al final: el script debe ser idempotente sin importar
# en que estado haya quedado la carpeta.
chown -R root:"$WEB_GROUP" "$VENV_DIR"
find "$VENV_DIR" -type d -exec chmod 0750 {} +
find "$VENV_DIR" -type f -exec chmod 0640 {} +
find "$VENV_DIR/bin" -type f -exec chmod 0750 {} +

"$VENV_DIR/bin/pip" install --upgrade pip -q
"$VENV_DIR/bin/pip" install gTTS -q

# pip (corriendo como root, DESPUES del chmod de arriba) escribe gtts/ y el
# resto de site-packages con el umask del proceso (0077 bajo unattended.sh)
# -> queda root:root 0700, ilegible para www-data. generate_audio.php (PHP/
# Apache = www-data) fallaba con "ImportError: cannot import name 'gTTS'
# from 'gtts' (unknown location)" aunque el chequeo de abajo (corrido como
# root) no lo detectara. Se repite el chown/chmod despues de instalar.
chown -R root:"$WEB_GROUP" "$VENV_DIR"
find "$VENV_DIR" -type d -exec chmod 0750 {} +
find "$VENV_DIR" -type f -exec chmod 0640 {} +
find "$VENV_DIR/bin" -type f -exec chmod 0750 {} +

"$VENV_DIR/bin/python" -c "import gtts" || {
  echo "FALLO: gTTS no quedo importable en $VENV_DIR" >&2
  exit 1
}

echo "MACELIOAI_TTS_INSTALLED venv=$VENV_DIR"

# List prebuild uses its own runtime/cache and never accesses the CARSA pipeline.
SOURCE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LIST_RUNTIME="${ASTERISK_ROOT:-/etc/asterisk/synervox}"
install -d -o root -g "$WEB_GROUP" -m 0750 "$LIST_RUNTIME/modules/bot_ivr"
install -d -o root -g "$WEB_GROUP" -m 2770 "$LIST_RUNTIME/bot_ivr/audio_jobs" "$LIST_RUNTIME/sounds/cache/ivr_builder/gtts"
install -o root -g "$WEB_GROUP" -m 0640 "$SOURCE_ROOT/asterisk/synervox/modules/bot_ivr/list_audio_worker.py" "$LIST_RUNTIME/modules/bot_ivr/list_audio_worker.py"
echo "BOT_IVR_LIST_AUDIO_READY runtime=$LIST_RUNTIME"
