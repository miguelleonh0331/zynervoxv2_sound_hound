#!/usr/bin/env bash
set -euo pipefail
[[ $EUID -eq 0 && "${ZYNERVOX_ISOLATED:-0}" == 1 ]] || exit 2
[[ "$ASTERISK_ROOT" == /var/lib/zynervoxv2/asterisk && "$WEB_ROOT" =~ ^/[a-zA-Z0-9_./-]+$ ]] || { echo 'Configure audit cron paths for this deployment' >&2; exit 2; }
install -d -o root -g "$WEB_GROUP" -m 2770 "$ASTERISK_ROOT/bot_ivr/call_audit_pending" "$ASTERISK_ROOT/bot_ivr/ivr_calls"
cat > /etc/cron.d/zynervoxv2-call-audit <<CRON
* * * * * root /usr/bin/php $WEB_ROOT/bot_ivr/call_tracking_agi.php --replay >> /var/log/zynervoxv2-call-audit.log 2>&1
CRON
chmod 0644 /etc/cron.d/zynervoxv2-call-audit
