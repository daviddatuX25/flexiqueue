#!/usr/bin/env bash
# Enable HTTPS on an existing FlexiQueue Pi (self-signed cert).
# Use this so mobile devices get a secure context and the camera/QR scanner works.
# Safe to run on a Pi that already has the app and Nginx installed (HTTP-only).
#
# Usage (on the Pi, from app root):
#   sudo ./scripts/pi/setup-ssl.sh [--hostname=flexiqueue.edge]
# Or: FQ_HOSTNAME=flexiqueue.edge sudo ./scripts/pi/setup-ssl.sh
#
# If --hostname is omitted, uses $(hostname); use the hostname phones use (e.g. flexiqueue.edge).
# Requires: run as root; app at /var/www/flexiqueue; Nginx already configured.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

DO_RELOAD=1
HOSTNAME_ARG=""
for arg in "$@"; do
  case "$arg" in
    --hostname=*) HOSTNAME_ARG="${arg#--hostname=}" ;;
    --no-reload) DO_RELOAD=0 ;;
  esac
done

FQ_HOSTNAME="${FQ_HOSTNAME:-$HOSTNAME_ARG}"
if [ -z "$FQ_HOSTNAME" ]; then
  FQ_HOSTNAME="flexiqueue.edge"
  echo "No --hostname= given; defaulting to flexiqueue.edge"
  echo "  (Override with: --hostname=<host> or FQ_HOSTNAME=<host>)"
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
NGINX_SITES_AVAILABLE=/etc/nginx/sites-available
NGINX_SITES_ENABLED=/etc/nginx/sites-enabled
mkdir -p "$NGINX_SITES_AVAILABLE" "$NGINX_SITES_ENABLED"

cp "$SCRIPT_DIR/nginx-flexiqueue-ssl.conf" "$NGINX_SITES_AVAILABLE/flexiqueue"

if [ -L "$NGINX_SITES_ENABLED/default" ] || [ -f "$NGINX_SITES_ENABLED/default" ]; then
  rm -f "$NGINX_SITES_ENABLED/default"
fi

if [ -L "$NGINX_SITES_ENABLED/flexiqueue" ] || [ -f "$NGINX_SITES_ENABLED/flexiqueue" ]; then
  rm -f "$NGINX_SITES_ENABLED/flexiqueue"
fi
ln -s ../sites-available/flexiqueue "$NGINX_SITES_ENABLED/flexiqueue"

# Optionally validate config and reload Nginx when running under systemd
if [ "$DO_RELOAD" -eq 1 ] && command -v nginx >/dev/null 2>&1; then
  if nginx -t; then
    if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
      systemctl reload nginx || true
    fi
  fi
fi

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

if [ "$DO_RELOAD" -eq 1 ] && command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
  if systemctl is-enabled flexiqueue-reverb &>/dev/null; then
    echo "Restarting Reverb..."
    systemctl restart flexiqueue-reverb || true
  fi
fi

echo ""
echo "=== HTTPS setup complete. ==="
echo "Open the app on your phone at: https://$FQ_HOSTNAME"
echo "Accept the browser certificate warning once; then the camera should work."
echo ""
