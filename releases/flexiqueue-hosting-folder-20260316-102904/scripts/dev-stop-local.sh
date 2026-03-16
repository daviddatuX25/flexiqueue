#!/usr/bin/env bash
# dev-stop-local — stop local dev processes (no Sail).
# Run from project root. Vite/Reverb/queue are stopped via Ctrl+C in the dev-start-local terminal.
# This script is a no-op placeholder; use it if you ever add background processes for local dev.
set -e
cd "$(dirname "$0")/.."

echo "Local dev (Vite + Reverb + queue) runs in foreground. Use Ctrl+C in that terminal to stop."
echo "If processes are orphaned: pkill -f 'php artisan reverb' ; pkill -f 'php artisan queue' ; pkill -f vite"
