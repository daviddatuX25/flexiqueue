#!/usr/bin/env bash
# Run this script ON THE PI to update the app by fetching the tarball from a URL.
# Use when you can't scp from your PC (e.g. tarball is on GitHub Releases or a file server).
#
# Setup once on the Pi:
#   sudo cp scripts/pi/update-from-url.sh /usr/local/bin/flexiqueue-update
#   sudo chmod +x /usr/local/bin/flexiqueue-update
#
# Usage (on the Pi):
#   sudo flexiqueue-update "https://github.com/YOUR_ORG/flexiqueue/releases/download/v1.0.0/flexiqueue-deploy.tar.gz"
#   sudo flexiqueue-update "https://your-server.com/path/flexiqueue-deploy.tar.gz"
#
# Requires: curl, app already installed at /var/www/flexiqueue with .env configured.

set -e
URL="${1:-}"
APP_DIR="${APP_DIR:-/var/www/flexiqueue}"

if [ -z "$URL" ]; then
  echo "Usage: $0 <tarball-url>"
  echo "Example: $0 https://example.com/flexiqueue-deploy.tar.gz"
  exit 1
fi

TARBALL="/tmp/flexiqueue-deploy-$$.tar.gz"
trap "rm -f $TARBALL" EXIT

echo "Downloading from $URL..."
curl -fsSL -o "$TARBALL" "$URL"

echo "Extracting in $APP_DIR..."
cd "$APP_DIR"
sudo tar -xzf "$TARBALL"
sudo rm -f database/migrations/2025_02_15_000013_create_print_settings_table.php
sudo chown -R www-data:www-data .

if [ -f .env.prod ] && [ ! -f .env ]; then
  echo "Creating .env from .env.prod (first-time setup)..."
  sudo cp .env.prod .env
  sudo chown www-data:www-data .env
fi

echo "Running migrate (with schema repair) and cache..."
sudo -u www-data ./scripts/pi/migrate-with-repair.sh
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache

sudo systemctl restart flexiqueue-reverb 2>/dev/null || true
sudo systemctl restart flexiqueue-queue 2>/dev/null || true

echo "Update complete."
