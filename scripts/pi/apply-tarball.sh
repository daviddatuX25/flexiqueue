#!/usr/bin/env bash
# Run ON THE PI after the tarball is present (e.g. /tmp/flexiqueue-deploy.tar.gz).
# Apply tarball: extract, chown, .env from .env.prod if missing, DB to sqlite, cache, migrate, restart Reverb.
# Callable from deploy-to-pi.sh (via SSH) or manually on the Pi.
#
# Usage (on the Pi):
#   sudo ./scripts/pi/apply-tarball.sh /path/to/flexiqueue-deploy.tar.gz [--migrate=incremental|fresh|skip]
# Default: --migrate=incremental
#
# Requires: tarball path; app dir exists (e.g. /var/www/flexiqueue).

set -e

APP_DIR="${APP_DIR:-/var/www/flexiqueue}"
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
  exit 1
fi

mkdir -p "$APP_DIR"
if [ ! -d "$APP_DIR" ] || [ ! -w "$APP_DIR" ]; then
  echo "Error: App directory $APP_DIR does not exist or is not writable."
  exit 1
fi

echo "Extracting $TARBALL into $APP_DIR..."
sudo tar -xzf "$TARBALL" -C "$APP_DIR"
sudo rm -f "$APP_DIR/database/migrations/2025_02_15_000013_create_print_settings_table.php" 2>/dev/null || true
sudo chown -R www-data:www-data "$APP_DIR"

echo "Ensuring storage and database writable..."
sudo mkdir -p "$APP_DIR/storage/app/public" "$APP_DIR/storage/framework/cache" "$APP_DIR/storage/framework/sessions" "$APP_DIR/storage/framework/views" "$APP_DIR/storage/logs"
sudo chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/database"
test -f "$APP_DIR/database/database.sqlite" && sudo chmod 664 "$APP_DIR/database/database.sqlite" || true

if [ -f "$APP_DIR/.env.prod" ] && [ ! -f "$APP_DIR/.env" ]; then
  echo "Creating .env from .env.prod..."
  sudo cp "$APP_DIR/.env.prod" "$APP_DIR/.env"
  sudo chown www-data:www-data "$APP_DIR/.env"
fi

# Force SQLite for Pi
if [ -f "$APP_DIR/.env" ]; then
  sudo sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' "$APP_DIR/.env"
  sudo sed -i 's|^DB_DATABASE=.*|DB_DATABASE=database/database.sqlite|' "$APP_DIR/.env"
fi

echo "Caching config and routes..."
cd "$APP_DIR"
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan storage:link 2>/dev/null || true

case "$MIGRATE" in
  incremental)
    echo "Running migrate --force..."
    sudo -u www-data php artisan migrate --force
    ;;
  fresh)
    echo "Running migrate:fresh --seed --force..."
    sudo -u www-data php artisan migrate:fresh --seed --force
    ;;
  skip)
    echo "Skipping migrate."
    ;;
  *)
    echo "Unknown --migrate=$MIGRATE; use incremental|fresh|skip. Skipping migrate."
    ;;
esac

echo "Restarting Reverb..."
sudo systemctl restart flexiqueue-reverb 2>/dev/null || true

echo "Apply complete. App is at $APP_DIR"
