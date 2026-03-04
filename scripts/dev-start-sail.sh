#!/usr/bin/env bash
# dev-start-sail — start Sail + Vite + Reverb + queue in one terminal.
# Run from project root. Requires: Sail, npm deps (concurrently).
set -e
cd "$(dirname "$0")/.."
sail=./vendor/bin/sail

echo "Starting Sail containers…"
"$sail" up -d

echo "Starting dev stack (Vite + Reverb + queue)…"
uid="$(id -u)"
gid="$(id -g)"
npx concurrently -n vite,reverb,queue -c green,yellow,magenta \
  "$sail exec -u ${uid}:${gid} laravel.test npm run dev" \
  "$sail artisan reverb:start" \
  "$sail artisan queue:work" \
  --kill-others
