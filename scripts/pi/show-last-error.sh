#!/usr/bin/env bash
# Run ON THE PI to see the last Laravel error (for debugging 500s).
# Usage: sudo ./scripts/pi/show-last-error.sh [lines]
# Default: last 80 lines of laravel.log

APP_DIR="${APP_DIR:-/var/www/flexiqueue}"
LINES="${1:-80}"
LOG="$APP_DIR/storage/logs/laravel.log"

echo "=== Last ${LINES} lines of $LOG ==="
if [ -f "$LOG" ]; then
  tail -n "$LINES" "$LOG"
else
  echo "Log file not found. Checking .env APP_KEY..."
  grep -E '^APP_KEY=' "$APP_DIR/.env" 2>/dev/null || true
  echo "Run: sudo -u www-data php $APP_DIR/artisan config:clear && sudo -u www-data php $APP_DIR/artisan config:cache"
fi
