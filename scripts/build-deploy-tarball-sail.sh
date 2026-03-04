#!/usr/bin/env bash
# Build FlexiQueue deploy tarball using Laravel Sail (PHP + Node inside container).
# Builds from a temporary prod worktree so the main repo (and dev Sail) is untouched.
# Run from repo root: ./scripts/build-deploy-tarball-sail.sh
# Output: flexiqueue-deploy.tar.gz in repo root (or in current worktree if run from deploy script).

set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
  echo "Error: Not inside a git repository. Run from the FlexiQueue repo root." >&2
  exit 1
fi

source "$SCRIPT_DIR/lib/git-worktree.sh"

ensure_prod_branch "[FlexiQueue]"
ensure_prod_worktree_temporary
trap cleanup_prod_worktree_temporary EXIT

MAIN_REPO_ROOT="$(get_main_repo_root)"
# Use docker compose run (not sail run) so we can mount the worktree; sail run uses exec (same container/mount).
if [ -f "$MAIN_REPO_ROOT/compose.yaml" ] || [ -f "$MAIN_REPO_ROOT/docker-compose.yml" ]; then
  COMPOSE_CMD="docker compose"
  if ! command -v docker >/dev/null 2>&1; then
    echo "Docker is required for Sail build. Install Docker or run build-deploy-tarball.sh on the host." >&2
    exit 1
  fi
else
  echo "compose.yaml not found in $MAIN_REPO_ROOT." >&2
  exit 1
fi

uid="$(id -u)"
gid="$(id -g)"

echo "Building inside container (prod worktree at $PROD_WORKTREE)..."
(cd "$MAIN_REPO_ROOT" && $COMPOSE_CMD run --rm \
  -v "$PROD_WORKTREE:/var/www/html" \
  -w /var/www/html \
  -u "${uid}:${gid}" \
  laravel.test bash -c '
  set -e
  cd /var/www/html
  echo "Composer install --no-dev (platform PHP 8.3 for Orange Pi prod)..."
  composer config platform.php 8.3
  rm -rf vendor
  composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
  echo "npm ci && npm run build..."
  npm ci && npm run build
  echo "Creating tarball (production-only files; includes scripts/pi/ for Reverb, zerotier-when-idle, nginx, etc.)..."
  tar -czf /tmp/flexiqueue-deploy.tar.gz \
    --exclude=.git \
    --exclude=node_modules \
    --exclude=.env \
    --exclude=.env.example \
    --exclude=.env.backup \
    --exclude=.env.production \
    --exclude=storage \
    --exclude=.phpunit.cache \
    --exclude=.phpunit.result.cache \
    --exclude=.github \
    --exclude=tests \
    --exclude=playwright-report \
    --exclude=test-results \
    --exclude=e2e \
    --exclude=.cursor \
    --exclude=.beads \
    --exclude=docs \
    --exclude="docs v1" \
    --exclude="other files" \
    --exclude=.styleci.yml \
    --exclude=.editorconfig \
    --exclude=phpunit.xml \
    --exclude=playwright.config.js \
    --exclude=compose.yaml \
    --exclude=AGENTS.md \
    --exclude=CHANGELOG.md \
    --exclude=planning-prompt-v1.md \
    --exclude=ui_tasks.json \
    --exclude=apply_ui_fixes.js \
    --exclude="patch_*.js" \
    --exclude="dottedflowedge*.txt" \
    --exclude="last23*.jsonl" \
    --exclude=manuscript* \
    --exclude="*.docx" \
    --exclude=nul \
    --exclude="root@*" \
    --exclude=public/hot \
    --exclude=scripts/build-deploy-tarball.sh \
    --exclude=scripts/build-deploy-tarball-sail.sh \
    --exclude=scripts/deploy-to-pi.sh \
    --exclude=scripts/sail-setup.sh \
    --exclude=scripts/start-dev.sh \
    --exclude=scripts/full-system-test.sh \
    --exclude=scripts/import-phase1-beads.sh \
    -C /var/www/html .
  mv /tmp/flexiqueue-deploy.tar.gz /var/www/html/flexiqueue-deploy.tar.gz
  echo "Done."
')

if [ ! -f "$PROD_WORKTREE/flexiqueue-deploy.tar.gz" ]; then
  echo "Error: Build did not produce tarball in worktree. Check container output above." >&2
  exit 1
fi
if [ "$PROD_WORKTREE" != "$REPO_ROOT" ]; then
  cp "$PROD_WORKTREE/flexiqueue-deploy.tar.gz" "$REPO_ROOT/flexiqueue-deploy.tar.gz"
fi

cd "$REPO_ROOT"
echo "Output: $REPO_ROOT/flexiqueue-deploy.tar.gz"
if [ -f flexiqueue-deploy.tar.gz ]; then
  ls -la flexiqueue-deploy.tar.gz
fi

print_build_complete_message_if_not_prod
