#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$ROOT/installer/platform.sh"
platform_defaults zynervoxv2
[[ $EUID -eq 0 && "${ZYNERVOX_ISOLATED:-0}" == 1 ]] || exit 1
[[ "$WEB_ROOT" =~ ^/[a-zA-Z0-9_./-]+$ ]] || exit 2
if [[ "${ID_LIKE:-}" == *rhel* ]]; then
    [[ -f "$WEB_ROOT/includes/IsolatedGate.php" ]] || { echo 'Falta gate aislado' >&2; exit 1; }
    command -v php-fpm >/dev/null
    httpd -M 2>/dev/null | grep 'proxy_fcgi_module' >/dev/null || exit 1
    [[ "$WEB_GROUP" == apache ]] || { echo 'Rocky requiere WEB_GROUP=apache' >&2; exit 1; }
    config_file=/etc/httpd/conf.d/zynervoxv2-isolated.conf
    pool_file=/etc/php-fpm.d/zynervoxv2.conf
    for file in "$config_file" "$pool_file"; do
        [[ ! -f "$file" ]] || cp -p "$file" "$file.bak.$(date +%Y%m%d%H%M%S)"
    done
    sed "s|__WEB_ROOT__|$WEB_ROOT|g" "$ROOT/installer/php-fpm-isolated.conf.template" > "$pool_file"
    sed "s|__WEB_ROOT__|$WEB_ROOT|g" "$ROOT/installer/apache-fpm-isolated.conf.template" > "$config_file"
    chmod 0644 "$config_file" "$pool_file"
    php-fpm -t
    httpd -t
    systemctl reload php-fpm
    systemctl reload httpd
    echo 'APACHE_V2_ISOLATED_GATE_READY backend=php-fpm'
    exit 0
fi
apache2ctl -M 2>/dev/null | grep -E 'php[0-9]*_module' >/dev/null || { echo 'Aislamiento requiere Apache mod_php; no se habilita sin proteccion' >&2; exit 1; }
if [[ "${ID:-}" == opensuse* || "${ID_LIKE:-}" == *suse* ]]; then
    apache_directory=/etc/apache2/conf.d
else
    apache_directory=/etc/apache2/conf-available
fi
config_file="$apache_directory/zynervoxv2-isolated.conf"
install -d -m 0755 "$apache_directory"
[[ ! -f "$config_file" ]] || cp -p "$config_file" "$config_file.bak.$(date +%Y%m%d%H%M%S)"
sed "s|__WEB_ROOT__|$WEB_ROOT|g" "$ROOT/installer/apache-isolated.conf.template" > "$config_file"
chmod 0644 "$config_file"
if [[ "$apache_directory" == /etc/apache2/conf-available ]]; then a2enconf zynervoxv2-isolated; fi
apache2ctl configtest
systemctl reload apache2
echo 'APACHE_V2_ISOLATED_GATE_READY'
