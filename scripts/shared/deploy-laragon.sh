#!/usr/bin/env bash
# Shared Laragon deploy implementation with mode switch.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

msg()    { echo "[FlexiQueue][deploy-laragon] $*" >&2; }
msg_ok() { echo "[FlexiQueue][deploy-laragon] ✓ $*" >&2; }

LARAGON_HOST="${LARAGON_HOST:-}"
LARAGON_USER="${LARAGON_USER:-root}"
LARAGON_APP_DIR="${LARAGON_APP_DIR:-/var/www/flexiqueue}"
MODE="edge"
BUILD=0
USE_SAIL=0
MIGRATE_ARG="incremental"

usage() {
  cat <<'EOF'
Usage:
  LARAGON_HOST=host ./scripts/shared/deploy-laragon.sh [--mode=central|edge] [--build] [--sail] [--migrate=incremental|fresh|skip]

Options:
  --mode=...     Deploy mode: central or edge (default: edge)
  --build        Build flexiqueue-deploy.tar.gz before deploy
  --sail         Prefer Sail build when --build is used
  --migrate=...  incremental|fresh|skip (default: incremental)
EOF
}

for arg in "$@"; do
  case "$arg" in
    --build) BUILD=1 ;;
    --sail) USE_SAIL=1 ;;
    --mode=*) MODE="${arg#--mode=}" ;;
    --migrate=*) MIGRATE_ARG="${arg#--migrate=}" ;;
    --help|-h) usage; exit 0 ;;
    *)
      msg "Unknown argument: $arg"
      usage
      exit 1
      ;;
  esac
done

if [ "$MODE" != "central" ] && [ "$MODE" != "edge" ]; then
  msg "Invalid mode '$MODE'. Use --mode=central or --mode=edge."
  exit 1
fi

if [ "$BUILD" -eq 1 ]; then
  if [ "$USE_SAIL" -eq 1 ] || ! command -v php >/dev/null 2>&1; then
    docker_ok=0
    if command -v docker >/dev/null 2>&1; then
      docker version >/dev/null 2>&1 && docker_ok=1 || docker info >/dev/null 2>&1 && docker_ok=1
    fi
    if [ "$docker_ok" -eq 1 ]; then
      msg "Building tarball (Sail)..."
      "$REPO_ROOT/scripts/edge/build/tar/build-edge-tarball-sail.sh"
    else
      msg "Building tarball (host)..."
      "$REPO_ROOT/scripts/edge/build/tar/build-edge-tarball.sh"
    fi
  else
    msg "Building tarball (host)..."
    "$REPO_ROOT/scripts/edge/build/tar/build-edge-tarball.sh"
  fi
fi

if [ ! -f "$REPO_ROOT/flexiqueue-deploy.tar.gz" ]; then
  msg "No flexiqueue-deploy.tar.gz in repo root. Run with --build."
  exit 1
fi

if [ -z "$LARAGON_HOST" ]; then
  read -r -p "Laragon/laptop host (IP or hostname): " LARAGON_HOST
  LARAGON_HOST="$(echo "$LARAGON_HOST" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  if [ -z "$LARAGON_HOST" ]; then
    msg "No host given. Exiting."
    exit 1
  fi
fi

APPLY_SCRIPT_REMOTE="/tmp/fq-apply-tarball.sh"
if [ "$MODE" = "central" ]; then
  APPLY_SCRIPT_LOCAL="$REPO_ROOT/scripts/laragon/apply-tarball-central.sh"
else
  APPLY_SCRIPT_LOCAL="$REPO_ROOT/scripts/laragon/apply-tarball-edge.sh"
fi

CONTROL="/tmp/fq-laragon-${LARAGON_USER}-${LARAGON_HOST}-$$"
cleanup_ssh() { ssh -S "$CONTROL" -O exit "${LARAGON_USER}@${LARAGON_HOST}" 2>/dev/null || true; rm -f "$CONTROL"; }
trap cleanup_ssh EXIT

msg "Connecting to ${LARAGON_USER}@${LARAGON_HOST}..."
ssh -M -S "$CONTROL" -o ControlPersist=120 "${LARAGON_USER}@${LARAGON_HOST}" true

msg "Copying tarball and $MODE apply script to ${LARAGON_HOST}..."
scp -o ControlPath="$CONTROL" "$REPO_ROOT/flexiqueue-deploy.tar.gz" "${LARAGON_USER}@${LARAGON_HOST}:/tmp/"
scp -o ControlPath="$CONTROL" "$APPLY_SCRIPT_LOCAL" "${LARAGON_USER}@${LARAGON_HOST}:${APPLY_SCRIPT_REMOTE}"

msg "Applying on target ($MODE mode, --migrate=$MIGRATE_ARG)..."
ssh -t -o ControlPath="$CONTROL" "${LARAGON_USER}@${LARAGON_HOST}" "sudo LARAGON_APP_DIR=$LARAGON_APP_DIR bash ${APPLY_SCRIPT_REMOTE} /tmp/flexiqueue-deploy.tar.gz --migrate=$MIGRATE_ARG"

msg_ok "Deploy to Laragon complete (mode: $MODE)."
