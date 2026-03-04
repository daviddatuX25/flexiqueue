#!/usr/bin/env bash
# Shared helpers for scripts/dev/*.sh. Source from each script: source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$PROJECT_ROOT"

if [ "${USE_SAIL:-0}" = "1" ]; then
  USE_SAIL=1
elif [ -x "$PROJECT_ROOT/vendor/bin/sail" ]; then
  USE_SAIL=1
else
  USE_SAIL=0
fi

sail_exec_user() {
  # Avoid root-owned files on the host when running commands in the container.
  # This matters for Vite build output (`public/build`) and node_modules temp files.
  "$PROJECT_ROOT/vendor/bin/sail" exec -u "$(id -u):$(id -g)" laravel.test "$@"
}

artisan() {
  if [ "$USE_SAIL" -eq 1 ]; then
    "$PROJECT_ROOT/vendor/bin/sail" artisan "$@"
  else
    php artisan "$@"
  fi
}

composer_cmd() {
  if [ "$USE_SAIL" -eq 1 ]; then
    sail_exec_user composer "$@"
  else
    composer "$@"
  fi
}

npm_cmd() {
  if [ "$USE_SAIL" -eq 1 ]; then
    sail_exec_user npm "$@"
  else
    npm "$@"
  fi
}

php_cmd() {
  if [ "$USE_SAIL" -eq 1 ]; then
    "$PROJECT_ROOT/vendor/bin/sail" php "$@"
  else
    php "$@"
  fi
}
