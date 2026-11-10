#!/usr/bin/env bash
# Carga installer/agi-list.txt en el array AGI_PATHS (rutas relativas a la web).
# Uso: source installer/agi-list.sh   (desde cualquier script de installer/)
agi_list_file="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/agi-list.txt"
mapfile -t AGI_PATHS < <(grep -vE '^[[:space:]]*(#|$)' "$agi_list_file")
[[ ${#AGI_PATHS[@]} -gt 0 ]] || { echo "Lista de AGI vacia: $agi_list_file" >&2; return 1; }
for agi_path in "${AGI_PATHS[@]}"; do
  [[ "$agi_path" =~ ^[a-z0-9_]+/[a-z0-9_]+\.php$ ]] || { echo "Ruta de AGI invalida: $agi_path" >&2; return 1; }
done
