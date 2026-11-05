#!/usr/bin/env bash
set -euo pipefail
source_root="${1:?source root required}"
runtime_root="${2:-/var/lib/zynervoxv2/asterisk}"
mysql zynervox_core < "$source_root/src/features/bot_ivr/models/012-dial-runs.sql"
mysql zynervox_core < "$source_root/src/features/bot_ivr/models/014-dialer-v1.sql"
mysql zynervox_core < "$source_root/src/features/bot_ivr/models/015-dialer-channels.sql"
mysql zynervox_core < "$source_root/src/features/bot_ivr/models/016-dialer-amd-sip-rules.sql"
mysql zynervox_core < "$source_root/src/features/bot_ivr/models/017-dialer-list-state.sql"
mysql zynervox_core < "$source_root/src/features/bot_ivr/models/018-amd-audit.sql"
mysql zynervox_core < "$source_root/src/features/bot_ivr/models/019-amd-global-summary-indexes.sql"
mysql zynervox_core < "$source_root/src/features/bot_ivr/models/020-amd-contact-audio.sql"
mysql zynervox_core < "$source_root/src/features/bot_ivr/models/021-zypad.sql"
mysql zynervox_core < "$source_root/src/features/bot_ivr/models/022-contact-distribution.sql"
mysql zynervox_core < "$source_root/src/features/bot_ivr/models/023-blacklist.sql"
mysql zynervox_core < "$source_root/src/features/bot_ivr/models/024-blacklist-campaign.sql"
mysql <<'SQL'
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_dial_runs TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_dial_runs TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_campaign_headers TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_campaign_retry_rules TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_campaign_schedules TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_campaign_headers TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_campaign_retry_rules TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_campaign_schedules TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_dialer_settings TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_dialer_settings TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_campaign_amd_actions TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_campaign_sip_rules TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_campaign_amd_actions TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_campaign_sip_rules TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_campaign_amd_params TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_amd_audit TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_campaign_amd_params TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_amd_audit TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_amd_global_versions TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_list_summary TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_amd_global_versions TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_list_summary TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_zypad_words TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON zynervox_core.zynervox_bot_zypad_words TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
GRANT SELECT ON zynervox_core.zynervox_blacklist TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT ON zynervox_core.zynervox_blacklist TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
SQL
install -d -o root -g "${WEB_GROUP:-www}" -m 2770 "$runtime_root/bot_ivr/call_jobs"
install -d -o root -g "${WEB_GROUP:-www}" -m 0750 "$runtime_root/modules/bot_ivr"
install -o root -g "${WEB_GROUP:-www}" -m 0640 "$source_root/asterisk/synervox/modules/bot_ivr/list_call_launcher.py" "$runtime_root/modules/bot_ivr/list_call_launcher.py"
# Grabaciones de llamadas contestadas del marcador: Asterisk escribe, el STT por lotes (grupo web) lee. SELinux lo cubre la regla de $runtime_root.
install -d -o asterisk -g "${WEB_GROUP:-www}" -m 2770 "$runtime_root/recordings/marcador"
setfacl -m u:asterisk:rwx,g:"${WEB_GROUP:-www}":rwx,d:u::rwx,d:u:asterisk:rwx,d:g::rwx,d:g:"${WEB_GROUP:-www}":rw,d:m::rwx,d:o::- "$runtime_root/recordings/marcador"
echo LIST_CALL_WORKER_READY
