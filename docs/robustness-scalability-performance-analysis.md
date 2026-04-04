# FlexiQueue — Robustness, Scalability & Performance Analysis

Code-based findings. All issues reference specific files and line behavior.  
*Last verified: 2025-03 — all items re-checked against current codebase.*

---

## ISSUE 1 — api.qrserver.com calls break in true offline deployment

**Files:** `resources/js/Pages/Display/Board.svelte` (~1134, ~1173), `Display/StationBoard.svelte` (~463), `Display/DeviceAuthorize.svelte` (~206), `Station/Index.svelte` (~2337), `Triage/Index.svelte` (~1152), `Triage/PublicStart.svelte` (~972, ~1044), `Admin/Programs/Show.svelte` (~2383, ~2395, ~2408)

**What's happening:** Multiple `<img src="https://api.qrserver.com/...">` and `<a href="https://api.qrserver.com/...">` calls are hardcoded across the UI — device auth QR, display settings request QR, unlock request QR, triage settings request QR, and admin “show QR” links for display/public-triage URLs. These render when needed during operations (e.g. “supervisor must scan this to approve an override”).

**The problem:** This is a live internet call inside a system designed to run offline on a LAN. With no internet — the stated use case — these images/links fail silently. No fallback, no error message, just a broken `<img>` or useless link. A supervisor opens the override flow, the QR is blank, and operations stall.

**Fix:** Replace api.qrserver.com with a locally-bundled QR generation library (e.g. `qrcode` npm package). Generate the QR as an SVG or canvas element entirely in the browser. Zero network dependency.

---

## ISSUE 2 — WebSocket connection error handling is shallow; no state resync on reconnect

**Files:** `resources/js/echo.js` (`setupConnectionErrorHandling`, ~16–41), `Pages/Display/Board.svelte` (setupEcho), `Pages/Display/StationBoard.svelte` (onMount)

**What's happening:** The connection error handler in echo.js only binds to `failed` and `unavailable` and shows a toast (“Live updates unavailable.”). There is no binding to the `connected` state and no reconnect callback, so no state resync after a reconnect.

Pusher/Reverb’s client reconnects automatically, but the client does not know what events it missed. In Board.svelte and StationBoard.svelte, queue state (now_serving, waiting, activityFeed) stays stale until the next socket event triggers `refreshBoardData()` / `refreshStationData()`.

If a display drops for 90 seconds during a busy period and then reconnects, it can show wrong “Now Serving” until the next event, which may be much later.

**Fix:** In echo.js, bind to the Pusher `connected` state and expose an optional reconnect callback. In Board.svelte and StationBoard.svelte, call `refreshBoardData()` / `refreshStationData()` when that callback fires so the display is corrected immediately after reconnect.

---

## ISSUE 3 — $effect prop-copying pattern is still present in StationBoard.svelte and Board.svelte

**Files:** `Pages/Display/StationBoard.svelte` ($effect ~158–175 syncing muted, volume, ttsLanguage, connectorPhrase, stationPhrase; $effect ~176–177 for activityFeed), `Pages/Display/Board.svelte` ($effect ~134–157 for program/display flags, ~138–157 for TTS/display settings, ~273–274 for activityFeed)

**What's happening:** Both components use `$effect` to copy prop values into local `$state`. That pattern is known to cause first-render bugs (prefer `$derived` for read-only derived state).

**Risk in Board.svelte:** The $effect that copies display_audio_muted, display_audio_volume, displayTtsRepeatCount, etc. runs after the first render. TTS can therefore initialize with default muted=false, volume=1. If a call event arrives via WebSocket in that window, `playFullAnnouncement` may run with wrong mute/volume.

In StationBoard.svelte, `activityFeed = [...(station_activity ?? [])]` inside $effect re-initializes the feed on every prop change, not only when `station_activity` changes, causing unnecessary list rebuilds.

**Fix:**

```js
// Instead of:
let muted = $state(false);
$effect(() => { muted = !!display_audio_muted; });

// Use:
const muted = $derived(!!display_audio_muted);

// For activityFeed (needs to be mutable for real-time prepending):
let activityFeed = $state([...(station_activity ?? [])]);
// Then only update from socket events — don't re-derive from prop on every render.
```

---

## ISSUE 4 — scanCountdownIntervalId (and similar) stored as $state unnecessarily

**Files:** `Pages/Display/Board.svelte` (~190), `Pages/Station/Index.svelte` (~187), `Pages/Triage/Index.svelte` (~94)

**What's happening:** The scanner countdown interval ID is stored as `$state`. Every assignment triggers reactivity and potential re-renders even though the value is never rendered or derived from. The same pattern appears in Station/Index and Triage/Index. CountdownTimer.svelte does it correctly — `intervalId` is a plain `let` (not $state).

**Fix:** Use a plain variable: `let scanCountdownIntervalId = null;` (not `$state`). Same for any other internal interval/timeout IDs that are not displayed or derived.

---

## ISSUE 5 — OfflineBanner monitors navigator.onLine, not actual server reachability

**File:** `resources/js/Components/OfflineBanner.svelte`

**What's happening:** The banner listens to `window.online` / `window.offline` and uses `navigator.onLine`. That only reflects “some” network; it does not reflect reachability of the Laravel server (e.g. server down, Reverb stopped).

In FlexiQueue’s scenario (phones on a hotspot hosted by the server), the phone can stay `navigator.onLine === true` while the app backend is unreachable, so the banner may never show when it matters.

**Fix:** Add a periodic server reachability check, e.g. `fetch('/api/ping', { signal: AbortSignal.timeout(3000) })` every 30 seconds (and a matching GET route). If it fails, show the banner. No `/api/ping` route exists today; it would need to be added.

---

## ISSUE 6 — TTS server fetches have no timeout; can hang indefinitely

**File:** `resources/js/lib/displayTts.js` (playTokenTts ~121, fetchServerTtsBlob ~170)

**What's happening:** Fetches to `/api/public/tts/token/{id}` and `/api/public/tts?text=...` use no AbortSignal. If the server is slow (e.g. ElevenLabs hanging), the TTS fetch can hang. The job queue backs up (currentJobPromise stays pending), so new call events queue and may never play, or play much later in a burst.

**Fix:** Add `signal: AbortSignal.timeout(5000)` to both TTS fetch calls so the existing fallback chain (serverTts → browserTts) runs instead of hanging.

```js
const res = await fetch(url, {
    credentials: 'same-origin',
    signal: AbortSignal.timeout(5000),
});
```

---

## ISSUE 7 — Polling intervals for unlock/settings requests not cleared before async teardown

**Files:** `Pages/Display/Board.svelte` (unlockPollIntervalId, displaySettingsPollIntervalId), `Pages/Display/StationBoard.svelte` (unlockPollIntervalId), `Pages/Triage/PublicStart.svelte` (unlockPollIntervalId)

**What's happening:** When an unlock or display-settings request is created, a 2s polling interval starts. Dismiss/cancel handlers correctly clear the interval. On page leave, `onDestroy` runs `cancelPendingRequestsOnLeave()` (or equivalent), which only does an async cancel fetch — fire-and-forget. It does not synchronously clear the interval first. If Inertia navigates away (e.g. after unlock approval) and the component unmounts and then re-mounts (e.g. user returns), the old interval may still be running until the cancel completes, so two intervals can exist briefly for the same request.

**Fix:** In onDestroy/leave handlers, synchronously clear all polling intervals (clearInterval(unlockPollIntervalId), clearInterval(displaySettingsPollIntervalId)) before calling the async cancel API. Optionally move interval IDs to module scope so they are cleared regardless of mount state.

---

## ISSUE 8 — station_queue_position has no DB-level uniqueness; MAX()+1 race

**File:** `app/Services/SessionService.php` — `getNextQueuePositionAtStation()` (~894–901), used from enqueue, bind, override, etc.

**What's happening:** The next position is computed as `MAX(station_queue_position) + 1` for the station. Two concurrent requests (e.g. two staff binding at once or enqueue-back) can read the same MAX and both write the same position. The queue then has duplicate positions with no tiebreaker; sort order is undefined. Migrations do not add a unique index on (current_station_id, station_queue_position).

**Fix:** Use a DB sequence, or wrap position assignment in a transaction with advisory lock, or add a unique index on (current_station_id, station_queue_position) and retry on duplicate in the application.

---

## ISSUE 9 — refreshStationData() / refreshBoardData() on every socket event

**Files:** `Pages/Display/Board.svelte` (setupEcho handler calls refreshBoardData() after every .station_activity), `Pages/Display/StationBoard.svelte` (handleStationActivity always calls refreshStationData() at end)

**What's happening:** Every station_activity event (check_in, call, hold, note, etc.) triggers a full `router.reload()` (with partial `only` keys, so payload is bounded). On a busy station this can mean a server round-trip every few seconds per device. With multiple tables and displays, many concurrent Inertia reloads can hit the same Laravel process whenever any action fires.

**Fix:** Only call reload for events that change the displayed queue. For example: call and check_in change now_serving → reload; hold changes holding list → reload. Note-only or staff_availability-only events do not change the queue display → skip reload. Add an action_type (or event payload) check before calling refreshBoardData() / refreshStationData().

---

## Summary Table

| # | Issue | Location | Risk in Deployment |
|---|-------|----------|--------------------|
| 1 | api.qrserver.com external calls | Board, StationBoard, DeviceAuthorize, Station, Triage, PublicStart, Programs/Show | 🔴 Breaks all QR flows offline |
| 2 | No state resync after WebSocket reconnect | echo.js, Board, StationBoard | 🔴 Stale queue display after drop |
| 3 | $effect prop-copying on TTS/display state | Board, StationBoard | 🟠 Wrong mute/volume on first call event |
| 4 | scanCountdownIntervalId (etc.) as $state | Board, Station/Index, Triage/Index | 🟠 Unnecessary re-renders |
| 5 | navigator.onLine not server reachability | OfflineBanner.svelte | 🟠 Banner never fires on server crash |
| 6 | No timeout on TTS fetch calls | displayTts.js | 🟠 Job queue backs up on slow ElevenLabs |
| 7 | Poll intervals not cleared before async teardown | Board, StationBoard, Triage/PublicStart | 🟡 Duplicate polling on fast navigations |
| 8 | MAX()+1 queue position race | SessionService.php | 🟡 Duplicate positions under concurrent load |
| 9 | Reload on every socket event | Board, StationBoard | 🟡 Unnecessary server load on busy days |

---

## Additional findings (same categories)

- **Polling IDs as $state:** `displaySettingsPollIntervalId` and `unlockPollIntervalId` are stored as `$state` in Board, StationBoard, and PublicStart. Same “internal plumbing” argument as Issue 4: consider plain `let` if they are never rendered.
- **No `/api/ping`:** Issue 5’s fix requires adding a GET route (e.g. `/api/ping`) that returns 200; the frontend would then poll it for reachability.

---

## Priority for developers

**Issues 1 and 2** should be addressed first — both are silent failures (wrong or blank UI, no error) in the exact conditions the system targets: offline LAN and network drops.
