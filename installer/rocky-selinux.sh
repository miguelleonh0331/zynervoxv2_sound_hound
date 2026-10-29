#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[[ $EUID -eq 0 && "${ZYNERVOX_ISOLATED:-0}" == 1 ]] || exit 2
[[ "$WEB_ROOT" =~ ^/[a-zA-Z0-9_./-]+$ && "$CONFIG_DIR" =~ ^/[a-zA-Z0-9_./-]+$ && "$ASTERISK_ROOT" == /var/lib/zynervoxv2/asterisk ]] || exit 2
[[ "$(getenforce)" != Disabled ]] || { echo 'SELinux deshabilitado; no se prepara politica'; exit 1; }
policy_directory="$(mktemp -d)"
trap 'rm -rf -- "$policy_directory"' EXIT
checkmodule -M -m -o "$policy_directory/zynervox.mod" "$ROOT/installer/rocky-selinux.te"
semodule_package -o "$policy_directory/zynervox.pp" -m "$policy_directory/zynervox.mod"
semodule -i "$policy_directory/zynervox.pp"
context() {
    semanage fcontext -a -t "$1" "$2" 2>/dev/null || semanage fcontext -m -t "$1" "$2"
}
context httpd_sys_content_t "$WEB_ROOT(/.*)?"
context httpd_sys_rw_content_t "$WEB_ROOT/runtime(/.*)?"
context zynervox_runtime_t "$ASTERISK_ROOT(/.*)?"
context zynervox_conf_t "$CONFIG_DIR(/.*)?"
context zynervox_runtime_t "$CONFIG_DIR/pbx(/.*)?"
for agi in call_tracking_agi.php ivr_engine_agi.php; do
    context zynervox_agi_exec_t "$WEB_ROOT/bot_ivr/$agi"
done
restorecon -RF "$WEB_ROOT" "$CONFIG_DIR" "$ASTERISK_ROOT"
setsebool -P httpd_can_network_connect_db on
setsebool -P httpd_can_network_connect on
if id asterisk >/dev/null 2>&1; then
    setfacl -Rm u:asterisk:rX "$WEB_ROOT" "$ASTERISK_ROOT"
    setfacl -m u:asterisk:rx "$CONFIG_DIR" "$CONFIG_DIR/pbx"
    for config in zynervox-core.conf zynervoxv2205-bot_ivr.conf pbx/ami.json; do
        [[ ! -f "$CONFIG_DIR/$config" ]] || setfacl -m u:asterisk:r "$CONFIG_DIR/$config"
    done
    setfacl -m d:u:asterisk:rX "$ASTERISK_ROOT/modules/asterisk"
    for directory in "$ASTERISK_ROOT/sounds" "$ASTERISK_ROOT/recordings" "$ASTERISK_ROOT/bot_ivr/call_audit_pending" "$ASTERISK_ROOT/bot_ivr/ivr_calls"; do
        setfacl -Rm u:asterisk:rwX "$directory"
        find "$directory" -type d -exec setfacl -m d:u:asterisk:rwX {} +
    done
fi
echo ROCKY_SELINUX_READY
