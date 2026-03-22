#!/usr/bin/env bash
set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

PI_HOST="${PI_HOST:-}"
PI_USER="${PI_USER:-root}"
CENTRAL_URL="${CENTRAL_URL:-}"
CENTRAL_API_KEY="${CENTRAL_API_KEY:-}"
SITE_ID="${SITE_ID:-}"
BUILD=0
USE_SAIL=0
MIGRATE_ARG=""
IMPORT_PROGRAM_ID="${EDGE_PROGRAM_ID:-}"
DO_IMPORT=""

for arg in "$@"; do
  case "$arg" in
    --build) BUILD=1 ;;
    --sail)  USE_SAIL=1 ;;
    --no-sail) USE_SAIL=0 ;;
    --migrate=*) MIGRATE_ARG="${arg#--migrate=}" ;;
    --import=*) IMPORT_PROGRAM_ID="${arg#--import=}"; DO_IMPORT="yes" ;;
    --no-import) DO_IMPORT="skip" ;;
  esac
done

[ -z "$PI_HOST" ] && { read -r -p "  Pi host: " PI_HOST; }
[ -z "$PI_HOST" ] && { echo "No host given."; exit 1; }

if [ "$BUILD" -eq 1 ]; then
  if [ "$USE_SAIL" -eq 0 ] && command -v php >/dev/null 2>&1; then
    ./scripts/edge/build/tar/build-edge-tarball.sh
  else
    ./scripts/edge/build/tar/build-edge-tarball-sail.sh
  fi
fi

[ -f flexiqueue-deploy.tar.gz ] || { echo "No flexiqueue-deploy.tar.gz."; exit 1; }
[ -f scripts/pi/apply-tarball.sh ] || { echo "Missing scripts/pi/apply-tarball.sh."; exit 1; }

ENV_EDGE_FILE=""
[ -f ".env.edge" ] && ENV_EDGE_FILE=".env.edge"
[ -z "$ENV_EDGE_FILE" ] && [ -f "env.edge" ] && ENV_EDGE_FILE="env.edge"
trim() { echo "$1" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'; }
read_env_value() {
  local key="$1"
  grep -E "^${key}=" "$ENV_EDGE_FILE" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'"
}
if [ -n "$ENV_EDGE_FILE" ]; then
  [ -z "$CENTRAL_URL" ] && CENTRAL_URL="$(trim "$(read_env_value "CENTRAL_URL")")"
  [ -z "$CENTRAL_API_KEY" ] && CENTRAL_API_KEY="$(trim "$(read_env_value "CENTRAL_API_KEY")")"
  [ -z "$SITE_ID" ] && SITE_ID="$(trim "$(read_env_value "SITE_ID")")"
fi
[ -z "$CENTRAL_URL" ] && CENTRAL_URL="https://flexiqueue.click"
[ -z "$CENTRAL_API_KEY" ] && { echo "CENTRAL_API_KEY required."; exit 1; }
[ -z "$SITE_ID" ] && SITE_ID="2"

CONTROL="/tmp/fq-edge-deploy-${PI_USER}-${PI_HOST}-$$"
cleanup_ssh() { ssh -S "$CONTROL" -O exit "${PI_USER}@${PI_HOST}" 2>/dev/null || true; rm -f "$CONTROL"; }
trap cleanup_ssh EXIT
ssh -M -S "$CONTROL" -o ControlPersist=120 "${PI_USER}@${PI_HOST}" true
scp -o ControlPath="$CONTROL" flexiqueue-deploy.tar.gz "${PI_USER}@${PI_HOST}:/tmp/"
scp -o ControlPath="$CONTROL" scripts/pi/apply-tarball.sh "${PI_USER}@${PI_HOST}:/tmp/fq-apply-tarball.sh"

MIGRATE_OPT="${MIGRATE_ARG:-incremental}"
ssh -t -o ControlPath="$CONTROL" "${PI_USER}@${PI_HOST}" "sudo bash /tmp/fq-apply-tarball.sh /tmp/flexiqueue-deploy.tar.gz --migrate=$MIGRATE_OPT"

if [ -n "$ENV_EDGE_FILE" ]; then
  scp -o ControlPath="$CONTROL" "$ENV_EDGE_FILE" "${PI_USER}@${PI_HOST}:/tmp/fq-env-edge"
  ssh -o ControlPath="$CONTROL" "${PI_USER}@${PI_HOST}" "sudo cp /tmp/fq-env-edge /var/www/flexiqueue/.env && sudo chown www-data:www-data /var/www/flexiqueue/.env && rm /tmp/fq-env-edge"
fi

ssh -o ControlPath="$CONTROL" "${PI_USER}@${PI_HOST}" "sudo sed -i 's/^APP_MODE=.*/APP_MODE=edge/' /var/www/flexiqueue/.env || true"

if [ "$DO_IMPORT" = "yes" ] && [ -n "$IMPORT_PROGRAM_ID" ]; then
  ssh -t -o ControlPath="$CONTROL" "${PI_USER}@${PI_HOST}" "cd /var/www/flexiqueue && sudo -u www-data php artisan edge:import-package --program=${IMPORT_PROGRAM_ID}"
fi
