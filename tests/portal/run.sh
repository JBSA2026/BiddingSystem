#!/bin/sh
# Tests the vendor renewal portal (license-portal/) against a mock PayMongo API. Needs php (curl, pdo_sqlite, sodium).
set -e
cd "$(dirname "$0")/../.."
W="${TMPDIR:-/tmp}/cityland-portal-test"; rm -rf "$W"; mkdir -p "$W"
php license-tools/generate.php keygen --out="$W" >/dev/null
export MOCK_STORE="$W/mock.json" LP_WORK="$W" MOCK_BASE="http://127.0.0.1:8201" LP_BASE="http://127.0.0.1:8202"
cat > "$W/config.php" <<PHP
<?php
\$c = require '$(pwd)/license-portal/config.sample.php';
\$c['portal_url'] = '$LP_BASE';
\$c['data_dir'] = '$W/data';
\$c['signing_key_file'] = '$W/cityland-license-signing-key.json';
\$c['public_keys'] = [];
\$c['vendor_email'] = '';
\$c['paymongo']['api_base'] = '$MOCK_BASE';
\$c['paymongo']['secret_key'] = 'sk_test_mock';
\$c['paymongo']['webhook_secret'] = 'whsk_test';
return \$c;
PHP
export LP_CONFIG="$W/config.php"
php -S 127.0.0.1:8201 tests/portal/mock-paymongo.php >"$W/mock.log" 2>&1 & M=$!
php -S 127.0.0.1:8202 -t license-portal >"$W/portal.log" 2>&1 & P=$!
trap 'kill $M $P 2>/dev/null || true' EXIT
sleep 1
grep -q 'config' license-portal/.htaccess && grep -q 'Require all denied' license-portal/data/.htaccess
php tests/portal/portal-test.php
