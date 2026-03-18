#!/usr/bin/env bash
# Deploy to Pi using prod-as-staging: merge current branch into prod and push, then build from prod worktree and run deploy-to-pi.sh.
#
# Usage (from repo root):
#   PI_HOST=flexiqueue.edge ./scripts/deploy-via-prod-to-pi.sh [--build] [--no-merge] [deploy-to-pi args...]
# Example: PI_HOST=10.22.25.107 ./scripts/deploy-via-prod-to-pi.sh --build DEPLOY_MIGRATE=1
#
# Option --no-merge: skip merge (e.g. already on prod). All other args are forwarded to deploy-to-pi.sh.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

msg()    { echo "[FlexiQueue][via-prod-to-pi] $*" >&2; }
msg_ok() { echo "[FlexiQueue][via-prod-to-pi] ✓ $*" >&2; }

DO_MERGE=1
ARGS=()
for arg in "$@"; do
  case "$arg" in
    --no-merge) DO_MERGE=0 ;;
    *) ARGS+=("$arg") ;;
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
  # Only block on tracked changes; ignore untracked files (e.g. flexiqueue-deploy.tar.gz)
  if [[ "${ALLOW_DIRTY_DEPLOY:-0}" != "1" ]] && [[ -n "$(git status --porcelain -uno)" ]]; then
    msg "Working tree has uncommitted changes (tracked files). Commit, stash, or set ALLOW_DIRTY_DEPLOY=1."
    exit 1
  fi
  ensure_prod_branch "[FlexiQueue][via-prod-to-pi]"
  EXISTING_PROD_WT="$(get_existing_prod_worktree_path)"
  if [[ -n "$EXISTING_PROD_WT" ]] && [[ -d "$EXISTING_PROD_WT" ]]; then
    # Prod is checked out in another worktree; merge there instead of checking out here
    ensure_prod_worktree_fixed "[FlexiQueue][via-prod-to-pi]"
    msg "Merging $CURRENT_BRANCH into prod (in prod worktree) and pushing..."
    (cd "$PROD_WORKTREE" && git merge --no-edit "$CURRENT_BRANCH" && git push origin prod)
    msg_ok "Merged to prod and pushed."
  else
    msg "Merging $CURRENT_BRANCH into prod and pushing..."
    git checkout prod
    git merge --no-edit "$CURRENT_BRANCH"
    git push origin prod
    git checkout "$CURRENT_BRANCH"
    msg_ok "Merged to prod and pushed."
  fi
fi

ensure_prod_branch "[FlexiQueue][via-prod-to-pi]"
ensure_prod_worktree_fixed "[FlexiQueue][via-prod-to-pi]"
if [[ -z "$(git -C "$PROD_WORKTREE" status --porcelain -uno)" ]]; then
  git -C "$PROD_WORKTREE" pull --ff-only >/dev/null 2>&1 || true
fi

msg_ok "Merged to prod and pushed. Tarball will be built from prod."
cd "$PROD_WORKTREE"
if [[ ! -x "./scripts/deploy-to-pi.sh" ]]; then
  msg "scripts/deploy-to-pi.sh not found or not executable in prod worktree."
  exit 1
fi

msg "Running deploy-to-pi.sh from prod worktree..."
./scripts/deploy-to-pi.sh "${ARGS[@]}"
msg_ok "Deploy to Pi finished."

cd "$REPO_ROOT"
# Only switch main repo to prod if prod is not checked out in another worktree
if [[ -z "$(get_existing_prod_worktree_path)" ]]; then
  git checkout prod
  msg_ok "Deploy to Pi complete. Switched to branch prod."
else
  msg_ok "Deploy to Pi complete. Main repo left on branch $CURRENT_BRANCH (prod is in worktree)."
fi
