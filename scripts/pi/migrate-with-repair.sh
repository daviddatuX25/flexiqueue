#!/usr/bin/env bash
# Run migrations (no runtime repair — migrations are idempotent).
#
# Usage (on Pi, from app root or with APP_DIR):
#   sudo -u www-data ./scripts/pi/migrate-with-repair.sh
#   APP_DIR=/var/www/flexiqueue sudo -u www-data ./scripts/pi/migrate-with-repair.sh
#
# Called by deploy-to-pi.sh and update-from-url.sh during deploy.

set -e
APP_DIR="${APP_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"
cd "$APP_DIR"

php artisan migrate --force
