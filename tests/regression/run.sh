#!/usr/bin/env bash
# One command for the Magento extension's automated tests:  bash tests/regression/run.sh
# Runs inside the local Magento PHP container (default magento-php-1, override with MAGENTO_CONTAINER). Checks: every PHP file compiles, every
# extension class loads and is compatible with the installed Magento (tools/compat-check.php), the log rotator works.
# Prints PASS/FAIL lines and "RESULT passed=N failed=M"; exit code 1 when anything failed.
set -u
export MSYS_NO_PATHCONV=1
HERE="$(cd "$(dirname "$0")/../.." && pwd)"
command -v cygpath >/dev/null 2>&1 && HERE="$(cygpath -m "$HERE")"   # docker.exe needs a Windows-style path on Windows
C="${MAGENTO_CONTAINER:-magento-php-1}"
pass=0; fail=0
ok()  { echo "PASS  $1"; pass=$((pass+1)); }
bad() { echo "FAIL  $1"; [ -n "${2:-}" ] && echo "$2" | sed 's/^/        /' | head -15; fail=$((fail+1)); }
docker exec "$C" true 2>/dev/null || { echo "FAIL  container $C is not running (start the test stack first: scripts/start-test-stack.sh)"; echo "RESULT passed=0 failed=1"; exit 1; }
# the module without tools/ and tests/ (they are not module classes), the tools and the tests next to it
docker exec "$C" rm -rf /tmp/fvgmod /tmp/fvgtools && docker exec "$C" mkdir -p /tmp/fvgtools
docker cp "$HERE" "$C:/tmp/fvgmod" >/dev/null
docker exec "$C" sh -c 'rm -rf /tmp/fvgtools/* && cp -r /tmp/fvgmod/tools /tmp/fvgmod/tests /tmp/fvgtools/ && rm -rf /tmp/fvgmod/tools /tmp/fvgmod/tests /tmp/fvgmod/.git'

out=$(docker exec "$C" sh -c 'cd /tmp/fvgmod || { echo "copy into the container failed"; exit 1; }; find . -name "*.php" | while read f; do php -l "$f" 2>&1 | grep -v "^No syntax errors"; done')
[ -z "$out" ] && ok "every PHP file compiles" || bad "every PHP file compiles" "$out"

out=$(docker exec -e AUTOLOAD=/var/www/html/vendor/autoload.php -e MODULE=/tmp/fvgmod "$C" php -d error_reporting=24575 /tmp/fvgtools/tools/compat-check.php 2>&1); rc=$?
echo "$out" | tail -n 3 | grep -qi "fail" && rc=1
[ $rc -eq 0 ] && ok "classes load and match the installed Magento's interfaces ($(echo "$out" | tail -n 1))" || bad "compatibility with the installed Magento" "$out"

out=$(docker exec "$C" php -r 'json_decode(file_get_contents("/tmp/fvgmod/composer.json"), true); echo json_last_error() === JSON_ERROR_NONE ? "" : json_last_error_msg();')
[ -z "$out" ] && ok "composer.json is valid JSON (bin/magento module:disable and Composer read it)" || bad "composer.json is valid JSON" "$out"

out=$(docker exec "$C" php /tmp/fvgtools/tests/log-rotator-test.php /tmp/fvgmod/Logger/LogRotator.php 'Flipick\VideoGenerator\Logger\LogRotator' 2>&1); rc=$?
[ $rc -eq 0 ] && ok "log rotator (rotate, zip, prune)" || bad "log rotator" "$out"

out=$(docker exec -e AUTOLOAD=/var/www/html/vendor/autoload.php -e MODULE=/tmp/fvgmod "$C" php -d error_reporting=24575 /tmp/fvgtools/tests/catalog-change-test.php 2>&1); rc=$?
[ $rc -eq 0 ] && ok "catalog change signal (queue, scheduled call with back-off, observer never throws): $(echo "$out" | tail -n 1)" || bad "catalog change signal" "$(echo "$out" | grep -E 'FAIL|Fatal|Error' | head -8)"

echo "RESULT passed=$pass failed=$fail"
[ $fail -eq 0 ]
