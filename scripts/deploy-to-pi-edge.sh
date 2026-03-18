#!/usr/bin/env bash
# Deploy FlexiQueue to an Orange Pi as an EDGE node.
#
# Build & ship workflow (same as deploy-to-pi.sh):
#   With --build: uses prod worktree (build-deploy-tarball.sh or build-deploy-tarball-sail.sh),
#   builds there (composer + npm), creates flexiqueue-deploy.tar.gz in repo root, then scp's
#   tarball + scripts/pi/apply-tarball.sh to the Pi and runs apply-tarball.
#
# What this does differently from deploy-to-pi.sh:
#   1. Uses .env.edge as the base .env on the Pi after apply.
#   2. After apply, writes APP_MODE=edge, CENTRAL_URL, CENTRAL_API_KEY, SITE_ID into .env.
#   3. After apply, prompts whether to run edge:import-package to sync a program from central.
#
# Central server for all envs: https://flexiqueue.click (set in env.edge and .env.hosting).
#
# Usage (from repo root):
#   PI_HOST=flexiqueue.edge ./scripts/deploy-to-pi-edge.sh
#   PI_HOST=flexiqueue.edge ./scripts/deploy-to-pi-edge.sh --build
#   PI_HOST=... DEPLOY_MIGRATE=1 ./scripts/deploy-to-pi-edge.sh --build
#   PI_HOST=... CENTRAL_URL=https://central.example.com CENTRAL_API_KEY=sk_live_xxx SITE_ID=1 \
#     ./scripts/deploy-to-pi-edge.sh --build --import=1
#
# Environment variables:
#   PI_HOST          Pi hostname or IP (required; prompted if not set)
#   PI_USER          Pi SSH user (default: root)
#   CENTRAL_URL      Central server URL (from .env.edge or env.edge, else prompted)
#   CENTRAL_API_KEY  Site API key (from .env.edge or env.edge, else prompted)
#   SITE_ID          Site ID on central (from .env.edge or env.edge, else prompted)
#   DEPLOY_MIGRATE   1=incremental, 2=fresh+seed, 3=skip (interactive prompt if not set)
#   EDGE_PROGRAM_ID  Program ID to import after deploy (prompted if --import flag used)
#
# Flags:
#   --build          Build tarball before deploying (defaults to Sail/Docker for build)
#   --sail           Use Sail/Docker for build (default when --build)
#   --no-sail        Use host PHP for build instead of Sail
#   --migrate=...    incremental|fresh|skip (same as deploy-to-pi.sh)
#   --import=N       After deploy, run edge:import-package --program=N on the Pi
#   --no-import      Skip the import prompt entirely
#
# Requires: flexiqueue-deploy.tar.gz in repo root (or run with --build).
# Pi must have: /var/www/flexiqueue (run full-setup-pi.sh once if first time).

set -e
cd "$(dirname "$0")/.."

PI_HOST="${PI_HOST:-}"
PI_USER="${PI_USER:-root}"
CENTRAL_URL="${CENTRAL_URL:-}"
CENTRAL_API_KEY="${CENTRAL_API_KEY:-}"
SITE_ID="${SITE_ID:-}"
BUILD=0
USE_SAIL=0
SAIL_CHOSEN=0  # 1 if user passed --sail or --no-sail
MIGRATE_ARG=""
IMPORT_PROGRAM_ID="${EDGE_PROGRAM_ID:-}"
DO_IMPORT=""  # empty = prompt, "skip" = skip

for arg in "$@"; do
  case "$arg" in
    --build) BUILD=1 ;;
    --sail)  USE_SAIL=1; SAIL_CHOSEN=1 ;;
    --no-sail) USE_SAIL=0; SAIL_CHOSEN=1 ;;
    --migrate=*) MIGRATE_ARG="${arg#--migrate=}" ;;
    --import=*)  IMPORT_PROGRAM_ID="${arg#--import=}"; DO_IMPORT="yes" ;;
    --no-import) DO_IMPORT="skip" ;;
  esac
done

# When building, prefer Sail/Docker (PHP and deps live there) unless user passed --no-sail.
if [ "$BUILD" -eq 1 ] && [ "$SAIL_CHOSEN" -eq 0 ]; then
  USE_SAIL=1
fi

# Edge config file: .env.edge or env.edge (so SITE_ID etc. are read without prompting)
if [ -f ".env.edge" ]; then
  ENV_EDGE_FILE=".env.edge"
elif [ -f "env.edge" ]; then
  ENV_EDGE_FILE="env.edge"
else
  ENV_EDGE_FILE=""
fi

# ── Ensure prod is up to date with current branch for Pi builds ────────────────
CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")"
if [[ "${ALLOW_DIRTY_DEPLOY:-0}" != "1" ]] && [[ -n "$(git status --porcelain)" ]]; then
  echo "[FlexiQueue][deploy-to-pi-edge] Working tree has uncommitted changes on ${CURRENT_BRANCH:-unknown}." >&2
  echo "  Commit or stash your changes, or set ALLOW_DIRTY_DEPLOY=1 to bypass this check." >&2
  exit 1
fi

if git show-ref --verify --quiet refs/heads/prod; then
  :
else
  echo "[FlexiQueue][deploy-to-pi-edge] Creating prod branch from ${CURRENT_BRANCH:-current HEAD}..."
  if [ -n "$CURRENT_BRANCH" ] && [ "$CURRENT_BRANCH" != "HEAD" ]; then
    git branch prod "$CURRENT_BRANCH"
  else
    git branch prod
  fi
fi

# Merge current branch into prod. If prod is checked out in another worktree, merge there (like deploy-via-prod-to-pi).
source "$(dirname "$0")/lib/git-worktree.sh"
EXISTING_PROD_WT="$(get_existing_prod_worktree_path)"
if [ "$CURRENT_BRANCH" != "prod" ] && [ -n "$CURRENT_BRANCH" ] && [ "$CURRENT_BRANCH" != "HEAD" ]; then
  if [ -n "$EXISTING_PROD_WT" ] && [ -d "$EXISTING_PROD_WT" ]; then
    echo "[FlexiQueue][deploy-to-pi-edge] Merging $CURRENT_BRANCH into prod (in prod worktree at $EXISTING_PROD_WT)..."
    (cd "$EXISTING_PROD_WT" && git merge --no-edit "$CURRENT_BRANCH")
    echo "[FlexiQueue][deploy-to-pi-edge] prod is now up to date with $CURRENT_BRANCH."
  else
    echo "[FlexiQueue][deploy-to-pi-edge] Merging $CURRENT_BRANCH into prod for Pi deploy..."
    git checkout prod
    git merge --no-edit "$CURRENT_BRANCH"
    git checkout "$CURRENT_BRANCH"
    echo "[FlexiQueue][deploy-to-pi-edge] prod is now up to date with $CURRENT_BRANCH."
  fi
fi

echo ""
echo "  FlexiQueue — Deploy to Pi (EDGE MODE)"
echo "  ——————————————————————————————————————"

# ── Pi host ────────────────────────────────────────────────────────────────────
if [ -z "$PI_HOST" ]; then
  read -r -p "  Pi host (IP or hostname, e.g. flexiqueue.edge): " PI_HOST
  PI_HOST="$(echo "$PI_HOST" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  if [ -z "$PI_HOST" ]; then
    echo "No host given. Exiting."
    exit 1
  fi
fi

# ── Central config (from env.edge when present; no prompts then) ───────────────
# Trim helper for values read from file
trim() { echo "$1" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'; }
DEFAULT_CENTRAL_URL="https://flexiqueue.click"
if [ -n "$ENV_EDGE_FILE" ]; then
  [ -z "$CENTRAL_URL" ]      && CENTRAL_URL="$(trim "$(grep -E '^CENTRAL_URL=' "$ENV_EDGE_FILE" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'")")"
  [ -z "$CENTRAL_API_KEY" ]  && CENTRAL_API_KEY="$(trim "$(grep -E '^CENTRAL_API_KEY=' "$ENV_EDGE_FILE" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'")")"
  [ -z "$SITE_ID" ]         && SITE_ID="$(trim "$(grep -E '^SITE_ID=' "$ENV_EDGE_FILE" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'")")"
  [ -z "$IMPORT_PROGRAM_ID" ] && [ "$DO_IMPORT" = "yes" ] && IMPORT_PROGRAM_ID="$(trim "$(grep -E '^EDGE_PROGRAM_ID=' "$ENV_EDGE_FILE" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'")")"
fi

# If env.edge supplied all central values, skip prompts (one-command deploy).
FROM_EDGE=
if [ -n "$ENV_EDGE_FILE" ] && [ -n "$CENTRAL_URL" ] && [ -n "$CENTRAL_API_KEY" ] && [ -n "$SITE_ID" ]; then
  FROM_EDGE=1
fi

if [ -z "$CENTRAL_URL" ]; then
  read -r -p "  Central server URL [$DEFAULT_CENTRAL_URL]: " CENTRAL_URL
  CENTRAL_URL="$(trim "${CENTRAL_URL:-$DEFAULT_CENTRAL_URL}")"
  [ -z "$CENTRAL_URL" ] && CENTRAL_URL="$DEFAULT_CENTRAL_URL"
fi
if [ -z "$CENTRAL_API_KEY" ]; then
  read -r -p "  Central API key (sk_live_... from Organization settings): " CENTRAL_API_KEY
  CENTRAL_API_KEY="$(trim "$CENTRAL_API_KEY")"
  if [ -z "$CENTRAL_API_KEY" ]; then
    echo "No API key given. Set CENTRAL_API_KEY in env.edge for one-command deploy. Exiting."
    exit 1
  fi
fi
if [ -z "$SITE_ID" ]; then
  read -r -p "  Site ID on central (number) [2]: " SITE_ID
  SITE_ID="$(trim "${SITE_ID:-2}")"
  [ -z "$SITE_ID" ] && SITE_ID=2
fi

# Default program to import when --import used without =N (or from env.edge EDGE_PROGRAM_ID)
if [ "$DO_IMPORT" = "yes" ] && [ -z "$IMPORT_PROGRAM_ID" ]; then
  IMPORT_PROGRAM_ID=1
fi

echo ""
if [ -n "$FROM_EDGE" ]; then
  echo "  Using central config from $ENV_EDGE_FILE (no prompts)."
fi
echo "  Config:"
echo "    Pi host:      $PI_HOST"
echo "    Central URL:  $CENTRAL_URL"
echo "    API key:      ${CENTRAL_API_KEY:0:12}..."
echo "    Site ID:      $SITE_ID"
echo ""

# ── Build tarball ──────────────────────────────────────────────────────────────
if [ "$BUILD" -eq 0 ]; then
  if [ -f flexiqueue-deploy.tar.gz ]; then
    read -r -p "  Build tarball first? [y/N]: " do_build
    case "${do_build^^}" in
      Y|YES) BUILD=1 ;;
      *) BUILD=0 ;;
    esac
  else
    echo "No existing flexiqueue-deploy.tar.gz; will build first."
    BUILD=1
  fi
fi

if [ "$BUILD" -eq 1 ]; then
  if [ "$USE_SAIL" -eq 0 ] && command -v php >/dev/null 2>&1; then
    echo "Building tarball (host)..."
    ./scripts/build-deploy-tarball.sh
  else
    docker_ok=0
    if command -v docker >/dev/null 2>&1; then
      if docker version >/dev/null 2>&1; then docker_ok=1
      elif docker info >/dev/null 2>&1; then docker_ok=1; fi
    fi
    if [ "$docker_ok" -eq 1 ]; then
      echo "Building tarball (Sail/Docker)..."
      ./scripts/build-deploy-tarball-sail.sh
    else
      echo "ERROR: Host PHP not found and Docker is not reachable."
      echo "  Options: 1) Start Docker Desktop and run with --sail"
      echo "           2) Install PHP: sudo apt install php php-cli php-mbstring php-xml php-curl php-zip unzip"
      exit 1
    fi
  fi
else
  echo "Using existing flexiqueue-deploy.tar.gz (run with --build to rebuild first)."
fi

if [ ! -f flexiqueue-deploy.tar.gz ]; then
  echo "No flexiqueue-deploy.tar.gz. Run with --build or create it first."
  exit 1
fi

if [ ! -f scripts/pi/apply-tarball.sh ]; then
  echo "Error: scripts/pi/apply-tarball.sh not found. Cannot deploy."
  exit 1
fi

# ── SSH connection (one password for all steps) ────────────────────────────────
CONTROL="/tmp/fq-edge-deploy-${PI_USER}-${PI_HOST}-$$"
cleanup_ssh() { ssh -S "$CONTROL" -O exit "${PI_USER}@${PI_HOST}" 2>/dev/null || true; rm -f "$CONTROL"; }
trap cleanup_ssh EXIT

echo "Connecting to ${PI_USER}@${PI_HOST} (one password for all steps)..."
ssh -M -S "$CONTROL" -o ControlPersist=120 "${PI_USER}@${PI_HOST}" true

echo "Copying tarball and apply script..."
scp -o ControlPath="$CONTROL" flexiqueue-deploy.tar.gz "${PI_USER}@${PI_HOST}:/tmp/"
scp -o ControlPath="$CONTROL" scripts/pi/apply-tarball.sh "${PI_USER}@${PI_HOST}:/tmp/fq-apply-tarball.sh"

# ── Migrate option ─────────────────────────────────────────────────────────────
MIGRATE_OPT=""
if [ -n "$MIGRATE_ARG" ]; then
  MIGRATE_OPT="$MIGRATE_ARG"
elif [ -n "${DEPLOY_MIGRATE:-}" ]; then
  case "${DEPLOY_MIGRATE}" in
    1) MIGRATE_OPT="incremental" ;;
    2) MIGRATE_OPT="fresh" ;;
    3) MIGRATE_OPT="skip" ;;
    *) MIGRATE_OPT="incremental" ;;
  esac
fi

if [ -z "$MIGRATE_OPT" ]; then
  echo ""
  echo "  Database (choose one):"
  echo "    1) migrate --force (incremental; keep existing data)  ← recommended for updates"
  echo "    2) migrate:fresh --seed --force (start from scratch; DROP all tables)"
  echo "    3) Skip (do not run migrate)"
  read -r -p "  Choice [1-3] (default 1): " post_choice
  post_choice="$(echo "${post_choice:-1}" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  case "$post_choice" in
    1) MIGRATE_OPT="incremental" ;;
    2)
      read -r -p "  Type 'yes' to DROP ALL TABLES and seed: " confirm
      if [ "$confirm" = "yes" ]; then MIGRATE_OPT="fresh"; else MIGRATE_OPT="skip"; fi
      ;;
    *) MIGRATE_OPT="skip" ;;
  esac
fi

# ── Apply tarball ──────────────────────────────────────────────────────────────
echo "Applying tarball on Pi (--migrate=$MIGRATE_OPT)..."
ssh -t -o ControlPath="$CONTROL" "${PI_USER}@${PI_HOST}" \
  "sudo bash /tmp/fq-apply-tarball.sh /tmp/flexiqueue-deploy.tar.gz --migrate=$MIGRATE_OPT"

# ── Write edge env vars into Pi's .env ────────────────────────────────────────
echo ""
echo "Writing edge configuration to Pi .env..."

# If edge env file exists locally (.env.edge or env.edge), scp it as the base .env on the Pi.
# Then patch in the confirmed values so prompts override anything in the file.
if [ -n "$ENV_EDGE_FILE" ]; then
  echo "  Found $ENV_EDGE_FILE locally — using as base .env on Pi..."
  scp -o ControlPath="$CONTROL" "$ENV_EDGE_FILE" "${PI_USER}@${PI_HOST}:/tmp/fq-env-edge"
  ssh -o ControlPath="$CONTROL" "${PI_USER}@${PI_HOST}" \
    "sudo cp /tmp/fq-env-edge /var/www/flexiqueue/.env && sudo chown www-data:www-data /var/www/flexiqueue/.env && rm /tmp/fq-env-edge"
fi

# Patch the four edge vars (upsert: replace existing line or append)
ssh -o ControlPath="$CONTROL" "${PI_USER}@${PI_HOST}" bash <<SSHEOF
  set -e
  ENV=/var/www/flexiqueue/.env

  patch_env() {
    local key="\$1" val="\$2"
    if grep -qE "^\${key}=" "\$ENV" 2>/dev/null; then
      sudo sed -i "s|^\${key}=.*|\${key}=\${val}|" "\$ENV"
    else
      echo "\${key}=\${val}" | sudo tee -a "\$ENV" > /dev/null
    fi
  }

  patch_env APP_MODE          edge
  patch_env CENTRAL_URL       "${CENTRAL_URL}"
  patch_env CENTRAL_API_KEY   "${CENTRAL_API_KEY}"
  patch_env SITE_ID           "${SITE_ID}"
  patch_env QUEUE_CONNECTION  database

  # Force SQLite (same as apply-tarball.sh does, belt-and-suspenders)
  sudo sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' "\$ENV"
  sudo sed -i 's|^DB_DATABASE=.*|DB_DATABASE=database/database.sqlite|' "\$ENV"

  echo "  Edge vars written to .env."

  # Rebuild config cache with new vars
  cd /var/www/flexiqueue
  sudo -u www-data php artisan config:clear
  sudo -u www-data php artisan config:cache
  echo "  Config cache rebuilt."

  # Ensure jobs table exists for database queue driver
  sudo -u www-data php artisan queue:table 2>/dev/null || true
  sudo -u www-data php artisan migrate --force
  echo "  Queue table migration done."

  # Restart queue worker so it picks up new env
  sudo systemctl restart flexiqueue-queue 2>/dev/null || true
  echo "  Queue worker restarted."
SSHEOF

echo ""
echo "  Edge configuration applied:"
echo "    APP_MODE=edge"
echo "    CENTRAL_URL=${CENTRAL_URL}"
echo "    CENTRAL_API_KEY=${CENTRAL_API_KEY:0:12}..."
echo "    SITE_ID=${SITE_ID}"
echo "    QUEUE_CONNECTION=database"

# ── Optional: import program package ──────────────────────────────────────────
if [ "$DO_IMPORT" = "skip" ]; then
  echo ""
  echo "Skipping program import (--no-import)."
else
  echo ""
  if [ -z "$IMPORT_PROGRAM_ID" ] && [ "$DO_IMPORT" != "yes" ]; then
    echo "  Import a program from central now?"
    echo "  This downloads the program config, staff, tokens, and TTS files onto the Pi."
    echo "  You can also do this later from the admin panel (Programs → Re-sync from central)."
    read -r -p "  Import program? [y/N]: " do_import_answer
    case "${do_import_answer^^}" in
      Y|YES) DO_IMPORT="yes" ;;
      *)     DO_IMPORT="skip" ;;
    esac
  fi

  if [ "$DO_IMPORT" = "yes" ]; then
    if [ -z "$IMPORT_PROGRAM_ID" ]; then
      read -r -p "  Program ID to import (find it in the URL on the central server, e.g. /admin/programs/1): " IMPORT_PROGRAM_ID
      IMPORT_PROGRAM_ID="$(echo "$IMPORT_PROGRAM_ID" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
    fi

    if [ -z "$IMPORT_PROGRAM_ID" ]; then
      echo "No program ID given. Skipping import."
      echo "  Run later on the Pi: sudo -u www-data php artisan edge:import-package --program=<ID>"
    else
      echo "Running edge:import-package --program=$IMPORT_PROGRAM_ID on the Pi..."
      echo "(This may take a while if TTS files are being downloaded)"
      ssh -t -o ControlPath="$CONTROL" "${PI_USER}@${PI_HOST}" \
        "cd /var/www/flexiqueue && sudo -u www-data php artisan edge:import-package --program=${IMPORT_PROGRAM_ID}"
    fi
  fi
fi

# ── Done ───────────────────────────────────────────────────────────────────────
echo ""
echo "══════════════════════════════════════════════════════"
echo "  Edge deploy complete."
echo "  Pi: ${PI_HOST}"
echo "  Mode: edge (offline)"
echo ""
echo "  The Pi is now ready to serve the imported program"
echo "  fully offline on the local network."
echo ""
echo "  Access the admin panel at: http://${PI_HOST}"
echo ""
echo "  To re-sync a program later:"
echo "    — Admin panel → Programs → Re-sync from central"
echo "    — Or on the Pi: sudo -u www-data php artisan edge:import-package --program=<ID>"
echo "══════════════════════════════════════════════════════"