#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
test_directory="$(mktemp -d)"
source_directory="$test_directory/source"
destination_directory="$test_directory/destination"
mkdir -p "$source_directory/config" "$source_directory/runtime" "$destination_directory/config" "$destination_directory/runtime"
printf 'initial' > "$source_directory/file with spaces.php"
printf 'unchanged' > "$source_directory/other.php"
printf 'source' > "$source_directory/config/reporting_mirror.json"
printf 'source-runtime' > "$source_directory/runtime/state.json"
printf 'local-config' > "$destination_directory/config/reporting_mirror.json"
printf 'local-runtime' > "$destination_directory/runtime/state.json"
printf 'local-only' > "$destination_directory/local.txt"
deploy() { bash "$ROOT/installer/deploy-web.sh" "$source_directory" "$destination_directory"; }
deploy > "$test_directory/first.log"
cmp "$source_directory/file with spaces.php" "$destination_directory/file with spaces.php"
[[ "$(stat -c %Y "$source_directory/other.php")" == "$(stat -c %Y "$destination_directory/other.php")" ]]
deploy > "$test_directory/second.log"
if grep -q '^>f' "$test_directory/second.log"; then echo 'Se retransfirieron archivos intactos' >&2; exit 1; fi
printf 'changed-content' > "$source_directory/file with spaces.php"
deploy > "$test_directory/third.log"
[[ "$(grep -c '^>f' "$test_directory/third.log")" == 1 ]]
cmp "$source_directory/file with spaces.php" "$destination_directory/file with spaces.php"
printf 'new' > "$source_directory/new.php"
deploy > "$test_directory/fourth.log"
[[ "$(grep -c '^>f' "$test_directory/fourth.log")" == 1 ]]
[[ "$(cat "$destination_directory/config/reporting_mirror.json")" == local-config ]]
[[ "$(cat "$destination_directory/runtime/state.json")" == local-runtime ]]
[[ "$(cat "$destination_directory/local.txt")" == local-only ]]
printf 'different' > "$source_directory/other.php"
touch -m -d '2030-01-01' "$source_directory/other.php"
deploy > "$test_directory/same-size.log"
[[ "$(grep -c '^>f' "$test_directory/same-size.log")" == 1 ]]
cmp "$source_directory/other.php" "$destination_directory/other.php"
rm "$source_directory/new.php"
deploy > "$test_directory/removed.log"
[[ -f "$destination_directory/new.php" ]]
if bash "$ROOT/installer/deploy-web.sh" "$source_directory" "$source_directory/nested" >/dev/null 2>&1; then exit 1; fi
echo 'PASS: primera copia, segunda sin transferencias, un cambio, archivo nuevo y datos locales conservados'
