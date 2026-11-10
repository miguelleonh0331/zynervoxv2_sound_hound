#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
helper="$ROOT/installer/deploy-zynervoxv2"
bash -n "$helper"
visudo -cf - < "$ROOT/installer/codex-web-zynervoxv2-deploy.sudoers"
grep -q 'setfacl -m u:asterisk:rX,u:codex-web:rX' "$helper" || { echo 'Falta restaurar las ACL de asterisk y codex-web tras el rsync.' >&2; exit 1; }
grep -q 'git -C "$source_root" show "$commit:installer/agi-list.txt"' "$helper" || { echo 'El envoltorio no lee installer/agi-list.txt del commit.' >&2; exit 1; }
source "$ROOT/installer/agi-list.sh"
[[ ${#AGI_PATHS[@]} -ge 4 ]] || { echo 'installer/agi-list.txt tiene menos AGI de los esperados.' >&2; exit 1; }
for agi in "${AGI_PATHS[@]}"; do
  [[ -f "$ROOT/app/web/$agi" ]] || { echo "AGI de la lista que no existe en app/web: $agi" >&2; exit 1; }
done
[[ $EUID -eq 0 ]] || { echo 'Las pruebas de argumentos requieren root; no publican archivos.' >&2; exit 1; }
reject() {
  local status=0
  bash "$helper" "$@" >/dev/null 2>&1 || status=$?
  [[ "$status" -eq 2 ]] || { echo 'Se aceptaron argumentos no permitidos.' >&2; exit 1; }
}
reject --source=/etc
reject --dry-run extra
reject /tmp/another-destination
echo 'PASS: sintaxis Bash/sudoers, rutas y argumentos adicionales rechazados.'
