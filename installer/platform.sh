#!/usr/bin/env bash
platform_defaults() {
  local application="$1"
  local release_file="${ZYNERVOX_OS_RELEASE_FILE:-/etc/os-release}"
  [[ -f "$release_file" ]] || { echo "Falta os-release: $release_file" >&2; return 1; }
  source "$release_file"
  local default_group
  case "${ID:-} ${ID_LIKE:-}" in
    *suse*) WEB_DOCUMENT_ROOT="${WEB_DOCUMENT_ROOT:-/srv/www/htdocs}"; default_group=www ;;
    *ubuntu*|*debian*) WEB_DOCUMENT_ROOT="${WEB_DOCUMENT_ROOT:-/var/www/html}"; default_group=www-data ;;
    *rhel*) WEB_DOCUMENT_ROOT="${WEB_DOCUMENT_ROOT:-/var/www/html}"; default_group=apache ;;
    *)
      [[ -n "${WEB_ROOT:-}" || -n "${WEB_DOCUMENT_ROOT:-}" ]] || { echo "Sistema ${ID:-desconocido}: especificar WEB_ROOT o WEB_DOCUMENT_ROOT" >&2; return 1; }
      default_group=www-data
      ;;
  esac
  WEB_ROOT="${WEB_ROOT:-${WEB_DOCUMENT_ROOT%/}/$application}"
  URL_PATH="${URL_PATH:-/$application}"
  WEB_GROUP="${WEB_GROUP:-$default_group}"
  export WEB_ROOT URL_PATH WEB_GROUP
}
