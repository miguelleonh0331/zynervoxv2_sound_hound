#!/usr/bin/env bash
set -euo pipefail
source_root="${1:?source root required}"
runtime_root="${2:-/var/lib/zynervoxv2/asterisk}"
mysql zynervox_core < "$source_root/src/features/bot_ivr/models/012-dial-runs.sql"
mysql <<'SQL'
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_dial_runs TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_dial_runs TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
SQL
install -d -o root -g "${WEB_GROUP:-www}" -m 2770 "$runtime_root/bot_ivr/call_jobs"
install -d -o root -g "${WEB_GROUP:-www}" -m 0750 "$runtime_root/modules/bot_ivr"
install -o root -g "${WEB_GROUP:-www}" -m 0640 "$source_root/asterisk/synervox/modules/bot_ivr/list_call_launcher.py" "$runtime_root/modules/bot_ivr/list_call_launcher.py"
echo LIST_CALL_WORKER_READY
