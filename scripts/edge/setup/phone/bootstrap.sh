#!/usr/bin/env bash
set -euo pipefail

# Idempotent Termux package installer for FlexiQueue Edge
# Run once on first setup. Safe to re-run.
#
# IMPORTANT: This script runs INSIDE Termux on the Android device.
# It does NOT run on your dev machine.
#
# NOTE: Termux from F-Droid only. Play Store version is obsolete/broken.
# Download from: https://f-droid.org/packages/com.termux/

echo "=== FlexiQueue Edge Phone Bootstrap ==="

# Update packages
pkg update -y 2>/dev/null || true

# Install required packages
# NOTE: Termux does not have a sqlcipher PHP extension package.
# Phone runtime uses plain SQLite (DB_CONNECTION=sqlite).
# See E13.1 Task 3 — AppServiceProvider skips PRAGMA key when runtime=phone.
PACKAGES=(
  php
  php-fpm
  nginx
  php-pdo-sqlite
  php-sqlite3
  php-openssl
  php-mbstring
  php-xml
  php-curl
  php-tokenizer
  php-fileinfo
  sqlite
  openssl
  termux-services
  tar
  curl
)

for pkg in "${PACKAGES[@]}"; do
  if ! dpkg -s "$pkg" >/dev/null 2>&1; then
    echo "Installing: $pkg"
    pkg install -y "$pkg"
  else
    echo "Already installed: $pkg"
  fi
done

# Setup shared storage (requires human interaction on first run)
if [ ! -d "$HOME/storage" ]; then
  echo ""
  echo ">>> IMPORTANT: A permission dialog will appear. Tap 'Allow'. <<<"
  echo ""
  termux-setup-storage
fi

# Install Termux boot hook
BOOT_DIR="$HOME/.termux/boot"
mkdir -p "$BOOT_DIR"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cp "$SCRIPT_DIR/boot.sh" "$BOOT_DIR/boot.sh"
chmod +x "$BOOT_DIR/boot.sh"

# Copy Termux properties
cp "$SCRIPT_DIR/termux.properties" "$HOME/.termux/termux.properties"

# Copy Nginx config
cp "$SCRIPT_DIR/nginx.conf" "$PREFIX/etc/nginx/nginx.conf"
mkdir -p "$PREFIX/etc/nginx/conf.d"

# PHP-FPM: Termux reads $PREFIX/etc/php-fpm.conf as main config.
# Do NOT write to php-fpm.d/www.conf — it is ignored on Termux.
# Append pool config if not already present.
if ! grep -q '\[www\]' "$PREFIX/etc/php-fpm.conf" 2>/dev/null; then
  cat "$SCRIPT_DIR/php-fpm.conf" >> "$PREFIX/etc/php-fpm.conf"
fi

echo "=== Bootstrap complete. Run start-stack.sh to start services. ==="