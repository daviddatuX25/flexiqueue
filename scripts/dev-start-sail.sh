#!/usr/bin/env bash
# dev-start-sail — start Sail + Vite + Reverb + queue in one terminal.
# Always: (1) bring Sail containers up, (2) sail npm run dev (+ Reverb + queue).
# Run from project root anytime to "start again". Requires: Sail, npm deps (concurrently).
set -e
cd "$(dirname "$0")/.."
sail=./vendor/bin/sail

echo "Starting Sail containers…"
"$sail" up -d

# Wait for Sail to be ready before starting dev (avoids race on first run)
echo "Waiting for Sail to be ready…"
sleep 3

echo "Starting dev stack (sail npm run dev + Reverb + queue)…"
uid="$(id -u)"
gid="$(id -g)"
npx concurrently -n vite,reverb,queue -c green,yellow,magenta \
  "$sail exec -u ${uid}:${gid} laravel.test npm run dev" \
  "$sail artisan reverb:start" \
  "$sail artisan queue:work" \
  --kill-others
