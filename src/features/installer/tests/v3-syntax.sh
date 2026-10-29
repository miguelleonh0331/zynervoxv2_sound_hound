#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT"
for file in installer/*.sh src/features/installer/tests/*.sh; do bash -n "$file"; done
for file in app/web/config/Config.php app/web/includes/{Database,DeploymentConfig}.php app/web/bot_ivr/{db,campaigns_page,page}.php app/web/ivr_builder/{auth,config_api,index}.php app/web/modules/admin/sidebar.php app/web/modules/admin/services/{database,test}.php src/features/zynervox_queries/tests/connection-db.php; do php -l "$file"; done
php src/features/core/tests/deployment-config.php
php -l src/features/stt_providers/vendor/lib/db.php
for file in app/web/includes/{Auth,Audit,IsolatedGate}.php app/web/ivr_builder/{flow_store,asterisk_receive,generate_audio,audio_serve,upload_audio}.php app/web/bot_ivr/{launch_campaign,launch_campaign_prebuild,tts_jobs_api,audio_lab_service,campaign_recordings,agents}.php app/web/modules/admin/index.php; do php -l "$file"; done
node --check app/web/ivr_builder/assets/js/editor/config-panel.js
for file in app/web/includes/Carriers.php app/web/modules/admin/carriers.php src/features/core/tests/carriers-{db,csrf}.php; do php -l "$file"; done
echo 'PASS: syntax and configuration validation'
