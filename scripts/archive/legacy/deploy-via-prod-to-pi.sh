#!/usr/bin/env bash
# Legacy script kept for backward compatibility.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
echo "[FlexiQueue][legacy] deploy-via-prod-to-pi.sh is archived; use scripts/edge/deploy/pi/deploy-edge-pi-tar.sh." >&2
exec "$REPO_ROOT/scripts/edge/deploy/pi/deploy-edge-pi-tar.sh" "$@"
