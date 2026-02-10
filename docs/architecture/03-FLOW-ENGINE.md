# FlexiQueue — Phase 1 Flow Engine

**Purpose:** Define the session state machine, routing algorithm, and edge-case behaviors that govern how clients move through tracks and stations.

This document is the **authoritative reference** for implementing `FlowEngine` and `SessionService`.

---

## 1. Session State Machine

### 1.1 States

| State | Meaning | Token Status | Terminal? |
|-------|---------|-------------|-----------|
| `waiting` | Session is in a station's queue, not yet being served | `in_use` | No |
| `serving` | Staff is actively processing this client at a station | `in_use` | No |
| `completed` | Client finished all steps successfully | `available` | Yes |
| `cancelled` | Session was cancelled before completion | `available` | Yes |
| `no_show` | Client failed to respond after repeated calls | `available` | Yes |

### 1.2 Valid Transitions

```text
bind()          → waiting       (token: available → in_use)
call_next()     → serving       (from waiting, at the same station)
transfer()      → waiting       (from serving, at a NEW station)
override()      → waiting       (from waiting/serving, at a CUSTOM station)
complete()      → completed     (from serving, token freed)
cancel()        → cancelled     (from waiting/serving, token freed)
mark_no_show()  → no_show       (from waiting/serving, token freed)
force_complete()→ completed     (from any active state, supervisor only, token freed)
```

### 1.3 Invalid Transitions (Must Be Rejected)

| Current State | Invalid Action | Response |
|--------------|---------------|----------|
| `completed` | Any mutation | 409: "Session is already completed." |
| `cancelled` | Any mutation | 409: "Session is already cancelled." |
| `no_show` | Any mutation | 409: "Session is already marked as no-show." |
| `waiting` | `complete()` | 409: "Session is not being served. Call next first." |
| `waiting` | `transfer()` | 409: "Session is not being served. Cannot transfer." |

---

## 2. Routing Algorithm: `calculateNextStation`

This is the core routing function. It determines which station a session should go to next.

### 2.1 Pseudocode

```text
FUNCTION calculateNextStation(session):

  INPUT: session (with track_id, current_step_order)
  OUTPUT: { station_id, step_order } OR NULL (flow complete)

  1. LOAD track_steps for session.track_id, ordered by step_order ASC.

  2. FIND next_step WHERE step_order = session.current_step_order + 1.

  3. IF next_step EXISTS:
       a. CHECK next_step.station is_active.
       b. IF station is active:
            RETURN { station_id: next_step.station_id, step_order: next_step.step_order }
       c. IF station is NOT active:
            LOG warning "Station {name} is inactive."
            RETURN NULL (manual routing needed — caller should alert staff)

  4. IF next_step DOES NOT EXIST:
       RETURN NULL (flow is complete — session can be marked completed)
```

### 2.2 Implementation Notes

- This function is **pure logic** — it reads data but does NOT mutate anything.
- The **caller** (SessionService) decides what to do with the result:
  - If `station_id` returned → perform transfer.
  - If `NULL` + flow complete → enable "Complete Session" button.
  - If `NULL` + station inactive → alert staff, suggest override.
- Override routing **bypasses** this function entirely. When `mode = 'custom'` or action is `override`, the target station is provided explicitly.

### 2.3 Edge Case: Overrides and Step Order

When a session is overridden to a non-standard station:
- If the target station appears in the track's step list → set `current_step_order` to that step's order.
- If the target station does NOT appear in the track → leave `current_step_order` unchanged. The session is "off-track" and subsequent routing returns to standard from the current step.

---

## 3. Service Operations

### 3.1 `SessionService::bind(qrHash, trackId, clientCategory)`

**Trigger:** Triage scans a token and confirms category.
**Pre-conditions:**
- Active program exists.
- Token exists with `status = 'available'`.
- `track_id` belongs to the active program.

**Steps:**
1. Look up token by `qr_code_hash`.
2. Validate token status (available, in_use, lost, damaged).
3. Load first `track_step` for the given track (step_order = 1).
4. Create `Session`:
   - `token_id`, `program_id`, `track_id`
   - `alias` = `token.physical_id`
   - `client_category`
   - `current_station_id` = first step's station
   - `current_step_order` = 1
   - `status` = `waiting`
   - `started_at` = now
5. Update `Token`: `status = 'in_use'`, `current_session_id = session.id`.
6. Log: `action_type = 'bind'`, `next_station_id` = first station.
7. Broadcast: `ClientArrived` to `station.{first_station_id}`, `QueueLength` to `global.queue`.

---

### 3.2 `SessionService::callNext(stationId)`

**Trigger:** Staff presses "Call Next Client" at their station.
**Pre-conditions:**
- Station has waiting sessions.
- No session currently being served at this station (or re-calling same session).

**Steps:**
1. Query: first session with `current_station_id = stationId` AND `status = 'waiting'`, ordered by `started_at ASC`.
2. If this is a **re-call** of the same session (already serving, staff calls again):
   - Increment `session.no_show_attempts`.
   - If `no_show_attempts >= 3` → return `threshold_reached: true` (UI should prompt no-show).
3. If this is a **new call** (no session being served):
   - Set `session.status = 'serving'`.
   - Reset `no_show_attempts = 0` if it was a fresh call.
4. Log: `action_type = 'check_in'`.
5. Broadcast: `StatusUpdate` to `station.{id}`, `NowServing` to `global.queue`.

**Queue Priority:** FIFO by `started_at`. Priority tracks do **NOT** automatically jump the queue — staff has discretion to call in any order they choose. The system suggests FIFO but does not enforce it.

---

### 3.3 `SessionService::transfer(session, mode, targetStationId = null)`

**Trigger:** Staff presses "Send to Next Station" or selects a custom target.
**Pre-conditions:** `session.status = 'serving'`.

**Steps:**
1. If `mode = 'standard'`:
   - Call `FlowEngine::calculateNextStation(session)`.
   - If result is NULL → return "flow complete" (no transfer, suggest completion).
   - Set `targetStationId` = result.station_id, `newStepOrder` = result.step_order.
2. If `mode = 'custom'`:
   - Validate `targetStationId` exists and station is active.
   - Look up matching track_step if target station appears in track. Update step order accordingly.
3. Save previous station: `previousStationId = session.current_station_id`.
4. Update session: `current_station_id = targetStationId`, `current_step_order = newStepOrder`, `status = 'waiting'`, `no_show_attempts = 0`.
5. Log: `action_type = 'transfer'`, `previous_station_id`, `next_station_id`.
6. Broadcast to old station, new station, and `global.queue`.

---

### 3.4 `SessionService::override(session, targetStationId, reason, supervisorUserId, pin)`

**Trigger:** Supervisor approves route deviation.
**Pre-conditions:** `session.status IN ('waiting', 'serving')`, valid supervisor PIN.

**Steps:**
1. Validate supervisor PIN via `PinService::validate(supervisorUserId, pin)`.
2. Validate `reason` is non-empty.
3. Validate `targetStationId` exists and is active.
4. Save previous station.
5. Update session: `current_station_id = targetStationId`, `status = 'waiting'`, `no_show_attempts = 0`.
6. Update `current_step_order`: match target to track step if possible, else leave unchanged.
7. Log: `action_type = 'override'`, `remarks = reason`, `metadata = { supervisor_id }`.
8. Broadcast `OverrideAlert` to target station, updates to `global.queue`.

---

### 3.5 `SessionService::complete(session)`

**Trigger:** Staff presses "Complete Session" at the final station.
**Pre-conditions:** `session.status = 'serving'`.

**Steps:**
1. **Validate required steps are done:**
   - Load all `track_steps` for `session.track_id` where `is_required = true`.
   - Find max `step_order` among required steps.
   - If `session.current_step_order < max_required_step` → reject with 409 + list of remaining steps.
2. Set `session.status = 'completed'`, `completed_at = now`, `current_station_id = NULL`.
3. Unbind token: `token.status = 'available'`, `token.current_session_id = NULL`.
4. Log: `action_type = 'complete'`.
5. Broadcast: `SessionCompleted` to station + `global.queue`.

---

### 3.6 `SessionService::cancel(session, remarks = null)`

**Trigger:** Any authenticated user presses "Cancel Session".
**Pre-conditions:** `session.status IN ('waiting', 'serving')`.

**Steps:**
1. Validate session is not in a terminal state.
2. Set `session.status = 'cancelled'`, `completed_at = now`, `current_station_id = NULL`.
3. Unbind token.
4. Log: `action_type = 'cancel'`, `remarks` (optional).
5. Broadcast updates.

---

### 3.7 `SessionService::markNoShow(session)`

**Trigger:** Staff marks client as no-show after repeated calls.
**Pre-conditions:** `session.status IN ('waiting', 'serving')`.

**Steps:**
1. Set `session.status = 'no_show'`, `completed_at = now`, `current_station_id = NULL`.
2. Unbind token.
3. Log: `action_type = 'no_show'`.
4. Broadcast updates. Remove from station queue.

---

### 3.8 `SessionService::forceComplete(session, reason, supervisorUserId, pin)`

**Trigger:** Supervisor force-ends an active session (double-scan scenario).
**Pre-conditions:** `session.status IN ('waiting', 'serving')`, valid supervisor PIN.

**Steps:**
1. Validate supervisor PIN.
2. Validate reason is non-empty.
3. Set `session.status = 'completed'`, `completed_at = now`, `current_station_id = NULL`.
4. Unbind token.
5. Log: `action_type = 'force_complete'`, `remarks = reason`, `metadata = { supervisor_id }`.
6. Broadcast updates.

---

## 4. Edge Case Behaviors

### 4.1 Ghost Client (No-Show Handling)

**Detection:** `session.no_show_attempts` incremented on each `callNext` for the same session.

**Behavior by attempt:**
| Attempt | UI Behavior | Sound/Alert |
|---------|------------|-------------|
| 1 | "Calling A1... (Attempt 1/3)" | Normal |
| 2 | "Calling A1 AGAIN... (Attempt 2/3)" | Urgent styling |
| >= 3 | Modal: "Client Not Responding" + [Mark No-Show] / [Keep Waiting] | Prominent warning |

**"Mark No-Show"** → calls `SessionService::markNoShow()`.
**"Keep Waiting"** → dismisses modal, session stays active. Counter keeps incrementing.

---

### 4.2 Double Scan (Token Already Active)

**Detection:** At bind time, `token.status = 'in_use'`.

**Behavior:**
- Show modal with current session info (alias, station, status, started_at).
- Options:
  - **View Details** — navigate to session info.
  - **Force End Session** — requires supervisor PIN + reason → calls `forceComplete()`.
- If `token.status IN ('lost', 'damaged')` → block with error, no recovery option.

---

### 4.3 Process Skipper (Out-of-Order Station)

**Detection:** When a session arrives at a station, compare expected step vs. actual station.

```text
FUNCTION validateSequence(session, stationId):
  expected = FlowEngine::calculateNextStation(session)

  IF expected IS NULL:
    RETURN { valid: true }  // Flow is complete, any station is fine

  IF expected.station_id == stationId:
    RETURN { valid: true }

  // Mismatch — check if the skipped step is required
  skipped_steps = track_steps WHERE step_order > session.current_step_order
                                AND step_order < expected.step_order
                                AND is_required = true

  IF skipped_steps is empty:
    RETURN { valid: true, warning: "Skipped optional step(s)" }

  RETURN {
    valid: false,
    expected_station: expected.station,
    missing_required_steps: skipped_steps,
    this_station: stationId
  }
```

**If invalid:** Show full-screen red "INVALID SEQUENCE" overlay.
- "Send Back to Expected Station" → transfer to expected station.
- "Supervisor Override" → override flow with PIN + reason.

---

### 4.4 Token Swap Fraud (Priority Abuse)

**Detection:** Cannot be detected automatically. Relies on staff verification.

**Behavior:** When serving a priority-category session (`client_category` IN 'PWD', 'Senior', 'Pregnant'):
- Show "VERIFY IDENTITY" prompt with category and alias.
- Staff clicks "ID Verified" → continue normally.
- Staff clicks "Mismatch" → log `identity_mismatch` with description.
  - Options: "Send Back to Triage" (re-route) or "Cancel Session".

---

## 5. Idempotency Rules

For future offline-replay support (Phase 2), design services to be idempotent where possible:

| Operation | Idempotency Strategy |
|-----------|---------------------|
| `bind` | Reject if token already in_use (natural idempotency) |
| `transfer` | Reject if session not in 'serving' state |
| `complete` | Reject if session already completed |
| `cancel` | Reject if session already in terminal state |
| `no_show` | Reject if session already in terminal state |

Phase 2 will add `request_id` (UUID) to all mutations for explicit duplicate detection.

---

## 6. Transaction Log Contract

**Every service operation in this document MUST produce exactly one `transaction_logs` entry.**

The log is created by `AuditLogger::logTransaction()`, called at the END of the service method (after all state changes succeed). If the service method fails or throws, no log is written (atomic with the business operation).

Required fields per action type:

| action_type | station_id | previous_station_id | next_station_id | remarks required? |
|------------|-----------|--------------------|-----------------|--------------------|
| `bind` | triage station | NULL | first station | No |
| `check_in` | current station | NULL | NULL | No |
| `transfer` | current station | old station | new station | No |
| `override` | current station | old station | new station | **YES** |
| `complete` | current station | NULL | NULL | No |
| `cancel` | current station (or NULL) | NULL | NULL | Recommended |
| `no_show` | current station | NULL | NULL | No |
| `force_complete` | current station | NULL | NULL | **YES** |
| `identity_mismatch` | current station | NULL | NULL | **YES** |
