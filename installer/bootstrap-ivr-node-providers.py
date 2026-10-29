#!/usr/bin/env python3
"""One-time, private migration of provider credentials; never used by the engine."""
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
from urllib.parse import urlsplit, parse_qs

source = Path(sys.argv[1])
destination = Path(sys.argv[2])
if destination.exists():
    print('IVR provider config preserved')
    sys.exit(0)
spec = importlib.util.spec_from_file_location('ivr_migration_source', source / 'services/initial_survey/handle_response.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
profiles = module.load_deepgram_stt_profiles()
vosk_key = module.read_vosk_stt_key()
if not vosk_key:
    raise RuntimeError('No Vosk credential available for migration')
try:
    date_keys = list(dict.fromkeys(key['api_key'] for key in module.load_stt_keys()))
except Exception:
    date_keys = []
flow = json.loads((source / 'modules/ivr_builder/flows/02.json').read_text())
url = flow['nodes']['ejecutar_1']['execute_url']
parts = urlsplit(url)
sms_key = parse_qs(parts.query).get('key', [''])[0]
base = parts.scheme + '://' + parts.netloc + parts.path
config = {'timezone': 'America/Lima', 'result_destination': 'core',
          'amd': {'endpoint': module.VOSK_STT_URL, 'key': vosk_key},
          'menu': {'backend': 'deepgram' if profiles else 'gateway', 'endpoint': 'http://127.0.0.1:8787/v1/transcriptions', 'keys': [profile['api_key'] for profile in profiles], 'min_confidence': getattr(module, 'DEEPGRAM_MIN_CONFIDENCE', 0.35)},
          'date_keys': date_keys, 'execute_allowlist': [base], 'variables': {'sms_key': sms_key}}
destination.parent.mkdir(parents=True, exist_ok=True)
fd, temp = tempfile.mkstemp(dir=str(destination.parent), prefix='.ivr-engine-')
try:
    with os.fdopen(fd, 'w') as handle:
        json.dump(config, handle, ensure_ascii=False)
        handle.flush()
        os.fsync(handle.fileno())
    os.chmod(temp, 0o640)
    os.replace(temp, destination)
finally:
    if os.path.exists(temp):
        os.unlink(temp)
print('IVR providers migrated privately; menu backend ' + config['menu']['backend'] + '; result destination core; date keys ' + str(len(date_keys)))
