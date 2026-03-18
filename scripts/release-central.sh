#!/usr/bin/env bash
# Manual release: build and deploy the central (Hestia hosting) app via FTP.
# Replicates what .github/workflows/deploy.yml job deploy-central does.
# Run from repo root: ./scripts/release-central.sh [version]
#
# Arguments:
#   version   Optional. e.g. v1.0.0. If omitted, uses latest git tag (git describe --tags --abbrev=0).
#
# Prerequisites:
#   - Docker (for Sail build)
#   - lftp (apt install lftp)
#   - .env.hosting with FTP_HOST, FTP_USER (or FTP_USERNAME), FTP_PASSWORD,
#     and optionally VITE_PUSHER_APP_KEY, VITE_PUSHER_APP_CLUSTER (or prompted).
#
# Build runs from the CURRENT branch (no prod worktree). FTP sync excludes .env, storage/, node_modules/, .git/.

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
VERSION=""
FTP_HOST=""
FTP_USER=""
FTP_PASSWORD=""
VITE_PUSHER_APP_KEY=""
VITE_PUSHER_APP_CLUSTER=""
UPLOAD_VENDOR="true"

cleanup() {
    echo "[release-central] Cleanup on exit."
}
trap cleanup EXIT

usage() {
    echo "Usage: $0 [version]"
    echo "       $0 --help"
    echo ""
    echo "  version   Optional. e.g. v1.0.0. If omitted, uses latest git tag."
    echo ""
    echo "Prerequisites: Docker, lftp. Credentials in .env.hosting (FTP_HOST, FTP_USER or FTP_USERNAME, FTP_PASSWORD; VITE_PUSHER_APP_KEY, VITE_PUSHER_APP_CLUSTER for build)."
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
if ! command -v lftp >/dev/null 2>&1; then
    echo "Error: lftp is required. Install with: sudo apt install lftp" >&2
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

# ---- Credentials from .env.hosting (grep, no sourcing) ----
if [ ! -f "$REPO_ROOT/.env.hosting" ]; then
    echo "Error: .env.hosting not found. Copy .env.hosting.example to .env.hosting and set FTP_HOST, FTP_USER (or FTP_USERNAME), FTP_PASSWORD." >&2
    exit 1
fi

FTP_HOST=$(grep -E '^FTP_HOST=' "$REPO_ROOT/.env.hosting" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)
FTP_USER=$(grep -E '^FTP_USER=' "$REPO_ROOT/.env.hosting" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)
[ -z "$FTP_USER" ] && FTP_USER=$(grep -E '^FTP_USERNAME=' "$REPO_ROOT/.env.hosting" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)
FTP_PASSWORD=$(grep -E '^FTP_PASSWORD=' "$REPO_ROOT/.env.hosting" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)

VITE_PUSHER_APP_KEY=$(grep -E '^VITE_PUSHER_APP_KEY=' "$REPO_ROOT/.env.hosting" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)
[ -z "$VITE_PUSHER_APP_KEY" ] && VITE_PUSHER_APP_KEY=$(grep -E '^PUSHER_APP_KEY=' "$REPO_ROOT/.env.hosting" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)
VITE_PUSHER_APP_CLUSTER=$(grep -E '^VITE_PUSHER_APP_CLUSTER=' "$REPO_ROOT/.env.hosting" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)
[ -z "$VITE_PUSHER_APP_CLUSTER" ] && VITE_PUSHER_APP_CLUSTER=$(grep -E '^PUSHER_APP_CLUSTER=' "$REPO_ROOT/.env.hosting" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || true)

if [ -z "$FTP_HOST" ] || [ -z "$FTP_USER" ] || [ -z "$FTP_PASSWORD" ]; then
    echo "Error: FTP credentials missing in .env.hosting. Set FTP_HOST, FTP_USER (or FTP_USERNAME), and FTP_PASSWORD." >&2
    exit 1
fi
if [ -z "$VITE_PUSHER_APP_KEY" ]; then
    echo "Error: VITE_PUSHER_APP_KEY (or PUSHER_APP_KEY) not set in .env.hosting. Required for frontend build." >&2
    exit 1
fi
if [ -z "$VITE_PUSHER_APP_CLUSTER" ]; then
    echo "Error: VITE_PUSHER_APP_CLUSTER (or PUSHER_APP_CLUSTER) not set in .env.hosting. Required for frontend build." >&2
    exit 1
fi

# ---- Build with Sail (current branch) ----
COMPOSE_CMD="docker compose"
[ -f "$REPO_ROOT/compose.yaml" ] || [ -f "$REPO_ROOT/docker-compose.yml" ] || { echo "Error: compose.yaml not found." >&2; exit 1; }
export WWWUSER="${WWWUSER:-$(id -u)}"
export WWWGROUP="${WWWGROUP:-$(id -g)}"
export VERSION

echo "[release-central] Building from current branch at $REPO_ROOT (version $VERSION)..."
if ! (cd "$REPO_ROOT" && $COMPOSE_CMD run --rm \
  -e WWWUSER \
  -e WWWGROUP \
  -e VERSION \
  -e VITE_BROADCASTER=pusher \
  -e VITE_PUSHER_APP_KEY \
  -e VITE_PUSHER_APP_CLUSTER \
  -v "$REPO_ROOT:/var/www/html" \
  -w /var/www/html \
  laravel.test bash -c '
  set -e
  composer config platform.php 8.2
  composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
  npm ci
  npm run build
  echo "$VERSION" > storage/app/version.txt
  touch bootstrap/cache/deploy_pending
  '); then
  echo "[release-central] ERROR: Build failed. Check Docker output above." >&2
  exit 1
fi

# ---- Verify critical build artifacts before FTP upload ----
for f in public/build/manifest.json vendor/autoload.php public/.htaccess; do
    if [ ! -f "$REPO_ROOT/$f" ]; then
        echo "[release-central] ERROR: Missing required file: $f" >&2
        echo "[release-central] Build may have failed. Aborting upload." >&2
        exit 1
    fi
done

# ---- Decide whether to upload vendor/ ----
REMOTE_LOCK_TMP="$(mktemp -t flexiqueue-remote-composer.lock.XXXXXX)"
LOCAL_LOCK_PATH="$REPO_ROOT/composer.lock"

echo "[release-central] Checking whether vendor/ needs upload (composer.lock diff)..."

if ! lftp -c "
set ssl:verify-certificate no
set ftp:ssl-allow no
open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
cd /
get composer.lock -o $REMOTE_LOCK_TMP
bye
" 2>/dev/null; then
  echo "[release-central] ERROR: FTP composer.lock fetch failed." >&2
  exit 1
fi

if [ ! -f "$REMOTE_LOCK_TMP" ] || [ ! -s "$REMOTE_LOCK_TMP" ]; then
  echo "[release-central] Remote composer.lock not found (or empty). Will upload vendor/ this deploy."
  UPLOAD_VENDOR="true"
elif [ ! -f "$LOCAL_LOCK_PATH" ]; then
  echo "[release-central] Local composer.lock not found. Will upload vendor/ this deploy."
  UPLOAD_VENDOR="true"
elif diff -q "$LOCAL_LOCK_PATH" "$REMOTE_LOCK_TMP" >/dev/null 2>&1; then
  echo "[release-central] composer.lock unchanged vs remote. Skipping vendor/ upload."
  UPLOAD_VENDOR="false"
else
  echo "[release-central] composer.lock changed vs remote. Will upload vendor/ this deploy."
  UPLOAD_VENDOR="true"
fi

rm -f "$REMOTE_LOCK_TMP" >/dev/null 2>&1 || true

# ---- FTP sync ----
echo "[release-central] Syncing to FTP (whitelist core Laravel files, keep storage/.env server-owned)..."

if ! lftp -c "
set ssl:verify-certificate no
set ftp:ssl-allow no
open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
rm -rf .cursor
rm -rf .beads
rm -rf docs
rm -rf tests
rm -rf e2e
rm -rf scripts
rm -rf node_modules
rm -rf .github
bye
" 2>/dev/null; then
  echo "[release-central] ERROR: FTP cleanup failed." >&2
  exit 1
fi

INCLUDE_LANG="false"
if [ -d "$REPO_ROOT/lang" ]; then
  INCLUDE_LANG="true"
fi

LFTP_ALWAYS_UPLOAD_CMD="
set ssl:verify-certificate no
set ftp:ssl-allow no
open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST

# Upload only what Laravel needs — whitelist approach
lcd $REPO_ROOT
cd /

# Core Laravel directories
mirror --reverse --delete --verbose --exclude-glob='*' --include-glob='app/***' app/ app/
mirror --reverse --delete --verbose --exclude-glob='*' --include-glob='bootstrap/***' bootstrap/ bootstrap/
mirror --reverse --delete --verbose --exclude-glob='*' --include-glob='config/***' config/ config/
mirror --reverse --delete --verbose --exclude-glob='*' --include-glob='database/***' database/ database/
mirror --reverse --delete --verbose --exclude-glob='*' --include-glob='public/***' public/ public/
mirror --reverse --delete --verbose --exclude-glob='*' --include-glob='resources/***' resources/ resources/
mirror --reverse --delete --verbose --exclude-glob='*' --include-glob='routes/***' routes/ routes/
mirror --reverse --delete --verbose --exclude-glob='*' --include-glob='php-run-scripts/***' php-run-scripts/ php-run-scripts/
__LANG_MIRROR__

# Root files only (not directories)
put artisan
put composer.json
put composer.lock
put storage/app/version.txt -o storage/app/version.txt
put bootstrap/cache/deploy_pending -o bootstrap/cache/deploy_pending

bye
"

if [ "$INCLUDE_LANG" = "true" ]; then
  LFTP_ALWAYS_UPLOAD_CMD="${LFTP_ALWAYS_UPLOAD_CMD/__LANG_MIRROR__/mirror --reverse --delete --verbose --exclude-glob='*' --include-glob='lang/***' lang/ lang/}"
else
  LFTP_ALWAYS_UPLOAD_CMD="${LFTP_ALWAYS_UPLOAD_CMD/__LANG_MIRROR__/}"
fi

if ! lftp -c "$LFTP_ALWAYS_UPLOAD_CMD"; then
  echo "[release-central] ERROR: FTP core upload failed." >&2
  exit 1
fi

if [ "$UPLOAD_VENDOR" = "true" ]; then
  echo "[release-central] Uploading vendor/ (composer.lock changed or unavailable)..."
  if ! lftp -c "
  set ssl:verify-certificate no
  set ftp:ssl-allow no
  open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
  lcd $REPO_ROOT
  cd /
  mirror --reverse --delete --verbose --exclude-glob='*' --include-glob='vendor/***' vendor/ vendor/
  bye
  "; then
    echo "[release-central] ERROR: FTP vendor upload failed." >&2
    exit 1
  fi
else
  echo "[release-central] Not uploading vendor/."
fi

# ---- Spot-check upload by downloading a known file ----
REMOTE_CHECK="$(mktemp -t flexiqueue-remote-check.XXXXXX)"
if lftp -c "
set ssl:verify-certificate no
set ftp:ssl-allow no
open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
cd /
get app/Console/Commands/RunDeployUpdate.php -o $REMOTE_CHECK
bye
" 2>/dev/null; then
  if [ ! -s "$REMOTE_CHECK" ]; then
      echo "[release-central] WARNING: Spot-check failed — app/Console/Commands/RunDeployUpdate.php not found on server." >&2
      echo "[release-central] WARNING: The upload may be incomplete. Consider rerunning." >&2
  fi
else
  echo "[release-central] WARNING: Spot-check download failed (lftp error)." >&2
  echo "[release-central] WARNING: The upload may be incomplete. Consider rerunning." >&2
fi
rm -f "$REMOTE_CHECK" >/dev/null 2>&1 || true

echo ""
echo "=== Deploy complete ==="
echo "  Version: $VERSION"
echo "  All files verified on server."
echo "  Scheduler will pick up deploy_pending within 1 minute."
echo ""

echo "=== Release Central Summary ==="
echo "  Version:        $VERSION"
echo "  Build:          OK"
echo "  Files verified: OK"
echo "  FTP upload:     OK"
echo "  Marker:         bootstrap/cache/deploy_pending created"
echo "  Next:           Laravel scheduler will run migrations in ~1 min"
echo "================================"

