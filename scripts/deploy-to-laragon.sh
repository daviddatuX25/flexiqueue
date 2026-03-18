#!/usr/bin/env bash
# Deploy to Laragon/laptop: build (optional) and deploy.
# (1) Optionally build tarball from current directory (--build).
# (2) scp tarball to LARAGON_HOST, SSH and run scripts/laragon/apply-tarball.sh.
#
# Usage (from repo root):
#   LARAGON_HOST=laptop.local ./scripts/deploy-to-laragon.sh [--build] [--migrate=incremental|fresh|skip]
#   LARAGON_HOST=192.168.1.10 LARAGON_USER=user ./scripts/deploy-to-laragon.sh --build
#
# Requires: LARAGON_HOST set (or prompted). Target must have app dir and SSH (e.g. WSL).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

msg()    { echo "[FlexiQueue][deploy-laragon] $*" >&2; }
msg_ok() { echo "[FlexiQueue][deploy-laragon] ✓ $*" >&2; }

LARAGON_HOST="${LARAGON_HOST:-}"
LARAGON_USER="${LARAGON_USER:-root}"
LARAGON_APP_DIR="${LARAGON_APP_DIR:-/var/www/flexiqueue}"
BUILD=0
USE_SAIL=0
MIGRATE_ARG="incremental"

for arg in "$@"; do
  case "$arg" in
    --build) BUILD=1 ;;
    --sail) USE_SAIL=1 ;;
    --migrate=*) MIGRATE_ARG="${arg#--migrate=}" ;;
  esac
done

if ! git rev-parse --git-dir >/dev/null 2>&1; then
  msg "This script must be run inside the FlexiQueue git repository."
  exit 1
fi

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")"
msg "Current branch: ${CURRENT_BRANCH:-unknown}"

if [ "$BUILD" -eq 1 ]; then
  if [ "$USE_SAIL" -eq 1 ] || ! command -v php >/dev/null 2>&1; then
    docker_ok=0
    if command -v docker >/dev/null 2>&1; then
      docker version >/dev/null 2>&1 && docker_ok=1 || docker info >/dev/null 2>&1 && docker_ok=1
    fi
    if [ "$docker_ok" -eq 1 ]; then
      msg "Building tarball (Sail)..."
      ./scripts/build-deploy-tarball-sail.sh
    else
      msg "Building tarball (host)..."
      ./scripts/build-deploy-tarball.sh
    fi
  else
    msg "Building tarball (host)..."
    ./scripts/build-deploy-tarball.sh
  fi
fi

if [ ! -f "$REPO_ROOT/flexiqueue-deploy.tar.gz" ]; then
  msg "No flexiqueue-deploy.tar.gz in repo root. Run with --build."
  exit 1
fi

if [ ! -f "$REPO_ROOT/scripts/laragon/apply-tarball.sh" ]; then
  msg "scripts/laragon/apply-tarball.sh not found. Cannot deploy."
  exit 1
fi

msg_ok "Tarball ready."

if [ -z "$LARAGON_HOST" ]; then
  read -r -p "Laragon/laptop host (IP or hostname): " LARAGON_HOST
  LARAGON_HOST="$(echo "$LARAGON_HOST" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  if [ -z "$LARAGON_HOST" ]; then
    msg "No host given. Exiting."
    exit 1
  fi
fi

CONTROL="/tmp/fq-laragon-${LARAGON_USER}-${LARAGON_HOST}-$$"
cleanup_ssh() { ssh -S "$CONTROL" -O exit "${LARAGON_USER}@${LARAGON_HOST}" 2>/dev/null || true; rm -f "$CONTROL"; }
trap cleanup_ssh EXIT

msg "Connecting to ${LARAGON_USER}@${LARAGON_HOST}..."
ssh -M -S "$CONTROL" -o ControlPersist=120 "${LARAGON_USER}@${LARAGON_HOST}" true

msg "Copying tarball and apply script to ${LARAGON_HOST}..."
scp -o ControlPath="$CONTROL" "$REPO_ROOT/flexiqueue-deploy.tar.gz" "${LARAGON_USER}@${LARAGON_HOST}:/tmp/"
scp -o ControlPath="$CONTROL" "$REPO_ROOT/scripts/laragon/apply-tarball.sh" "${LARAGON_USER}@${LARAGON_HOST}:/tmp/fq-apply-tarball.sh"

msg "Applying on target (apply-tarball.sh --migrate=$MIGRATE_ARG)..."
ssh -t -o ControlPath="$CONTROL" "${LARAGON_USER}@${LARAGON_HOST}" "sudo LARAGON_APP_DIR=$LARAGON_APP_DIR bash /tmp/fq-apply-tarball.sh /tmp/flexiqueue-deploy.tar.gz --migrate=$MIGRATE_ARG"

msg_ok "Deploy to Laragon complete."
