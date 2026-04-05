#!/usr/bin/env bash
# =============================================================================
# FlexiQueue — Deploy to flexiqueue.click hosting via lftp
#
# Usage:
#   ./scripts/shared/deploy-flexiqueue-hosting.sh [--local-build] [--keep-worktree] [--no-vendor-sync]
#
# What it does:
#   1. Reads FTP credentials from .env.hosting (or prompts interactively)
#   2. Creates a git worktree from the LOCAL dev branch (no remote push needed)
#   3. Runs composer install --no-dev --optimize-autoloader + npm run build
#   4. Stages only essential app files (no docs, no tests, no CI config)
#   5. Uploads essential dirs via lftp (always replace)
#   6. Asks interactively before pushing vendor/
#   7. Cleans up worktree on exit (unless --keep-worktree)
#
# lftp sync strategy: --ignore-size --ignore-time so server-time-drift
# between local and remote does NOT cause missed or wrong uploads.
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

# ---------- defaults ----------
BUILD_MODE="local"          # always local — no docker dependency
KEEP_WORKTREE="0"          # cleanup worktree on exit by default
SYNC_VENDOR=""              # "" = interactive ask; "1" = always push; "0" = never push
HOSTING_REMOTE_PATH="/"     # remote path on FTP server (adjust if hosting uses a subdir)
APP_REMOTE_PATH=""          # e.g. "" for root, or "subdir/" if hosting serves from a subdirectory

# ---------- coloured output ----------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

msg()    { echo -e "${CYAN}[fq-deploy]${NC} $*" >&2; }
ok()     { echo -e "${GREEN}[fq-deploy] ✓${NC} $*" >&2; }
warn()   { echo -e "${YELLOW}[fq-deploy] ⚠ $*" >&2; }
err()    { echo -e "${RED}[fq-deploy] ✗ ERROR:${NC} $*" >&2; }
section(){ echo -e "\n${BOLD}=== $*) ===${NC}" >&2; }

# ---------- usage ----------
usage() {
  cat <<'EOF'
Usage: ./scripts/shared/deploy-flexiqueue-hosting.sh [options]

Options:
  --keep-worktree      Do NOT remove the git worktree after deploy (keep for inspection)
  --sync-vendor        Always push vendor/ without asking (use when deps changed)
  --no-vendor-sync     Never push vendor/ (hosting keeps existing vendor)
  --app-path=NAME      Remote subdirectory on hosting (e.g. "flexiqueue/"). Default: /

Environment variables (override .env.hosting or prompt):
  FTP_HOST, FTP_USER, FTP_PASSWORD
  VITE_PUSHER_APP_KEY, VITE_PUSHER_APP_CLUSTER
  VITE_BROADCASTER=pusher

Examples:
  # Normal deploy (prompts for vendor)
  ./scripts/shared/deploy-flexiqueue-hosting.sh

  # Deploy with vendor included (deps changed)
  ./scripts/shared/deploy-flexiqueue-hosting.sh --sync-vendor

  # Inspect worktree after deploy
  ./scripts/shared/deploy-flexiqueue-hosting.sh --keep-worktree
EOF
}

# ---------- parse args ----------
for arg in "$@"; do
  case "$arg" in
    --help|-h)          usage; exit 0 ;;
    --keep-worktree)    KEEP_WORKTREE="1" ;;
    --sync-vendor)      SYNC_VENDOR="1" ;;
    --no-vendor-sync)   SYNC_VENDOR="0" ;;
    --app-path=*)       APP_REMOTE_PATH="${arg#--app-path=}" ;;
    *)                  err "Unknown argument: $arg"; usage; exit 1 ;;
  esac
done

# ---------- prerequisites ----------
if ! command -v lftp >/dev/null 2>&1; then
  err "lftp is not installed. Install it and try again."
  err "  Windows: choco install lftp  (or via Laragon's apt)"
  err "  Linux:  sudo apt install lftp"
  err "  macOS:  brew install lftp"
  exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
  err "composer not found. Install Composer and try again."
  exit 1
fi

if ! command -v npm >/dev/null 2>&1; then
  err "npm not found. Install Node.js and try again."
  exit 1
fi

# ---------- load .env.hosting (ignore errors, credentials may be incomplete) ----------
ENV_HOSTING="$REPO_ROOT/.env.hosting"
if [ -f "$ENV_HOSTING" ]; then
  msg "Loading credentials from .env.hosting..."
  # shellcheck disable=SC1090
  set -a
  source "$ENV_HOSTING" 2>/dev/null || true
  set +a
else
  warn ".env.hosting not found. Will prompt for FTP credentials."
fi

# ---------- interactive credential gathering ----------
get_credential() {
  local label="$1" var_name="$2" is_password="${3:-no}"
  local value
  eval "value=\"\$$var_name\""
  if [ -n "$value" ]; then
    msg "  $label: ${is_password=yes ? '********' : $value}  [using .env.hosting]"
  else
    if [ -t 0 ]; then
      if [ "$is_password" = "yes" ]; then
        read -r -s -p "  Enter $label: " value; echo >&2
      else
        read -r -p "  Enter $label: " value; echo >&2
      fi
    else
      err "No $label set and not running interactively. Set FTP_* vars in .env.hosting."
      exit 1
    fi
    eval "$var_name=\$value"
  fi
}

section "FTP Credentials"
FTP_HOST="${FTP_HOST:-}"
FTP_USER="${FTP_USER:-${FTP_USERNAME:-}}"
FTP_PASSWORD="${FTP_PASSWORD:-}"

get_credential "FTP host (IP or domain)" FTP_HOST
get_credential "FTP username"           FTP_USER
get_credential "FTP password"             FTP_PASSWORD yes

if [ -z "$FTP_HOST" ] || [ -z "$FTP_USER" ] || [ -z "$FTP_PASSWORD" ]; then
  err "FTP credentials incomplete. Set them in .env.hosting or answer the prompts."
  exit 1
fi

# ---------- vite/pusher vars ----------
VITE_BROADCASTER="${VITE_BROADCASTER:-pusher}"
VITE_PUSHER_APP_KEY="${VITE_PUSHER_APP_KEY:-${PUSHER_APP_KEY:-}}"
VITE_PUSHER_APP_CLUSTER="${VITE_PUSHER_APP_CLUSTER:-${PUSHER_APP_CLUSTER:-mt1}}"

if [ -z "$VITE_PUSHER_APP_KEY" ]; then
  warn "VITE_PUSHER_APP_KEY not set — Vite build may miss real-time config. Set in .env.hosting."
fi

# ---------- worktree setup ----------
section "Git Worktree (dev branch)"

# Verify local dev branch exists
if ! git rev-parse --verify dev >/dev/null 2>&1; then
  err "Local 'dev' branch not found. Run this from the flexiqueue repo."
  exit 1
fi

WORKTREE_DIR="$REPO_ROOT/.build/worktrees/flexiqueue-hosting"
STAGE_DIR="$REPO_ROOT/.build/stage/flexiqueue-hosting"

# Clean up any stale worktree from a previous run
if [ -d "$WORKTREE_DIR" ]; then
  msg "Removing stale worktree at $WORKTREE_DIR..."
  git worktree remove --force "$WORKTREE_DIR" 2>/dev/null || true
fi

mkdir -p "$WORKTREE_DIR" "$STAGE_DIR"
msg "Creating worktree from local 'dev' branch..."
git worktree add --detach "$WORKTREE_DIR" dev >/dev/null
ok "Worktree created at: $WORKTREE_DIR"

# ---------- build ----------
section "Building in worktree (local)"

BUILD_LOG="$REPO_ROOT/.build/deploy-build.log"

msg "Installing Composer dependencies..."
cd "$WORKTREE_DIR"
composer config platform.php 8.2 2>/dev/null || true
composer install \
  --no-dev \
  --optimize-autoloader \
  --prefer-dist \
  --no-interaction \
  --ignore-platform-reqs \
  > "$BUILD_LOG" 2>&1 || {
    err "composer install failed. See: $BUILD_LOG"
    cat "$BUILD_LOG" >&2
    exit 1
  }
ok "Composer install complete"

msg "Running npm ci + npm run build..."
npm ci > "$BUILD_LOG" 2>&1 || {
  err "npm ci failed. See: $BUILD_LOG"
  cat "$BUILD_LOG" >&2
  exit 1
}
npm run build >> "$BUILD_LOG" 2>&1 || {
  err "npm run build failed. See: $BUILD_LOG"
  cat "$BUILD_LOG" >&2
  exit 1
}
ok "Build complete"

msg "Generating APP_KEY in worktree..."
php artisan key:generate --force >> "$BUILD_LOG" 2>&1 || {
  err "php artisan key:generate failed. See: $BUILD_LOG"
  cat "$BUILD_LOG" >&2
  exit 1
}
ok "APP_KEY generated and written to .env"

# Record deploy marker
mkdir -p "$WORKTREE_DIR/bootstrap/cache" "$WORKTREE_DIR/storage/app"
touch "$WORKTREE_DIR/bootstrap/cache/deploy_pending"

# ---------- stage .env with APP_KEY ----------
section "Staging .env with APP_KEY"

if [ -f "$WORKTREE_DIR/.env" ]; then
  mkdir -p "$STAGE_DIR"
  cp "$WORKTREE_DIR/.env" "$STAGE_DIR/.env"
  msg "  .env staged (APP_KEY is set)"
  msg "  On hosting: rename .env.hosting to .env OR replace .env with this one"
else
  warn "  No .env found in worktree — ensure .env.hosting on hosting has APP_KEY"
fi

# ---------- stage essential files ----------
section "Staging essential files"

msg "Copying only deploy-needed files to staging dir..."
rm -rf "$STAGE_DIR" >/dev/null 2>&1 || true
mkdir -p "$STAGE_DIR"

# Essential dirs (order matters for clear error reporting)
ESSENTIAL_DIRS="app bootstrap config database public resources routes"

for dir in $ESSENTIAL_DIRS; do
  if [ -d "$WORKTREE_DIR/$dir" ]; then
    cp -R "$WORKTREE_DIR/$dir" "$STAGE_DIR/$dir"
    msg "  staged: $dir/"
  else
    warn "  missing (skipping): $dir/"
  fi
done

# Individual files
for file in artisan composer.json composer.lock; do
  if [ -f "$WORKTREE_DIR/$file" ]; then
    cp "$WORKTREE_DIR/$file" "$STAGE_DIR/$file"
    msg "  staged: $file"
  else
    err "Required file missing: $file — cannot deploy."
    exit 1
  fi
done

# storage/app version marker
if [ -f "$WORKTREE_DIR/storage/app/version.txt" ]; then
  mkdir -p "$STAGE_DIR/storage/app"
  cp "$WORKTREE_DIR/storage/app/version.txt" "$STAGE_DIR/storage/app/version.txt"
fi

# bootstrap/cache deploy marker
if [ -f "$WORKTREE_DIR/bootstrap/cache/deploy_pending" ]; then
  mkdir -p "$STAGE_DIR/bootstrap/cache"
  cp "$WORKTREE_DIR/bootstrap/cache/deploy_pending" "$STAGE_DIR/bootstrap/cache/deploy_pending"
fi

# lang/ if present
if [ -d "$WORKTREE_DIR/lang" ]; then
  cp -R "$WORKTREE_DIR/lang" "$STAGE_DIR/lang"
  msg "  staged: lang/"
fi

ok "Staging complete: $STAGE_DIR"

# ---------- vendor decision ----------
section "Vendor Directory"

if [ "$SYNC_VENDOR" = "" ]; then
  if [ -t 0 ] && [ -t 1 ]; then
    echo "  Vendor directory (vendor/) is ready to upload."
    read -r -p "  Upload or replace vendor/ on hosting via FTP? [y/N] " _ans
    case "${_ans:-n}" in
      y|Y|yes|YES) SYNC_VENDOR="1" ;;
      *)            SYNC_VENDOR="0" ;;
    esac
  else
    # Non-interactive (CI/headless): default to NOT pushing vendor
    warn "Not interactive — defaulting to SKIP vendor upload (keep existing on hosting)."
    SYNC_VENDOR="0"
  fi
fi

if [ "$SYNC_VENDOR" = "1" ]; then
  if [ -d "$WORKTREE_DIR/vendor" ]; then
    rm -rf "$STAGE_DIR/vendor" >/dev/null 2>&1 || true
    cp -R "$WORKTREE_DIR/vendor" "$STAGE_DIR/vendor"
    msg "Vendor staged for upload."
  else
    warn "No vendor/ in worktree — skipping vendor upload."
    SYNC_VENDOR="0"
  fi
else
  msg "Skipping vendor/ — hosting keeps its existing vendor directory."
fi

# ---------- lftp upload ----------
section "FTP Upload via lftp"

# Normalise remote path (ensure trailing slash for concatenation)
REMOTE_BASE="${APP_REMOTE_PATH%/}"
[ -n "$REMOTE_BASE" ] && REMOTE_BASE="${REMOTE_BASE}/"

# Build the lftp batch file
LFTP_BATCH=$(mktemp)
LFTP_LOG="$REPO_ROOT/.build/deploy-ftp.log"

{
  cat <<LFTP_HEADER
# FlexiQueue hosting deploy — lftp batch
# Generated by deploy-flexiqueue-hosting.sh

set ssl:verify-certificate no
set ftp:ssl-allow no
set cache:enable yes
set cache:expire 0
set mirror:use-pget-n 3
set ftp:use-mdtm no
set ftp:use-size no
set ftp:use-remote-fsize no

open -u ${FTP_USER},${FTP_PASSWORD} ${FTP_HOST}
lcd ${STAGE_DIR}

# --ignore-size --ignore-time: content comparison only
# Server time drift must NOT cause missed or incorrect uploads.
# --reverse: upload local → remote
# --delete: remove remote files that no longer exist locally

LFTP_HEADER

  # Upload essential dirs (always)
  for dir in app bootstrap config database public resources routes; do
    if [ -d "$STAGE_DIR/$dir" ]; then
      echo "echo 'Syncing $dir/ ...'"
      echo "mirror --reverse --delete --verbose \\"
      echo "  --no-perms --no-umask --no-symlinks \\"
      echo "  --ignore-size --ignore-time \\"
      echo "  $dir/ ${REMOTE_BASE}$dir/"
    fi
  done

  # lang/ if present
  if [ -d "$STAGE_DIR/lang" ]; then
    echo "echo 'Syncing lang/ ...'"
    echo "mirror --reverse --delete --verbose \\"
    echo "  --no-perms --no-umask --no-symlinks \\"
    echo "  --ignore-size --ignore-time \\"
    echo "  lang/ ${REMOTE_BASE}lang/"
  fi

  # Individual files (artisan, composer.*, .env with APP_KEY, markers)
  echo "echo 'Syncing individual files ...'"
  echo "put artisan -o ${REMOTE_BASE}artisan"
  echo "put composer.json -o ${REMOTE_BASE}composer.json"
  echo "put composer.lock -o ${REMOTE_BASE}composer.lock"
  if [ -f "$STAGE_DIR/.env" ]; then
    echo "put .env -o ${REMOTE_BASE}.env"
  fi

  # version.txt
  if [ -f "$STAGE_DIR/storage/app/version.txt" ]; then
    echo "mkdir -p ${REMOTE_BASE}storage/app"
    echo "put storage/app/version.txt -o ${REMOTE_BASE}storage/app/version.txt"
  fi

  # deploy_pending marker
  if [ -f "$STAGE_DIR/bootstrap/cache/deploy_pending" ]; then
    echo "mkdir -p ${REMOTE_BASE}bootstrap/cache"
    echo "put bootstrap/cache/deploy_pending -o ${REMOTE_BASE}bootstrap/cache/deploy_pending"
  fi

  # Vendor (conditional)
  if [ "$SYNC_VENDOR" = "1" ] && [ -d "$STAGE_DIR/vendor" ]; then
    echo "echo 'Syncing vendor/ ...'"
    echo "mirror --reverse --delete --verbose \\"
    echo "  --no-perms --no-umask --no-symlinks \\"
    echo "  --ignore-size --ignore-time \\"
    echo "  vendor/ ${REMOTE_BASE}vendor/"
  fi

  echo "echo 'Upload complete.'"
  echo "bye"
} > "$LFTP_BATCH"

msg "FTP upload in progress (logging to: $LFTP_LOG)..."
msg "  Host : $FTP_HOST"
msg "  User : $FTP_USER"
msg "  Mode : mirror --reverse --ignore-size --ignore-time"
msg "  Vendor sync: $([ "$SYNC_VENDOR" = "1" ] && echo "YES" || echo "NO")"

lftp -f "$LFTP_BATCH" > "$LFTP_LOG" 2>&1
LFTP_EXIT=$?

if [ $LFTP_EXIT -ne 0 ]; then
  err "lftp exited with code $LFTP_EXIT. See: $LFTP_LOG"
  cat "$LFTP_LOG" >&2
  exit 1
fi

ok "FTP upload complete."
msg "lftp log: $LFTP_LOG"

# ---------- deploy marker cleanup ----------
section "Post-Deploy"

# Remove deploy_pending on remote (RunDeployUpdate.php will recreate it on next artisan call)
# This is done via a mini lftp batch inline
if [ -f "$STAGE_DIR/bootstrap/cache/deploy_pending" ]; then
  DEPLOY_CLEANUP_BATCH=$(mktemp)
  {
    cat <<CLEANUP
open -u ${FTP_USER},${FTP_PASSWORD} ${FTP_HOST}
rm -f ${REMOTE_BASE}bootstrap/cache/deploy_pending
bye
CLEANUP
  } > "$DEPLOY_CLEANUP_BATCH"
  lftp -f "$DEPLOY_CLEANUP_BATCH" > /dev/null 2>&1 || true
  rm -f "$DEPLOY_CLEANUP_BATCH"
fi

# ---------- worktree cleanup ----------
section "Worktree Cleanup"

if [ "$KEEP_WORKTREE" = "1" ]; then
  ok "Keeping worktree at: $WORKTREE_DIR (use --keep-worktree flag)"
  msg "To remove manually: git worktree remove --force $WORKTREE_DIR"
else
  msg "Removing worktree at: $WORKTREE_DIR"
  git worktree remove --force "$WORKTREE_DIR" 2>/dev/null || true
  ok "Worktree removed."
fi

# ---------- done ----------
section "Deploy Summary"
ok "All done! FlexiQueue deployed to $FTP_HOST"
msg "  Build log  : $BUILD_LOG"
msg "  FTP log    : $LFTP_LOG"
msg "  Staging    : $STAGE_DIR"
msg "  Vendor sync: $([ "$SYNC_VENDOR" = "1" ] && echo "Uploaded" || echo "Skipped")"

# ---------- post-deploy reminders ----------
echo ""
echo -e "${BOLD}=== POST-DEPLOY CHECKLIST ===${NC}"
echo -e "  1. ${RED}Database export/import:${NC} If this is a fresh deploy or schema changed,"
echo -e "     export your local database and import it on hosting (phpMyAdmin or CLI)."
echo -e "     Run: php artisan migrate on hosting if new migrations exist."
echo ""
echo -e "  2. ${YELLOW}Google OAuth (if used):${NC} Set these in .env on hosting BEFORE going live:"
echo -e "       GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com"
echo -e "       GOOGLE_CLIENT_SECRET=your-client-secret"
echo -e "     Then in Google Cloud Console → Authorized redirect URIs add:"
echo -e "       https://yourdomain.com/auth/google/callback"
echo -e "     Until these are set, the Google login button stays hidden."
echo ""
echo -e "  3. ${CYAN}Clear caches on hosting:${NC}"
echo -e "       php artisan config:clear"
echo -e "       php artisan view:clear"
echo -e "       php artisan route:clear"