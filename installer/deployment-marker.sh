#!/usr/bin/env bash
set -euo pipefail
[[ $EUID -eq 0 && "${ZYNERVOX_ISOLATED:-0}" == 1 ]] || exit 1
[[ "$ZYNERVOX_CONFIG_DIR" =~ ^/[a-zA-Z0-9_./-]+$ && "$ASTERISK_ROOT" =~ ^/[a-zA-Z0-9_./-]+$ ]] || exit 2
[[ "$URL_PATH" =~ ^/[a-zA-Z0-9_/-]+$ ]] || exit 2
install -d -o root -g "$WEB_GROUP" -m 0770 "$ASTERISK_ROOT/modules/flows/published"
install -d -o root -g "$WEB_GROUP" -m 0770 "$ASTERISK_ROOT/sounds/cache/ivr_builder/macelioai" "$ASTERISK_ROOT/sounds/cache/ivr_builder/mic" "$ASTERISK_ROOT/recordings"
marker="$(mktemp "$WEB_ROOT/config/.deployment.XXXXXX")"
printf "<?php\nreturn ['isolated'=>true,'directory'=>'%s','runtime'=>'%s','url'=>'%s'];\n" "$ZYNERVOX_CONFIG_DIR" "$ASTERISK_ROOT" "$URL_PATH" > "$marker"
chown root:"$WEB_GROUP" "$marker"
chmod 0640 "$marker"
mv -f "$marker" "$WEB_ROOT/config/deployment.php"
