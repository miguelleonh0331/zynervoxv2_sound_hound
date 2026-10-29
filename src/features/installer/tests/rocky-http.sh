#!/usr/bin/env bash
set -euo pipefail
[[ $EUID -eq 0 ]] || exit 1
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
source "$ROOT/installer/platform.sh"
platform_defaults zynervoxv2
source "$ROOT/installer/isolated-env.sh"
[[ "${ID:-}" == rocky && "$WEB_ROOT" == /var/www/html/zynervoxv2 ]] || exit 2
source "$ZYNERVOX_CONFIG_DIR/zynervox-core-admin.env"
export ZYNERVOX_CORE_ADMIN_PASSWORD
php-fpm -t
httpd -t
python3 - <<'PY'
import http.cookiejar
import os
import urllib.error
import urllib.parse
import urllib.request

base = 'http://127.0.0.1/zynervoxv2/'
def request_status(path, headers=None):
    try:
        with urllib.request.urlopen(urllib.request.Request(base + path, headers=headers or {}), timeout=10) as response:
            return response.status
    except urllib.error.HTTPError as error:
        return error.code

assert request_status('index.php') == 200, 'login unavailable'
for path in ('agc/api.php', 'setup_db.php', 'config/deployment.php', 'includes/Database.php'):
    assert request_status(path) == 403, 'unprotected path: ' + path
assert request_status('agc/api.php', {'PHP_VALUE': 'auto_prepend_file='}) == 403, 'gate override accepted'
for password, expected in (('invalid-test-password', False), (os.environ['ZYNERVOX_CORE_ADMIN_PASSWORD'], True)):
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    body = urllib.parse.urlencode(dict(username='admin', password=password)).encode()
    with opener.open(base + 'index.php', body, timeout=15) as response:
        assert response.status == 200
        assert response.url.endswith('modules/admin/index.php') == expected, 'login outcome wrong'
    if expected:
        assert any(cookie.name == 'ZYNERVOXV2' and cookie.path == '/zynervoxv2/' for cookie in jar), 'session not isolated'
print('PASS: HTTP login, invalid password, scoped session, legacy/private denial and gate override denial')
PY
gate="$WEB_ROOT/includes/IsolatedGate.php"
restore_gate() { chmod 0640 "$gate"; systemctl reload php-fpm; }
trap restore_gate EXIT
chmod 0000 "$gate"
systemctl reload php-fpm
status="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1/zynervoxv2/index.php)"
[[ "$status" == 500 ]] || { echo "Gate ilegible no fallo cerrado: $status"; exit 1; }
restore_gate
trap - EXIT
[[ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1/zynervoxv2/index.php)" == 200 ]]
echo 'PASS: unreadable gate fails closed and login recovers'
