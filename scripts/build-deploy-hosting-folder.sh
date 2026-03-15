#!/usr/bin/env bash
# Build a hosting-ready folder from the prod-hosting branch (no in-progress dev work).
# - Targets PHP 8.2 via composer platform.php
# - Uses .env.hosting for MySQL + Pusher and RUN_SCRIPTS_PASSWORD=123456
# - Includes php-run-scripts in the output
# - Output: releases/flexiqueue-hosting-folder-<timestamp>/

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
  echo "Error: Not inside a git repository. Run from the FlexiQueue repo root." >&2
  exit 1
fi

if [ ! -f "$REPO_ROOT/.env.hosting" ]; then
  echo "Error: .env.hosting not found." >&2
  echo "  Copy .env.hosting.example to .env.hosting and fill DB_*, APP_URL, Pusher, etc." >&2
  exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
  echo "Error: composer not found. Install Composer (PHP 8.2) on this machine." >&2
  exit 1
fi

if ! command -v npm >/dev/null 2>&1; then
  echo "Error: npm not found. Install Node/npm to build assets." >&2
  exit 1
fi

BRANCH="prod-hosting"

# Ensure prod-hosting branch exists at the safe commit (6cec111 = pre edge-central).
if ! git show-ref --verify --quiet "refs/heads/$BRANCH"; then
  echo "Creating $BRANCH branch at safe commit 6cec111..."
  git branch "$BRANCH" 6cec111
fi

PARENT_DIR="$(cd "$REPO_ROOT/.." && pwd)"
PROD_WORKTREE="$PARENT_DIR/flexiqueue-prod-hosting"

if [ ! -d "$PROD_WORKTREE" ]; then
  echo "Creating prod-hosting worktree at $PROD_WORKTREE..."
  git worktree add "$PROD_WORKTREE" "$BRANCH"
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
RELEASES_DIR="$REPO_ROOT/releases"
OUTPUT_DIR="$RELEASES_DIR/flexiqueue-hosting-folder-$STAMP"

mkdir -p "$OUTPUT_DIR"

echo "Building from prod worktree at: $PROD_WORKTREE"
echo "Output folder will be: $OUTPUT_DIR"

# Load hosting env so Vite sees hosting values when building.
set -a
source "$REPO_ROOT/.env.hosting" 2>/dev/null || true
set +a

cd "$PROD_WORKTREE"

echo "Running Composer install for PHP 8.2 (no-dev, optimized autoloader)..."
composer config platform.php 8.2
composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

echo "Installing npm dependencies and building frontend..."
if npm ci; then
  :
else
  npm install
fi
npm run build

echo "Copying production files into output folder (includes php-run-scripts)..."
rsync -a \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='storage/logs' \
  --exclude='storage/framework/cache' \
  --exclude='.env' \
  --exclude='.env.example' \
  --exclude='.env.prod' \
  --exclude='.env.production' \
  --exclude='.env.hosting' \
  --exclude='.cursor' \
  --exclude='.beads' \
  --exclude='tests' \
  --exclude='e2e' \
  --exclude='playwright-report' \
  --exclude='test-results' \
  "$PROD_WORKTREE"/ "$OUTPUT_DIR"/

echo "Injecting hosting env with RUN_SCRIPTS_PASSWORD into output folder as .env..."
cp "$REPO_ROOT/.env.hosting" "$OUTPUT_DIR/.env"

echo ""
echo "Done. Hosting folder is ready to upload via FTP:"
echo "  $OUTPUT_DIR"
echo ""
echo "On the server after upload:"
echo "  1) Ensure document root points to the 'public' folder inside this directory."
echo "  2) Run (from app root via PHP CLI / hosting panel):"
echo "       php php-run-scripts/initial-setup.php   # first time"
echo "       php php-run-scripts/deploy-update.php   # on future code updates"
echo ""
echo "php-run-scripts/run.php will use RUN_SCRIPTS_PASSWORD from .env (currently 123456)."

