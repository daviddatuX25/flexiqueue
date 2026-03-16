#!/usr/bin/env bash
# Enable HTTPS on an existing FlexiQueue Pi (self-signed cert).
# Use this so mobile devices get a secure context and the camera/QR scanner works.
# Safe to run on a Pi that already has the app and Nginx installed (HTTP-only).
#
# Usage (on the Pi, from app root):
#   sudo ./scripts/pi/setup-ssl.sh [--hostname=orangepione.local]
# Or: FQ_HOSTNAME=orangepione.local sudo ./scripts/pi/setup-ssl.sh
#
# If --hostname is omitted, uses $(hostname); use the hostname phones use (e.g. orangepione.local).
# Requires: run as root; app at /var/www/flexiqueue; Nginx already configured.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

HOSTNAME_ARG=""
for arg in "$@"; do
  case "$arg" in
    --hostname=*) HOSTNAME_ARG="${arg#--hostname=}" ;;
  esac
done
FQ_HOSTNAME="${FQ_HOSTNAME:-$HOSTNAME_ARG}"
if [ -z "$FQ_HOSTNAME" ]; then
  FQ_HOSTNAME="$(hostname)"
  echo "No --hostname= given; using hostname: $FQ_HOSTNAME"
  echo "  (If phones use something like orangepione.local, run with: --hostname=orangepione.local)"
fi

echo "=== FlexiQueue Pi: enable HTTPS (self-signed) ==="
echo "Hostname: $FQ_HOSTNAME"
echo "App root: $APP_ROOT"
echo ""

echo "Creating SSL directory and generating self-signed certificate..."
mkdir -p /etc/nginx/ssl
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /etc/nginx/ssl/flexiqueue.key \
  -out /etc/nginx/ssl/flexiqueue.crt \
  -subj "/CN=$FQ_HOSTNAME" \
  -addext "subjectAltName=DNS:$FQ_HOSTNAME,DNS:$(hostname),IP:127.0.0.1"
chmod 644 /etc/nginx/ssl/flexiqueue.crt
chmod 600 /etc/nginx/ssl/flexiqueue.key

echo "Installing Nginx HTTPS site config..."
cp "$SCRIPT_DIR/nginx-flexiqueue-ssl.conf" /etc/nginx/sites-available/flexiqueue
nginx -t && systemctl reload nginx

echo "Setting APP_URL to https://$FQ_HOSTNAME in .env..."
ENV_FILE="$APP_ROOT/.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "Warning: $ENV_FILE not found; skipping APP_URL update."
else
  if grep -q '^APP_URL=' "$ENV_FILE"; then
    sed -i "s|^APP_URL=.*|APP_URL=https://$FQ_HOSTNAME|" "$ENV_FILE"
  else
    echo "APP_URL=https://$FQ_HOSTNAME" >> "$ENV_FILE"
  fi
  sudo -u www-data php -d memory_limit=128M "$APP_ROOT/artisan" config:cache
fi

if systemctl is-enabled flexiqueue-reverb &>/dev/null; then
  echo "Restarting Reverb..."
  systemctl restart flexiqueue-reverb
fi

echo ""
echo "=== HTTPS setup complete. ==="
echo "Open the app on your phone at: https://$FQ_HOSTNAME"
echo "Accept the browser certificate warning once; then the camera should work."
echo ""
