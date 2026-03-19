#!/usr/bin/env bash
# Manual release: build and deploy the central (Hestia hosting) app via FTP.
# Replicates what .github/workflows/deploy.yml job deploy-central does.
# Run from repo root: ./scripts/release-central.sh [version]
#
# Arguments:
#   version   Optional. e.g. v1.0.0. If omitted, uses latest git tag (git describe --tags --abbrev=0).
#
# Prerequisites:
#   - lftp (apt install lftp)
#   - Build tools:
#       - Default: Docker (for Sail build)
#       - Local mode (--local): PHP 8.2+, Composer, Node 20+, npm (Laragon OK)
#   - .env.hosting with FTP_HOST, FTP_USER (or FTP_USERNAME), FTP_PASSWORD,
#     and optionally VITE_PUSHER_APP_KEY, VITE_PUSHER_APP_CLUSTER (or prompted).
#
# Build runs from a detached worktree at HEAD so your current working tree is not modified.
# Upload uses a staging folder (copy of required deploy files).

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
VERSION=""
FTP_HOST=""
FTP_USER=""
FTP_PASSWORD=""
VITE_PUSHER_APP_KEY=""
VITE_PUSHER_APP_CLUSTER=""
PUSHER_APP_KEY=""
PUSHER_APP_CLUSTER=""
BUILD_MODE="docker" # docker|local
KEEP_WORKTREE="0"
DO_FTP="1"
WORKTREE_DIR=""
STAGE_DIR=""
BUILD_ROOT=""

cleanup() {
    echo "[release-central] Cleanup on exit."
    if [ -n "${WORKTREE_DIR:-}" ] && [ -d "${WORKTREE_DIR:-}" ] && [ "$KEEP_WORKTREE" != "1" ]; then
        # Best-effort cleanup; don't fail the script on cleanup errors.
        (cd "$REPO_ROOT" && git worktree remove --force "$WORKTREE_DIR" >/dev/null 2>&1) || true
    fi
}
trap cleanup EXIT

usage() {
    echo "Usage: $0 [version]"
    echo "       $0 --help"
    echo "       $0 --local [version]"
    echo "       $0 --keep-worktree [--local] [version]"
    echo "       $0 --no-ftp [--local] [version]"
    echo "       $0 --build-only [--local] [version]"
    echo ""
    echo "  version   Optional. e.g. v1.0.0. If omitted, uses latest git tag."
    echo ""
    echo "Options:"
    echo "  --local          Build on this machine (no Docker). Requires php/composer/node/npm on PATH."
    echo "  --keep-worktree  Do not delete the temporary worktree (debugging)."
    echo "  --no-ftp         Skip lftp upload; build + stage only (manual FTP upload)."
    echo "  --build-only     Alias of --no-ftp."
    echo ""
    echo "Prerequisites: lftp. Credentials in .env.hosting (FTP_HOST, FTP_USER or FTP_USERNAME, FTP_PASSWORD; PUSHER_* for build)."
    exit 0
}

ARGS=()
for arg in "$@"; do
    if [ "$arg" = "--help" ] || [ "$arg" = "-h" ]; then
        usage
    elif [ "$arg" = "--local" ]; then
        BUILD_MODE="local"
    elif [ "$arg" = "--keep-worktree" ]; then
        KEEP_WORKTREE="1"
    elif [ "$arg" = "--no-ftp" ] || [ "$arg" = "--build-only" ]; then
        DO_FTP="0"
    else
        ARGS+=("$arg")
    fi
done

# ---- Prerequisites ----
if [ "$DO_FTP" = "1" ]; then
    if ! command -v lftp >/dev/null 2>&1; then
        echo "Error: lftp is required for FTP upload. Install it or run with --no-ftp to build only." >&2
        exit 1
    fi
fi
if [ "$BUILD_MODE" = "docker" ]; then
    if ! command -v docker >/dev/null 2>&1; then
        echo "Error: docker is required for the default build mode. Re-run with --local to build on this machine." >&2
        exit 1
    fi
else
    if ! command -v composer >/dev/null 2>&1; then
        echo "Error: composer not found. Install PHP+Composer (Laragon OK) or run without --local to use Docker." >&2
        exit 1
    fi
    if ! command -v npm >/dev/null 2>&1; then
        echo "Error: npm not found. Install Node.js (20+) or run without --local to use Docker." >&2
        exit 1
    fi
fi

cd "$REPO_ROOT"
if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "Error: Not inside a git repository. Run from the FlexiQueue repo root." >&2
    exit 1
fi

# ---- Version ----
if [ -n "${ARGS[0]:-}" ]; then
    VERSION="${ARGS[0]}"
else
    VERSION=$(git describe --tags --abbrev=0 2>/dev/null || true)
    if [ -z "$VERSION" ]; then
        echo "Error: No version passed and no git tag found. Create a tag (e.g. git tag v0.1.0) or pass version: $0 v0.1.0" >&2
        exit 1
    fi
fi

# ---- Credentials from .env.hosting ----
if [ ! -f "$REPO_ROOT/.env.hosting" ]; then
    echo "Error: .env.hosting not found. Copy .env.hosting.example to .env.hosting and set FTP_HOST, FTP_USER (or FTP_USERNAME), FTP_PASSWORD." >&2
    exit 1
fi

unset FTP_HOST FTP_USER FTP_USERNAME FTP_PASSWORD
unset PUSHER_APP_KEY PUSHER_APP_CLUSTER VITE_PUSHER_APP_KEY VITE_PUSHER_APP_CLUSTER VITE_BROADCASTER
set -a
# shellcheck disable=SC1090
source "$REPO_ROOT/.env.hosting" 2>/dev/null || true
set +a

FTP_HOST="${FTP_HOST:-}"
FTP_USER="${FTP_USER:-${FTP_USERNAME:-}}"
FTP_PASSWORD="${FTP_PASSWORD:-}"

PUSHER_APP_KEY="${PUSHER_APP_KEY:-}"
PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER:-}"

is_interpolated() {
    case "${1:-}" in
        *'${'*'}'* ) return 0 ;;
        *'$('*')'* ) return 0 ;;
        *'`'* ) return 0 ;;
        * ) return 1 ;;
    esac
}

# Resolve Vite vars safely even if .env.hosting uses VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}".
VITE_PUSHER_APP_KEY="${VITE_PUSHER_APP_KEY:-}"
if [ -z "$VITE_PUSHER_APP_KEY" ] || is_interpolated "$VITE_PUSHER_APP_KEY"; then
    VITE_PUSHER_APP_KEY="$PUSHER_APP_KEY"
fi

VITE_PUSHER_APP_CLUSTER="${VITE_PUSHER_APP_CLUSTER:-}"
if [ -z "$VITE_PUSHER_APP_CLUSTER" ] || is_interpolated "$VITE_PUSHER_APP_CLUSTER"; then
    VITE_PUSHER_APP_CLUSTER="$PUSHER_APP_CLUSTER"
fi

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

# ---- Build in a detached worktree (keeps current working tree clean) ----
TS="$(date +%Y%m%d%H%M%S)"
WORKTREE_DIR="$REPO_ROOT/.build/worktrees/release-central-${VERSION}-${TS}"
STAGE_DIR="$REPO_ROOT/.build/stage/release-central-${VERSION}-${TS}"
mkdir -p "$WORKTREE_DIR" "$STAGE_DIR"

echo "[release-central] Creating detached worktree at $WORKTREE_DIR ..."
git worktree add --detach "$WORKTREE_DIR" HEAD >/dev/null

export VERSION
export VITE_BROADCASTER="pusher"
export VITE_PUSHER_APP_KEY
export VITE_PUSHER_APP_CLUSTER

echo "[release-central] Building (mode: $BUILD_MODE) from $WORKTREE_DIR (version $VERSION)..."
if [ "$BUILD_MODE" = "docker" ]; then
    COMPOSE_CMD="docker compose"
    [ -f "$REPO_ROOT/compose.yaml" ] || [ -f "$REPO_ROOT/docker-compose.yml" ] || { echo "Error: compose.yaml not found." >&2; exit 1; }
    export WWWUSER="${WWWUSER:-$(id -u)}"
    export WWWGROUP="${WWWGROUP:-$(id -g)}"

    if ! (cd "$REPO_ROOT" && $COMPOSE_CMD run --rm \
      -e WWWUSER \
      -e WWWGROUP \
      -e VERSION \
      -e VITE_BROADCASTER \
      -e VITE_PUSHER_APP_KEY \
      -e VITE_PUSHER_APP_CLUSTER \
      -v "$WORKTREE_DIR:/var/www/html" \
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
else
    if ! (cd "$WORKTREE_DIR" && \
      composer config platform.php 8.2 && \
      composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --ignore-platform-reqs && \
      npm ci && \
      npm run build && \
      mkdir -p storage/app bootstrap/cache && \
      echo "$VERSION" > storage/app/version.txt && \
      touch bootstrap/cache/deploy_pending); then
      echo "[release-central] ERROR: Local build failed. Check the output above." >&2
      exit 1
    fi
    (cd "$WORKTREE_DIR" && composer config --unset platform.php >/dev/null 2>&1) || true
fi

BUILD_ROOT="$WORKTREE_DIR"

# ---- Verify critical build artifacts before staging ----
for f in public/build/manifest.json vendor/autoload.php public/.htaccess; do
    if [ ! -f "$BUILD_ROOT/$f" ]; then
        echo "[release-central] ERROR: Missing required file: $f" >&2
        echo "[release-central] Build may have failed. Aborting upload." >&2
        exit 1
    fi
done

# ---- Stage deploy folder (what gets FTP'd) ----
echo "[release-central] Staging deploy folder at $STAGE_DIR ..."
mkdir -p "$STAGE_DIR/storage/app" "$STAGE_DIR/bootstrap/cache"
for d in app bootstrap config database public resources routes php-run-scripts vendor; do
    rm -rf "$STAGE_DIR/$d" >/dev/null 2>&1 || true
    cp -R "$BUILD_ROOT/$d" "$STAGE_DIR/$d"
done
if [ -d "$BUILD_ROOT/lang" ]; then
    rm -rf "$STAGE_DIR/lang" >/dev/null 2>&1 || true
    cp -R "$BUILD_ROOT/lang" "$STAGE_DIR/lang"
fi
cp "$BUILD_ROOT/artisan" "$STAGE_DIR/artisan"
cp "$BUILD_ROOT/composer.json" "$STAGE_DIR/composer.json"
cp "$BUILD_ROOT/composer.lock" "$STAGE_DIR/composer.lock"
cp "$BUILD_ROOT/storage/app/version.txt" "$STAGE_DIR/storage/app/version.txt"
cp "$BUILD_ROOT/bootstrap/cache/deploy_pending" "$STAGE_DIR/bootstrap/cache/deploy_pending"

# ---- Optional: stop after build/stage ----
if [ "$DO_FTP" != "1" ]; then
    echo "[release-central] Build complete (no FTP upload)."
    echo "[release-central] Staged deploy folder:"
    echo "  $STAGE_DIR"
    echo "[release-central] Upload the contents of that folder to your server root (public_html)."
    exit 0
fi

# ---- FTP sync ----
echo "[release-central] Syncing to FTP (whitelist core Laravel files, keep storage/.env server-owned)..."

# Cleanup junk and dev-only files from the server before upload
lftp -c "
set ssl:verify-certificate no
set ftp:ssl-allow no
set cache:enable yes
set cache:expire 0
set mirror:use-pget-n 1
set ftp:use-mdtm no
set ftp:use-size yes
open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST

# Remove junk files that should never be on the server
rm -f public/hot
rm -f dbtest.php
rm -f dbtest-output.txt
rm -f cachetest.php
rm -f cachetest-output.txt
rm -f fixcache.php
rm -f fixcache-output.txt
rm -f flexiqueue-deploy.tar.gz
rm -f flexiqueue-*.tar.gz
rm -f root@*
rm -f env.edge
rm -f compose.yaml
rm -f package.json
rm -f package-lock.json
rm -f phpunit.xml
rm -f playwright.config.js
rm -f .phpunit.result.cache
rm -f .styleci.yml
rm -f .editorconfig
rm -f .gitattributes
rm -f .gitignore
rm -f svelte.config.js
rm -f vite
rm -f vite.config.js
rm -f robots.txt
rm -f dev
rm -rf releases
rm -rf node_modules
rm -rf .github
rm -rf tests
rm -rf e2e
rm -rf scripts
rm -rf docs
rm -rf .cursor
rm -rf .beads
bye
" 2>/dev/null || true

INCLUDE_LANG="false"
if [ -d "$REPO_ROOT/lang" ]; then
  INCLUDE_LANG="true"
fi

LFTP_ALWAYS_UPLOAD_CMD="
set ssl:verify-certificate no
set ftp:ssl-allow no
set cache:enable yes
set cache:expire 0
set mirror:use-pget-n 1
set ftp:use-mdtm no
set ftp:use-size yes
open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST

# Upload only what Laravel needs — whitelist approach
lcd $STAGE_DIR
cd /

# Core Laravel directories
mirror --reverse --delete --verbose --no-perms --no-umask --no-symlinks --ignore-time app/ app/
mirror --reverse --delete --verbose --no-perms --no-umask --no-symlinks --ignore-time bootstrap/ bootstrap/
mirror --reverse --delete --verbose --no-perms --no-umask --no-symlinks --ignore-time config/ config/
mirror --reverse --delete --verbose --no-perms --no-umask --no-symlinks --ignore-time database/ database/
mirror --reverse --delete --verbose --no-perms --no-umask --no-symlinks --ignore-time public/ public/
rm -f public/hot
put public/build/manifest.json -o public/build/manifest.json
mirror --reverse --delete --verbose --no-perms --no-umask --no-symlinks --ignore-time resources/ resources/
mirror --reverse --delete --verbose --no-perms --no-umask --no-symlinks --ignore-time routes/ routes/
mirror --reverse --delete --verbose --no-perms --no-umask --no-symlinks --ignore-time php-run-scripts/ php-run-scripts/
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
  LFTP_ALWAYS_UPLOAD_CMD="${LFTP_ALWAYS_UPLOAD_CMD/__LANG_MIRROR__/mirror --reverse --delete --verbose --no-perms --no-umask --no-symlinks --ignore-time lang/ lang/}"
else
  LFTP_ALWAYS_UPLOAD_CMD="${LFTP_ALWAYS_UPLOAD_CMD/__LANG_MIRROR__/}"
fi

if ! lftp -c "$LFTP_ALWAYS_UPLOAD_CMD"; then
  echo "[release-central] ERROR: FTP core upload failed." >&2
  exit 1
fi

echo "[release-central] Uploading vendor/ (always; avoids partial vendor corruption)..."
if ! lftp -c "
set ssl:verify-certificate no
set ftp:ssl-allow no
set cache:enable yes
set cache:expire 0
set mirror:use-pget-n 1
set ftp:use-mdtm no
set ftp:use-size yes
open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
lcd $STAGE_DIR
cd /
mirror --reverse --delete --verbose --no-perms --no-umask --no-symlinks --ignore-time vendor/ vendor/
put vendor/autoload.php -o vendor/autoload.php
put vendor/composer/autoload_real.php -o vendor/composer/autoload_real.php
put vendor/composer/autoload_static.php -o vendor/composer/autoload_static.php
put vendor/composer/autoload_classmap.php -o vendor/composer/autoload_classmap.php
bye
"; then
  echo "[release-central] ERROR: FTP vendor upload failed." >&2
  exit 1
fi

# ---- Spot-check upload by downloading critical files ----
SPOT_CHECK_FILES=(
    "app/Console/Commands/RunDeployUpdate.php"
    "app/Console/Commands/RunInitialSetup.php"
    "routes/console.php"
    "artisan"
    "public/.htaccess"
    "public/build/manifest.json"
    "vendor/autoload.php"
    "vendor/composer/autoload_static.php"
)

SPOT_FAILED=0
for remote_file in "${SPOT_CHECK_FILES[@]}"; do
    TMPFILE=$(mktemp)
    lftp -c "
    set ssl:verify-certificate no
    set ftp:ssl-allow no
    set cache:enable yes
    set cache:expire 0
    set mirror:use-pget-n 1
    set ftp:use-mdtm no
    set ftp:use-size yes
    open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
    get $remote_file -o $TMPFILE
    bye
    " 2>/dev/null
    if [ ! -s "$TMPFILE" ]; then
        echo "[release-central] ERROR: Missing on server: $remote_file" >&2
        SPOT_FAILED=1
    else
        echo "[release-central] ✓ Verified: $remote_file"
    fi
    rm -f "$TMPFILE"
done

# Ensure Vite dev mode is not enabled on prod
HOT_TMP=$(mktemp)
lftp -c "
set ssl:verify-certificate no
set ftp:ssl-allow no
set cache:enable yes
set cache:expire 0
set mirror:use-pget-n 1
set ftp:use-mdtm no
set ftp:use-size yes
open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
get public/hot -o $HOT_TMP
bye
" 2>/dev/null || true
if [ -s "$HOT_TMP" ]; then
    echo "[release-central] WARNING: public/hot exists on server; deleting to avoid Vite dev mode." >&2
    lftp -c "
    set ssl:verify-certificate no
    set ftp:ssl-allow no
    set cache:enable yes
    set cache:expire 0
    set mirror:use-pget-n 1
    set ftp:use-mdtm no
    set ftp:use-size yes
    open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
    rm -f public/hot
    bye
    " 2>/dev/null || true
fi
rm -f "$HOT_TMP" >/dev/null 2>&1 || true

# Optional: warn if scheduler hasn't generated caches yet (do not fail deploy)
CFG_TMP=$(mktemp)
lftp -c "
set ssl:verify-certificate no
set ftp:ssl-allow no
set cache:enable yes
set cache:expire 0
set mirror:use-pget-n 1
set ftp:use-mdtm no
set ftp:use-size yes
open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
get bootstrap/cache/config.php -o $CFG_TMP
bye
" 2>/dev/null || true
if [ ! -s "$CFG_TMP" ]; then
    echo "[release-central] WARNING: bootstrap/cache/config.php not found yet. Scheduler may not have run; wait 1-2 minutes or run php-run-scripts/bootstrap.php once." >&2
fi
rm -f "$CFG_TMP" >/dev/null 2>&1 || true

if [ "$SPOT_FAILED" -eq 1 ]; then
    echo "[release-central] ERROR: Upload verification failed. Some files are missing on server." >&2
    echo "[release-central] Re-run ./scripts/release-central.sh $VERSION to retry." >&2
    exit 1
fi

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

