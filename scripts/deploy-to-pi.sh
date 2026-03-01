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
# Pi must have: /var/www/flexiqueue, .env already set up (first-time setup is separate).

set -e
cd "$(dirname "$0")/.."

PI_HOST="${PI_HOST:-}"
PI_USER="${PI_USER:-root}"
BUILD=0
for arg in "$@"; do
  case "$arg" in
    --build) BUILD=1 ;;
  esac
done

if [ -z "$PI_HOST" ]; then
  echo "Usage: PI_HOST=<pi-ip-or-hostname> ./scripts/deploy-to-pi.sh [--build]"
  echo "Example: PI_HOST=orangepi.local ./scripts/deploy-to-pi.sh --build"
  exit 1
fi

if [ "$BUILD" -eq 1 ]; then
  if [ -f ./scripts/build-deploy-tarball-sail.sh ]; then
    ./scripts/build-deploy-tarball-sail.sh
  else
    ./scripts/build-deploy-tarball.sh
  fi
fi

if [ ! -f flexiqueue-deploy.tar.gz ]; then
  echo "No flexiqueue-deploy.tar.gz. Run with --build or create it first."
  exit 1
fi

echo "Copying tarball to ${PI_USER}@${PI_HOST}..."
scp flexiqueue-deploy.tar.gz "${PI_USER}@${PI_HOST}:/tmp/"

echo "Applying on Pi (extract, chown, migrate, cache)..."
ssh "${PI_USER}@${PI_HOST}" 'cd /var/www/flexiqueue && sudo tar -xzf /tmp/flexiqueue-deploy.tar.gz && sudo chown -R www-data:www-data . && php artisan migrate --force && php artisan config:cache && php artisan route:cache'

# Optional: restart Reverb if running as systemd
ssh "${PI_USER}@${PI_HOST}" 'sudo systemctl restart flexiqueue-reverb 2>/dev/null || true'

echo "Done. App updated at ${PI_HOST}."
