#!/usr/bin/env bash
# Build FlexiQueue deploy tarball using Laravel Sail (PHP + Node inside container).
# Use when you have Sail up and want to avoid installing PHP/Node on the host.
# Run from repo root: ./scripts/build-deploy-tarball-sail.sh
# Output: flexiqueue-deploy.tar.gz in repo root.
# Note: Runs everything in one exec so that composer install --no-dev (which removes Sail) doesn't break the script.

set -e
cd "$(dirname "$0")/.."

if [ -f ./vendor/bin/sail ]; then
  SAIL="./vendor/bin/sail"
else
  SAIL="docker compose"
  echo "Using docker compose (Sail binary not in vendor)."
fi

echo "Building inside container (composer, npm, tar)..."
$SAIL exec laravel.test bash -c '
  set -e
  cd /var/www/html
  echo "Composer install --no-dev (platform PHP 8.3 for Orange Pi prod)..."
  composer config platform.php 8.3
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
'

echo "Output: $(pwd)/flexiqueue-deploy.tar.gz"
if [ -f flexiqueue-deploy.tar.gz ]; then
  ls -la flexiqueue-deploy.tar.gz
fi

# Restore dev dependencies (composer install --no-dev removed Sail)
echo "Restoring dev dependencies..."
$SAIL exec laravel.test composer install --no-interaction 2>/dev/null || docker compose exec laravel.test composer install --no-interaction 2>/dev/null || true

echo ""
echo "Deploy: scp flexiqueue-deploy.tar.gz root@<pi-ip>:/tmp/"
echo "Then SSH and: cd /var/www/flexiqueue && sudo tar -xzf /tmp/flexiqueue-deploy.tar.gz && sudo chown -R www-data:www-data . && php artisan migrate --force && php artisan config:cache && php artisan route:cache"
