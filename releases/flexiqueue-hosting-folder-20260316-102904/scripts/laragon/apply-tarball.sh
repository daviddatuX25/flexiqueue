#!/usr/bin/env bash
# Run ON THE LARAGON/LAPTOP after the tarball is present (e.g. /tmp/flexiqueue-deploy.tar.gz).
# Same logic as scripts/pi/apply-tarball.sh: extract, .env from .env.prod if missing, DB to sqlite, cache, migrate, restart Reverb and queue worker.
# App dir configurable via LARAGON_APP_DIR (default /var/www/flexiqueue for WSL/Linux).
#
# Usage (on the target machine, e.g. via SSH from deploy-to-laragon.sh):
#   sudo ./scripts/laragon/apply-tarball.sh /path/to/flexiqueue-deploy.tar.gz [--migrate=incremental|fresh|skip]
# Or: LARAGON_APP_DIR=/path/to/app sudo ./scripts/laragon/apply-tarball.sh /tmp/flexiqueue-deploy.tar.gz --migrate=incremental
#
# Requires: tarball path; app dir exists. Target must have PHP, Composer (if needed), and Reverb runnable.

set -e

APP_DIR="${LARAGON_APP_DIR:-/var/www/flexiqueue}"
TARBALL=""
MIGRATE="incremental"

for arg in "$@"; do
  case "$arg" in
    --migrate=*) MIGRATE="${arg#--migrate=}" ;;
    *) [ -z "$TARBALL" ] && TARBALL="$arg" ;;
  esac
done

if [ -z "$TARBALL" ] || [ ! -f "$TARBALL" ]; then
  echo "Usage: $0 /path/to/flexiqueue-deploy.tar.gz [--migrate=incremental|fresh|skip]"
  echo "  --migrate=incremental  Run migrate --force (default)."
  echo "  --migrate=fresh        Run migrate:fresh --seed --force (drops all tables)."
  echo "  --migrate=skip         Do not run migrations."
  echo "  Optional: LARAGON_APP_DIR=/path/to/app  (default: /var/www/flexiqueue)"
  exit 1
fi

mkdir -p "$APP_DIR"
if [ ! -d "$APP_DIR" ]; then
  echo "Error: App directory $APP_DIR could not be created."
  exit 1
fi

echo "Extracting $TARBALL into $APP_DIR..."
sudo tar -xzf "$TARBALL" -C "$APP_DIR"
sudo rm -f "$APP_DIR/database/migrations/2025_02_15_000013_create_print_settings_table.php" 2>/dev/null || true
cd "$APP_DIR"
# On Laragon/laptop, use www-data if available, else current user
RUN_USER="${RUN_USER:-www-data}"
if id -u "$RUN_USER" >/dev/null 2>&1; then
  sudo chown -R "$RUN_USER:$RUN_USER" .
else
  RUN_USER="$(whoami)"
  sudo chown -R "$RUN_USER:$RUN_USER" .
fi

echo "Ensuring storage and database writable..."
sudo mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
sudo chown -R "$RUN_USER:$RUN_USER" storage 2>/dev/null || true
sudo chown -R "$RUN_USER:$RUN_USER" database 2>/dev/null || true
test -f database/database.sqlite && sudo chmod 664 database/database.sqlite || true

if [ -f .env.prod ] && [ ! -f .env ]; then
  echo "Creating .env from .env.prod..."
  sudo cp .env.prod .env
  sudo chown "$RUN_USER:$RUN_USER" .env 2>/dev/null || true
fi

sudo sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
sudo sed -i 's|^DB_DATABASE=.*|DB_DATABASE=database/database.sqlite|' .env

echo "Caching config and routes..."
sudo -u "$RUN_USER" php artisan config:cache
sudo -u "$RUN_USER" php artisan route:cache
sudo -u "$RUN_USER" php artisan storage:link 2>/dev/null || true

case "$MIGRATE" in
  incremental)
    echo "Running migrate --force..."
    sudo -u "$RUN_USER" php artisan migrate --force
    ;;
  fresh)
    echo "Running migrate:fresh --seed --force..."
    sudo -u "$RUN_USER" php artisan migrate:fresh --seed --force
    ;;
  skip)
    echo "Skipping migrate."
    ;;
  *)
    echo "Unknown --migrate=$MIGRATE; use incremental|fresh|skip. Skipping migrate."
    ;;
esac

echo "Restarting Reverb (if systemd unit present)..."
sudo systemctl restart flexiqueue-reverb 2>/dev/null || true
echo "Restarting queue worker (if systemd unit present)..."
sudo systemctl restart flexiqueue-queue 2>/dev/null || true

echo "Apply complete. App is at $APP_DIR"
