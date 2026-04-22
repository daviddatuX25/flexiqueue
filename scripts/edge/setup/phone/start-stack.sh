#!/usr/bin/env bash
set -euo pipefail

echo "=== Starting FlexiQueue Edge Stack ==="

FLEXI_DIR="/data/data/com.termux/files/home/flexiqueue"

# Activate .env.edge if no .env exists yet
if [ -f "$FLEXI_DIR/.env.edge" ] && [ ! -f "$FLEXI_DIR/.env" ]; then
  cp "$FLEXI_DIR/.env.edge" "$FLEXI_DIR/.env"
  echo "Copied .env.edge -> .env"
fi

# Generate APP_KEY if empty (required for Laravel encryption).
# Skip if already set.
if grep -q '^APP_KEY=$' "$FLEXI_DIR/.env" 2>/dev/null; then
  cd "$FLEXI_DIR" && php artisan key:generate --force
  echo "Generated APP_KEY"
fi

# Cache config AFTER .env.edge is in place.
# NEVER cache before .env is ready — cached config bakes in wrong values.
cd "$FLEXI_DIR" && php artisan config:cache 2>/dev/null || true

# Enable services via termux-services (runit supervision)
sv-enable nginx 2>/dev/null || true
sv-enable php-fpm 2>/dev/null || true

# Start services
sv restart nginx 2>/dev/null || true
sv restart php-fpm 2>/dev/null || true

# Wait for Nginx and PHP-FPM to be ready (up to 30s)
for i in $(seq 1 30); do
  if curl -s -o /dev/null -w '' 'http://127.0.0.1:8000/up' 2>/dev/null; then
    echo "Stack is ready (attempt $i)."
    break
  fi
  sleep 1
done

# Run Laravel migrations
cd "$FLEXI_DIR" && php artisan migrate --force 2>/dev/null || true

# Start the scheduler as a background process
mkdir -p "$FLEXI_DIR/storage/logs"
nohup php artisan schedule:work >> "$FLEXI_DIR/storage/logs/scheduler.log" 2>&1 &

echo "=== FlexiQueue Edge Stack running on http://127.0.0.1:8000 ==="