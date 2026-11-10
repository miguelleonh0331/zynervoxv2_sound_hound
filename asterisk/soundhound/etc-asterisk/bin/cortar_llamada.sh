#!/bin/bash
# Cortador de llamadas pre-answer (dialplans 7312/7313). Lo invoca el puente de zypad cuando detecta la palabra. Solo cuelga si la llamada AÚN no ha contestado.
# Uso: cortar_llamada.sh <uuid> [palabra] [ms_epoch_en_que_apareció]     (palabra y hora son opcionales; sin ellas el registro dice «x» y 0)
# - el UUID debe llevar la marca c07e (la pone el dialplan 7312/7313; 7310/7311 no la llevan y no se pueden cortar por aquí)
# - zypadmap/<uuid> guarda el nombre del canal SIP hasta el answer; en el answer el dialplan lo cambia a CONTESTADA y aquí no se hace nada
# Salida (una línea JSON): estado CORTADO | YA_CONTESTO | SIN_REGISTRO | UUID_NO_PERMITIDO, y los instantes en ms de época.
set -u
U=${1:-}; P=$(printf '%s' "${2:-}" | tr -cd 'a-z0-9' | cut -c1-30); APA=$(printf '%s' "${3:-}" | tr -cd '0-9' | cut -c1-14)
[[ $U =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-c07e-[0-9a-f]{12}$ ]] || { echo '{"estado":"UUID_NO_PERMITIDO"}'; exit 3; }
ORD=$(date +%s%3N)
CH=$(sudo -n asterisk -rx "database get zypadmap $U" 2>/dev/null | sed -n 's/^Value: //p' | tr -d '\r')
if [[ $CH =~ ^SIP/[A-Za-z0-9_.-]+-[0-9a-f]{8}$ ]]; then
  sudo -n asterisk -rx "database put zypadcut $U ${P:-x},${APA:-0},$ORD" > /dev/null 2>&1
  sudo -n asterisk -rx "channel request hangup $CH" > /dev/null 2>&1; HUP=$(date +%s%3N)
  printf '{"estado":"CORTADO","canal":"%s","orden_ms":%s,"hangup_ms":%s}\n' "$CH" "$ORD" "$HUP"
elif [ "$CH" = CONTESTADA ]; then printf '{"estado":"YA_CONTESTO","orden_ms":%s}\n' "$ORD"
else printf '{"estado":"SIN_REGISTRO","orden_ms":%s}\n' "$ORD"; fi
