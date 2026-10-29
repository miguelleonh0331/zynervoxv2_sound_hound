#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
helper="$ROOT/installer/deploy-zynervoxv2"
bash -n "$helper"
visudo -cf - < "$ROOT/installer/codex-web-zynervoxv2-deploy.sudoers"
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
