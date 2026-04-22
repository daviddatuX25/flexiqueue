#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd)"
cd "$REPO_ROOT"

echo "=== Building Edge Bundle for Termux ==="

BUILD_DIR=".build/edge-phone"
BUNDLE_NAME="edge-bundle.tgz"
ASSETS_DIR="android/app/src/main/assets"

# Clean previous build
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"
mkdir -p "$ASSETS_DIR"

# Install production dependencies
composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction 2>/dev/null || true

# Build Vite assets
npm ci
npm run build

# IMPORTANT: Do NOT run config:cache here!
# The cached config would bake in the dev .env values (APP_MODE=local),
# which would override .env.edge on the device.
# Config caching runs on-device AFTER .env.edge is in place (start-stack.sh).

# Copy Laravel app into build dir (exclude dev artifacts)
rsync -a --exclude='.git' \
  --exclude='node_modules' \
  --exclude='.env' \
  --exclude='.env.example' \
  --exclude='storage/framework/cache/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  --exclude='storage/logs/*' \
  --exclude='.phpunit.cache' \
  --exclude='tests' \
  --exclude='playwright-report' \
  --exclude='test-results' \
  --exclude='e2e' \
  --exclude='.claude' \
  --exclude='.planning' \
  --exclude='scripts/edge/build' \
  app/ "$BUILD_DIR/app/"
rsync -a bootstrap/ "$BUILD_DIR/bootstrap/"
rsync -a config/ "$BUILD_DIR/config/"
rsync -a database/ "$BUILD_DIR/database/"
rsync -a public/ "$BUILD_DIR/public/"
rsync -a resources/views/ "$BUILD_DIR/resources/views/"
rsync -a routes/ "$BUILD_DIR/routes/"
rsync -a vendor/ "$BUILD_DIR/vendor/"
rsync -a storage/ "$BUILD_DIR/storage/"

# Copy Termux scripts into the bundle
mkdir -p "$BUILD_DIR/scripts/edge/setup/phone"
cp scripts/edge/setup/phone/bootstrap.sh "$BUILD_DIR/scripts/edge/setup/phone/"
cp scripts/edge/setup/phone/start-stack.sh "$BUILD_DIR/scripts/edge/setup/phone/"
cp scripts/edge/setup/phone/stop-stack.sh "$BUILD_DIR/scripts/edge/setup/phone/"
cp scripts/edge/setup/phone/nginx.conf "$BUILD_DIR/scripts/edge/setup/phone/"
cp scripts/edge/setup/phone/php-fpm.conf "$BUILD_DIR/scripts/edge/setup/phone/"
cp scripts/edge/setup/phone/boot.sh "$BUILD_DIR/scripts/edge/setup/phone/"
cp scripts/edge/setup/phone/termux.properties "$BUILD_DIR/scripts/edge/setup/phone/"
cp scripts/edge/setup/phone/cron.crontab "$BUILD_DIR/scripts/edge/setup/phone/"

# Create .env.edge template inside bundle
cat > "$BUILD_DIR/.env.edge" << 'ENVEOF'
APP_MODE=edge
EDGE_RUNTIME=phone
APP_KEY=
APP_DEBUG=false
DB_CONNECTION=sqlite
DB_DATABASE=/data/data/com.termux/files/home/flexiqueue/database/edge.db
CENTRAL_URL=
ENVEOF

# Make scripts executable in bundle
chmod +x "$BUILD_DIR/scripts/edge/setup/phone/"*.sh

# Package into tarball
tar -czf "$BUNDLE_NAME" -C "$BUILD_DIR" .

# Move to Android assets
cp "$BUNDLE_NAME" "$ASSETS_DIR/"

# Clean up
rm -rf "$BUILD_DIR"
rm -f "$BUNDLE_NAME"

echo "=== edge-bundle.tgz deposited at $ASSETS_DIR/$BUNDLE_NAME ==="