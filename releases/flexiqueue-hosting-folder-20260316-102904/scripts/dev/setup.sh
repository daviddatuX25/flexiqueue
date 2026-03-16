#!/usr/bin/env bash
# First-time or reset setup: composer install, npm install, optional key:generate, storage:link.
# Run from repo root: ./scripts/dev/setup.sh  or  USE_SAIL=1 ./scripts/dev/setup.sh
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "$SCRIPT_DIR/common.sh"

if [ "$USE_SAIL" -eq 1 ]; then
  echo "Using Sail for PHP/Composer/npm."
else
  echo "Using local PHP/Composer/npm (Sail not detected or USE_SAIL=0)."
fi

echo "Running Composer install..."
composer_cmd install --no-interaction

echo "Running npm install..."
npm_cmd install

if [ ! -f .env ] && [ -f .env.example ]; then
  echo "Creating .env from .env.example..."
  cp .env.example .env
fi

if [ -f .env ]; then
  if ! grep -q '^APP_KEY=.\+' .env 2>/dev/null; then
    echo "Generating APP_KEY..."
    artisan key:generate
  fi
fi

echo "Running storage:link..."
artisan storage:link 2>/dev/null || true

echo "Dev setup complete."
