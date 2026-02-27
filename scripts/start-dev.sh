#!/usr/bin/env bash
# FlexiQueue — start Sail + Reverb in one run
#
# Brings up containers and starts the Reverb server (foreground).
# Stop with Ctrl+C; containers stay up. For queue worker run in another terminal:
#   ./vendor/bin/sail artisan queue:work
#
# Run from project root: ./scripts/start-dev.sh

set -e

cd "$(dirname "$0")/.."

echo "=== FlexiQueue dev startup ==="
echo ""

echo "[1/2] Starting Sail containers..."
./vendor/bin/sail up -d

echo ""
echo "[2/2] Waiting for app container..."
for i in 1 2 3 4 5 6 7 8 9 10; do
  if ./vendor/bin/sail ps 2>/dev/null | grep -q 'laravel.test.*Up'; then
    break
  fi
  sleep 1
done

echo ""
echo "Starting Reverb (stop with Ctrl+C; Sail keeps running)..."
echo "If you see 'Address already in use', Reverb may already be running in another terminal."
echo ""

exec ./vendor/bin/sail artisan reverb:start
