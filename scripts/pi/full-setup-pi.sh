#!/usr/bin/env bash
# Run ONCE on the Pi to prepare the system (PHP, Nginx, SQLite, app dir, nginx site, Reverb + queue worker services).
# Does NOT install app code or .env — deploy from PC (deploy-to-pi.sh --build) does that.
# Run from the Pi, from the app directory (e.g. after extracting tarball to /var/www/flexiqueue).
#
# Usage (on the Pi):
#   sudo ./scripts/pi/full-setup-pi.sh [--hostname=flexiqueue.edge]
# Or: FQ_HOSTNAME=flexiqueue.edge sudo ./scripts/pi/full-setup-pi.sh
#
# Requires: run as root; Armbian/Ubuntu (apt).

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

# Must run as root (apt, systemctl, /etc, chown)
if [ "$(id -u)" -ne 0 ]; then
  echo "This script must be run as root (e.g. sudo)." >&2
  echo "Usage: sudo $0 [--hostname=flexiqueue.edge]" >&2
  exit 1
fi

HOSTNAME_ARG=""
for arg in "$@"; do
  case "$arg" in
    --hostname=*) HOSTNAME_ARG="${arg#--hostname=}" ;;
  esac
done
FQ_HOSTNAME="${FQ_HOSTNAME:-$HOSTNAME_ARG}"

echo "=== FlexiQueue Pi: full system setup (app root: $APP_ROOT) ==="

# Install PHP 8.3, Nginx, SQLite only if not already present (re-run safe; skip heavy apt when already set up)
if ! dpkg -l php8.3-fpm &>/dev/null || ! dpkg -l nginx &>/dev/null; then
  echo "Installing PHP 8.3, Nginx, SQLite..."
  apt-get update -qq
  apt-get install -y php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl nginx
else
  echo "PHP and Nginx already installed, skipping package install."
fi

echo "Creating app directory and database..."
mkdir -p "$APP_ROOT/database"
touch "$APP_ROOT/database/database.sqlite"
chown -R www-data:www-data "$APP_ROOT"

if [ -n "$FQ_HOSTNAME" ]; then
  hostnamectl set-hostname "$FQ_HOSTNAME" 2>/dev/null || true
  if ! dpkg -l avahi-daemon &>/dev/null; then
    echo "Installing Avahi..."
    apt-get update -qq
    apt-get install -y avahi-daemon
    systemctl enable --now avahi-daemon 2>/dev/null || true
  else
    echo "Avahi already installed, skipping."
  fi
fi

echo "Installing Nginx site..."
cp "$SCRIPT_DIR/nginx-flexiqueue.conf" /etc/nginx/sites-available/flexiqueue
ln -sf /etc/nginx/sites-available/flexiqueue /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# Always copy FlexiQueue systemd units (so re-running applies any changes to the service files)
echo "Updating Reverb and queue worker systemd units..."
cp "$SCRIPT_DIR/flexiqueue-reverb.service" /etc/systemd/system/
cp "$SCRIPT_DIR/flexiqueue-queue.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now flexiqueue-reverb
systemctl enable --now flexiqueue-queue

echo ""
echo "=== Full setup complete. ==="
echo "Next: from your PC run: PI_HOST=${FQ_HOSTNAME:-<pi-ip>} ./scripts/edge/deploy/pi/deploy-edge-pi.sh --build"
echo ""
