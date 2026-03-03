#!/usr/bin/env bash
# One-command deploy: (optionally) build tarball, scp to Pi, SSH and apply.
# Fully interactive: prompts for host, build tarball, then post-deploy (seed / migrate:fresh --seed).
# Use when you're remote or want to streamline updates.
#
# Usage (from repo root):
#   PI_HOST=orangepi.local ./scripts/deploy-to-pi.sh              # use existing tarball
#   PI_HOST=192.168.1.50 ./scripts/deploy-to-pi.sh --build        # build then deploy
#   PI_HOST=orangepi.local PI_USER=root ./scripts/deploy-to-pi.sh --build
#
# Requires: flexiqueue-deploy.tar.gz in repo root (or run with --build).
# Pi must have: /var/www/flexiqueue (and database/database.sqlite for SQLite). If .env is missing, it is created from .env.prod in the tarball on first deploy.

set -e
cd "$(dirname "$0")/.."

PI_HOST="${PI_HOST:-}"
PI_USER="${PI_USER:-root}"
BUILD=0
USE_SAIL=0
for arg in "$@"; do
  case "$arg" in
    --build) BUILD=1 ;;
    --sail)  USE_SAIL=1 ;;
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

echo "Copying tarball to ${PI_USER}@${PI_HOST}..."
scp flexiqueue-deploy.tar.gz "${PI_USER}@${PI_HOST}:/tmp/"

echo "Applying on Pi (extract, chown, storage + database writable, force SQLite env, migrate with schema repair, cache, storage:link)..."
ssh "${PI_USER}@${PI_HOST}" 'cd /var/www/flexiqueue && sudo tar -xzf /tmp/flexiqueue-deploy.tar.gz && sudo chown -R www-data:www-data . && sudo mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs && sudo chown -R www-data:www-data storage && sudo chown -R www-data:www-data database && sudo chmod 775 database && (test -f database/database.sqlite && sudo chmod 664 database/database.sqlite || true) && if test -f .env.prod && test ! -f .env; then sudo cp .env.prod .env && sudo chown www-data:www-data .env; fi && sudo sed -i "s/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/" .env && sudo sed -i "s/^DB_DATABASE=.*/DB_DATABASE=database\/database.sqlite/" .env && (./scripts/pi/migrate-with-repair.sh || php artisan migrate --force) && php artisan config:cache && php artisan route:cache && php artisan storage:link'

# Optional: restart Reverb if running as systemd
ssh "${PI_USER}@${PI_HOST}" 'sudo systemctl restart flexiqueue-reverb 2>/dev/null || true'

echo ""
echo "Done. App updated at ${PI_HOST}."
echo ""
echo "  Post-deploy (on Pi):"
echo "    1) Nothing else"
echo "    2) Run db:seed"
echo "    3) Run migrate:fresh --seed (DROP all tables, then migrate + seed)"
read -r -p "  Choice [1-3] (default 1): " post_choice
post_choice="${post_choice:-1}"

case "$post_choice" in
  2)
    echo "Running db:seed on Pi..."
    ssh -t "${PI_USER}@${PI_HOST}" 'cd /var/www/flexiqueue && php artisan db:seed'
    ;;
  3)
    echo "WARNING: migrate:fresh will DROP ALL TABLES and recreate the database."
    read -r -p "  Are you sure? Type 'yes' to continue: " confirm
    if [ "$confirm" = "yes" ]; then
      echo "Running migrate:fresh --seed on Pi..."
      ssh -t "${PI_USER}@${PI_HOST}" 'cd /var/www/flexiqueue && php artisan migrate:fresh --seed --force'
    else
      echo "Skipped migrate:fresh --seed."
    fi
    ;;
  *)
    echo "No extra steps."
    ;;
esac

echo ""
echo "All done."
