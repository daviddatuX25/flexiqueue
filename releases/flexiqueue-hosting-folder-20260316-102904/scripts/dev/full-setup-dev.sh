#!/usr/bin/env bash
# One-click dev setup: run setup.sh (composer, npm, .env, key, storage:link), then start the dev stack (Vite + Reverb + queue).
# Prefer Sail if available, else local. Run from repo root: ./scripts/dev/full-setup-dev.sh
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$PROJECT_ROOT"

# shellcheck source=common.sh
source "$SCRIPT_DIR/common.sh"

echo "=== FlexiQueue dev: full setup then start ==="
./scripts/dev/setup.sh

echo ""
echo "Starting dev stack (Vite + Reverb + queue)..."
if [ "$USE_SAIL" -eq 1 ]; then
  exec "$PROJECT_ROOT/scripts/dev-start-sail.sh"
else
  exec "$PROJECT_ROOT/scripts/dev-start-local.sh"
fi
