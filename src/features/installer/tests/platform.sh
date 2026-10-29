#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
test_directory="$(mktemp -d)"
printf 'ID=ubuntu\nID_LIKE=debian\n' > "$test_directory/debian"
printf 'ID=opensuse-leap\nID_LIKE="suse opensuse"\n' > "$test_directory/suse"
printf 'ID=custom\n' > "$test_directory/custom"
printf 'ID=rocky\nID_LIKE="rhel centos fedora"\n' > "$test_directory/rocky"
check_defaults() (
  unset WEB_ROOT URL_PATH WEB_GROUP WEB_DOCUMENT_ROOT
  export ZYNERVOX_OS_RELEASE_FILE="$test_directory/$1"
  source "$ROOT/installer/platform.sh"
  platform_defaults "$2" || return 1
  [[ "$WEB_ROOT" == "$3" && "$URL_PATH" == "/$2" && "$WEB_GROUP" == "$4" ]]
)
check_defaults debian zynervoxv2 /var/www/html/zynervoxv2 www-data
check_defaults suse zynervoxv2 /srv/www/htdocs/zynervoxv2 www
check_defaults suse zynervox /srv/www/htdocs/zynervox www
check_defaults rocky zynervoxv2 /var/www/html/zynervoxv2 apache
(
  export ZYNERVOX_OS_RELEASE_FILE="$test_directory/suse"
  export WEB_ROOT=/custom/web URL_PATH=/custom WEB_GROUP=root
  source "$ROOT/installer/platform.sh"
  platform_defaults zynervoxv2
  [[ "$WEB_ROOT" == /custom/web && "$URL_PATH" == /custom && "$WEB_GROUP" == root ]]
)
if check_defaults custom zynervoxv2 /unused root; then exit 1; fi
for distribution in debian suse rocky; do
  output="$(env -u WEB_ROOT -u URL_PATH -u WEB_GROUP -u WEB_DOCUMENT_ROOT ZYNERVOX_OS_RELEASE_FILE="$test_directory/$distribution" bash "$ROOT/installer/unattended.sh" --dry-run)"
  case "$distribution" in
    debian) [[ "$output" == *web=/var/www/html/zynervoxv2* ]] ;;
    suse) [[ "$output" == *web=/srv/www/htdocs/zynervoxv2* ]] ;;
    rocky) [[ "$output" == *web=/var/www/html/zynervoxv2* && "$output" == *web_group=apache* ]] ;;
  esac
done
echo 'PASS: SUSE/Debian paths, application names, overrides, unsupported OS and dry-run'
