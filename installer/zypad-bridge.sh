#!/usr/bin/env bash
# Instala el servicio zypad-bridge (puente AudioSocket de Asterisk hacia zypad). Lo deja habilitado y NO lo arranca.
# Uso (root):  zypad-bridge.sh <raiz de la fuente> <fichero con ZYPAD_URL y ZYPAD_API_KEY>
#              zypad-bridge.sh --check <raiz de la fuente>     solo comprueba; no cambia nada (sale 1 si algo falta o difiere)
# Variable:    ZYPAD_SERVICE_USER (por defecto codex-web): usuario del servicio; recibe la regla sudoers minima.
set -euo pipefail
check=0
if [[ "${1:-}" == --check ]]; then check=1; shift; fi
source_root="${1:?source root required}"
secret_file=''
if [[ $check -eq 0 ]]; then secret_file="${2:?fichero con ZYPAD_URL y ZYPAD_API_KEY required}"; fi
service_user="${ZYPAD_SERVICE_USER:-codex-web}"
[[ "$service_user" =~ ^[a-z_][a-z0-9_-]*$ ]] || { echo 'ZYPAD_SERVICE_USER invalido' >&2; exit 2; }
state=/var/lib/zypad-bridge
unit_source="$source_root/src/features/zypad/zypad-bridge.service"
sudoers_source="$source_root/src/features/zypad/zypad-bridge.sudoers"
unit_target=/etc/systemd/system/zypad-bridge.service
sudoers_target=/etc/sudoers.d/zypad-bridge
catalog_dropin=/etc/systemd/system/zypad-bridge.service.d/20-catalog.conf
[[ -f "$unit_source" && -f "$sudoers_source" ]] || { echo "Faltan la unidad o la regla sudoers en $source_root/src/features/zypad" >&2; exit 1; }

# Se generan antes de tocar nada: unidad con el usuario del servicio y regla sudoers con el mismo usuario.
unit_rendered="$(mktemp)"; sudoers_rendered="$(mktemp)"
trap 'rm -f "$unit_rendered" "$sudoers_rendered"' EXIT
sed -e "s|^User=.*|User=$service_user|" "$unit_source" > "$unit_rendered"
sed -e "s|@SERVICE_USER@|$service_user|g" "$sudoers_source" > "$sudoers_rendered"
grep -q "^User=$service_user$" "$unit_rendered" || { echo 'No se pudo generar la unidad (User=)' >&2; exit 1; }
! grep -q '@SERVICE_USER@' "$sudoers_rendered" || { echo 'La regla sudoers conserva marcadores sin sustituir' >&2; exit 1; }
visudo -cf "$sudoers_rendered" >/dev/null || { echo 'La regla sudoers generada no es valida (visudo)' >&2; exit 1; }

if [[ $check -eq 1 ]]; then
  status=0
  unit_state=no; sudoers_state=no; dropin_state=no
  [[ -f "$unit_target" ]] && cmp -s "$unit_rendered" "$unit_target" && unit_state=yes
  [[ -f "$sudoers_target" ]] && cmp -s "$sudoers_rendered" "$sudoers_target" && sudoers_state=yes
  [[ -f "$catalog_dropin" ]] && dropin_state=yes
  [[ -f /etc/zypad/bridge.env ]] && env_state=yes || env_state=no
  echo "ZYPAD unidad_igual=$unit_state sudoers_igual=$sudoers_state dropin_catalogo=$dropin_state bridge_env=$env_state habilitada=$(systemctl is-enabled zypad-bridge.service 2>/dev/null || true) activa=$(systemctl is-active zypad-bridge.service 2>/dev/null || true) usuario=$service_user"
  [[ "$unit_state$sudoers_state$env_state" == yesyesyes ]] || status=1
  exit $status
fi

[[ $EUID -eq 0 ]] || { echo 'Requiere root' >&2; exit 2; }
[[ -f "$secret_file" ]] || { echo "No existe el fichero de claves: $secret_file" >&2; exit 1; }
getent passwd "$service_user" >/dev/null || { echo "No existe el usuario del servicio: $service_user" >&2; exit 1; }

# Clave: dueño root, grupo del servicio, 640. No se imprime.
install -d -o root -g "$service_user" -m 0750 /etc/zypad
install -o root -g "$service_user" -m 0640 "$secret_file" /etc/zypad/bridge.env

# Estado: logs con transcripciones, solo el usuario del servicio.
install -d -o "$service_user" -g "$service_user" -m 0700 "$state" "$state/logs"
restorecon -RF "$state"

# Sin limpieza por edad: logs y grabaciones los conserva el usuario para el STT; su borrado es decision suya.
# Privilegios minimos del servicio (catalogo como apache y ordenes concretas del CLI de Asterisk). Ya validada arriba.
install -m 0440 -o root -g root "$sudoers_rendered" "$sudoers_target"
install -m 0644 -o root -g root "$unit_rendered" "$unit_target"
# Catalogo real: programa de MarcadorBuilder (lee zynervox_bot_zypad_words); el servicio guarda su copia en catalog.json.
install -d -m 0755 /etc/systemd/system/zypad-bridge.service.d
printf '[Service]\nEnvironment="CATALOG_CMD=sudo -n -u apache php /var/www/html/zynervoxv2/marcador/zypad_catalog.php"\n' > "$catalog_dropin"
restorecon -RF "$unit_target" /etc/systemd/system/zypad-bridge.service.d
systemctl daemon-reload
systemctl enable zypad-bridge.service
echo ZYPAD_BRIDGE_INSTALLED
