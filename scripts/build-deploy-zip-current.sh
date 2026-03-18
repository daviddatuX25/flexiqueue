#!/usr/bin/env bash
# Build a deployable ZIP from the CURRENT checked-out commit using Sail.
# - Uses .env.hosting for production settings (including RUN_SCRIPTS_PASSWORD).
# - Includes php-run-scripts/ in the artifact.
# - Output: releases/flexiqueue-<short-sha>-<timestamp>.zip

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

if [ ! -f "$REPO_ROOT/.env.hosting" ]; then
  echo "Error: .env.hosting not found."
  echo "  Copy .env.hosting.example to .env.hosting and fill DB_*, APP_URL, Pusher, etc."
  exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
  echo "Error: composer not found. Install Composer or use an existing deploy tarball script instead." >&2
  exit 1
fi

if ! command -v npm >/dev/null 2>&1; then
  echo "Error: npm not found. Install Node/npm to build assets." >&2
  exit 1
fi

SAIL_BIN=""
if [ -f "$REPO_ROOT/vendor/bin/sail" ]; then
  SAIL_BIN="$REPO_ROOT/vendor/bin/sail"
fi

BUILD_DIR="$REPO_ROOT/build-zip-current"
RELEASES_DIR="$REPO_ROOT/releases"

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR" "$RELEASES_DIR"

echo "Installing npm dependencies and building frontend..."
if [ -n "$SAIL_BIN" ]; then
  if "$SAIL_BIN" npm ci; then
    :
  else
    "$SAIL_BIN" npm install
  fi
  "$SAIL_BIN" npm run build
else
  if npm ci; then
    :
  else
    npm install
  fi
  npm run build
fi

echo "Running Composer install (no-dev, optimized autoloader)..."
composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

echo "Copying application files into build directory (includes php-run-scripts)..."
rsync -a \
  --exclude=".git" \
  --exclude="node_modules" \
  --exclude="storage/framework/cache" \
  --exclude="storage/logs" \
  --exclude=".env" \
  --exclude=".env.example" \
  --exclude=".env.edge" \
  --exclude=".env.production" \
  --exclude=".cursor" \
  --exclude=".beads" \
  --exclude="tests" \
  --exclude="e2e" \
  --exclude="playwright-report" \
  --exclude="test-results" \
  "$REPO_ROOT"/ "$BUILD_DIR"/

echo "Injecting hosting env (.env.hosting) as .env inside the ZIP..."
cp "$REPO_ROOT/.env.hosting" "$BUILD_DIR/.env"

SHORT_SHA="$(git -C "$REPO_ROOT" rev-parse --short HEAD)"
STAMP="$(date +%Y%m%d-%H%M%S)"
BASE_NAME="flexiqueue-${SHORT_SHA}-${STAMP}"

if command -v zip >/dev/null 2>&1; then
  ZIP_PATH="$RELEASES_DIR/${BASE_NAME}.zip"
  echo "Creating ZIP at $ZIP_PATH ..."
  (cd "$BUILD_DIR" && zip -r "$ZIP_PATH" . >/dev/null)

  echo ""
  echo "Deploy ZIP created:"
  ls -la "$ZIP_PATH"
else
  TAR_PATH="$RELEASES_DIR/${BASE_NAME}.tar.gz"
  echo "zip command not found; creating tar.gz at $TAR_PATH instead..."
  (cd "$BUILD_DIR" && tar -czf "$TAR_PATH" .)

  echo ""
  echo "Deploy tarball created:"
  ls -la "$TAR_PATH"
fi

echo ""
echo "Upload this package to hosting, extract it into your app folder, then run:"
echo "  php php-run-scripts/initial-setup.php   # first time"
echo "  php php-run-scripts/deploy-update.php   # on future code updates"

