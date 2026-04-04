# A.2.4 Display Board Program Resolution — Execution Plan

**Reference:** [central-edge-tasks.md](../../central-edge-tasks.md) (Phase A), [central-edge-v2-final.md](../central-edge-v2-final.md)  
**Goal:** Resolve `$programId` for the display board from query param `?program={id}`. If absent, show a program selector (no default single program). Update Echo subscriptions to program-scoped channels (`display.activity.{programId}`, `queue.{programId}`); backend event migration to those channels is done in A.5.

**Status:** Not started.

---

## Two delegateable tasks

### Task A — Controller, resolver, DisplayBoardService, and program selector data

**Scope:** DisplayController::board(), DisplayBoardService::getBoardData(), route unchanged; add optional query param handling and program selector fallback.

**Steps:**
1. **DisplayController::board()** — Read `$programId = $request->query('program')` (integer or string that can be cast to int). If present: resolve program by `Program::where('id', $programId)->where('is_active', true)->first()`. If found, call `DisplayBoardService::getBoardData($programId)` and render `Display/Board` with that data plus `currentProgram` (id, name) for the frontend. If not found (invalid id or inactive), render same view with `program_name: null`, empty queue data, and a flag or message so the frontend can show "Program not found" or redirect to selector. If `program` query param is absent: do not resolve a single program; render `Display/Board` with `programs` (list of active programs for selector), `currentProgram: null`, and empty or placeholder board data so the frontend shows the program selector.
2. **DisplayBoardService::getBoardData(?int $programId = null)** — Add optional parameter. When `$programId !== null`, use `Program::find($programId)` (and ensure active) for all board queries (sessions, stations, settings). When `$programId === null`, return the same "no program" structure as today (null program_name, empty arrays). Remove internal `Program::where('is_active', true)->first()` from getBoardData when programId is provided.
3. **Program list for selector** — When no `?program=` in URL, controller loads `Program::where('is_active', true)->get(['id', 'name'])` and passes as `programs` to the view. Frontend uses this to render program selector and to build links like `/display?program=1`.
4. **Tests** — Feature test: `GET /display` (no query) returns 200 with `programs` array and no single program; `GET /display?program=1` with active program 1 returns board data for program 1 and `currentProgram`; `GET /display?program=999` (invalid/inactive) returns 200 with empty/not-found state. Unit or feature test: DisplayBoardService::getBoardData(1) returns data scoped to program 1; getBoardData(null) returns empty/no-program structure.

**Files:** `app/Http/Controllers/DisplayController.php`, `app/Services/DisplayBoardService.php`, `tests/Feature/DisplayBoardTest.php` or `tests/Feature/DisplayControllerTest.php`.

---

### Task B — Frontend program selector and Echo channel subscription

**Scope:** Display/Board.svelte — program selector UI when no program in URL, and Echo subscriptions to program-scoped channels.

**Steps:**
1. **Program selector** — When page props have `currentProgram == null` and `programs` (array) with length > 0, render a program selector: list or dropdown of program names; each option links to `/display?program={id}`. When `programs` is empty, show "No active program" message. When `currentProgram` is set, render the existing board UI (now serving, queue, etc.).
2. **URL sync** — On load, if URL has `?program=`, use it; if not, show selector. When user selects a program, navigate to `router.visit('/display?program=' + id)` so the page reloads with program in URL (or use replaceState + same page data if controller supports it). Prefer full navigation so controller passes correct program-scoped data.
3. **Echo channel** — When `currentProgram` is set (and Echo is available), subscribe to `display.activity.{programId}` and `queue.{programId}` instead of `display.activity` and `global.queue`. Use the same event names (e.g. `station_activity`, `queue_length`). Unsubscribe on cleanup or when program changes. Note: backend events (StationActivity, QueueLengthUpdated, etc.) will broadcast on these channels in A.5; until then, either keep dual subscription (old + new) for compatibility or implement A.5 first so backend emits on new channels.
4. **Tests** — Manual or E2E: open `/display`, see program list; select program, see board for that program. Open `/display?program=1`, see board for program 1. Verify Echo subscriptions use program-scoped channel names when program is set (and that real-time updates work after A.5).

**Files:** `resources/js/Pages/Display/Board.svelte`, `resources/js/echo.js` (no change unless channel name helper is centralized).

---

## Notes

- **Unauthenticated:** Display board remains public; no session. Program resolution is solely from query param and active program list.
- **A.5 dependency:** Backend must broadcast on `display.activity.{programId}` and `queue.{programId}` (A.5.1, A.5.2) for real-time to work. Frontend can subscribe in A.2.4; coordinate with A.5 so both are deployed together or backend is updated first.
- **Station board:** `DisplayController::stationBoard(Station $station)` and `Display/StationBoard.svelte` may remain single-program or be updated to accept program from URL/context in a follow-up; scope of A.2.4 is the main board only unless task list says otherwise.
