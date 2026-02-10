## Flow Engine

**Purpose:** Define how sessions move through tracks and stations—state machine, standard routing, overrides, and selected edge‑case behaviors.

Related docs: `03-domain-model.md`, `04-database-schema.md`, `08-edge-cases.md`

---

### 1. Session State Machine

Core lifecycle (from architecture Section 5.2 and 8.1):

```text
Token.status = 'available'
  │ scan_at_triage()
  ▼
Session.status = 'waiting'
  │ call_next()
  ▼
Session.status = 'serving'
  │
  ├─ transfer()      → 'waiting' at next station
  ├─ override()      → 'waiting' at custom station
  ├─ complete()      → 'completed' (then token → 'available')
  └─ mark_no_show()  → 'no_show' (then token → 'available')
```

Key invariants:

- A `Session` is always tied to exactly one `Token`.
- A `Token` can have at most one active `Session` at a time.
- All state transitions must write a `TransactionLog` entry.

---

### 2. Standard Routing (`calculateNextStation`)

From architecture Section 5.1:

```text
FUNCTION calculateNextStation(session_id):

  1. LOAD session with track, current_step

  2. CHECK for STAFF OVERRIDE
     IF last log action for this session is 'override':
       RETURN override.next_station_id

  3. CALCULATE STANDARD PATH
     current_step = session.current_step_order
     next_step = track_steps WHERE step_order = current_step + 1

     IF next_step EXISTS:
       RETURN next_step.station_id
     ELSE:
       RETURN NULL (flow complete)

  4. VALIDATE REQUIREMENTS
     IF next_step.is_required:
       CHECK station.is_active
       IF NOT active:
         TRIGGER staff_alert("Station offline - manual routing needed")
         RETURN NULL
```

Implementation notes:

- Overrides are considered **first**; once applied, they behave like a one‑off “custom next step”.
- Returning `NULL` from `calculateNextStation` tells the caller:
  - either the flow is complete, or
  - a manual decision is required (e.g., station inactive).
- The caller (service/controller) must decide whether to:
  - mark session as `completed`, or
  - show an error and block transfer, or
  - prompt supervisor for override.

---

### 3. Multi‑Track Routing Example

From Section 5.3:

```text
PROGRAM: "Cash Assistance Distribution"

TRACK A (Regular):
  Step 1: Triage (Screening)
  Step 2: Documentation Review
  Step 3: Interview
  Step 4: Cashier

TRACK B (PWD/Senior Priority):
  Step 1: Triage (Express)
  Step 2: Interview
  Step 3: Cashier

TRACK C (Incomplete Docs):
  Step 1: Triage
  Step 2: Legal Assistance
  Step 3: Documentation Review
  Step 4: Interview
  Step 5: Cashier
```

Example scenario:

- Client arrives; triage scans token and selects “PWD”.
- System binds to **Track B**.
- At **Step 1 (Triage)**, standard next step is **Interview**.
- Staff may either:
  - follow standard routing to Interview; or
  - apply an override (e.g., send to Legal first), which is logged.

---

### 4. Edge Behaviors that Affect Flow

The flow engine is also responsible for enforcing certain constraints and patterns described in Section 8.

#### 4.1 No‑Show Handling (“Ghost Client”)

Each repeated “call next” for the same alias increments `no_show_attempts`:

- Attempt 1: regular chime, informational message.
- Attempt 2: urgent chime, stronger wording.
- Attempt ≥ 3: UI prompts staff:
  - **Mark No‑Show** → set session to `no_show`, unbind token, remove from queue, log action.
  - **Keep Waiting** → do nothing and keep session active.

Key rules:

- Marking a no‑show must:
  - set `Session.status = 'no_show'`.
  - set `Token.status = 'available'` and clear `current_session_id`.
  - append a `TransactionLog` with `action_type = 'no_show'`.

#### 4.2 Double Scan Protection

At triage, scanning a token with `Token.status = 'in_use'`:

- Looks up the current `Session`.
- Shows a modal with:
  - Alias, station, status, started time.
- Offers:
  - **View Details**, or
  - **Force End Session** (supervisor only).

Force end must:

- Require supervisor PIN.
- Log `action_type = 'force_complete'` (or `override` with reason).
- Unbind the token so a fresh session can be created.

#### 4.3 Sequence Enforcement (“Process Skipper”)

When a station scans a token:

- Compute the **expected next step** from `current_step_order + 1`.
- Compare `expected_step.station_id` vs. the scanning station.
- If mismatch:
  - Show a red “Invalid Sequence” screen.
  - Offer:
    - **Send Back to Expected Station**, or
    - **Supervisor Override** with PIN + required reason.
- If valid:
  - Proceed with normal servicing.

The flow engine must therefore expose:

- `getExpectedStep(session, current_step_order)` helper.
- A clear contract for what happens when a scan is **not** at the expected station.

---

### 5. High‑Level Service Operations

At the application‑services layer, the main operations around flow are:

- `bindToken(token, program, track, category)`
  - Create a new `Session` with `status = 'waiting'`.
  - Set `Token.status = 'in_use'` and `current_session_id`.
  - Log `bind`.

- `callNext(station)`
  - Select the next waiting session in the station’s queue.
  - Set it to `serving`.
  - Increment `no_show_attempts` only when *re‑calling* the same session.
  - Log `check_in` or a “call” event as needed.

- `transfer(session, targetStation = null)`
  - If `targetStation` is null, call `calculateNextStation(session.id)`.
  - Update `Session.current_station_id` and `current_step_order`.
  - Set `status = 'waiting'` at the next station.
  - Log `transfer` with `previous_station_id` and `next_station_id`.

- `overrideRoute(session, targetStation, supervisor, reason)`
  - Require supervisor authorization.
  - Set custom next station / update `current_step_order` as appropriate.
  - Log `override` with `remarks = reason` and metadata linking supervisor.

- `complete(session)`
  - Validate that all required steps have been completed.
  - Set `Session.status = 'completed'` and `completed_at`.
  - Set token back to `status = 'available'`, clear `current_session_id`.
  - Log `complete`.

- `markNoShow(session)`
  - As described in 4.1.

Each of these must:

- Be **idempotent** where possible (especially for offline retries).
- Emit appropriate WebSocket events so UIs and displays stay in sync.

---

### 6. Idempotency and Offline Considerations

Relevant to the “WiFi Blackout” scenario:

- Frontends may queue actions locally (e.g., in IndexedDB) when offline.
- On reconnect, they replay pending actions with unique request IDs.
- Backend must:
  - Accept slightly stale timestamps.
  - Use request IDs / natural keys to avoid double‑processing transfers or completions.

This means the flow engine services should be designed to:

- Treat duplicate `transfer` or `complete` requests for the same session + request ID as **no‑ops**.
- Provide clear error states when a replayed action is no longer valid (e.g., session already completed).

---

For HTTP endpoints calling into this flow engine, see `06-api-and-realtime.md`. For UI behavior around edge cases, see `08-edge-cases.md`.

