#!/usr/bin/env bash
# Local end-to-end test: MariaDB + PHP built-in server + mock Stripe + Playwright.
# Assumes a local MariaDB/MySQL with database familieboger_test and user fb/fbpass@127.0.0.1.
set -euo pipefail
cd "$(dirname "$0")/.."

mysql familieboger_test < database/schema.sql
mysql familieboger_test -e "TRUNCATE orders; TRUNCATE waitlist;"
rm -f "$(php -r 'echo sys_get_temp_dir();')/fb-mock-stripe.json"

php -S 127.0.0.1:12111 tests/mock_stripe.php > /tmp/fb-mock.log 2>&1 & MOCK=$!
FB_CONFIG="$PWD/tests/config.test.php" php -S 127.0.0.1:8080 -t public_html > /tmp/fb-site.log 2>&1 & SITE=$!
trap 'kill $MOCK $SITE 2>/dev/null || true' EXIT
sleep 1

php tests/start_dates_test.php
node tests/e2e.js
