#!/usr/bin/env bash
# dev-start-local — start Vite + Reverb + queue in one terminal (bare metal, no Sail).
# Run from project root. Requires: PHP, Composer, Node, npm deps (concurrently).
set -e
cd "$(dirname "$0")/.."

echo "Starting dev stack (Vite + Reverb + queue)…"
npx concurrently -n vite,reverb,queue -c green,yellow,magenta \
  "npm run dev" \
  "php artisan reverb:start" \
  "php artisan queue:work" \
  --kill-others
