#!/usr/bin/env bash
# Run on the Pi (as root, e.g. from cron) to start ZeroTier only when no session is open.
# When a program is active or any queue session is active, ZeroTier is stopped to save RAM (~20–40 MB).
# When idle, ZeroTier is started so you can remote in and deploy.
#
# Optimizations:
# - Only toggles systemctl when session state changes (avoids thrashing).
# - Skips if zerotier-one is not installed (exit 0 so cron does not complain).
# - On transition to idle: optionally reload PHP-FPM and restart Reverb to reclaim memory.
#   Set RECLAIM_MEMORY_ON_IDLE=1 to enable; see 10-DEPLOYMENT.md Low-RAM section.
#
# Setup on the Pi:
#   sudo cp scripts/pi/zerotier-when-idle.sh /usr/local/bin/zerotier-when-idle
#   sudo chmod +x /usr/local/bin/zerotier-when-idle
#   # Cron every 2–5 min as root: */5 * * * * /usr/local/bin/zerotier-when-idle
#
# Requires: zerotier-one installed and joined to your network (or script exits 0 if not installed); app at APP_DIR.

set -e
APP_DIR="${APP_DIR:-/var/www/flexiqueue}"
STATE_FILE="${STATE_FILE:-/run/flexiqueue-zerotier-state}"
RECLAIM_MEMORY_ON_IDLE="${RECLAIM_MEMORY_ON_IDLE:-0}"

# Fallback to storage/app if /run is not writable
if [ ! -w "$(dirname "$STATE_FILE")" ] 2>/dev/null; then
  STATE_FILE="$APP_DIR/storage/app/zerotier-idle.state"
fi

# Skip if ZeroTier is not installed
if ! systemctl list-unit-files zerotier-one.service 2>/dev/null | grep -q zerotier-one; then
  exit 0
fi

cd "$APP_DIR"
# Run artisan as www-data so DB and env are correct. Exit 0 = session active, 1 = idle.
sudo -u www-data php artisan flexiqueue:session-active 2>/dev/null
session_active=$?

# Read previous state (default: unknown so we always apply on first run)
prev_active=""
[ -f "$STATE_FILE" ] && prev_active=$(cat "$STATE_FILE" 2>/dev/null || true)

# Only toggle when state changes
if [ "$session_active" -eq 0 ]; then
  # Session open → stop ZeroTier to save RAM
  if [ "$prev_active" != "active" ]; then
    systemctl stop zerotier-one 2>/dev/null || true
    echo "active" > "$STATE_FILE" 2>/dev/null || true
  fi
else
  # Idle → start ZeroTier so you can remote in
  if [ "$prev_active" != "idle" ]; then
    # Transition to idle: optionally reclaim memory
    if [ "$RECLAIM_MEMORY_ON_IDLE" = "1" ]; then
      php_fpm=$(systemctl list-units --type=service --no-pager --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}' | head -1)
      [ -n "$php_fpm" ] && systemctl reload "$php_fpm" 2>/dev/null || true
      systemctl restart flexiqueue-reverb 2>/dev/null || true
    fi
    systemctl start zerotier-one 2>/dev/null || true
    echo "idle" > "$STATE_FILE" 2>/dev/null || true
  fi
fi
