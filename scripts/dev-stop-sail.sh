#!/usr/bin/env bash
# dev-stop-sail — stop Sail containers and bring down the dev stack.
# Run from project root. Use after dev-start-sail.sh (Ctrl+C stops Vite/Reverb/queue; this stops Sail).
set -e
cd "$(dirname "$0")/.."
sail=./vendor/bin/sail

echo "Stopping Sail containers…"
"$sail" down
