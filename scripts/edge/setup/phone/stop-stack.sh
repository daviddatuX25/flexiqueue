#!/usr/bin/env bash
set -euo pipefail

echo "=== Stopping FlexiQueue Edge Stack ==="

# Stop scheduler
pkill -f "schedule:work" 2>/dev/null || true

# Stop services via sv or direct kill
sv stop nginx 2>/dev/null || pkill nginx 2>/dev/null || true
sv stop php-fpm 2>/dev/null || pkill -f "php-fpm" 2>/dev/null || true

echo "=== Stack stopped ==="