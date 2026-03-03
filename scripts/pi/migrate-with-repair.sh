#!/usr/bin/env bash
# Run migrate, then verify critical schema and repair orphaned migration records if needed.
# Prevents "table X has no column named Y" when migrations table is out of sync with actual DB.
#
# Usage (on Pi, from app root or with APP_DIR):
#   sudo -u www-data ./scripts/pi/migrate-with-repair.sh
#   APP_DIR=/var/www/flexiqueue sudo -u www-data ./scripts/pi/migrate-with-repair.sh
#
# Called by deploy-to-pi.sh and update-from-url.sh during deploy.

set -e
APP_DIR="${APP_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"
cd "$APP_DIR"

# 1. Run migrations
php artisan migrate --force

# 2. Schema verification: if tokens exists but pronounce_as missing, migration record is orphaned
if [ ! -f database/database.sqlite ]; then
  exit 0
fi

REPAIR=0

# Check tokens.pronounce_as (migration: 2026_02_28_000001_add_pronounce_as_to_tokens_table)
if command -v sqlite3 >/dev/null 2>&1; then
  if sqlite3 database/database.sqlite "SELECT name FROM sqlite_master WHERE type='table' AND name='tokens';" 2>/dev/null | grep -q 'tokens'; then
    # tokens table exists — verify pronounce_as column
    if ! sqlite3 database/database.sqlite "PRAGMA table_info(tokens);" 2>/dev/null | grep -q 'pronounce_as'; then
      REPAIR=1
      MIGRATION_TO_REMOVE="2026_02_28_000001_add_pronounce_as_to_tokens_table"
    fi
  fi
fi

if [ "$REPAIR" -eq 1 ]; then
  echo "Schema repair: re-running orphaned migration ($MIGRATION_TO_REMOVE)..."
  php artisan tinker --execute="DB::table('migrations')->where('migration', '$MIGRATION_TO_REMOVE')->delete();"
  php artisan migrate --force
fi
