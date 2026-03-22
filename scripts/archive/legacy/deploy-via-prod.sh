#!/usr/bin/env bash
# Legacy wrapper preserved for older workflows.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
echo "[FlexiQueue][legacy] deploy-via-prod.sh is archived. Forwarding to deploy-via-prod-to-pi.sh." >&2
exec "$REPO_ROOT/scripts/archive/legacy/deploy-via-prod-to-pi.sh" "$@"
