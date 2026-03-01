#!/usr/bin/env bash
# Build FlexiQueue deploy tarball locally for Orange Pi deployment.
# Run from repo root: ./scripts/build-deploy-tarball.sh
# Output: flexiqueue-deploy.tar.gz in repo root.

set -e
cd "$(dirname "$0")/.."

echo "Installing Composer dependencies (--no-dev)..."
composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction

echo "Installing npm and building..."
npm ci
npm run build

echo "Creating deploy tarball (production-only files)..."
tar -czf flexiqueue-deploy.tar.gz \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='.env' \
  --exclude='.env.*' \
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

echo "Done. Output: $(pwd)/flexiqueue-deploy.tar.gz"
echo "Deploy: scp flexiqueue-deploy.tar.gz root@<pi-ip>:/tmp/"
echo "Then SSH and: cd /var/www/flexiqueue && sudo tar -xzf /tmp/flexiqueue-deploy.tar.gz && sudo chown -R www-data:www-data . && php artisan migrate --force && php artisan config:cache && php artisan route:cache"
