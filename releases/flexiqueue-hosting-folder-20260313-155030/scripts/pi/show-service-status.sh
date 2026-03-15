#!/usr/bin/env bash
# Show status of FlexiQueue systemd services (Reverb + queue worker).
# Run on the Pi: ./scripts/pi/show-service-status.sh
# No root required for status.

echo "=== FlexiQueue Reverb ==="
systemctl is-active flexiqueue-reverb 2>/dev/null || echo "not installed"
systemctl is-enabled flexiqueue-reverb 2>/dev/null || true
echo ""
echo "=== FlexiQueue queue worker ==="
systemctl is-active flexiqueue-queue 2>/dev/null || echo "not installed"
systemctl is-enabled flexiqueue-queue 2>/dev/null || true
echo ""
echo "=== Full status (queue worker) ==="
systemctl status flexiqueue-queue --no-pager 2>/dev/null || echo "Run: systemctl status flexiqueue-queue"
