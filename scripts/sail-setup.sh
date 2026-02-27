#!/usr/bin/env bash
# FlexiQueue Sail Setup — Docker-only (no local PHP, Composer, or Node required)
#
# Prerequisites: Docker Desktop (or Docker + Docker Compose), Git
# Run from project root: ./scripts/sail-setup.sh
# On Windows: use WSL or Git Bash

set -e

cd "$(dirname "$0")/.."
PROJECT_ROOT="$(pwd)"

echo "=== FlexiQueue Sail Setup (Docker-only) ==="
echo "Project root: $PROJECT_ROOT"
echo ""

# 1. Composer install via Docker
echo "[1/6] Installing PHP dependencies (composer install)..."
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$PROJECT_ROOT:/var/www/html" \
    -w /var/www/html \
    laravelsail/php82-composer:latest \
    composer install --ignore-platform-reqs --no-interaction

# 2. Environment file
echo "[2/6] Setting up .env..."
if [ ! -f .env ]; then
    cp .env.example .env
    echo "    Created .env from .env.example"
else
    echo "    .env already exists, skipping"
fi

# 3. Sail install with MariaDB + Redis (non-interactive)
echo "[3/6] Installing Sail with MariaDB and Redis..."
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$PROJECT_ROOT:/var/www/html" \
    -w /var/www/html \
    laravelsail/php82-composer:latest \
    php artisan sail:install --with=mariadb,redis --no-interaction

# 4. Start Sail containers
echo "[4/6] Starting Sail containers..."
./vendor/bin/sail up -d

# 5. Application key
echo "[5/6] Generating application key..."
./vendor/bin/sail artisan key:generate

# 6. NPM install and build
echo "[6/6] Installing Node dependencies and building assets..."
./vendor/bin/sail npm install
./vendor/bin/sail npm run build

echo ""
echo "=== Setup complete ==="
echo ""
echo "Sail is running. Access the app at http://localhost"
echo ""
echo "Common commands:"
echo "  ./scripts/start-dev.sh          # Start Sail + Reverb in one run (recommended)"
echo "  ./vendor/bin/sail down          # Stop containers"
echo "  ./vendor/bin/sail up -d         # Start containers only"
echo "  ./vendor/bin/sail artisan reverb:start   # Reverb only (after sail up)"
echo "  ./vendor/bin/sail artisan migrate   # Run migrations (when available)"
echo "  ./vendor/bin/sail npm run dev   # Vite dev server"
echo "  ./vendor/bin/sail mysql         # MariaDB CLI"
echo ""
echo "Tip: Add 'alias sail=\"./vendor/bin/sail\"' to your shell profile."
echo ""
