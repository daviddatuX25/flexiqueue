#!/usr/bin/env bash
# Deploy to Laragon/laptop: prod-as-staging flow.
# (1) Merge current branch into prod and push.
# (2) Build tarball from prod worktree.
# (3) Inform: "Merged to prod and pushed. Tarball built from prod."
# (4) scp tarball to LARAGON_HOST, SSH and run scripts/laragon/apply-tarball.sh.
#
# Usage (from repo root):
#   LARAGON_HOST=laptop.local ./scripts/deploy-to-laragon.sh [--build] [--no-merge] [--migrate=incremental|fresh|skip]
#   LARAGON_HOST=192.168.1.10 LARAGON_USER=user ./scripts/deploy-to-laragon.sh --build
#
# Requires: prod branch exists; LARAGON_HOST set (or prompted). Target must have app dir and SSH (e.g. WSL).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

msg()    { echo "[FlexiQueue][deploy-laragon] $*" >&2; }
msg_ok() { echo "[FlexiQueue][deploy-laragon] ✓ $*" >&2; }

LARAGON_HOST="${LARAGON_HOST:-}"
LARAGON_USER="${LARAGON_USER:-root}"
LARAGON_APP_DIR="${LARAGON_APP_DIR:-/var/www/flexiqueue}"
DO_MERGE=1
BUILD=0
USE_SAIL=0
MIGRATE_ARG="incremental"

for arg in "$@"; do
  case "$arg" in
    --build) BUILD=1 ;;
    --no-merge) DO_MERGE=0 ;;
    --sail) USE_SAIL=1 ;;
    --migrate=*) MIGRATE_ARG="${arg#--migrate=}" ;;
  esac
done

if ! git rev-parse --git-dir >/dev/null 2>&1; then
  msg "This script must be run inside the FlexiQueue git repository."
  exit 1
fi

source "$SCRIPT_DIR/lib/git-worktree.sh"

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")"
msg "Current branch: ${CURRENT_BRANCH:-unknown}"

if [ "$DO_MERGE" -eq 1 ]; then
  if [[ "${ALLOW_DIRTY_DEPLOY:-0}" != "1" ]] && [[ -n "$(git status --porcelain)" ]]; then
    msg "Working tree has uncommitted changes. Commit, stash, or set ALLOW_DIRTY_DEPLOY=1."
    exit 1
  fi
  ensure_prod_branch "[FlexiQueue][deploy-laragon]"
  msg "Merging $CURRENT_BRANCH into prod and pushing..."
  git checkout prod
  git merge --no-edit "$CURRENT_BRANCH"
  git push origin prod
  git checkout "$CURRENT_BRANCH"
  msg_ok "Merged to prod and pushed."
fi

ensure_prod_branch "[FlexiQueue][deploy-laragon]"
ensure_prod_worktree_fixed "[FlexiQueue][deploy-laragon]"
if [[ -z "$(git -C "$PROD_WORKTREE" status --porcelain)" ]]; then
  git -C "$PROD_WORKTREE" pull --ff-only >/dev/null 2>&1 || true
fi

cd "$PROD_WORKTREE"

if [ "$BUILD" -eq 1 ]; then
  if [ "$USE_SAIL" -eq 1 ] || ! command -v php >/dev/null 2>&1; then
    docker_ok=0
    if command -v docker >/dev/null 2>&1; then
      docker version >/dev/null 2>&1 && docker_ok=1 || docker info >/dev/null 2>&1 && docker_ok=1
    fi
    if [ "$docker_ok" -eq 1 ]; then
      msg "Building tarball (Sail)..."
      ./scripts/build-deploy-tarball-sail.sh
    else
      msg "Building tarball (host)..."
      ./scripts/build-deploy-tarball.sh
    fi
  else
    msg "Building tarball (host)..."
    ./scripts/build-deploy-tarball.sh
  fi
fi

if [ ! -f "$PROD_WORKTREE/flexiqueue-deploy.tar.gz" ]; then
  msg "No flexiqueue-deploy.tar.gz in prod worktree. Run with --build."
  exit 1
fi

if [ ! -f "$PROD_WORKTREE/scripts/laragon/apply-tarball.sh" ]; then
  msg "scripts/laragon/apply-tarball.sh not found in prod worktree. Cannot deploy."
  exit 1
fi

msg_ok "Merged to prod and pushed. Tarball built from prod."

if [ -z "$LARAGON_HOST" ]; then
  read -r -p "Laragon/laptop host (IP or hostname): " LARAGON_HOST
  LARAGON_HOST="$(echo "$LARAGON_HOST" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  if [ -z "$LARAGON_HOST" ]; then
    msg "No host given. Exiting."
    exit 1
  fi
fi

CONTROL="/tmp/fq-laragon-${LARAGON_USER}-${LARAGON_HOST}-$$"
cleanup_ssh() { ssh -S "$CONTROL" -O exit "${LARAGON_USER}@${LARAGON_HOST}" 2>/dev/null || true; rm -f "$CONTROL"; }
trap cleanup_ssh EXIT

msg "Connecting to ${LARAGON_USER}@${LARAGON_HOST}..."
ssh -M -S "$CONTROL" -o ControlPersist=120 "${LARAGON_USER}@${LARAGON_HOST}" true

msg "Copying tarball and apply script to ${LARAGON_HOST}..."
scp -o ControlPath="$CONTROL" "$PROD_WORKTREE/flexiqueue-deploy.tar.gz" "${LARAGON_USER}@${LARAGON_HOST}:/tmp/"
scp -o ControlPath="$CONTROL" "$PROD_WORKTREE/scripts/laragon/apply-tarball.sh" "${LARAGON_USER}@${LARAGON_HOST}:/tmp/fq-apply-tarball.sh"

msg "Applying on target (apply-tarball.sh --migrate=$MIGRATE_ARG)..."
ssh -t -o ControlPath="$CONTROL" "${LARAGON_USER}@${LARAGON_HOST}" "sudo LARAGON_APP_DIR=$LARAGON_APP_DIR bash /tmp/fq-apply-tarball.sh /tmp/flexiqueue-deploy.tar.gz --migrate=$MIGRATE_ARG"

cd "$REPO_ROOT"
git checkout prod
msg_ok "Deploy to Laragon complete. Switched to branch prod."
