#!/usr/bin/env bash
# Import granular Phase 1 tasks from PHASE-1-TASKS.md into beads (bd).
# Section-scoped: only creates tasks in the given section.
#
# Usage:
#   ./scripts/import-phase1-beads.sh foundation   # BD-001 to BD-007
#   ./scripts/import-phase1-beads.sh 1-7           # same by range
#
# Sections: foundation(1-7), admin(8-14), triage(15-18), station(19-27),
#           override(28-30), edge(31-32), informant(33-35), realtime(36-39),
#           dashboard(40-42), reports(43-45), polish(46-50), misc(51-52)

set -e
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKLOG="$REPO_ROOT/docs/plans/backlog/PHASE-1-TASKS.md"

get_range() {
  case "$1" in
    foundation) echo "1 7" ;;
    admin) echo "8 14" ;;
    triage) echo "15 18" ;;
    station) echo "19 27" ;;
    override) echo "28 30" ;;
    edge) echo "31 32" ;;
    informant) echo "33 35" ;;
    realtime) echo "36 39" ;;
    dashboard) echo "40 42" ;;
    reports) echo "43 45" ;;
    polish) echo "46 50" ;;
    misc) echo "51 52" ;;
    *)
      if [[ "$1" =~ ^([0-9]+)-([0-9]+)$ ]]; then
        echo "${BASH_REMATCH[1]} ${BASH_REMATCH[2]}"
      else
        echo "Unknown section or range. Use: foundation, admin, or N-M (e.g. 1-7)" >&2
        exit 1
      fi ;;
  esac
}

MIN=1
MAX=7
if [[ -n "$1" ]]; then
  read -r MIN MAX < <(get_range "$1")
fi

TMPDIR="${TMPDIR:-/tmp}"
BODIES="$TMPDIR/beads-import-$$"
mkdir -p "$BODIES"
cleanup() { rm -rf "$BODIES"; }
trap cleanup EXIT

# Parse backlog: collect task title, body, deps for each BD-xxx in range
declare -a TITLES
declare -a BODIES_CONTENT
declare -a DEPS_LIST
declare -A ID_MAP

current_id=""
current_body=""
current_deps=""
in_block=""

while IFS= read -r line; do
  if [[ "$line" =~ ^###\ (BD-[0-9]+):\ (.+)$ ]]; then
    # Save previous
    if [[ -n "$current_id" ]]; then
      num="${current_id#BD-}"
      num=$((10#$num))
      if (( num >= MIN && num <= MAX )); then
        TITLES+=("$current_id: $current_title")
        BODIES_CONTENT+=("$current_body")
        DEPS_LIST+=("$current_deps")
      fi
    fi
    current_id="${BASH_REMATCH[1]}"
    n=$((10#${current_id#BD-}))
    current_id=$(printf "BD-%03d" "$n")
    current_title="${BASH_REMATCH[2]}"
    current_body="### $current_id: $current_title"$'\n'
    current_deps=""
    in_block=1
    continue
  fi
  if [[ -n "$in_block" ]]; then
    if [[ "$line" =~ ^---$ ]] || [[ "$line" =~ ^###\ BD- ]]; then
      in_block=""
      continue
    fi
    current_body+="$line"$'\n'
    if [[ "$line" =~ -\ \*\*Dependencies\*\*:\ ([^.]+) ]]; then
      depstr="${BASH_REMATCH[1]}"
      while [[ "$depstr" =~ (BD-[0-9]+) ]]; do
        d="${BASH_REMATCH[1]}"
        dn=$((10#${d#BD-}))
        current_deps+=$(printf "BD-%03d " "$dn")
        depstr="${depstr#*${BASH_REMATCH[1]}}"
      done
    fi
  fi
done < "$BACKLOG"
# last block
if [[ -n "$current_id" ]]; then
  num="${current_id#BD-}"
  num=$((10#$num))
  if (( num >= MIN && num <= MAX )); then
    TITLES+=("$current_id: $current_title")
    BODIES_CONTENT+=("$current_body")
    DEPS_LIST+=("$current_deps")
  fi
fi

count=${#TITLES[@]}
if (( count == 0 )); then
  echo "No tasks in range BD-$MIN to BD-$MAX" >&2
  exit 1
fi

echo "Importing $count tasks (BD-$MIN to BD-$MAX)..."
for i in "${!TITLES[@]}"; do
  title="${TITLES[$i]}"
  body="${BODIES_CONTENT[$i]}"
  bodyfile="$BODIES/task$i.txt"
  printf '%s' "$body" > "$bodyfile"
  out=$(cd "$REPO_ROOT" && bd create --title "$title" --body-file "$bodyfile" 2>&1) || true
  if [[ "$out" =~ (flexiqueue-[a-z0-9]+) ]]; then
    created="${BASH_REMATCH[1]}"
    bid=$(echo "$title" | sed -n 's/^\(BD-[0-9]*\):.*/\1/p')
    ID_MAP[$bid]=$created
    echo "  $bid -> $created"
  else
    echo "Failed to create: $title" >&2
    exit 1
  fi
done

for i in "${!TITLES[@]}"; do
  title="${TITLES[$i]}"
  bid=$(echo "$title" | sed -n 's/^\(BD-[0-9]*\):.*/\1/p')
  myid="${ID_MAP[$bid]}"
  deps="${DEPS_LIST[$i]}"
  for dep in $deps; do
    depid="${ID_MAP[$dep]:-}"
    if [[ -n "$depid" ]]; then
      (cd "$REPO_ROOT" && bd dep add "$myid" "$depid" 2>/dev/null) || true
      echo "  dep: $bid depends on $dep"
    fi
  done
done

echo "Done. Run \"bd ready\" to see next tasks."
