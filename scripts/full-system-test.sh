#!/usr/bin/env bash
# FlexiQueue whole-system test — PHPUnit + Playwright E2E
#
# Prerequisites: Sail up, app serving (built assets or npm run dev)
# Run from project root: ./scripts/full-system-test.sh

set -e

cd "$(dirname "$0")/.."

echo "=== FlexiQueue full system test ==="
echo ""

echo "1. PHPUnit (backend)"
./vendor/bin/sail artisan test
echo ""

echo "2. Playwright E2E"
./vendor/bin/sail npx playwright test
echo ""

echo "Done. All tests passed."
