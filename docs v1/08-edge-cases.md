## Edge Cases and Recovery Patterns

**Purpose:** Capture the critical “what can go wrong” scenarios, how they are detected, and how the system should respond—both automatically and with human overrides.

Related docs: `05-flow-engine.md`, `06-api-and-realtime.md`, `07-ui-ux-specs.md`

---

### 1. Edge Case Matrix (Summary)

From architecture Section 8.7:

| Edge Case        | Detection Method        | Automated Response                      | Manual Override / Option          |
|------------------|------------------------|-----------------------------------------|-----------------------------------|
| Ghost Client     | No‑show counter        | Auto no‑show after 3 attempts           | Staff can keep waiting            |
| Double Scan      | Token status check     | Block with alert                        | Supervisor force‑end              |
| WiFi Blackout    | Browser offline event  | Queue actions in IndexedDB              | Manual calling fallback           |
| Rogue Hardware   | Backend rate limiting  | Auto‑disable device                     | Admin can re‑enable               |
| Process Skipper  | Step validation        | Block with red “Invalid Sequence”       | Supervisor PIN + reason           |
| Token Swap Fraud | Visual ID verification | Alert staff to verify ID / mismatch log | Send back to triage or cancel     |

The sections below provide implementation‑oriented detail for each scenario.

---

### 2. Ghost Client (No‑Show Handling)

**Scenario:** A client repeatedly fails to respond when called. Staff keep pressing “Call Next” and the queue stalls.

**Detection:**

- `Session.no_show_attempts` integer field.
- Incremented each time the station attempts to call the same alias without successful check‑in.

**Behavior:**

1. On first call:
   - Display: “Calling A1… (Attempt 1/3)”.
   - Play normal chime (if audio).
2. On second call:
   - Display: “Calling A1 AGAIN… (Attempt 2/3)”.
   - Play a more urgent sound.
3. On third and later calls:
   - Show modal:
     - “Client Not Responding”.
     - Buttons: **Mark No‑Show** / **Keep Waiting**.

If staff select **Mark No‑Show**:

- Set `Session.status = 'no_show'`.
- Log `TransactionLog` with `action_type = 'no_show'`.
- Unbind token:
  - `Token.status = 'available'`.
  - `Token.current_session_id = NULL`.
- Remove from station queue and broadcast queue updates (`global.queue`).

**Why it matters:**

- Prevents queue from being blocked by absent clients.
- Creates a clear, auditable trail of no‑shows.

---

### 3. Double Scan (Token Already Active)

**Scenario:** At triage, staff accidentally scan a token that is already in use by an active session.

**Detection:**

- On `bind` attempt:
  - Look up `Token` by `qr_code_hash`.
  - Check `Token.status`.

**Behavior:**

- If `status = 'available'`:
  - Proceed with normal bind flow.
- If `status = 'in_use'`:
  - Find current `Session`.
  - Show modal with:
    - Alias (e.g., “A1”).
    - Station (e.g., “Table 2 – Interview”).
    - Current status (`waiting` / `serving`).
    - Started time.
  - Options:
    - **View Details** – navigate to session details.
    - **Force End Session** – **supervisor only**.
- If `status = 'lost'` or `status = 'damaged'`:
  - Show error:
    - “Token marked as LOST/DAMAGED. Please use a different token.”
  - Block binding.

**Force End Logic:**

- Requires supervisor PIN / higher role.
- Logs transaction (e.g., `action_type = 'force_complete'` with reason).
- Unbinds token so it can be reused.

**Why it matters:**

- Prevents duplicate active aliases (e.g., two “A1” sessions).
- Provides a controlled escape hatch for messy real‑world scenarios.

---

### 4. WiFi Blackout (Offline‑First Operation)

**Scenario:** Wi‑Fi or router loses power for a couple of minutes during operations; staff still need to work.

**Detection:**

- Browser’s `offline`/`online` events.
- Failed network requests in the frontend.

**Frontend Strategy:**

1. **Detect Offline**
   - On `window.offline`, show banner:
     - “⚠️ Offline Mode – Actions will sync when connection returns”.

2. **Queue Actions Locally**
   - Wrap critical mutations (e.g., `transfer`, `complete`, `no_show`) in a function that:
     - Attempts HTTP call.
     - On network failure, stores action in IndexedDB / local queue with:
       - `type` (e.g., `transfer`).
       - `session_id`.
       - `payload` (e.g., target station).
       - `timestamp`.
       - `request_id` (UUID for idempotency).

3. **Replay on Reconnect**
   - On `window.online`, iterate pending actions and attempt to replay.
   - On success, delete from local queue; on failure, log error and optionally show a notification.

**Backend Strategy:**

- Accept actions with **request IDs** to support idempotent operations.
- Treat duplicates (same request ID) as no‑ops.
- Allow a small “clock skew” window when evaluating timestamps.

**Graceful Degradation:**

- Informant displays freeze at last known state.
- Staff can still call out aliases manually.
- When network recovers, system replays queued actions and re‑synchronizes state.

---

### 5. Rogue Hardware (Flooding “Next” Events)

**Scenario:** A misbehaving ESP32 button (or other hardware) spams “Next” commands, skipping multiple clients.

**Detection:**

- Backend rate limiting based on device MAC address and action type.

**Behavior:**

- Maintain a small time‑windowed counter:
  - Key: `device:{mac}:call_next`.
  - If `call_next` attempts exceed a threshold within a short window (e.g., 3 in 10 seconds):
    - Mark device as **rogue**.
    - Log a security/ops alert.
    - Update `HardwareUnit.status = 'disabled'`.
    - Notify admins via WebSocket (e.g., admin dashboard alert).
    - Return HTTP 429 or error response to further requests.

**Admin UI:**

- Admin dashboard shows:
  - “⚠️ Device AA:BB:CC:DD:EE:FF disabled (flooding)”.
  - Buttons: **View Logs**, **Re‑enable**, **Replace Hardware**.

**Recovery:**

- After physical inspection, admin can set `status` back to `online`.
- Station can fall back to software “Next” button (see `07-ui-ux-specs.md`) while hardware is disabled.

---

### 6. Process Skipper (Out‑of‑Order Station Scan)

**Scenario:** A client tries to be served at a later station (e.g., Cashier) without completing earlier required steps (e.g., Interview).

**Detection:**

- On token scan at station:
  - Fetch session and its `current_step_order`.
  - Determine expected next step from `TrackStep` table.
  - Compare expected station ID to current station ID.

**Behavior:**

- If station ≠ expected station and the missing step is **required**:
  - Show a full‑screen red “Invalid Sequence” screen:
    - “🚫 INVALID SEQUENCE”.
    - Current progress: “Current step: 2/4 (Interview)”.
    - Expected station: “Table 2 (Interview)”.
    - This station: “Table 4 (Cashier)”.
    - List of missing required step(s) with ✗ mark.
  - Options:
    - **Send Back to Expected Station** – prints or shows the station name prominently.
    - **Supervisor Override** – requires PIN + reason.
- If station = expected station or missing step is not required:
  - Proceed normally.

**Override Path:**

- On supervisor override:
  - Show a form with:
    - Reason (required).
  - Log transaction with `action_type = 'override'`, remarks, supervisor ID.
  - Allow processing at this station.

**Audit Benefit:**

- Every violation is traceable, including who allowed it and why.

---

### 7. Token Swap Fraud (Priority Abuse)

**Scenario:** Two clients swap priority tokens so a non‑eligible person gets priority treatment.

**Detection:**

- The system itself cannot fully detect identity mismatch, but can **enforce a verification step** for priority categories.

**Behavior:**

- When serving a session with a priority category (e.g., PWD/Senior/Pregnant):
  - Show a prominent “Verify Identity” component:
    - Header: “👤 VERIFY IDENTITY”.
    - Alias and category (e.g., “Alias: A1”, “Category: ⭐ PWD/SENIOR”).
    - Reminder: “Priority clients: request valid ID.”
  - Buttons:
    - **✓ ID Verified**.
    - **❌ Mismatch**.

- If staff click **Mismatch**:
  - Show a text area: “Describe issue”.
  - Options:
    - **Send Back to Triage** – re‑route the session.
    - **Cancel Session** – mark as cancelled with remarks.
  - Log `TransactionLog` with a specific action (e.g., `identity_mismatch`) and remarks.

**Why it matters:**

- Encourages staff vigilance.
- Captures data for investigations and process improvement.

---

### 8. How to Use This Doc

- When implementing controllers/services:
  - Map each scenario to explicit branches in your code and tests.
  - Ensure all relevant `TransactionLog` entries are written.
- When building UI:
  - Use this as a guide for modals, banners, and warnings.
  - Coordinate with `05-flow-engine.md` so state transitions and edge cases stay consistent.

