#!/usr/bin/env bash
# Clear Laravel caches in sequence. Run from repo root: ./scripts/dev/cache-clear.sh
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "$SCRIPT_DIR/common.sh"

echo "Clearing config cache..."
artisan config:clear
echo "Clearing route cache..."
artisan route:clear
echo "Clearing view cache..."
artisan view:clear
echo "Clearing application cache..."
artisan cache:clear
echo "Cache clear done."
