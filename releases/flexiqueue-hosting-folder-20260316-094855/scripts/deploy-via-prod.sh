#!/usr/bin/env bash
# DEPRECATED: Use deploy-via-prod-to-pi.sh (for Pi) or deploy-to-laragon.sh (for Laragon/laptop).
# This script now forwards to deploy-via-prod-to-pi.sh so existing usage keeps working.
#
#   Pi:     PI_HOST=... ./scripts/deploy-via-prod-to-pi.sh --build
#   Laragon: LARAGON_HOST=... ./scripts/deploy-to-laragon.sh --build

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
echo "[FlexiQueue][via-prod] DEPRECATED: Use deploy-via-prod-to-pi.sh or deploy-to-laragon.sh. Forwarding to deploy-via-prod-to-pi.sh." >&2
exec "$SCRIPT_DIR/deploy-via-prod-to-pi.sh" "$@"
