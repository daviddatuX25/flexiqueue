# Display TTS Gapless Stitch + Targeted Preload Plan

## Goal

Eliminate audible lag between token call (segment 1) and station directions (segment 2) on display boards by introducing a gapless stitched playback path based on pre-generated MP3 assets, while preserving current fallbacks and keeping memory usage bounded.

## Success Criteria

- Segment 1 + segment 2 playback is gapless on the main display when generated audio is enabled and both assets exist.
- Preloading starts for only the next serviceable waiting clients per station (bounded by station `client_capacity`), not the entire queue.
- Preloaded and decoded audio is evicted promptly when queue targets change (cancel, transfer, serve, completion, reorder).
- Existing behavior remains intact when generated assets are unavailable:
  - stitched playback -> current A then B path -> browser speech fallback.
- Changes are modular (single playback engine), reusable (clear API), robust (abort/race safe), and performance-aware (memory + concurrency limits).

## Non-Goals

- No redesign of AnnouncementBuilder segment semantics.
- No migration of asset naming/versioning in this task (covered by TTS asset follow-up).
- No broad refactor of station/admin pages beyond data contract needs for preload targeting.

## Current Baseline

- `resources/js/lib/displayTts.js` currently preloads only server-synthesized segment 2 blob (via `/api/public/tts?text=...`), then plays segment 1 and segment 2 separately.
- Token generated MP3 is already available via `/api/public/tts/token/{token}`.
- Station segment 2 generated MP3 exists in `station.settings.tts.languages.{lang}.audio_path` (via `GenerateStationTtsJob`) but has no public stream endpoint.
- Display board (`Board.svelte`) announces on `station_activity` `call` events and reloads queue state on queue events.

## Architecture (Target)

```mermaid
flowchart LR
  stationActivityEvent[station_activity call event] --> board[Board.svelte]
  board --> preloadSync[syncPreloadTargets]
  board --> playRequest[playFullAnnouncement]

  preloadSync --> ttsEngine[displayTts.js preload cache]
  playRequest --> ttsEngine

  ttsEngine --> tokenApi[/api/public/tts/token/{tokenId}]
  ttsEngine --> stationApi[/api/public/tts/station/{stationId}/{lang}]

  tokenApi --> stitch[WebAudio decode + stitch]
  stationApi --> stitch
  stitch --> gaplessPlay[Single AudioBufferSource playback]
  gaplessPlay --> fallbackPath[current A then B fallback chain]
```

## Design Principles

- **Modularity:** Keep all preload/cache/stitch logic inside `displayTts.js`; page layer only supplies targets and calls small exported functions.
- **Reusability:** Expose generic methods (`syncPreloadTargets`, `playFullAnnouncement`, `clearStitchedPreloads`) so StationBoard or future boards can reuse without duplicating logic.
- **Robustness:** Abort stale fetches, isolate failures per target, keep existing fallback chain untouched.
- **Performance:** Bounded preload set, bounded decode cache, LRU eviction, concurrency cap, quick stale cleanup.

## Contracts and Data Shape

### 1) New public station-asset endpoint

- Route: `GET /api/public/tts/station/{station}/{lang}`
- `lang` restricted to `en|fil|ilo`
- Reads `station.settings.tts.languages.{lang}.audio_path`
- Returns:
  - `200` MP3 stream when path exists and file exists
  - `404` when path missing/invalid or file missing

### 2) Display board payload additions

In `DisplayBoardService::getBoardData()` include:

- `waiting_by_station[].station_id`
- `waiting_by_station[].waiting_clients[].token_id`

These enable deterministic preload targets for the next clients.

### 3) Preload target rule

Per station:

- Take only first `min(waiting_clients.length, client_capacity)` clients.
- Build preload targets from `(token_id, station_id, active_lang)`.

Global:

- De-duplicate targets across reactive updates.
- Remove any cached/in-flight targets not in current target set.

## Implementation Plan

### Phase 1: API + tests first

1. Add feature tests in `tests/Feature/Api/TtsControllerTest.php` for station endpoint:
   - success stream
   - no path
   - missing file
   - invalid language
2. Implement endpoint in `app/Http/Controllers/Api/TtsController.php`.
3. Register route in `routes/web.php`.

### Phase 2: Payload contract update

1. Extend `app/Services/DisplayBoardService.php` waiting payload with `station_id` and `token_id`.
2. Ensure existing consumers remain compatible (no removed fields).

### Phase 3: Playback engine upgrade (core)

In `resources/js/lib/displayTts.js`:

1. Add preload cache module:
   - key: `${tokenId}:${stationId}:${lang}`
   - value: in-flight state + decoded `AudioBuffer` (stitched)
2. Add fetch/decode functions:
   - fetch token MP3
   - fetch station MP3
   - decode via `AudioContext.decodeAudioData`
   - stitch two decoded buffers into one `AudioBuffer`
3. Add exported API:
   - `syncStitchedPreloadTargets(targets, opts)`
   - `clearStitchedPreloads()`
4. Add controls:
   - max in-flight fetches (e.g., 2-3)
   - max decoded entries (global cap, LRU)
   - abort stale in-flight requests
5. Update `runOneAnnouncement(...)`:
   - if stitched buffer for current `(tokenId, stationId, lang)` exists, play gapless
   - else try on-demand stitch
   - on failure, use existing A then B chain

### Phase 4: Board wiring

In `resources/js/Pages/Display/Board.svelte`:

1. Pass `stationId` into full announcement params from activity event.
2. Add reactive preload sync based on:
   - `waiting_by_station`
   - `ttsLanguage`
   - `preferGeneratedAudio`
   - `segment2Enabled`
3. Generate targets per rule (per-station next `client_capacity` waiting clients).
4. On queue-changing events and teardown, trigger target resync/cleanup.

### Phase 5: Validation

1. Run PHPUnit suite.
2. Manual runtime verification checklist:
   - `call` announcement gapless path works.
   - cancel/transfer remove stale preload.
   - memory remains stable under repeated queue churn.
   - fallback still works when station or token MP3 missing.

## Robustness Guardrails

- Never block announcement if preload fails.
- Stitched cache lookup must be best-effort only.
- Keep prior `cancelCurrentAnnouncement()` behavior intact.
- Guard against stale async completion (ignore late results for evicted targets).

## Performance Guardrails

- Preload only targeted next clients, not full queue.
- Limit concurrent fetch+decode operations.
- LRU eviction on decoded cache overflow.
- Clear all decoded buffers and abort controllers on page teardown.

## Reuse Hooks (for future)

- Keep preload key builder and target sync generic so StationBoard can adopt later with minimal integration.
- Keep endpoint and cache abstractions independent of filename conventions; retrieval contract only.

## Risks and Mitigations

- **Risk:** Browser Web Audio compatibility differences.
  - **Mitigation:** feature-detect `AudioContext`; fallback to existing path.
- **Risk:** Over-decoding on rapid queue churn.
  - **Mitigation:** debounce sync lightly, cap concurrency, abort stale requests.
- **Risk:** Endpoint misuse/path traversal.
  - **Mitigation:** strict language validation, path prefix checks, disk existence checks.

## Test Matrix

- **Backend Feature Tests**
  - Station endpoint success and failure modes.
- **Unit/Behavioral Checks (frontend/manual)**
  - gapless path selected when both assets exist
  - fallback chain on partial/missing assets
  - eviction correctness on queue mutation
  - teardown cleanup

## Rollout Strategy

1. Deploy endpoint + payload additions first (safe additive change).
2. Deploy playback engine and board wiring.
3. Observe logs/UX for fallback frequency and memory behavior.
4. If needed, tune preload limits via constants in `displayTts.js`.

## Done Definition

- Tests for new endpoint pass.
- Board uses bounded next-client preload strategy (`client_capacity` per station).
- Gap between segment 1 and segment 2 is removed in generated-audio path.
- No regressions in fallback playback or queue event handling.
- Plan alignment note exists in `docs/plans/TTS_ASSET_MANAGEMENT_FOLLOWUP.md`.
