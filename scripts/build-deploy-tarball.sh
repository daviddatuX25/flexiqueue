#!/usr/bin/env bash
# Build FlexiQueue deploy tarball locally for Orange Pi deployment.
# Builds from a temporary prod worktree so the main repo (and dev) is untouched.
# Run from repo root: ./scripts/build-deploy-tarball.sh
# Output: flexiqueue-deploy.tar.gz in repo root.

set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
  echo "Error: Not inside a git repository. Run from the FlexiQueue repo root." >&2
  exit 1
fi
if ! command -v composer >/dev/null 2>&1; then
  echo "Error: composer not found. Install Composer or use: ./scripts/build-deploy-tarball-sail.sh" >&2
  exit 1
fi
if ! command -v npm >/dev/null 2>&1; then
  echo "Error: npm not found. Install Node/npm or use: ./scripts/build-deploy-tarball-sail.sh" >&2
  exit 1
fi

source "$SCRIPT_DIR/lib/git-worktree.sh"

ensure_prod_branch "[FlexiQueue]"
ensure_prod_worktree_temporary
trap cleanup_prod_worktree_temporary EXIT

echo "Building in prod worktree at $PROD_WORKTREE..."
cd "$PROD_WORKTREE"

echo "Installing Composer dependencies (--no-dev, platform PHP 8.3 for Orange Pi prod)..."
composer config platform.php 8.3
composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

echo "Installing npm and building..."
npm ci
npm run build

echo "Creating deploy tarball (production-only files; includes scripts/pi/ for Reverb, zerotier-when-idle, nginx, etc.)..."
tar -czf flexiqueue-deploy.tar.gz \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='.env' \
  --exclude='.env.example' \
  --exclude='.env.backup' \
  --exclude='.env.production' \
  --exclude='storage' \
  --exclude='.phpunit.cache' \
  --exclude='.phpunit.result.cache' \
  --exclude='.github' \
  --exclude='tests' \
  --exclude='playwright-report' \
  --exclude='test-results' \
  --exclude='e2e' \
  --exclude='.cursor' \
  --exclude='.beads' \
  --exclude='docs' \
  --exclude='docs v1' \
  --exclude='other files' \
  --exclude='.styleci.yml' \
  --exclude='.editorconfig' \
  --exclude='phpunit.xml' \
  --exclude='playwright.config.js' \
  --exclude='compose.yaml' \
  --exclude='AGENTS.md' \
  --exclude='CHANGELOG.md' \
  --exclude='planning-prompt-v1.md' \
  --exclude='ui_tasks.json' \
  --exclude='apply_ui_fixes.js' \
  --exclude='patch_*.js' \
  --exclude='dottedflowedge*.txt' \
  --exclude='last23*.jsonl' \
  --exclude='manuscript*' \
  --exclude='*.docx' \
  --exclude='nul' \
  --exclude='root@*' \
  --exclude='public/hot' \
  --exclude='scripts/build-deploy-tarball.sh' \
  --exclude='scripts/build-deploy-tarball-sail.sh' \
  --exclude='scripts/deploy-to-pi.sh' \
  --exclude='scripts/sail-setup.sh' \
  --exclude='scripts/start-dev.sh' \
  --exclude='scripts/full-system-test.sh' \
  --exclude='scripts/import-phase1-beads.sh' \
  .

composer config --unset platform.php 2>/dev/null || true

if [ ! -f flexiqueue-deploy.tar.gz ]; then
  echo "Error: Tarball was not created in worktree." >&2
  exit 1
fi
cp flexiqueue-deploy.tar.gz "$REPO_ROOT/flexiqueue-deploy.tar.gz"
cd "$REPO_ROOT"

echo "Done. Output: $REPO_ROOT/flexiqueue-deploy.tar.gz"
if [ -f flexiqueue-deploy.tar.gz ]; then
  ls -la flexiqueue-deploy.tar.gz
fi

print_build_complete_message_if_not_prod
