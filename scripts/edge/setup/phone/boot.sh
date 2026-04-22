#!/usr/bin/env bash
# Termux boot hook — runs on device startup
# Installed to ~/.termux/boot/ by bootstrap.sh

HOME_DIR="/data/data/com.termux/files/home"
FLEXI_DIR="$HOME_DIR/flexiqueue"

if [ -d "$FLEXI_DIR" ]; then
  cd "$FLEXI_DIR"
  termux-setup-storage 2>/dev/null || true
  sv-enable nginx 2>/dev/null || true
  sv-enable php-fpm 2>/dev/null || true
  sv restart nginx 2>/dev/null || true
  sv restart php-fpm 2>/dev/null || true
  nohup php artisan schedule:work >> "$FLEXI_DIR/storage/logs/scheduler.log" 2>&1 &
fi