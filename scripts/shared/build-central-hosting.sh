#!/usr/bin/env bash
set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
  echo "Error: Not inside a git repository. Run from the FlexiQueue repo root." >&2
  exit 1
fi
if ! command -v composer >/dev/null 2>&1; then
  echo "Error: composer not found. Install Composer to build for hosting." >&2
  exit 1
fi
if ! command -v npm >/dev/null 2>&1; then
  echo "Error: npm not found. Install Node/npm to build for hosting." >&2
  exit 1
fi

if [ ! -f "$REPO_ROOT/.env.hosting" ]; then
  echo "Error: .env.hosting not found." >&2
  echo "  Copy .env.hosting.example to .env.hosting and fill in your Pusher + MySQL credentials:" >&2
  echo "    cp .env.hosting.example .env.hosting" >&2
  echo "  Then edit .env.hosting (DB_*, PUSHER_*, APP_URL)." >&2
  exit 1
fi

unset REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET REVERB_HOST REVERB_PORT REVERB_SCHEME
unset VITE_REVERB_APP_KEY VITE_REVERB_HOST VITE_REVERB_PORT VITE_REVERB_SCHEME VITE_REVERB_VIA_PROXY
unset PUSHER_APP_ID PUSHER_APP_KEY PUSHER_APP_SECRET PUSHER_APP_CLUSTER
unset VITE_BROADCASTER VITE_PUSHER_APP_KEY VITE_PUSHER_APP_CLUSTER
set -a
source "$REPO_ROOT/.env.hosting" 2>/dev/null || true
set +a

export VITE_BROADCASTER="${VITE_BROADCASTER:-pusher}"
export VITE_PUSHER_APP_KEY="${VITE_PUSHER_APP_KEY:-$PUSHER_APP_KEY}"
export VITE_PUSHER_APP_CLUSTER="${VITE_PUSHER_APP_CLUSTER:-$PUSHER_APP_CLUSTER}"

if [ -z "$VITE_PUSHER_APP_KEY" ]; then
  echo "Error: PUSHER_APP_KEY (or VITE_PUSHER_APP_KEY) not set in .env.hosting." >&2
  exit 1
fi

echo "Building for hosting in $REPO_ROOT..."
composer config platform.php 8.2
composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
npm ci
npm run build

tar -czf flexiqueue-hosting.tar.gz \
  --exclude='.git' --exclude='node_modules' --exclude='.env' --exclude='.env.example' --exclude='.env.backup' \
  --exclude='.env.production' --exclude='.env.hosting' --exclude='storage' --exclude='.phpunit.cache' \
  --exclude='.phpunit.result.cache' --exclude='.github' --exclude='tests' --exclude='playwright-report' \
  --exclude='test-results' --exclude='e2e' --exclude='.cursor' --exclude='.beads' --exclude='docs' \
  --exclude='docs v1' --exclude='other files' --exclude='.styleci.yml' --exclude='.editorconfig' \
  --exclude='phpunit.xml' --exclude='playwright.config.js' --exclude='compose.yaml' --exclude='AGENTS.md' \
  --exclude='CHANGELOG.md' --exclude='planning-prompt-v1.md' --exclude='ui_tasks.json' \
  --exclude='apply_ui_fixes.js' --exclude='patch_*.js' --exclude='dottedflowedge*.txt' \
  --exclude='last23*.jsonl' --exclude='manuscript*' --exclude='*.docx' --exclude='nul' \
  --exclude='root@*' --exclude='public/hot' --exclude='scripts/build-deploy-tarball.sh' \
  --exclude='scripts/build-deploy-tarball-sail.sh' --exclude='scripts/build-deploy-hosting.sh' \
  --exclude='scripts/deploy-to-pi.sh' --exclude='scripts/sail-setup.sh' --exclude='scripts/start-dev.sh' \
  --exclude='scripts/full-system-test.sh' --exclude='scripts/import-phase1-beads.sh' .

composer config --unset platform.php 2>/dev/null || true
