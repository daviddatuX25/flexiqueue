#!/usr/bin/env bash
# Apply deploy tarball in Laragon central mode.

set -euo pipefail

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
  exit 1
fi

mkdir -p "$APP_DIR"
cd "$APP_DIR"

echo "Extracting $TARBALL into $APP_DIR..."
sudo tar -xzf "$TARBALL" -C "$APP_DIR"

RUN_USER="${RUN_USER:-www-data}"
if id -u "$RUN_USER" >/dev/null 2>&1; then
  sudo chown -R "$RUN_USER:$RUN_USER" .
else
  RUN_USER="$(whoami)"
  sudo chown -R "$RUN_USER:$RUN_USER" .
fi

sudo mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
sudo chown -R "$RUN_USER:$RUN_USER" storage 2>/dev/null || true
sudo chown -R "$RUN_USER:$RUN_USER" database 2>/dev/null || true

if [ ! -f .env ]; then
  if [ -f env.laragon.central.example ]; then
    echo "Creating .env from env.laragon.central.example..."
    sudo cp env.laragon.central.example .env
  elif [ -f .env.example ]; then
    echo "Creating .env from .env.example..."
    sudo cp .env.example .env
  fi
  sudo chown "$RUN_USER:$RUN_USER" .env 2>/dev/null || true
fi

echo "Caching config and routes..."
sudo -u "$RUN_USER" php artisan config:cache
sudo -u "$RUN_USER" php artisan route:cache
sudo -u "$RUN_USER" php artisan storage:link 2>/dev/null || true

case "$MIGRATE" in
  incremental) sudo -u "$RUN_USER" php artisan migrate --force ;;
  fresh) sudo -u "$RUN_USER" php artisan migrate:fresh --seed --force ;;
  skip) echo "Skipping migrate." ;;
  *) echo "Unknown --migrate=$MIGRATE; skipping migrate." ;;
esac

sudo systemctl restart flexiqueue-reverb 2>/dev/null || true
sudo systemctl restart flexiqueue-queue 2>/dev/null || true

echo "Apply complete (central mode). App is at $APP_DIR"
