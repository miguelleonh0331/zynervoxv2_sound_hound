#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
[[ $EUID -eq 0 ]] || { echo "Ejecutar en un entorno MySQL local de pruebas como root" >&2; exit 1; }
suffix="$(openssl rand -hex 4)"
test_db="zv_v3_test_$suffix"
test_core_user="zv_core_$suffix"
test_bot_user="zv_bot_$suffix"
test_stt_user="zv_stt_$suffix"
isolated_db=zynervox_core
isolated_core_user=zynervoxv2_core
isolated_bot_user="zv_v2_bot_$suffix"
test_directory="$(mktemp -d)"
export ZYNERVOX_DB_PORT="$(python3 -c 'import socket; listener=socket.socket(); listener.bind(("127.0.0.1",0)); print(listener.getsockname()[1]); listener.close()')"
test_socket="$test_directory/mysql.sock"
mysqld --no-defaults --initialize-insecure --user=root --datadir="$test_directory/data" --log-error="$test_directory/mysql.log"
mysqld --no-defaults --daemonize --user=root --datadir="$test_directory/data" --socket="$test_socket" --port="$ZYNERVOX_DB_PORT" --bind-address=127.0.0.1 --mysqlx=OFF --pid-file="$test_directory/mysql.pid" --log-error="$test_directory/mysql.log"
install -d "$test_directory/bin"
printf '#!/usr/bin/env bash\nexec /usr/bin/mysql --no-defaults --socket=%q "$@"\n' "$test_socket" > "$test_directory/bin/mysql"
chmod 0700 "$test_directory/bin/mysql"
export PATH="$test_directory/bin:$PATH"
cleanup() {
  [[ "$test_db" == zv_v3_test_* ]] || return
  mysql -e "DROP DATABASE IF EXISTS \`$test_db\`; DROP USER IF EXISTS '$test_core_user'@'localhost', '$test_core_user'@'127.0.0.1', '$test_bot_user'@'localhost', '$test_bot_user'@'127.0.0.1', '$test_stt_user'@'localhost', '$test_stt_user'@'127.0.0.1';"
  mysql -e "DROP DATABASE IF EXISTS \`$isolated_db\`; DROP USER IF EXISTS '$isolated_core_user'@'localhost', '$isolated_core_user'@'127.0.0.1', '$isolated_bot_user'@'localhost', '$isolated_bot_user'@'127.0.0.1';"
  mysqladmin --no-defaults --socket="$test_socket" shutdown
}
trap cleanup EXIT
export ZYNERVOX_CONFIG_DIR="$test_directory/config"
export ZYNERVOX_DB_PASSWORD_FILE="$test_directory/mysql-password"
export ZYNERVOX_CORE_DB="$test_db" ZYNERVOX_CORE_DB_USER="$test_core_user"
export BOT_IVR_DB_USER="$test_bot_user" STT_DB_USER="$test_stt_user"
export WEB_ROOT="$test_directory/web" WEB_GROUP=root
export BOT_IVR_LEGACY_CONFIG="$test_directory/absent-legacy.json"
export ZYNERVOX_CONFIG_FILE="$ZYNERVOX_CONFIG_DIR/zynervox-core.conf"
export ZYNERVOX_CORE_CONFIG_FILE="$ZYNERVOX_CONFIG_FILE"
export ZYNERVOX_BOT_IVR_CONFIG_FILE="$ZYNERVOX_CONFIG_DIR/zynervoxv2205-bot_ivr.conf"
for installer in zynervox-core ivr-builder-bot-db stt-providers; do bash "$ROOT/installer/$installer.sh"; done
ZYNERVOX_STT_CONFIG="$ZYNERVOX_CONFIG_DIR/zynervox-stt.php" php -r 'require $argv[1]; if (carsa_db()->query("SELECT 1")->fetchColumn() != 1) exit(1);' "$ROOT/src/features/stt_providers/vendor/lib/db.php"
if ZYNERVOX_DB_PORT=0 bash "$ROOT/installer/zynervox-core.sh" >/dev/null 2>&1; then echo "Se acepto un destino MySQL incorrecto" >&2; exit 1; fi
mysql "$test_db" <<SQL
INSERT INTO zynervox_bot_campaigns(name) VALUES ('retained-campaign');
INSERT INTO bot_ivr_flows(flow_code,name,data_json) VALUES ('99','retained-flow','{}');
INSERT INTO deepgram_profiles(name,api_key) VALUES ('retained-stt','test-placeholder');
UPDATE ivr_deploy_config SET asterisk_api_url='https://retained.invalid';
SQL
before_hash="$(find "$ZYNERVOX_CONFIG_DIR" -maxdepth 1 -type f -exec sha256sum {} + | sort | sha256sum)"
for installer in zynervox-core ivr-builder-bot-db stt-providers; do bash "$ROOT/installer/$installer.sh"; done
after_hash="$(find "$ZYNERVOX_CONFIG_DIR" -maxdepth 1 -type f -exec sha256sum {} + | sort | sha256sum)"
[[ "$before_hash" == "$after_hash" ]] || { echo "La segunda instalacion cambio configuraciones" >&2; exit 1; }
[[ "$(mysql -N "$test_db" -e "SELECT COUNT(*) FROM zynervox_bot_campaigns WHERE name='retained-campaign'")" == 1 ]]
[[ "$(mysql -N "$test_db" -e "SELECT COUNT(*) FROM bot_ivr_flows WHERE name='retained-flow'")" == 1 ]]
[[ "$(mysql -N "$test_db" -e "SELECT COUNT(*) FROM deepgram_profiles WHERE name='retained-stt'")" == 1 ]]
[[ "$(mysql -N "$test_db" -e "SELECT asterisk_api_url FROM ivr_deploy_config WHERE id=1")" == https://retained.invalid ]]
for db_user in "$test_bot_user" "$test_stt_user"; do
  grants="$(mysql -N -e "SHOW GRANTS FOR '$db_user'@'localhost'")"
  if grep -v '^GRANT USAGE ON' <<< "$grants" | grep -Eq 'zynervox_users|ivr_deploy_config|ON .*\.\*'; then echo "Privilegios excesivos" >&2; exit 1; fi
done
for test_file in campaigns-db list-import-db list-flow-db; do php "$ROOT/src/features/bot_ivr/tests/$test_file.php" "$ROOT/app/web/bot_ivr"; done
php "$ROOT/src/features/zynervox_queries/tests/connection-db.php" "$ROOT/app/web/bot_ivr"
echo "PASS: instalacion repetida, datos/config conservados, privilegios aislados y repositorios"
legacy_config_hash="$(find "$ZYNERVOX_CONFIG_DIR" -maxdepth 1 -type f -exec sha256sum {} + | sort | sha256sum)"
legacy_directory="$ZYNERVOX_CONFIG_DIR"
export ZYNERVOX_CONFIG_DIR="$test_directory/isolated"
export ZYNERVOX_CORE_DB="$isolated_db" ZYNERVOX_CORE_DB_USER="$isolated_core_user" BOT_IVR_DB_USER="$isolated_bot_user"
export ZYNERVOX_DB_PASSWORD_FILE="$ZYNERVOX_CONFIG_DIR/core-password"
export WEB_ROOT="$test_directory/isolated-web" URL_PATH=/zynervoxv2 ASTERISK_ROOT="$test_directory/isolated-runtime"
export BOT_IVR_DB_PASSWORD=test-isolated-placeholder
bash "$ROOT/installer/isolate-v2.sh" > "$test_directory/isolated-install.log"
php "$ROOT/src/features/installer/tests/isolated-v2.php" "$WEB_ROOT"
export ZYNERVOX_CORE_ADMIN_PASSWORD=test-admin-reconciled ZYNERVOX_CORE_USERS_TABLE=v2_zynervox_users ZYNERVOX_CORE_CONFIG_TABLE=v2_ivr_deploy_config ZYNERVOX_ISOLATED=1
bash "$ROOT/installer/zynervox-core.sh"
[[ "$(mysql -N "$isolated_db" -e "SELECT pass FROM v2_zynervox_users WHERE user='admin'")" == test-admin-reconciled ]]
php "$ROOT/src/features/installer/tests/isolated-v2.php" "$WEB_ROOT"
export ZYNERVOX_ISOLATED=1 ZYNERVOX_CORE_CONFIG_TABLE=v2_ivr_deploy_config BOT_IVR_DB_PASSWORD=test-rotated-placeholder
bash "$ROOT/installer/ivr-builder-bot-db.sh"
php "$ROOT/src/features/installer/tests/isolated-v2.php" "$WEB_ROOT"
[[ "$(mysql -N "$isolated_db" -e "SELECT db_pass FROM v2_ivr_deploy_config WHERE id=1")" == test-rotated-placeholder ]]
bash "$ROOT/installer/ivr-builder-bot-db.sh"
before_rotation_config="$(sha256sum "$ZYNERVOX_CONFIG_DIR/zynervoxv2205-bot_ivr.conf")"
mysql "$isolated_db" -e "UPDATE v2_ivr_deploy_config SET db_name='zynervox' WHERE id=1;"
if BOT_IVR_DB_PASSWORD=rejected-placeholder bash "$ROOT/installer/ivr-builder-bot-db.sh" >/dev/null 2>&1; then exit 1; fi
[[ "$before_rotation_config" == "$(sha256sum "$ZYNERVOX_CONFIG_DIR/zynervoxv2205-bot_ivr.conf")" ]]
mysql "$isolated_db" -e "UPDATE v2_ivr_deploy_config SET db_name='$isolated_db' WHERE id=1;"
php "$ROOT/src/features/installer/tests/isolated-v2.php" "$WEB_ROOT"
[[ "$(php "$ROOT/src/features/installer/tests/isolated-gate.php" "$WEB_ROOT" index.php)" == *ALLOWED* ]]
[[ "$(php "$ROOT/src/features/installer/tests/isolated-gate.php" "$WEB_ROOT" agc/api.php)" == *STATUS=403* ]]
[[ "$(php "$ROOT/src/features/installer/tests/isolated-gate.php" "$WEB_ROOT" bot_ivr/../setup_db.php)" == *STATUS=403* ]]
[[ "$legacy_config_hash" == "$(find "$legacy_directory" -maxdepth 1 -type f -exec sha256sum {} + | sort | sha256sum)" ]]
[[ "$(mysql -N "$test_db" -e "SELECT asterisk_api_url FROM ivr_deploy_config WHERE id=1")" == https://retained.invalid ]]
echo 'PASS: configuracion legacy intacta tras instalacion v2 aislada'
before_module_config="$(find "$ZYNERVOX_CONFIG_DIR" -maxdepth 1 -type f -exec sha256sum {} + | sort | sha256sum)"
before_module_web="$(find "$WEB_ROOT" -type f ! -path '*/includes/Carriers.php' ! -path '*/includes/IsolatedGate.php' ! -path '*/modules/admin/carriers.php' -exec sha256sum {} + | sort | sha256sum)"
bash "$ROOT/installer/unattended.sh" --module carriers
php "$ROOT/src/features/core/tests/carriers-db.php" "$WEB_ROOT"
mysql "$isolated_db" -e "INSERT INTO v2_carriers VALUES ('RETAINED','Retained','CUSTOM','PJSIP','','','127.0.0.1','N','');"
bash "$ROOT/installer/unattended.sh" --module=carriers
[[ "$(mysql -N "$isolated_db" -e "SELECT COUNT(*) FROM v2_carriers WHERE carrier_id='RETAINED'")" == 1 ]]
[[ "$before_module_config" == "$(find "$ZYNERVOX_CONFIG_DIR" -maxdepth 1 -type f -exec sha256sum {} + | sort | sha256sum)" ]]
[[ "$before_module_web" == "$(find "$WEB_ROOT" -type f ! -path '*/includes/Carriers.php' ! -path '*/includes/IsolatedGate.php' ! -path '*/modules/admin/carriers.php' -exec sha256sum {} + | sort | sha256sum)" ]]
[[ "$(php "$ROOT/src/features/installer/tests/isolated-gate.php" "$WEB_ROOT" modules/admin/carriers.php)" == *ALLOWED* ]]
[[ "$(php "$ROOT/src/features/core/tests/carriers-csrf.php" "$WEB_ROOT")" == *CSRF_REJECTED* ]]
cp "$ZYNERVOX_CONFIG_DIR/zynervox-core.conf" "$test_directory/core-before.conf"
awk '{ if ($1 == "CORE_DB_database") print "CORE_DB_database => zynervox"; else print }' "$test_directory/core-before.conf" > "$ZYNERVOX_CONFIG_DIR/zynervox-core.conf"
if bash "$ROOT/installer/unattended.sh" --module carriers >/dev/null 2>&1; then echo 'Carriers acepto BD productiva' >&2; exit 1; fi
cp "$test_directory/core-before.conf" "$ZYNERVOX_CONFIG_DIR/zynervox-core.conf"
[[ "$before_module_config" == "$(find "$ZYNERVOX_CONFIG_DIR" -maxdepth 1 -type f -exec sha256sum {} + | sort | sha256sum)" ]]
if bash "$ROOT/installer/unattended.sh" --module=unknown >/dev/null 2>&1; then exit 1; fi
if bash "$ROOT/installer/unattended.sh" --module= >/dev/null 2>&1; then exit 1; fi
if bash "$ROOT/installer/unattended.sh" --module=carriers --with-farm >/dev/null 2>&1; then exit 1; fi
echo 'PASS: modulo selectivo idempotente, CSRF, datos y archivos ajenos conservados'
