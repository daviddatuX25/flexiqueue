#!/usr/bin/env bash
# Wrapper deploy script:
# - Run from dev (or any) branch in the main repo
# - Uses a separate prod worktree to build + deploy, so your main tree/branch never changes
#
# Usage (from repo root):
#   ./scripts/deploy-via-prod.sh [args forwarded to deploy-to-pi.sh]
#
# Example:
#   ./scripts/deploy-via-prod.sh --build
#   PI_HOST=orangepi.local ./scripts/deploy-via-prod.sh --build --seed

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

msg()    { echo "[FlexiQueue][via-prod] $*" >&2; }
msg_ok() { echo "[FlexiQueue][via-prod] ✓ $*" >&2; }

# Ensure we are in a git repo
if ! git rev-parse --git-dir >/dev/null 2>&1; then
  msg "This script must be run inside the FlexiQueue git repository."
  exit 1
fi

# Remember the current branch just for info (we don't switch it)
CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")"
msg "Current branch in main worktree: ${CURRENT_BRANCH:-unknown}"

# Require clean working tree in the main repo, unless user explicitly overrides
if [[ "${ALLOW_DIRTY_DEPLOY:-0}" != "1" ]]; then
  if [[ -n "$(git status --porcelain)" ]]; then
    msg "Working tree has uncommitted changes. Commit, stash, or set ALLOW_DIRTY_DEPLOY=1 to override."
    exit 1
  fi
fi

# Location for the prod worktree
PROD_WORKTREE="$(cd "$REPO_ROOT/.." && pwd)/flexiqueue-prod"

# Ensure prod branch exists
if ! git show-ref --verify --quiet refs/heads/prod; then
  msg "Branch 'prod' does not exist locally. Create it (or fetch it) before using this script."
  exit 1
fi

# Ensure there is a worktree for prod
if git worktree list --porcelain | grep -q "worktree $PROD_WORKTREE"; then
  msg "Using existing prod worktree at: $PROD_WORKTREE"
else
  msg "Creating prod worktree at: $PROD_WORKTREE"
  git worktree add "$PROD_WORKTREE" prod
fi

# Optionally update prod from remote (if tracking). We keep this conservative: only pull if clean.
if [[ -z "$(git -C "$PROD_WORKTREE" status --porcelain)" ]]; then
  # Ignore errors if no remote/tracking is configured.
  git -C "$PROD_WORKTREE" pull --ff-only >/dev/null 2>&1 || true
fi

msg_ok "Prod worktree ready."

# Run the existing deploy script from inside the prod worktree, forwarding all args.
cd "$PROD_WORKTREE"
if [[ ! -x "./scripts/deploy-to-pi.sh" ]]; then
  msg "scripts/deploy-to-pi.sh not found or not executable in prod worktree."
  exit 1
fi

msg "Running deploy-to-pi.sh from prod worktree..."
./scripts/deploy-to-pi.sh "$@"
msg_ok "Deploy finished via prod worktree."

# Return to main repo directory (branch there was never changed)
cd "$REPO_ROOT"
msg_ok "Back in main repo at branch: ${CURRENT_BRANCH:-unknown}"

