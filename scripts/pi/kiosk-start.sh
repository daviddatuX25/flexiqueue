#!/usr/bin/env bash
# FlexiQueue Kiosk Launcher
#
# Starts Cage (minimal Wayland compositor) with Chromium in kiosk mode.
# Runs as the flexiqueue-kiosk system user under the flexiqueue-kiosk.service unit.
#
# Chromium flags — rationale:
#   --kiosk                          fullscreen, no address bar, no tabs, no devtools
#   --no-first-run                   skip "Welcome to Chromium" setup screen
#   --disable-translate              no translation bar (reduces distractions)
#   --disable-infobars               hides "Chrome is being controlled by automated test software"
#   --disable-session-crashed-bubble prevents "Chromium didn't shut down correctly" dialog on restart
#   --disable-component-update       prevents update checks from opening dialogs
#   --autoplay-policy=no-user-gesture-required  allows TTS audio to play without a user click
#   --check-for-update-interval=31536000        effectively disables Chromium auto-update in kiosk
#   --disable-features=Translate     belt-and-suspenders for --disable-translate
#   --overscroll-history-navigation=0           prevents touchscreen swipe-back from leaving FlexiQueue
set -euo pipefail
exec cage -- chromium-browser \
 --kiosk \
 --no-first-run \
 --disable-translate \
 --disable-infobars \
 --disable-session-crashed-bubble \
 --disable-component-update \
 --autoplay-policy=no-user-gesture-required \
 --check-for-update-interval=31536000 \
 --disable-features=Translate \
 --overscroll-history-navigation=0 \
 https://flexiqueue.local