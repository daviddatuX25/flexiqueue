#!/usr/bin/env bash
set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
  echo "Error: Not inside a git repository." >&2
  exit 1
fi
command -v composer >/dev/null 2>&1 || { echo "Error: composer not found." >&2; exit 1; }
command -v npm >/dev/null 2>&1 || { echo "Error: npm not found." >&2; exit 1; }

unset REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET REVERB_HOST REVERB_PORT REVERB_SCHEME
unset VITE_REVERB_APP_KEY VITE_REVERB_HOST VITE_REVERB_PORT VITE_REVERB_SCHEME VITE_REVERB_VIA_PROXY
[ -f "$REPO_ROOT/.env.prod" ] && set -a && source "$REPO_ROOT/.env.prod" 2>/dev/null && set +a
export VITE_REVERB_APP_KEY="${REVERB_APP_KEY:-flexiqueue-app-key}"
export VITE_REVERB_HOST="${REVERB_HOST:-localhost}"
export VITE_REVERB_PORT="${REVERB_PORT:-6001}"
export VITE_REVERB_SCHEME="${REVERB_SCHEME:-http}"
export VITE_REVERB_VIA_PROXY="${VITE_REVERB_VIA_PROXY:-true}"

composer config platform.php 8.3
composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
npm ci
npm run build

tar -czf flexiqueue-deploy.tar.gz \
  --exclude='.git' --exclude='node_modules' --exclude='.env' --exclude='.env.example' \
  --exclude='.env.backup' --exclude='.env.production' --exclude='storage' --exclude='.phpunit.cache' \
  --exclude='.phpunit.result.cache' --exclude='.github' --exclude='tests' --exclude='playwright-report' \
  --exclude='test-results' --exclude='e2e' --exclude='.cursor' --exclude='.beads' --exclude='docs' \
  --exclude='docs v1' --exclude='other files' --exclude='.styleci.yml' --exclude='.editorconfig' \
  --exclude='phpunit.xml' --exclude='playwright.config.js' --exclude='compose.yaml' --exclude='AGENTS.md' \
  --exclude='CHANGELOG.md' --exclude='planning-prompt-v1.md' --exclude='ui_tasks.json' \
  --exclude='apply_ui_fixes.js' --exclude='patch_*.js' --exclude='dottedflowedge*.txt' \
  --exclude='last23*.jsonl' --exclude='manuscript*' --exclude='*.docx' --exclude='nul' \
  --exclude='root@*' --exclude='public/hot' --exclude='scripts/build-deploy-tarball.sh' \
  --exclude='scripts/build-deploy-tarball-sail.sh' --exclude='scripts/deploy-to-pi.sh' \
  --exclude='scripts/deploy-to-pi-edge.sh' --exclude='scripts/sail-setup.sh' \
  --exclude='scripts/start-dev.sh' --exclude='scripts/full-system-test.sh' \
  --exclude='scripts/import-phase1-beads.sh' .

composer config --unset platform.php 2>/dev/null || true
