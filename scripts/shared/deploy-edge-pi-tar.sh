#!/usr/bin/env bash
set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

PI_HOST="${PI_HOST:-}"
PI_USER="${PI_USER:-root}"
BUILD=0
USE_SAIL=0
MIGRATE_ARG=""
for arg in "$@"; do
  case "$arg" in
    --build) BUILD=1 ;;
    --sail)  USE_SAIL=1 ;;
    --migrate=*) MIGRATE_ARG="${arg#--migrate=}" ;;
  esac
done

if [ -z "$PI_HOST" ]; then
  read -r -p "  Pi host (IP or hostname): " PI_HOST
  PI_HOST="$(echo "$PI_HOST" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  [ -z "$PI_HOST" ] && { echo "No host given. Exiting."; exit 1; }
fi

if [ "$BUILD" -eq 1 ]; then
  if [ "$USE_SAIL" -eq 0 ] && command -v php >/dev/null 2>&1; then
    ./scripts/edge/build/tar/build-edge-tarball.sh
  else
    ./scripts/edge/build/tar/build-edge-tarball-sail.sh
  fi
fi

[ -f flexiqueue-deploy.tar.gz ] || { echo "No flexiqueue-deploy.tar.gz. Run with --build."; exit 1; }
[ -f scripts/pi/apply-tarball.sh ] || { echo "Error: scripts/pi/apply-tarball.sh not found."; exit 1; }

CONTROL="/tmp/fq-deploy-${PI_USER}-${PI_HOST}-$$"
cleanup_ssh() { ssh -S "$CONTROL" -O exit "${PI_USER}@${PI_HOST}" 2>/dev/null || true; rm -f "$CONTROL"; }
trap cleanup_ssh EXIT

ssh -M -S "$CONTROL" -o ControlPersist=120 "${PI_USER}@${PI_HOST}" true
scp -o ControlPath="$CONTROL" flexiqueue-deploy.tar.gz "${PI_USER}@${PI_HOST}:/tmp/"
scp -o ControlPath="$CONTROL" scripts/pi/apply-tarball.sh "${PI_USER}@${PI_HOST}:/tmp/fq-apply-tarball.sh"

MIGRATE_OPT=""
if [ -n "$MIGRATE_ARG" ]; then
  MIGRATE_OPT="$MIGRATE_ARG"
elif [ -n "${DEPLOY_MIGRATE:-}" ]; then
  case "${DEPLOY_MIGRATE}" in
    1) MIGRATE_OPT="incremental" ;;
    2) MIGRATE_OPT="fresh" ;;
    3) MIGRATE_OPT="skip" ;;
    *) MIGRATE_OPT="incremental" ;;
  esac
fi
[ -z "$MIGRATE_OPT" ] && MIGRATE_OPT="incremental"

ssh -t -o ControlPath="$CONTROL" "${PI_USER}@${PI_HOST}" "sudo bash /tmp/fq-apply-tarball.sh /tmp/flexiqueue-deploy.tar.gz --migrate=$MIGRATE_OPT"
