#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd)"
cd "$REPO_ROOT"

echo "=== FlexiQueue Edge APK Build Pipeline ==="

# Step 1: Build Vite assets
echo "[1/4] Building Vite assets..."
npm ci
npm run build

# Step 2: Package edge bundle
echo "[2/4] Packaging edge bundle..."
bash scripts/edge/build/phone/package-edge-termux.sh

# Step 3: Sync Capacitor
echo "[3/4] Syncing Capacitor..."
npx cap sync android

# Step 4: Build APK
echo "[4/4] Building APK..."
cd android
./gradlew assembleDebug

APK_PATH="app/build/outputs/apk/debug/app-debug.apd"
if [ -f "$APK_PATH" ]; then
  APK_SIZE=$(du -h "$APK_PATH" | cut -f1)
  echo "=== APK built successfully: $APK_PATH ($APK_SIZE) ==="
  cp "$APK_PATH" "../flexiqueue-edge-phone.apk"
  echo "=== Copied to flexiqueue-edge-phone.apk ==="
else
  echo "ERROR: APK not found at $APK_PATH" >&2
  exit 1
fi