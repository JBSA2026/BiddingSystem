#!/bin/sh
# Runs the automated end-to-end suite against an ISOLATED database and a temporary PHP web server.
# Your manual test data is not touched.
#
#   Docker:      docker compose exec app sh tests/run-e2e.sh
#   Local PHP:   E2E_DB_HOST=127.0.0.1 E2E_DB_USER=root E2E_DB_PASS=secret sh tests/run-e2e.sh
set -e
cd "$(dirname "$0")/.."
export E2E_DB_HOST="${E2E_DB_HOST:-db}"
export E2E_DB_PORT="${E2E_DB_PORT:-3306}"
export E2E_DB_USER="${E2E_DB_USER:-root}"
export E2E_DB_PASS="${E2E_DB_PASS:-rootpass}"
export E2E_DB_NAME="${E2E_DB_NAME:-cityland_e2e}"
export E2E_PORT="${E2E_PORT:-8099}"
export E2E_BASE="http://127.0.0.1:${E2E_PORT}"
WORK="${TMPDIR:-/tmp}/cityland-e2e"
rm -rf "$WORK"; mkdir -p "$WORK"
export E2E_STORAGE="$WORK/storage"
export CL_CONFIG="$WORK/config.php"

php tests/setup-e2e.php
php -S "127.0.0.1:${E2E_PORT}" -t . > "$WORK/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
sleep 1
php tests/e2e.php
