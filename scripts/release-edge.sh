#!/usr/bin/env bash
# Manual release: build the edge tarball and publish it as a GitHub Release asset.
# Replicates what .github/workflows/deploy.yml job deploy-edge-release does.
# Run from repo root: ./scripts/release-edge.sh [version]
#
# Arguments:
#   version   Optional. e.g. v1.0.0. If omitted, uses latest git tag (git describe --tags --abbrev=0).
#
# Prerequisites:
#   - Docker (for Sail build)
#   - gh CLI (https://cli.github.com), authenticated: gh auth login
#   - .env.edge with VITE_REVERB_APP_KEY, VITE_REVERB_HOST, VITE_REVERB_PORT (or prompted).
#
# Build runs from the CURRENT branch. Creates flexiqueue-$VERSION-edge.tar.gz, uploads via gh release create/upload, then removes the tarball.

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
VERSION=""
TARBALL=""

cleanup() {
    if [ -n "$TARBALL" ] && [ -f "$TARBALL" ]; then
        echo "[release-edge] Removing local tarball $TARBALL"
        rm -f "$TARBALL"
    fi
    echo "[release-edge] Cleanup on exit."
}
trap cleanup EXIT

usage() {
    echo "Usage: $0 [version]"
    echo "       $0 --help"
    echo ""
    echo "  version   Optional. e.g. v1.0.0. If omitted, uses latest git tag."
    echo ""
    echo "Prerequisites: Docker, gh CLI (gh auth login). Reverb vars in .env.edge (VITE_REVERB_APP_KEY, VITE_REVERB_HOST, VITE_REVERB_PORT)."
    exit 0
}

for arg in "$@"; do
    if [ "$arg" = "--help" ] || [ "$arg" = "-h" ]; then
        usage
    fi
done

# ---- Prerequisites ----
if ! command -v docker >/dev/null 2>&1; then
    echo "Error: docker is required. Install Docker and ensure it is running." >&2
    exit 1
fi
if ! command -v gh >/dev/null 2>&1; then
    echo "Error: gh (GitHub CLI) is required. Install from https://cli.github.com and run: gh auth login" >&2
    exit 1
fi

cd "$REPO_ROOT"
if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "Error: Not inside a git repository. Run from the FlexiQueue repo root." >&2
    exit 1
fi

# ---- Version ----
if [ -n "${1:-}" ]; then
    VERSION="$1"
else
    VERSION=$(git describe --tags --abbrev=0 2>/dev/null || true)
    if [ -z "$VERSION" ]; then
        echo "Error: No version passed and no git tag found. Create a tag (e.g. git tag v0.1.0) or pass version: $0 v0.1.0" >&2
        exit 1
    fi
fi

# ---- Reverb vars from .env.edge (grep, no sourcing) ----
VITE_REVERB_APP_KEY=""
VITE_REVERB_HOST=""
VITE_REVERB_PORT=""
if [ -f "$REPO_ROOT/.env.edge" ]; then
    VITE_REVERB_APP_KEY=$(grep -E '^VITE_REVERB_APP_KEY=' "$REPO_ROOT/.env.edge" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)
    [ -z "$VITE_REVERB_APP_KEY" ] && VITE_REVERB_APP_KEY=$(grep -E '^REVERB_APP_KEY=' "$REPO_ROOT/.env.edge" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)
    VITE_REVERB_HOST=$(grep -E '^VITE_REVERB_HOST=' "$REPO_ROOT/.env.edge" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)
    [ -z "$VITE_REVERB_HOST" ] && VITE_REVERB_HOST=$(grep -E '^REVERB_HOST=' "$REPO_ROOT/.env.edge" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)
    VITE_REVERB_PORT=$(grep -E '^VITE_REVERB_PORT=' "$REPO_ROOT/.env.edge" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)
    [ -z "$VITE_REVERB_PORT" ] && VITE_REVERB_PORT=$(grep -E '^REVERB_PORT=' "$REPO_ROOT/.env.edge" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)
fi
VITE_REVERB_APP_KEY="${VITE_REVERB_APP_KEY:-}"
VITE_REVERB_HOST="${VITE_REVERB_HOST:-}"
VITE_REVERB_PORT="${VITE_REVERB_PORT:-6001}"
if [ -z "$VITE_REVERB_APP_KEY" ] || [ -z "$VITE_REVERB_HOST" ]; then
    echo "Error: Set VITE_REVERB_APP_KEY and VITE_REVERB_HOST (or REVERB_APP_KEY, REVERB_HOST) in .env.edge." >&2
    exit 1
fi

export VITE_REVERB_APP_KEY VITE_REVERB_HOST VITE_REVERB_PORT
export VITE_REVERB_SCHEME=https
export VITE_REVERB_VIA_PROXY=true
export WWWUSER="${WWWUSER:-$(id -u)}"
export WWWGROUP="${WWWGROUP:-$(id -g)}"
export VERSION

# ---- Build with Sail (current branch) ----
COMPOSE_CMD="docker compose"
[ -f "$REPO_ROOT/compose.yaml" ] || [ -f "$REPO_ROOT/docker-compose.yml" ] || { echo "Error: compose.yaml not found." >&2; exit 1; }

echo "[release-edge] Building from current branch at $REPO_ROOT (version $VERSION)..."
(cd "$REPO_ROOT" && $COMPOSE_CMD run --rm \
  -e WWWUSER \
  -e WWWGROUP \
  -e VERSION \
  -e VITE_REVERB_APP_KEY \
  -e VITE_REVERB_HOST \
  -e VITE_REVERB_PORT \
  -e VITE_REVERB_SCHEME \
  -e VITE_REVERB_VIA_PROXY \
  -v "$REPO_ROOT:/var/www/html" \
  -w /var/www/html \
  laravel.test bash -c '
  set -e
  composer config platform.php 8.3
  composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
  npm ci
  npm run build
  echo "Building tarball..."
  tar -czf flexiqueue-${VERSION}-edge.tar.gz \
    --exclude=.git \
    --exclude=node_modules \
    --exclude=.env \
    --exclude=storage \
    --exclude=tests \
    --exclude=e2e \
    --exclude=.github \
    --exclude=php-run-scripts \
    .
  echo "Tarball created."
')

TARBALL="$REPO_ROOT/flexiqueue-${VERSION}-edge.tar.gz"
if [ ! -f "$TARBALL" ]; then
    echo "Error: Tarball was not created: $TARBALL" >&2
    exit 1
fi

# ---- GitHub Release ----
if gh release view "$VERSION" >/dev/null 2>&1; then
    echo "[release-edge] Release $VERSION already exists; uploading asset (--clobber)..."
    gh release upload "$VERSION" "$TARBALL" --clobber
else
    echo "[release-edge] Creating release $VERSION and uploading tarball..."
    gh release create "$VERSION" --title "Release $VERSION" --notes "Edge release $VERSION" "$TARBALL"
fi

RELEASE_URL=$(gh release view "$VERSION" --json url -q .url 2>/dev/null || true)
echo ""
echo "=== Success: Edge release complete ==="
echo "  Version: $VERSION"
echo "  Tarball: flexiqueue-${VERSION}-edge.tar.gz (uploaded)"
[ -n "$RELEASE_URL" ] && echo "  URL: $RELEASE_URL"
echo ""

# Remove local tarball after successful upload
rm -f "$TARBALL"
TARBALL=""
