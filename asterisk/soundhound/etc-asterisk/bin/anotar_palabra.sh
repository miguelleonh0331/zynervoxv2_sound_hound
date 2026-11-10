#!/bin/bash
# Modo SOMBRA (dialplans 7314/7315): anota la palabra que zypad detectó ANTES del answer. No cuelga nada, no corta nada.
# Uso: anotar_palabra.sh <uuid> <palabra> <ms_epoch_aparición> [audio_ms_desde_el_primer_frame]
# Solo anota la PRIMERA palabra de cada llamada y solo si la llamada sigue sin contestar (zypadmap/<uuid> guarda el canal SIP hasta el answer).
# Salida: una línea JSON con estado ANOTADA | YA_ANOTADA | YA_CONTESTO | SIN_REGISTRO | UUID_INVALIDO. El cierre de la llamada lee zypadword/<uuid>.
set -u
U=${1:-}; P=$(printf '%s' "${2:-}" | tr -cd 'a-z0-9' | cut -c1-30); MS=$(printf '%s' "${3:-}" | tr -cd '0-9' | cut -c1-14); AU=$(printf '%s' "${4:-}" | tr -cd '0-9' | cut -c1-9)
[[ $U =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]] || { echo '{"estado":"UUID_INVALIDO"}'; exit 3; }
[ -n "$P" ] && [ -n "$MS" ] || { echo '{"estado":"UUID_INVALIDO","motivo":"falta palabra o instante"}'; exit 3; }
CH=$(sudo -n asterisk -rx "database get zypadmap $U" 2>/dev/null | sed -n 's/^Value: //p' | tr -d '\r')
if [[ $CH =~ ^SIP/[A-Za-z0-9_.-]+-[0-9a-f]{8}$ ]]; then
  if sudo -n asterisk -rx "database get zypadword $U" 2>/dev/null | grep -q '^Value:'; then echo '{"estado":"YA_ANOTADA"}'; exit 0; fi
  sudo -n asterisk -rx "database put zypadword $U $P,$MS,${AU:-0}" > /dev/null 2>&1
  printf '{"estado":"ANOTADA","palabra":"%s","ms":%s}\n' "$P" "$MS"
elif [ "$CH" = CONTESTADA ]; then echo '{"estado":"YA_CONTESTO"}'
else echo '{"estado":"SIN_REGISTRO"}'; fi
