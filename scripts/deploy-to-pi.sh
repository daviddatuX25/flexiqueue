#!/usr/bin/env bash
# One-command deploy: (optionally) build tarball, scp to Pi, SSH and apply.
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
for arg in "$@"; do
  [ "$arg" = "--build" ] && BUILD=1
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
  echo "Building tarball..."
  if [ -f ./scripts/build-deploy-tarball-sail.sh ]; then
    ./scripts/build-deploy-tarball-sail.sh
  else
    ./scripts/build-deploy-tarball.sh
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

echo "Applying on Pi (extract, chown, storage + database writable, migrate, cache, storage:link)..."
ssh "${PI_USER}@${PI_HOST}" 'cd /var/www/flexiqueue && sudo tar -xzf /tmp/flexiqueue-deploy.tar.gz && sudo chown -R www-data:www-data . && sudo mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs && sudo chown -R www-data:www-data storage && sudo chown -R www-data:www-data database && sudo chmod 775 database && (test -f database/database.sqlite && sudo chmod 664 database/database.sqlite || true) && if test -f .env.prod && test ! -f .env; then sudo cp .env.prod .env && sudo chown www-data:www-data .env; fi && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan storage:link'

# Optional: restart Reverb if running as systemd
ssh "${PI_USER}@${PI_HOST}" 'sudo systemctl restart flexiqueue-reverb 2>/dev/null || true'

echo "Done. App updated at ${PI_HOST}."
