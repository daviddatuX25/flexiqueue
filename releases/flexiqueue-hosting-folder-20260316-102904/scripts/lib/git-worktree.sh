#!/usr/bin/env bash
# Helpers for prod branch and worktree handling. Source from scripts that need prod worktrees.
# Usage: source "$(dirname "${BASH_SOURCE[0]}")/../lib/git-worktree.sh"  (from scripts/*.sh)
# Requires: REPO_ROOT set by caller, or we are in a git repo (REPO_ROOT derived from git rev-parse --show-toplevel).

# Ensure REPO_ROOT is set
if [ -z "${REPO_ROOT:-}" ]; then
  if git rev-parse --git-dir >/dev/null 2>&1; then
    REPO_ROOT="$(git rev-parse --show-toplevel)"
  else
    echo "git-worktree.sh: not in a git repo and REPO_ROOT not set." >&2
    exit 1
  fi
fi

# When in a worktree, return the main repo root (where .git is a dir). Otherwise return REPO_ROOT.
get_main_repo_root() {
  if [ -f "$REPO_ROOT/.git" ]; then
    local gitdir
    gitdir="$(cat "$REPO_ROOT/.git" | sed 's/^gitdir: //')"
    if [[ "$gitdir" == /* ]]; then
      echo "$(dirname "$(dirname "$(dirname "$gitdir")")")"
    else
      (cd "$REPO_ROOT" && cd "$(dirname "$(dirname "$(dirname "$gitdir")")")" && pwd)
    fi
  else
    echo "$REPO_ROOT"
  fi
}

# Ensure prod branch exists. If missing, prompt "Create prod branch from current? [y/N]".
# On y: create prod from current HEAD and return 0. On n or empty: print message and exit 1.
# When not a TTY (e.g. CI), exits with message instead of prompting.
ensure_prod_branch() {
  local msg_prefix="${1:-[FlexiQueue]}"
  if git -C "$REPO_ROOT" show-ref --verify --quiet refs/heads/prod; then
    return 0
  fi
  if [ ! -t 0 ]; then
    echo "${msg_prefix} Prod branch is required. Create it with: git branch prod" >&2
    exit 1
  fi
  echo ""
  read -r -p "${msg_prefix} Create prod branch from current? [y/N]: " answer
  case "${answer^^}" in
    Y|YES)
      git -C "$REPO_ROOT" branch prod
      return 0
      ;;
    *)
      echo "${msg_prefix} Prod branch is required. Create it with: git branch prod" >&2
      exit 1
      ;;
  esac
}

# Get the path of the worktree that has branch prod checked out, or empty if none.
get_existing_prod_worktree_path() {
  local wt_path=""
  while read -r line; do
    if [[ "$line" == worktree* ]]; then
      wt_path="${line#worktree }"
    elif [[ "$line" == branch* ]] && [[ "$line" == *refs/heads/prod ]]; then
      echo "$wt_path"
      return
    fi
  done < <(git -C "$REPO_ROOT" worktree list --porcelain)
}

# Create a temporary prod worktree at a unique path (../flexiqueue-prod-$$).
# Sets PROD_WORKTREE. Call cleanup_prod_worktree_temporary on exit (trap) only if we created this worktree.
# Call ensure_prod_branch before this. If already in a prod worktree, use current dir and do not create.
# On "prod already checked out": use existing worktree path if user confirms.
CREATED_TEMP_WORKTREE=0
ensure_prod_worktree_temporary() {
  local current_branch
  current_branch="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
  if [ -f "$REPO_ROOT/.git" ] && [ "$current_branch" = "prod" ]; then
    PROD_WORKTREE="$REPO_ROOT"
    CREATED_TEMP_WORKTREE=0
    return 0
  fi

  local parent_dir
  parent_dir="$(cd "$REPO_ROOT/.." && pwd)"
  PROD_WORKTREE="${parent_dir}/flexiqueue-prod-$$"
  CREATED_TEMP_WORKTREE=0

  local worktree_log="/tmp/fq-worktree-add-$$.log"
  if ! git -C "$REPO_ROOT" worktree add "$PROD_WORKTREE" prod 2>"$worktree_log"; then
    local err
    err="$(cat "$worktree_log" 2>/dev/null)"
    rm -f "$worktree_log"
    if [[ "$err" == *"already checked out"* ]]; then
      local existing
      existing="$(get_existing_prod_worktree_path)"
      if [ -n "$existing" ] && [ -d "$existing" ]; then
        if [ -t 0 ]; then
          echo ""
          read -r -p "[FlexiQueue] Prod is already checked out elsewhere. Use that worktree for build? [y/N]: " answer
          case "${answer^^}" in
            Y|YES)
              PROD_WORKTREE="$existing"
              return 0
              ;;
          esac
        fi
      fi
      echo "[FlexiQueue] Prod is already checked out at ${existing:-unknown}. Use that path for build, or run from the other worktree." >&2
      exit 1
    fi
    echo "$err" >&2
    exit 1
  fi
  rm -f "$worktree_log"
  CREATED_TEMP_WORKTREE=1
}

cleanup_prod_worktree_temporary() {
  if [ "${CREATED_TEMP_WORKTREE:-0}" -eq 1 ] && [ -n "${PROD_WORKTREE:-}" ] && [ -d "$PROD_WORKTREE" ]; then
    if git -C "$REPO_ROOT" worktree list --porcelain 2>/dev/null | grep -q "worktree $PROD_WORKTREE"; then
      git -C "$REPO_ROOT" worktree remove --force "$PROD_WORKTREE" 2>/dev/null || true
    fi
  fi
}

# Create or reuse a fixed-path prod worktree (../flexiqueue-prod).
# For deploy scripts. If path exists but is not a valid worktree, prompt to remove.
# If "prod already checked out", use existing worktree path (from get_existing_prod_worktree_path).
ensure_prod_worktree_fixed() {
  local msg_prefix="${1:-[FlexiQueue]}"
  local parent_dir
  parent_dir="$(cd "$REPO_ROOT/.." && pwd)"
  PROD_WORKTREE="${parent_dir}/flexiqueue-prod"

  if [ -d "$PROD_WORKTREE" ]; then
    if git -C "$REPO_ROOT" worktree list --porcelain | grep -q "worktree $PROD_WORKTREE"; then
      # Already a valid worktree
      return 0
    fi
    # Path exists but is not a valid worktree (e.g. leftover directory)
    echo ""
    read -r -p "${msg_prefix} Remove existing path at $PROD_WORKTREE and continue? [y/N]: " answer
    case "${answer^^}" in
      Y|YES)
        rm -rf "$PROD_WORKTREE"
        ;;
      *)
        echo "${msg_prefix} Exiting. Remove or rename $PROD_WORKTREE and try again." >&2
        exit 1
        ;;
    esac
  fi

  local worktree_log="/tmp/fq-worktree-add-$$.log"
  if ! git -C "$REPO_ROOT" worktree add "$PROD_WORKTREE" prod 2>"$worktree_log"; then
    local err
    err="$(cat "$worktree_log" 2>/dev/null)"
    rm -f "$worktree_log"
    if [[ "$err" == *"already checked out"* ]]; then
      local existing
      existing="$(get_existing_prod_worktree_path)"
      if [ -n "$existing" ] && [ -d "$existing" ]; then
        if [ -t 0 ]; then
          echo ""
          read -r -p "${msg_prefix} Prod is already checked out elsewhere. Use that worktree? [y/N]: " answer
          case "${answer^^}" in
            Y|YES)
              PROD_WORKTREE="$existing"
              return 0
              ;;
          esac
        fi
      fi
      echo "${msg_prefix} Prod is already checked out at ${existing:-unknown}. Use that path or run from the other worktree." >&2
      exit 1
    fi
    echo "$err" >&2
    exit 1
  fi
  rm -f "$worktree_log"
}

# Print post-build message when main repo is not on prod (for build-only scripts).
# Call with REPO_ROOT set to the main repo root (where we want to report branch).
# When REPO_ROOT is a worktree (.git is a file), skip: caller is a deploy script that will handle branch UX.
print_build_complete_message_if_not_prod() {
  if [ -f "$REPO_ROOT/.git" ]; then
    return 0
  fi
  local main_branch
  main_branch="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")"
  if [ "$main_branch" = "prod" ]; then
    return 0
  fi
  echo ""
  echo "Build complete. Output is from prod branch. To deploy to a device: switch to prod (git checkout prod) and run deploy-to-pi.sh or deploy-to-laragon.sh, or use deploy-via-prod-to-pi.sh / deploy-to-laragon.sh from your current branch (they merge into prod and deploy)."
  echo ""
}
