#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
echo "[FlexiQueue][legacy] build-deploy-zip-current.sh is archived; use scripts/central/build/build-central-hosting.sh or scripts/edge/build/tar/." >&2
exec "$REPO_ROOT/scripts/central/build/build-central-hosting.sh" "$@"
