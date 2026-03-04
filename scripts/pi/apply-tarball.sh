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
sudo mkdir -p "$APP_DIR/bootstrap/cache"
sudo chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/database" "$APP_DIR/bootstrap/cache"
test -f "$APP_DIR/database/database.sqlite" && sudo chmod 664 "$APP_DIR/database/database.sqlite" || true

# Preserve existing APP_KEY before overwriting .env (Laravel 500s if APP_KEY is empty).
SAVED_APP_KEY=""
if [ -f "$APP_DIR/.env" ]; then
  SAVED_APP_KEY=$(grep -E '^APP_KEY=' "$APP_DIR/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)
fi

# Set .env from tarball's .env.prod so BROADCAST_CONNECTION and REVERB_* are correct (avoids 747972 / stale keys).
if [ -f "$APP_DIR/.env.prod" ]; then
  echo "Setting .env from .env.prod..."
  sudo cp "$APP_DIR/.env.prod" "$APP_DIR/.env"
  sudo chown www-data:www-data "$APP_DIR/.env"
fi

# Restore or set APP_KEY (empty APP_KEY causes internal server errors everywhere).
if [ -n "$SAVED_APP_KEY" ]; then
  echo "Restoring existing APP_KEY..."
  if grep -qE '^APP_KEY=' "$APP_DIR/.env" 2>/dev/null; then
    sudo sed -i "s|^APP_KEY=.*|APP_KEY=${SAVED_APP_KEY}|" "$APP_DIR/.env"
  else
    echo "APP_KEY=${SAVED_APP_KEY}" | sudo tee -a "$APP_DIR/.env" > /dev/null
  fi
fi

# Force SQLite for Pi
if [ -f "$APP_DIR/.env" ]; then
  sudo sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' "$APP_DIR/.env"
  sudo sed -i 's|^DB_DATABASE=.*|DB_DATABASE=database/database.sqlite|' "$APP_DIR/.env"
fi

echo "Caching config and routes..."
cd "$APP_DIR"
# Generate APP_KEY if still empty (e.g. first deploy)
if ! grep -qE '^APP_KEY=base64:[A-Za-z0-9+/=]+' "$APP_DIR/.env" 2>/dev/null; then
  echo "Generating APP_KEY..."
  sudo -u www-data php artisan key:generate --force
fi
sudo -u www-data php artisan config:clear
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
