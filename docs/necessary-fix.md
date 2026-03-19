# FlexiQueue — Pre-Debut Performance Fix Plan
**Window:** 12 hours | **Goal:** Eliminate browser-wide lag before debut

---

## Root Cause Summary

Three issues are confirmed from the live codebase. They compound each other:

| # | File | What it does wrong | Severity |
|---|------|--------------------|----------|
| 1 | `Admin/Dashboard.svelte` line ~68788 | `setInterval(refresh, 10000)` — fires 2 HTTP requests every 10s unconditionally, forever | 🔴 PRIMARY |
| 2 | `Display/Board.svelte` line ~59899 | `refreshBoardData()` (full Inertia reload) called on **every** socket event with no filter | 🔴 MAJOR |
| 3 | `Display/StationBoard.svelte` line ~61481 | `refreshStationData()` called on **every** socket event with no filter | 🔴 MAJOR |
| 4 | Both Board files | `refreshIntervalId`, `unlockPollIntervalId`, `displaySettingsPollIntervalId`, `scanCountdownIntervalId` stored as `$state` — triggers reactivity on every interval tick | 🟠 MODERATE |
| 5 | `Display/Board.svelte` line ~59404 | `$effect` copies all TTS/display props into `$state` — re-runs on every prop change, may initialize TTS with wrong mute/volume | 🟠 MODERATE |

---

## Fix 1 — Dashboard: Stop Unconditional Polling

**File:** `resources/js/Pages/Admin/Dashboard.svelte`

**Problem (confirmed from code):**
```js
// Line ~68788 — this runs every 10 seconds forever
onMount(() => {
    refresh();
    refreshIntervalId = setInterval(refresh, 10000);  // ← THE PROBLEM
    return () => {
        if (refreshIntervalId) clearInterval(refreshIntervalId);
    };
});
```
Also confirmed: `let refreshIntervalId = $state<...>(null)` — stored as `$state` unnecessarily.

**Fix — Two changes:**

**Change A:** Slow down to 60s AND pause when tab is hidden.
```js
// Replace the onMount block entirely:
onMount(() => {
    refresh();

    function startPolling() {
        refreshIntervalId = setInterval(refresh, 60000); // 60s not 10s
    }
    function stopPolling() {
        if (refreshIntervalId) {
            clearInterval(refreshIntervalId);
            refreshIntervalId = null;
        }
    }

    startPolling();

    // Pause when tab is hidden, resume when visible
    function handleVisibility() {
        if (document.hidden) {
            stopPolling();
        } else {
            refresh();         // immediate refresh on return
            startPolling();
        }
    }
    document.addEventListener('visibilitychange', handleVisibility);

    return () => {
        stopPolling();
        document.removeEventListener('visibilitychange', handleVisibility);
    };
});
```

**Change B:** Make refreshIntervalId a plain variable, not `$state`:
```js
// Before:
let refreshIntervalId = $state<ReturnType<typeof setInterval> | null>(null);

// After:
let refreshIntervalId: ReturnType<typeof setInterval> | null = null;
```

**Impact:** Drops from 12 server hits/minute to 1/minute when visible, 0 when tab is hidden.

---

## Fix 2 — Board.svelte: Gate reload on action type

**File:** `resources/js/Pages/Display/Board.svelte`

**Problem (confirmed from code):**
```js
// Lines ~59877–59900 — handler fires refreshBoardData() unconditionally
const handler = (e) => {
    const item = { ... };
    activityFeed = [item, ...activityFeed].slice(0, 20);
    if (e.action_type === 'call') {
        playFullAnnouncement(...);
    }
    refreshBoardData();  // ← fires on EVERY action: note, hold, no_show, override, everything
};
```

**Fix — Add action type gate before reload:**
```js
// Actions that actually change the displayed queue state
const QUEUE_CHANGING_ACTIONS = new Set([
    'bind', 'call', 'serve', 'transfer', 'complete',
    'cancel', 'hold', 'resume', 'no_show', 'enqueue_back',
    'force_complete', 'override'
]);

const handler = (e) => {
    const item = {
        station_name: e.station_name ?? '—',
        message: e.message ?? '',
        alias: e.alias ?? '—',
        action_type: e.action_type ?? '',
        created_at: e.created_at ?? new Date().toISOString(),
    };
    activityFeed = [item, ...activityFeed].slice(0, 20);

    if (e.action_type === 'call') {
        playFullAnnouncement(
            createFullAnnouncementParams(e, {
                connectorPhrase,
                stationTtsByName,
                muted: displayAudioMuted,
                volume: displayAudioVolume,
                onFallback: (reason, text) => { console.warn?.('TTS fallback', reason, text); },
                onCompleteFailure: (reason, text) => { console.warn?.('TTS complete failure', reason, text); },
                repeatCount: displayTtsRepeatCount,
                repeatDelayMs: displayTtsRepeatDelayMs,
            })
        );
    }

    // Only reload if this action changes queue state
    if (QUEUE_CHANGING_ACTIONS.has(e.action_type)) {
        refreshBoardData();
    }
    // Note-only, staff events, etc. — activity feed already updated above, no reload needed
};
```

**Impact:** Eliminates server reloads for `note`, `staff_availability`, and any audit-only events. On a busy day this could be 30–50% fewer Inertia round-trips.

---

## Fix 3 — StationBoard.svelte: Gate reload on action type

**File:** `resources/js/Pages/Display/StationBoard.svelte`

**Problem (confirmed from code):**
```js
// Lines ~61458–61481 — always calls refreshStationData() at the end
function handleStationActivity(e) {
    if (Number(e?.station_id) !== Number(station_id)) {
        refreshStationData();
        return;
    }
    const item = { ... };
    activityFeed = [item, ...activityFeed].slice(0, 20);
    if (e?.action_type === 'call') {
        playSegmentAQueued(...);
    }
    refreshStationData();  // ← unconditional, even for note events
}
```

**Fix:**
```js
const QUEUE_CHANGING_ACTIONS = new Set([
    'bind', 'call', 'serve', 'transfer', 'complete',
    'cancel', 'hold', 'resume', 'no_show', 'enqueue_back',
    'force_complete', 'override'
]);

function handleStationActivity(e) {
    if (Number(e?.station_id) !== Number(station_id)) {
        // Different station — only reload if queue-relevant
        if (QUEUE_CHANGING_ACTIONS.has(e?.action_type)) {
            refreshStationData();
        }
        return;
    }
    const item = {
        station_name: e.station_name ?? station_name,
        message: e.message ?? '',
        alias: e.alias ?? '—',
        action_type: e.action_type ?? '',
        created_at: e.created_at ?? new Date().toISOString(),
    };
    activityFeed = [item, ...activityFeed].slice(0, 20);

    if (e?.action_type === 'call') {
        const pronounceAs = (e.pronounce_as === 'word' ? 'word' : 'letters');
        playSegmentAQueued(e.alias, pronounceAs, e.token_id ?? null, {
            muted,
            volume,
            onCompleteFailure: () => {
                toaster.warning({ title: 'Audio unavailable', description: 'Call announcement could not be played.' });
            },
        });
    }

    // Only reload for queue-changing events
    if (QUEUE_CHANGING_ACTIONS.has(e?.action_type)) {
        refreshStationData();
    }
}
```

---

## Fix 4 — Board.svelte: Demote interval IDs from $state to plain let

**File:** `resources/js/Pages/Display/Board.svelte`

**Problem:** These internal plumbing variables are stored as `$state`, meaning every `setInterval`/`clearInterval` call triggers Svelte's reactivity system.

**Fix — Find and replace these declarations:**
```js
// Before:
let scanCountdownIntervalId = $state(null);
let displaySettingsPollIntervalId = $state(null);
let unlockPollIntervalId = $state(null);
let footerScrollableTimeoutId = $state(null);

// After:
let scanCountdownIntervalId = null;
let displaySettingsPollIntervalId = null;
let unlockPollIntervalId = null;
let footerScrollableTimeoutId = null;
```

**File:** `resources/js/Pages/Display/StationBoard.svelte`

```js
// Before:
let unlockPollIntervalId = $state(null);

// After:
let unlockPollIntervalId = null;
```

**Note:** Do NOT touch interval IDs that are rendered in the template or derived from. These specific ones are pure internal plumbing — confirmed by reading both files.

---

## Fix 5 — TTS fetch: Add timeout to prevent queue lockup

**File:** `resources/js/lib/displayTts.js`

**Problem:** TTS fetches to ElevenLabs endpoints have no timeout. If ElevenLabs is slow, the entire TTS job queue backs up — call events queue silently and may burst-play later.

**Fix:** Add `AbortSignal.timeout(5000)` to both fetch calls in `fetchServerTtsBlob` and `playTokenTts`:

```js
// Find both fetch calls inside displayTts.js and add the signal:
const res = await fetch(url, {
    credentials: 'same-origin',
    signal: AbortSignal.timeout(5000),  // ← add this line
});
```

---

## Execution Order for Developer

Work in this order. Each fix is independent — no dependencies between them.

| Order | Fix | File(s) | Time estimate |
|-------|-----|---------|---------------|
| 1st | Fix 1 — Dashboard polling | `Admin/Dashboard.svelte` | 20 min |
| 2nd | Fix 2 — Board reload gate | `Display/Board.svelte` | 30 min |
| 3rd | Fix 3 — StationBoard reload gate | `Display/StationBoard.svelte` | 20 min |
| 4th | Fix 4 — Demote $state interval IDs | Both Board files | 20 min |
| 5th | Fix 5 — TTS timeout | `lib/displayTts.js` | 15 min |

**Total estimated dev time: ~1.5–2 hours**

---

## What NOT to Touch Before Debut

These are valid issues but are risky to change in a 12-hour window:

- **`$effect` → `$derived` refactor (Issue 3 from audit)** — affects TTS init path, needs careful testing. Risk > reward before debut.
- **Full WebSocket replacement for Dashboard** — architecture change, not a patch. Do after debut.
- **QR offline (api.qrserver.com)** — functional gap, not a lag cause. Schedule separately.
- **OfflineBanner server reachability** — requires new `/api/ping` route. Not blocking debut.
- **DB queue position race (MAX+1)** — only matters under concurrent load. Low risk for controlled debut environment.

---

## After Debut (Post-Launch Sprint)

| Priority | Fix | Notes |
|----------|-----|-------|
| High | Dashboard WebSocket subscription | Proper replacement for polling; subscribe to `display.activity.{programId}` events |
| High | QR generation offline (`qrcode` npm) | Replace all `api.qrserver.com` calls; critical for true field deployment |
| Medium | `$effect` → `$derived` for TTS state | Fixes first-render mute/volume race; needs testing |
| Medium | OfflineBanner server reachability check | Add `/api/ping` route + 30s fetch poll |
| Low | DB queue position uniqueness | Advisory lock or unique index on `(current_station_id, station_queue_position)` |
| Low | super_admin audit scope toggle | Platform vs. operational view switch |

---

