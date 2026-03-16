#!/usr/bin/env bash
# Install or update only the FlexiQueue systemd units (Reverb + queue worker).
# Use this when PHP/Nginx are already set up and you only want Reverb and queue:work.
# Run from the Pi, from the app directory (e.g. /var/www/flexiqueue).
#
# Usage (on the Pi):
#   sudo ./scripts/pi/install-flexiqueue-services.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ "$(id -u)" -ne 0 ]; then
  echo "This script must be run as root (e.g. sudo)." >&2
  echo "Usage: sudo $0" >&2
  exit 1
fi

if [ ! -f "$SCRIPT_DIR/flexiqueue-reverb.service" ] || [ ! -f "$SCRIPT_DIR/flexiqueue-queue.service" ]; then
  echo "Service files not found in $SCRIPT_DIR. Run from app root after deploy." >&2
  exit 1
fi

echo "Installing FlexiQueue Reverb and queue worker systemd units..."
cp "$SCRIPT_DIR/flexiqueue-reverb.service" /etc/systemd/system/
cp "$SCRIPT_DIR/flexiqueue-queue.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now flexiqueue-reverb flexiqueue-queue
echo "Done. Reverb and queue worker are enabled and started."
