#!/usr/bin/env bash
# One-command deploy: (optionally) build tarball, scp to Pi, SSH and apply.
# Fully interactive: prompts for host, build tarball, then choose migrate --force or migrate:fresh --seed --force.
# Use when you're remote or want to streamline updates.
#
# Usage (from repo root):
#   PI_HOST=orangepi.local ./scripts/deploy-to-pi.sh              # use existing tarball
#   PI_HOST=192.168.1.50 ./scripts/deploy-to-pi.sh --build        # build then deploy
#   PI_HOST=... DEPLOY_MIGRATE=1 ./scripts/deploy-to-pi.sh --build   # non-interactive: 1=incremental, 2=fresh+seed, 3=skip
#   PI_HOST=... ./scripts/deploy-to-pi.sh --build --migrate=incremental|fresh|skip
#
# Requires: flexiqueue-deploy.tar.gz in repo root (or run with --build).
# Pi must have: /var/www/flexiqueue (and database/database.sqlite for SQLite). If .env is missing, it is created from .env.edge in the tarball on first deploy.

set -e
cd "$(dirname "$0")/.."

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

# Interactive host prompt when PI_HOST is not provided
if [ -z "$PI_HOST" ]; then
  echo ""
  echo "  FlexiQueue — Deploy to Pi"
  echo "  —————————————————————————"
  read -r -p "  Pi host (IP or hostname): " PI_HOST
  PI_HOST="$(echo "$PI_HOST" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  if [ -z "$PI_HOST" ]; then
    echo "No host given. Exiting."
    exit 1
  fi
fi

# Decide whether to build tarball
if [ "$BUILD" -eq 0 ]; then
  if [ -f flexiqueue-deploy.tar.gz ]; then
    # Ask user if they want to rebuild
    read -r -p "  Build tarball first? [y/N]: " do_build
    case "${do_build^^}" in
      Y|YES) BUILD=1 ;;
      *) BUILD=0 ;;
    esac
  else
    echo "No existing flexiqueue-deploy.tar.gz; will build first."
    BUILD=1
  fi
fi

if [ "$BUILD" -eq 1 ]; then
  # Prefer Sail when --sail or when host PHP is missing (e.g. WSL without PHP)
  if [ "$USE_SAIL" -eq 0 ] && command -v php >/dev/null 2>&1; then
    echo "Building tarball (host)..."
    ./scripts/build-deploy-tarball.sh
  elif [ "$USE_SAIL" -eq 1 ] || ! command -v php >/dev/null 2>&1; then
    # Try docker version (lighter) first; docker info can hang on WSL
    docker_ok=0
    if command -v docker >/dev/null 2>&1; then
      if docker version >/dev/null 2>&1; then
        docker_ok=1
      elif docker info >/dev/null 2>&1; then
        docker_ok=1
      fi
    fi
    if [ "$docker_ok" -eq 1 ]; then
      echo "Building tarball (Sail/Docker)..."
      ./scripts/build-deploy-tarball-sail.sh
    else
      echo "ERROR: Host PHP not found and Docker is not reachable."
      echo "  - Docker installed? $(command -v docker 2>/dev/null || echo 'No')"
      echo "  - Docker reachable? $(docker version 2>&1 | head -3 || docker info 2>&1 | head -3)"
      echo "  Options: 1) Start Docker Desktop (WSL) and run: ./scripts/deploy-to-pi.sh --build --sail"
      echo "           2) Install PHP in WSL: sudo apt install php php-cli php-mbstring php-xml php-curl php-zip unzip"
      exit 1
    fi
  fi
else
  echo "Using existing flexiqueue-deploy.tar.gz (run with --build to rebuild first)."
fi

if [ ! -f flexiqueue-deploy.tar.gz ]; then
  echo "No flexiqueue-deploy.tar.gz. Run with --build or create it first."
  exit 1
fi

if [ ! -f scripts/pi/apply-tarball.sh ]; then
  echo "Error: scripts/pi/apply-tarball.sh not found. Cannot deploy."
  exit 1
fi

# Reuse one SSH connection so we only ask for password once (ControlMaster)
CONTROL="/tmp/fq-deploy-${PI_USER}-${PI_HOST}-$$"
cleanup_ssh() { ssh -S "$CONTROL" -O exit "${PI_USER}@${PI_HOST}" 2>/dev/null || true; rm -f "$CONTROL"; }
trap cleanup_ssh EXIT

echo "Connecting to ${PI_USER}@${PI_HOST} (one password for all steps)..."
ssh -M -S "$CONTROL" -o ControlPersist=120 "${PI_USER}@${PI_HOST}" true

echo "Copying tarball and apply script to ${PI_USER}@${PI_HOST}..."
scp -o ControlPath="$CONTROL" flexiqueue-deploy.tar.gz "${PI_USER}@${PI_HOST}:/tmp/"
scp -o ControlPath="$CONTROL" scripts/pi/apply-tarball.sh "${PI_USER}@${PI_HOST}:/tmp/fq-apply-tarball.sh"

# Determine migrate option: DEPLOY_MIGRATE (1=incremental, 2=fresh, 3=skip), or --migrate=, or interactive prompt
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

if [ -z "$MIGRATE_OPT" ]; then
  echo ""
  echo "  Database (choose one):"
  echo "    1) migrate --force (incremental; keep existing data)"
  echo "    2) migrate:fresh --seed --force (start from scratch; DROP all tables)"
  echo "    3) Skip (do not run migrate)"
  read -r -p "  Choice [1-3] (default 1): " post_choice
  post_choice="$(echo "${post_choice:-1}" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  case "$post_choice" in
    1) MIGRATE_OPT="incremental" ;;
    2)
      read -r -p "  Type 'yes' to DROP ALL TABLES and seed: " confirm
      if [ "$confirm" = "yes" ]; then
        MIGRATE_OPT="fresh"
      else
        MIGRATE_OPT="skip"
      fi
      ;;
    *) MIGRATE_OPT="skip" ;;
  esac
fi

echo "Applying on Pi (apply-tarball.sh --migrate=$MIGRATE_OPT)..."
ssh -t -o ControlPath="$CONTROL" "${PI_USER}@${PI_HOST}" "sudo bash /tmp/fq-apply-tarball.sh /tmp/flexiqueue-deploy.tar.gz --migrate=$MIGRATE_OPT"

echo ""
echo "All done. App updated at ${PI_HOST}."
