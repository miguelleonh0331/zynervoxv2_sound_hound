#!/usr/bin/env bash
set -euo pipefail
source_directory="${1:?Falta carpeta fuente}"
destination_directory="${2:?Falta carpeta destino}"
command -v rsync >/dev/null 2>&1 || { echo "Falta rsync: instalar con apt-get install rsync o zypper install rsync" >&2; exit 1; }
[[ -d "$source_directory" ]] || { echo "Fuente web inexistente" >&2; exit 1; }
source_directory="$(realpath "$source_directory")"
destination_directory="$(realpath -m "$destination_directory")"
[[ "$destination_directory" != / && "$destination_directory" != "$source_directory" && "$destination_directory" != "$source_directory/"* && "$source_directory" != "$destination_directory/"* ]] || { echo "Destino web inseguro o solapado con fuente" >&2; exit 1; }
printf '[%s] Revisando web y transfiriendo solo archivos nuevos/modificados\n' "$(date +%T)"
install -d -m 0750 "$destination_directory"
rsync -rtc --itemize-changes --stats --exclude='venvs/' --exclude='*.bak*' --exclude='__pycache__/' --exclude='*.pyc' --exclude='runtime/' --exclude='secrets/' --exclude='/config/deployment.php' --exclude='/config/reporting_mirror.json' -- "$source_directory/" "$destination_directory/"

# AGI programs require execution permission even after a web-only copy.
source "$(dirname "${BASH_SOURCE[0]}")/agi-list.sh"
for agi in "${AGI_PATHS[@]}"; do
  if [[ -f "$destination_directory/$agi" ]]; then
    chmod 0750 "$destination_directory/$agi"
  fi
done
