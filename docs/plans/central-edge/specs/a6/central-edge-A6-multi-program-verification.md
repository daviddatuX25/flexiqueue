## A.6 — Multi-Program Verification (Execution Plan)

### Goal

Verify that Phase A changes (A.1–A.5) correctly support **two active programs in parallel** with **no cross-contamination** between programs across the display board, station queues, triage/public flows, and session lifecycle, while keeping the **Pre-Work tests (PW.1–PW.5)** green. This bead is verification-only: it adds/extends tests and applies minimal fixes as needed so that behavior matches the Phase A success criteria in `central-edge-v2-final.md` §Phase A.

### Reference

- Main spec: `central-edge-v2-final.md`
  - Phase A success criteria (lines 170–180), especially:
    - Two programs (`Program A`, `Program B`) both active simultaneously with 5 sessions each and isolated display boards
    - Staff assigned to Program A must not see Program B sessions at their station
    - Public triage routes `/public/triage/{program}` bind sessions to the correct `program_id`
    - All pre-work integration tests still pass
- Task list: `../central-edge-tasks.md` — A.6.1–A.6.3
- Services/controllers used in verification:
  - `DisplayController::board`, `DisplayController::publicTriage`, `DisplayController::status`
  - `DisplayBoardService`
  - `StationQueueService`
  - `SessionController` / `SessionService` (session lifecycle)
  - `PublicTriageController` API routes for `/api/public/token-lookup` and `/api/public/sessions/bind`

### Delegateable Tasks

#### 1. A.6.1 — Two-Program Session Lifecycle (Bind → Call → Serve → Transfer → Complete)

**Scope**

- Exercise the full session lifecycle **for two active programs in parallel** using the HTTP APIs:
  - Program A: 5 sessions on a two-station track
  - Program B: 5 sessions on its own two-station track
- Confirm:
  - `queue_sessions.program_id` is always equal to the owning program for each flow
  - Tokens for Program A sessions never appear in Program B’s lifecycle, and vice versa
- Out of scope:
  - Edge/bridge modes (APP_MODE, EdgeModeService)
  - Sync/edge packages (Phases D–G)

**Steps**

1. Create new feature test: `tests/Feature/Api/MultiProgramSessionLifecycleTest.php`.
2. In test setup, create:
   - Program A and Program B (both `is_active = true`)
   - For each program:
     - Two stations (S1, S2) with `station_process` links
     - One default track with two required steps mapping to S1 then S2
     - One staff user assigned to S1 (per program)
3. Add test `test_multi_program_session_lifecycle_flows_are_isolated_per_program`:
   - For Program A staff:
     - Bind 5 sessions via `POST /api/sessions/bind` using Program A’s track/station context
     - For one of the sessions: call → serve → transfer → complete using the standard endpoints
   - For Program B staff:
     - Bind 5 sessions via `POST /api/sessions/bind` using Program B’s track/station context
     - For one of the sessions: call → serve → transfer → complete
   - Assert:
     - All Program A sessions have `program_id = programA.id`
     - All Program B sessions have `program_id = programB.id`
     - No session row exists where a Program A token is attached to `program_id = programB.id` or vice versa
     - Relevant `transaction_logs` rows exist for both programs (bind, call, check_in, transfer, complete)
4. Add test `test_staff_for_program_a_never_sees_program_b_sessions_in_queue`:
   - Create sessions at Program A’s station and Program B’s station
   - Acting as Program A staff, hit `/api/stations/{stationA}/queue`
   - Assert:
     - `stats.total_waiting` and `waiting` only include Program A aliases
     - No Program B alias appears in any `waiting` or `serving` entry
5. Run PHPUnit and adjust assertions as needed to match existing API shapes. Code changes are only allowed when a genuine bug or missing guard is revealed by tests (not for stylistic refactors).

**Files**

- `tests/Feature/Api/MultiProgramSessionLifecycleTest.php`
- (Potential small fixes only if tests reveal gaps) `SessionController`, `SessionService`, or related policies.

#### 2. A.6.2 — Display Board and Station Queue Isolation

**Scope**

- Verify that **display board** and **station display/queue APIs** remain isolated per program when two programs are active:
  - `/display?program=A` only shows Program A sessions
  - `/display?program=B` only shows Program B sessions
  - Station display `/display/station/{station}` and station queue `/api/stations/{station}/queue` never mix programs
- Out of scope:
  - Echo/Svelte subscription wiring (already covered by `tests/Unit/Events/BroadcastingChannelsTest.php` and Svelte updates in A.5)

**Steps**

1. Extend `tests/Feature/DisplayBoardTest.php` with:
   - `test_multi_program_display_board_is_isolated_per_program`:
     - Create Program A and Program B, each with:
       - One station
       - One track
       - One serving session (`alias` distinct per program)
     - Call `/display?program={programA}` and assert:
       - `program_name` equals Program A
       - `total_in_queue` and `now_serving` only reference Program A alias
     - Call `/display?program={programB}` and assert the same for Program B.
2. Extend `tests/Feature/DisplayBoardTest.php` or add a new test to ensure:
   - `/display` without `?program=` returns both programs in `programs` and no `currentProgram`, as already covered but now exercised with both programs active.
3. Extend `tests/Feature/DisplayBoardTest.php` or `tests/Feature/Api/StationQueueApiTest.php` with:
   - `test_multi_program_station_display_is_isolated_per_program`:
     - Create station and sessions for Program A and Program B
     - Call `/display/station/{stationA}` and assert:
       - `program_name` is Program A
       - `now_serving`/`waiting` only include Program A aliases
4. Extend `tests/Feature/Api/StationQueueApiTest.php` with:
   - `test_multi_program_station_queue_is_isolated_per_program`:
     - Create Program B + station + waiting session
     - Existing Program A + station + waiting session from setUp
     - Acting as Program A staff, hit `/api/stations/{stationA}/queue`
     - Assert `waiting` only includes Program A alias and that total waiting matches Program A count.
5. Re-run the test suite and fix any discrepancies by tightening `program_id` filters where missing, rather than reintroducing single-active-program assumptions.

**Files**

- `tests/Feature/DisplayBoardTest.php`
- `tests/Feature/Api/StationQueueApiTest.php`
- (Potential small fixes) `DisplayBoardService`, `DisplayController`, or `StationQueueService` if missing `program_id` filters are uncovered.

#### 3. A.6.3 — Public Triage and Pre-Work Tests in Multi-Program Context

**Scope**

- Confirm that public triage URLs and APIs are correctly **program-scoped** when multiple programs are active.
- Confirm that the **pre-work integration tests (PW.1–PW.5)** still pass after A.1–A.5 and the new A.6 tests.

**Steps**

1. Extend `tests/Feature/PublicTriageTest.php` with:
   - `test_public_triage_multi_program_bind_sessions_are_scoped_to_correct_programs`:
     - Create Program A + track and Program B + track (both active, `allow_public_triage = true`)
     - Bind a session for Program A via `POST /api/public/sessions/bind` with `program_id = programA.id`
     - Bind a session for Program B via `POST /api/public/sessions/bind` with `program_id = programB.id`
     - Assert:
       - `queue_sessions` contains one row per program with matching `program_id`
       - No row exists where a token for Program A is attached to `program_id = programB.id` or vice versa.
2. (Optional but recommended) Add a test asserting that `/public/triage/{programB}` returns props with `program_id = programB.id` and does not leak any state from Program A.
3. Run the full test suite:
   - Ensure that:
     - PW.1 — Session lifecycle integration tests still pass (`SessionBindTest`, `SessionActionsTest`)
     - PW.2 — Display board tests still pass (`DisplayBoardTest`)
     - PW.3 — Triage tests still pass (`PublicTriageTest` and related identity registration tests)
     - PW.4 — Baseline coverage tests for single-active-program files still pass
     - PW.5 — Tag state is unchanged in git history (no rollback)
4. If any pre-work tests fail, treat that as a **regression blocker** for A.6 and fix the underlying code before marking A.6 complete.

**Files**

- `tests/Feature/PublicTriageTest.php`
- (No changes expected) Existing PW test classes; only verification that they still pass.

