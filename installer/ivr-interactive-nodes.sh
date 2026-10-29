#!/usr/bin/env bash
set -euo pipefail
task_source="${1:?source root required}"
task_web="${2:?deployed web root required}"
task_config="${3:-/etc/zynervox/zynervoxv2}"
task_runtime="${4:-/var/lib/zynervoxv2/asterisk}"
mysql zynervox_core < "$task_source/src/features/bot_ivr/models/009-ivr-results.sql"
mysql <<'SQL'
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_ivr_results TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_ivr_results TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
SQL
install -d -o root -g www -m 2770 "$task_runtime/bot_ivr/ivr_calls"
install -o root -g www -m 0640 "$task_source/asterisk/synervox/modules/bot_ivr/ivr_node_io.py" "$task_runtime/modules/bot_ivr/ivr_node_io.py"
[[ -f "$task_config/ivr_engine.json" ]] || { echo 'Install private ivr_engine.json with provider credentials before interactive calls.' >&2; exit 1; }
chown root:www "$task_config/ivr_engine.json"
chmod 0640 "$task_config/ivr_engine.json"
chown root:www "$task_web/bot_ivr/ivr_engine_agi.php"
chmod 0750 "$task_web/bot_ivr/ivr_engine_agi.php"
echo 'IVR_INTERACTIVE_NODES_READY'
