#!/usr/bin/env bash
# Manual central release/deploy via FTP.
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VERSION=""
FTP_HOST=""
FTP_USER=""
FTP_PASSWORD=""
VITE_PUSHER_APP_KEY=""
VITE_PUSHER_APP_CLUSTER=""
PUSHER_APP_KEY=""
PUSHER_APP_CLUSTER=""
BUILD_MODE="docker"
KEEP_WORKTREE="0"
DO_FTP="1"
SYNC_VENDOR_FLAG=""
WORKTREE_DIR=""
STAGE_DIR=""
BUILD_ROOT=""

cleanup() {
    echo "[release-central] Cleanup on exit."
    if [ -n "${WORKTREE_DIR:-}" ] && [ -d "${WORKTREE_DIR:-}" ] && [ "$KEEP_WORKTREE" != "1" ]; then
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
    echo "       $0 [--sync-vendor|--no-vendor-sync] ...  (FTP: upload vendor/ or skip; env: RELEASE_SYNC_VENDOR=1|0)"
    echo ""
    echo "Build output: .build/stage/release-central-<version>-<timestamp>/ (FTP-ready copy)."
    echo "Pointer file: .build/stage/LATEST.txt"
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
    elif [ "$arg" = "--sync-vendor" ]; then
        SYNC_VENDOR_FLAG="1"
    elif [ "$arg" = "--no-vendor-sync" ]; then
        SYNC_VENDOR_FLAG="0"
    else
        ARGS+=("$arg")
    fi
done

if [ "$DO_FTP" = "1" ] && ! command -v lftp >/dev/null 2>&1; then
    echo "Error: lftp is required for FTP upload." >&2
    exit 1
fi
if [ "$BUILD_MODE" = "docker" ] && ! command -v docker >/dev/null 2>&1; then
    echo "Error: docker is required (or use --local)." >&2
    exit 1
fi
if [ "$BUILD_MODE" = "local" ]; then
    command -v composer >/dev/null 2>&1 || { echo "Error: composer not found." >&2; exit 1; }
    command -v npm >/dev/null 2>&1 || { echo "Error: npm not found." >&2; exit 1; }
fi

cd "$REPO_ROOT"
git rev-parse --git-dir >/dev/null 2>&1 || { echo "Error: not in git repo." >&2; exit 1; }

if [ -n "${ARGS[0]:-}" ]; then
    VERSION="${ARGS[0]}"
else
    VERSION=$(git describe --tags --abbrev=0 2>/dev/null || true)
    [ -z "$VERSION" ] && { echo "Error: no tag found and no version passed." >&2; exit 1; }
fi

[ -f "$REPO_ROOT/.env.hosting" ] || { echo "Error: .env.hosting not found." >&2; exit 1; }
unset FTP_HOST FTP_USER FTP_USERNAME FTP_PASSWORD
unset PUSHER_APP_KEY PUSHER_APP_CLUSTER VITE_PUSHER_APP_KEY VITE_PUSHER_APP_CLUSTER VITE_BROADCASTER
set -a
source "$REPO_ROOT/.env.hosting" 2>/dev/null || true
set +a

FTP_HOST="${FTP_HOST:-}"
FTP_USER="${FTP_USER:-${FTP_USERNAME:-}}"
FTP_PASSWORD="${FTP_PASSWORD:-}"
PUSHER_APP_KEY="${PUSHER_APP_KEY:-}"
PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER:-}"
VITE_PUSHER_APP_KEY="${VITE_PUSHER_APP_KEY:-$PUSHER_APP_KEY}"
VITE_PUSHER_APP_CLUSTER="${VITE_PUSHER_APP_CLUSTER:-$PUSHER_APP_CLUSTER}"

[ -n "$FTP_HOST" ] && [ -n "$FTP_USER" ] && [ -n "$FTP_PASSWORD" ] || { echo "Error: FTP credentials missing." >&2; exit 1; }
[ -n "$VITE_PUSHER_APP_KEY" ] && [ -n "$VITE_PUSHER_APP_CLUSTER" ] || { echo "Error: PUSHER/VITE vars missing." >&2; exit 1; }

TS="$(date +%Y%m%d%H%M%S)"
WORKTREE_DIR="$REPO_ROOT/.build/worktrees/release-central-${VERSION}-${TS}"
STAGE_DIR="$REPO_ROOT/.build/stage/release-central-${VERSION}-${TS}"
mkdir -p "$WORKTREE_DIR" "$STAGE_DIR"
git worktree add --detach "$WORKTREE_DIR" HEAD >/dev/null

export VERSION VITE_BROADCASTER="pusher" VITE_PUSHER_APP_KEY VITE_PUSHER_APP_CLUSTER
if [ "$BUILD_MODE" = "docker" ]; then
    COMPOSE_CMD="docker compose"
    [ -f "$REPO_ROOT/compose.yaml" ] || [ -f "$REPO_ROOT/docker-compose.yml" ] || { echo "Error: compose.yaml missing." >&2; exit 1; }
    export WWWUSER="${WWWUSER:-$(id -u)}"
    export WWWGROUP="${WWWGROUP:-$(id -g)}"
    (cd "$REPO_ROOT" && $COMPOSE_CMD run --rm \
      -e WWWUSER -e WWWGROUP -e VERSION -e VITE_BROADCASTER -e VITE_PUSHER_APP_KEY -e VITE_PUSHER_APP_CLUSTER \
      -v "$WORKTREE_DIR:/var/www/html" -w /var/www/html laravel.test bash -c '
      set -e
      composer config platform.php 8.2
      composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
      npm ci
      npm run build
      echo "$VERSION" > storage/app/version.txt
      touch bootstrap/cache/deploy_pending
      ')
else
    (cd "$WORKTREE_DIR" && \
      composer config platform.php 8.2 && \
      composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --ignore-platform-reqs && \
      npm ci && npm run build && \
      mkdir -p storage/app bootstrap/cache && \
      echo "$VERSION" > storage/app/version.txt && \
      touch bootstrap/cache/deploy_pending)
    (cd "$WORKTREE_DIR" && composer config --unset platform.php >/dev/null 2>&1) || true
fi

BUILD_ROOT="$WORKTREE_DIR"
for f in public/build/manifest.json vendor/autoload.php public/.htaccess; do
  [ -f "$BUILD_ROOT/$f" ] || { echo "Missing required build file: $f" >&2; exit 1; }
done

mkdir -p "$STAGE_DIR/storage/app" "$STAGE_DIR/bootstrap/cache"
for d in app bootstrap config database public resources routes php-run-scripts vendor; do
  rm -rf "$STAGE_DIR/$d" >/dev/null 2>&1 || true
  cp -R "$BUILD_ROOT/$d" "$STAGE_DIR/$d"
done
[ -d "$BUILD_ROOT/lang" ] && cp -R "$BUILD_ROOT/lang" "$STAGE_DIR/lang" || true
cp "$BUILD_ROOT/artisan" "$STAGE_DIR/artisan"
cp "$BUILD_ROOT/composer.json" "$STAGE_DIR/composer.json"
cp "$BUILD_ROOT/composer.lock" "$STAGE_DIR/composer.lock"
cp "$BUILD_ROOT/storage/app/version.txt" "$STAGE_DIR/storage/app/version.txt"
cp "$BUILD_ROOT/bootstrap/cache/deploy_pending" "$STAGE_DIR/bootstrap/cache/deploy_pending"

mkdir -p "$REPO_ROOT/.build/stage"
printf '%s\n' "$STAGE_DIR" > "$REPO_ROOT/.build/stage/LATEST.txt"
echo "[release-central] Deployment directory (FTP-ready): $STAGE_DIR"
echo "[release-central] Path recorded in: $REPO_ROOT/.build/stage/LATEST.txt"

if [ "$DO_FTP" != "1" ]; then
  echo "[release-central] Build complete (no FTP upload). Upload the directory above with lftp or any FTP client."
  exit 0
fi

SYNC_VENDOR=""
if [ "$SYNC_VENDOR_FLAG" = "1" ] || [ "$SYNC_VENDOR_FLAG" = "0" ]; then
  SYNC_VENDOR="$SYNC_VENDOR_FLAG"
elif [ -n "${RELEASE_SYNC_VENDOR:-}" ]; then
  case "$RELEASE_SYNC_VENDOR" in
    1|yes|true|Y|y) SYNC_VENDOR=1 ;;
    0|no|false|N|n) SYNC_VENDOR=0 ;;
    *) echo "[release-central] Error: RELEASE_SYNC_VENDOR must be 1/yes or 0/no." >&2; exit 1 ;;
  esac
elif [ -t 0 ] && [ -t 1 ]; then
  echo "[release-central] Staging already includes a full vendor/ tree for this release."
  read -r -p "Upload or replace vendor/ on hosting via FTP (lftp)? [Y/n] " _ans
  case "${_ans:-y}" in
    n|N|no|NO) SYNC_VENDOR=0 ;;
    *) SYNC_VENDOR=1 ;;
  esac
else
  SYNC_VENDOR=1
fi

if [ "$SYNC_VENDOR" = "1" ]; then
  echo "[release-central] FTP (lftp): mirroring vendor/ to hosting."
else
  echo "[release-central] FTP (lftp): skipping vendor/ — hosting keeps existing vendor; only use skip if deps unchanged."
fi

LFTP_BATCH=$(mktemp)
{
  cat <<PART1
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
PART1
  if [ "$SYNC_VENDOR" = "1" ]; then
    echo 'mirror --reverse --delete --verbose --no-perms --no-umask --no-symlinks --ignore-time vendor/ vendor/'
  fi
  cat <<'PART2'
put artisan
put composer.json
put composer.lock
put storage/app/version.txt -o storage/app/version.txt
put bootstrap/cache/deploy_pending -o bootstrap/cache/deploy_pending
bye
PART2
} > "$LFTP_BATCH"
lftp -f "$LFTP_BATCH"
rm -f "$LFTP_BATCH"

echo "[release-central] Deploy complete: $VERSION"
