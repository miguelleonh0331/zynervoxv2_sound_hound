#!/usr/bin/env bash
# Repositorios, modulos y paquetes de Rocky Linux 9 que necesita Zynervox v2.
# Lista sacada del servidor de referencia (soundhound) y limitada a lo que usan los scripts y la web.
# Uso (root):  packages-rocky9.sh           instala lo que falte
#              packages-rocky9.sh --check   solo comprueba; no cambia nada (sale 1 si falta algo)
# Deja arrancados y habilitados mariadb, php-fpm, httpd y crond.
# NO cubre: Asterisk (en soundhound esta compilado desde fuente, no es un paquete),
#           Docker y Tailscale (opcionales), ni el VPN.
set -euo pipefail

# Repositorios: BaseOS, AppStream y Extras vienen con Rocky; CRB y EPEL hay que habilitarlos
# (sox y ffmpeg-free salen de EPEL).
REPOS=(crb epel)
# Flujos de modulo de AppStream (versiones del servidor de referencia).
MODULES=(php:8.3 nodejs:22 mariadb:10.11)
PACKAGES=(
  # Web y PHP (extensiones que exige check.sh: curl json mbstring mysqli pdo_mysql session xml zip)
  httpd php php-cli php-common php-fpm php-gd php-mbstring php-mysqlnd php-opcache php-pdo php-process php-xml php-pecl-zip
  # Base de datos
  mariadb-server
  # Python: motor de audio (venv con gTTS) y workers
  python3 python3-pip python3-PyMySQL
  # Audio del IVR Builder (gTTS -> ffmpeg -> sox)
  sox ffmpeg-free
  # Puente de zypad
  nodejs npm
  # SELinux (rocky-selinux.sh) y ACL
  policycoreutils policycoreutils-python-utils checkpolicy selinux-policy-targeted acl
  # Herramientas que usan los scripts
  rsync git openssl curl sudo cronie crontabs firewalld
)

check=0
case "${1:-}" in
  '') ;;
  --check) check=1 ;;
  *) echo 'Uso: packages-rocky9.sh [--check]' >&2; exit 2 ;;
esac
[[ $EUID -eq 0 ]] || { echo 'Ejecutar como root' >&2; exit 1; }
source /etc/os-release
[[ "${ID_LIKE:-} ${ID:-}" == *rhel* && "${VERSION_ID%%.*}" == 9 ]] || { echo "Solo Rocky/RHEL 9; sistema: ${PRETTY_NAME:-desconocido}" >&2; exit 2; }

if [[ $check -eq 1 ]]; then
  missing=0
  for repo in "${REPOS[@]}"; do
    dnf -q repolist --enabled | awk '{print $1}' | grep -qx "$repo" || { echo "FALTA repositorio habilitado: $repo"; missing=1; }
  done
  for package in "${PACKAGES[@]}"; do
    rpm -q "$package" >/dev/null 2>&1 || { echo "FALTA paquete: $package"; missing=1; }
  done
  [[ $missing -eq 0 ]] && echo "PACKAGES_OK paquetes=${#PACKAGES[@]} repos=${REPOS[*]}"
  exit "$missing"
fi

dnf -y install dnf-plugins-core epel-release
dnf config-manager --set-enabled crb
dnf -y module enable "${MODULES[@]}"
dnf -y install "${PACKAGES[@]}"
# Servicios que los scripts siguientes necesitan ARRANCADOS: mysql por socket, recarga de httpd y php-fpm, cron.d.
# firewalld no se toca aqui (las reglas de red son manuales).
systemctl enable --now mariadb php-fpm httpd crond
echo "PACKAGES_INSTALLED paquetes=${#PACKAGES[@]}"
