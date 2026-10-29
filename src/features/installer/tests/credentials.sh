#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
test_directory="$(mktemp -d)"
export ZYNERVOX_CONFIG_DIR="$test_directory"
printf 'ZYNERVOX_CORE_ADMIN_USER=test-admin\nZYNERVOX_CORE_ADMIN_PASSWORD=test-placeholder\n' > "$test_directory/zynervox-core-admin.env"
chmod 0600 "$test_directory/zynervox-core-admin.env"
printf 'BOT_IVR_DB_server => localhost\nBOT_IVR_DB_port => 3306\nBOT_IVR_DB_database => testdb\nBOT_IVR_DB_user => testuser\nBOT_IVR_DB_pass => test-db-placeholder\n' > "$test_directory/zynervoxv2205-bot_ivr.conf"
printf 'RECEIVER_TOKEN => test-token-placeholder\n' > "$test_directory/zynervoxv2205-ivr-receiver.conf"
before="$(sha256sum "$test_directory"/*)"
output="$(bash "$ROOT/installer/credentials.sh")"
[[ "$output" == *test-admin* && "$output" == *test-placeholder* && "$output" == *test-db-placeholder* && "$output" == *test-token-placeholder* ]]
[[ "$before" == "$(sha256sum "$test_directory"/*)" ]]
chmod 0644 "$test_directory/zynervox-core-admin.env"
output="$(bash "$ROOT/installer/credentials.sh")"
[[ "$output" != *test-placeholder* ]]
mkdir "$test_directory/missing"
ZYNERVOX_CONFIG_DIR="$test_directory/missing" bash "$ROOT/installer/credentials.sh" >/dev/null
if runuser -u nobody -- bash "$ROOT/installer/credentials.sh" >/dev/null 2>&1; then exit 1; fi
echo 'PASS: reporte de credenciales ficticias, archivos intactos y permisos privados exigidos'
