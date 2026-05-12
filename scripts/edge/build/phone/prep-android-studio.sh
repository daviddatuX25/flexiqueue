#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd)"
cd "$REPO_ROOT"

echo "=== FlexiQueue Edge: Prep for Android Studio ==="
echo ""

# Step 1: Build Vite assets
echo "[1/3] Building Vite assets..."
npm run build
echo ""

# Step 2: Package edge bundle
echo "[2/3] Packaging edge bundle..."
bash scripts/edge/build/phone/package-edge-termux.sh
echo ""

# Step 3: Sync Capacitor
echo "[3/3] Syncing Capacitor..."
npx cap sync android
echo ""

echo "=== Prep complete ==="
echo ""
echo "Opening Android Studio..."
echo ""

# Open Android Studio with the android project
STUDIO="/d/Program Files/Android/Android Studio/bin/studio64.exe"
"$STUDIO" "$REPO_ROOT/android" &

echo "=== Next Steps ==="
echo ""
echo "1. Wait for Gradle sync to finish in Android Studio"
echo "2. Plug your phone in (USB debugging ON)"
echo "3. Select your phone in the device dropdown (top bar)"
echo "4. Hit Run (green play) or Shift+F10"
echo "5. Watch Logcat — filter by: tag:MainActivity"
echo ""
echo "Expected boot sequence in Logcat:"
echo "  [1/4] Extracting edge bundle..."
echo "  [2/4] Running Termux bootstrap (installing packages)..."
echo "  [3/4] Starting Termux edge stack..."
echo "  [4/4] Polling health endpoint..."
echo ""
echo "=== For further troubleshooting, follow the playbook ==="
echo "  .claude/projects/D--Projects-flexiqueue/memory/edge-phone-debug-playbook.md"
echo ""