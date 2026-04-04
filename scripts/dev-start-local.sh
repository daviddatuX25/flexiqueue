#!/usr/bin/env bash
# dev-start-local — start local app stack in one terminal (no Sail).
# Starts: Vite + Reverb + queue worker (Laravel app served by Laragon/Nginx/Apache).
set -euo pipefail
cd "$(dirname "$0")/.."

need_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Error: '$1' is required but not found in PATH." >&2
        exit 1
    fi
}

need_cmd php
need_cmd npm
need_cmd npx

if [ ! -f artisan ]; then
    echo "Error: artisan not found. Run this from the project root." >&2
    exit 1
fi

if [ ! -d vendor ]; then
    echo "Error: vendor missing. Run: composer install" >&2
    exit 1
fi

if [ ! -d node_modules ]; then
    echo "Error: node_modules missing. Run: npm install" >&2
    exit 1
fi

APP_URL_VALUE="$(php -r 'echo getenv("APP_URL") ?: "http://flexiqueue.test";' 2>/dev/null || echo "http://flexiqueue.test")"
echo "Starting local dev stack (vite + reverb + queue)..."
echo "App URL (Laragon): ${APP_URL_VALUE}"
echo "Tip: if queue exits, check DB/session tables with: php artisan migrate"

npx concurrently \
  --names vite,reverb,queue \
  --prefix "[{name}]" \
  --prefix-colors "green,yellow,magenta" \
  --restart-tries 3 \
  --kill-others-on-fail \
  "npm run dev" \
  "php artisan reverb:start" \
  "php artisan queue:listen --tries=1 --timeout=0"
