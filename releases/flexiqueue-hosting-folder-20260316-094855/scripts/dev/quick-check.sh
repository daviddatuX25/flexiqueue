#!/usr/bin/env bash
# Quick dev check: PHP syntax (php -l) + PHPUnit. Run from repo root: ./scripts/dev/quick-check.sh
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "$SCRIPT_DIR/common.sh"

if [ "$USE_SAIL" -eq 1 ]; then
  PHP_LINT=( "$PROJECT_ROOT/vendor/bin/sail" php )
else
  PHP_LINT=( php )
fi

echo "Running PHP syntax check..."
find app bootstrap config database routes tests -type f -name '*.php' -print0 2>/dev/null \
  | xargs -0 -n1 "${PHP_LINT[@]}" -l >/dev/null

echo "Running PHPUnit (artisan test)..."
artisan test

echo "Quick-check done."
