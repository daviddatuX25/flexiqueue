## API and Realtime Contracts

**Purpose:** Specify HTTP endpoints and WebSocket channels so frontend and backend can integrate against a clear, stable contract.

Related docs: `05-flow-engine.md`, `07-ui-ux-specs.md`

---

### 1. Overview

FlexiQueue exposes:

- A small set of **public** HTTP endpoints for informant displays.
- **Authenticated staff** endpoints for triage and station operations.
- **Admin‑level** endpoints for configuration and reporting.
- WebSocket channels for **realtime updates** to staff interfaces, informant displays, and (post‑MVP) hardware devices.

---

### 2. HTTP API

#### 2.1 Public Endpoints (No Auth)

For kiosks and self‑service status checks.

- `GET /api/check-status/{qr_hash}`
  - **Purpose:** Allow a client to scan a token and see current status and next steps.
  - **Path params:** `qr_hash` – hash stored in `tokens.qr_code_hash`.
  - **Response (example):**
    ```json
    {
      "alias": "A1",
      "track": "Priority",
      "status": "waiting",
      "current_station": "Interview",
      "progress": {
        "total_steps": 3,
        "current_step": 2,
        "steps": [
          {"name": "Triage", "status": "complete"},
          {"name": "Interview", "status": "in_progress"},
          {"name": "Cashier", "status": "pending"}
        ]
      },
      "estimated_wait_minutes": 5
    }
    ```

---

#### 2.2 Staff Endpoints (Session Auth)

Used by triage and station UIs. Authentication is assumed (e.g., Laravel session or token) and role checks are enforced at middleware/policy level.

- `POST /api/sessions/bind`
  - **Purpose:** Triage binds a token to a new session.
  - **Body (example):**
    ```json
    {
      "qr_hash": "…",
      "program_id": 1,
      "track_id": 2,
      "client_category": "PWD"
    }
    ```
  - **Behavior:**
    - Validates token availability.
    - Creates `Session` in `waiting` state.
    - Updates `Token` to `in_use`.
    - Appends `TransactionLog` with `action_type = "bind"`.

- `POST /api/sessions/{id}/transfer`
  - **Purpose:** Move a session to the next station in the standard path, or to a specific target.
  - **Body (standard path):**
    ```json
    {
      "mode": "standard"
    }
    ```
  - **Body (explicit target):**
    ```json
    {
      "mode": "custom",
      "target_station_id": 4
    }
    ```

- `POST /api/sessions/{id}/override`
  - **Purpose:** Supervisor‑approved deviation from the standard flow.
  - **Body:**
    ```json
    {
      "target_station_id": 3,
      "reason": "Needs legal assistance before documentation",
      "supervisor_pin": "123456"
    }
    ```
  - **Behavior:**
    - Checks supervisor credentials/PIN.
    - Logs `TransactionLog` with `action_type = "override"` and `remarks = reason`.

- `POST /api/sessions/{id}/complete`
  - **Purpose:** Mark session as completed at the final station.
  - **Behavior:**
    - Validates that all required steps are done.
    - Sets `Session.status = "completed"` and `completed_at`.
    - Frees the token (`Token.status = "available"`, `current_session_id = NULL`).

- `POST /api/sessions/{id}/no-show`
  - **Purpose:** Mark a client as no‑show after repeated calls.
  - **Behavior:**
    - Sets `Session.status = "no_show"`.
    - Unbinds token.
    - Logs `TransactionLog` with `action_type = "no_show"`.

- `GET /api/stations/{id}/queue`
  - **Purpose:** Fetch current queue for a given station.
  - **Response (example):**
    ```json
    {
      "station_id": 2,
      "now_serving": {
        "session_id": 101,
        "alias": "A1",
        "track": "Priority",
        "status": "serving",
        "current_step_order": 2
      },
      "waiting": [
        {
          "session_id": 102,
          "alias": "B3",
          "track": "Regular",
          "status": "waiting",
          "queued_at": "2026-02-10T10:35:00Z"
        }
      ]
    }
    ```

---

#### 2.3 Admin Endpoints (Admin Role)

Used by the admin dashboard for configuring flows and managing hardware.

- `POST /api/programs`
  - Creates a new program (optionally deactivates others).

- `POST /api/service-tracks`
  - Defines a new track for a program.

- `POST /api/track-steps`
  - Adds steps to a given track in order.

- `POST /api/hardware-units`
  - Registers a new hardware device with capabilities.

- `PUT /api/hardware-units/{id}/assign`
  - Assigns a hardware unit to a station or unassigns it.

- `GET /api/reports/audit`
  - Generates COA‑oriented export of `TransactionLog` for a given program/date range.

Implementation detail (not contract): these endpoints can be grouped under Laravel route groups with appropriate middleware (`auth`, `role:admin`).

---

### 3. WebSocket Channels

#### 3.1 `station.{id}`

- **Purpose:** Private channel for devices operating at a specific station.
- **Subscribers:** Staff UIs (and optionally hardware) assigned to that station.
- **Typical Messages:**
  - `client_arrived`
    ```json
    {
      "type": "client_arrived",
      "session_id": 101,
      "alias": "A1",
      "category": "PWD"
    }
    ```
  - `status_update`
    ```json
    {
      "type": "status_update",
      "session_id": 101,
      "new_status": "serving"
    }
    ```
  - `override_alert`
    ```json
    {
      "type": "override_alert",
      "session_id": 101,
      "from_station": "Triage",
      "reason": "Sent back due to missing documents"
    }
    ```

---

#### 3.2 `device.{mac}`

- **Purpose:** Direct control channel for a single hardware unit (post‑MVP).
- **Subscribers:** Exactly one device (e.g., one ESP32).
- **Typical Messages:**
  - `display_update`
    ```json
    {
      "type": "display_update",
      "text": "A1 → Table 2",
      "color": "#2563EB",
      "animation": "slide_in"
    }
    ```
  - `trigger_sound`
    ```json
    {
      "type": "trigger_sound",
      "sound_id": "chime_priority",
      "volume": 0.8
    }
    ```
  - `flash_lights`
    ```json
    {
      "type": "flash_lights",
      "pattern": "double_blink",
      "duration_ms": 2000
    }
    ```

MVP note: the channel structure is defined now so UI and backend won’t conflict with future IoT extensions, but actual hardware handling can be left for later phases.

---

#### 3.3 `global.queue`

- **Purpose:** Broadcast queue status and “now serving” information to all informant displays.
- **Subscribers:** Informant PWAs and wall displays in the waiting area.
- **Typical Messages:**
  - `now_serving`
    ```json
    {
      "type": "now_serving",
      "entries": [
        {"alias": "A1", "station": "Table 2", "label": "Interview"},
        {"alias": "B3", "station": "Table 1", "label": "Verification"}
      ]
    }
    ```
  - `queue_length`
    ```json
    {
      "type": "queue_length",
      "station_id": 2,
      "station_name": "Interview",
      "count": 3
    }
    ```
  - `system_announcement`
    ```json
    {
      "type": "system_announcement",
      "message": "Distribution will pause for 15 minutes.",
      "priority": "high"
    }
    ```

---

### 4. [Post‑MVP] Audio Streaming Architecture

> This section is **post‑MVP** and serves as a roadmap for audio features.

Goal: allow staff at a station to use their phone as a microphone and stream voice to ESP32 speakers at that station.

High‑level flow:

1. **Staff Phone (Browser)**
   - Uses WebAudio API:
     - `getUserMedia()` to capture mic input.
     - Encodes audio in short chunks (e.g., Opus).
   - Sends encoded frames over a binary WebSocket to the server.

2. **Server (Laravel + Audio Relay)**
   - Authenticates the staff device and target hardware (by MAC and station).
   - Option 1: Forwards encoded Opus directly.
   - Option 2: Decodes/encodes as needed.
   - Broadcasts frames over `device.{mac}` or a dedicated audio channel.

3. **ESP32 Speaker**
   - Receives binary chunks via WebSocket.
   - Decodes audio.
   - Outputs through I2S to an amplifier (e.g., MAX98357A).

Latency budget (approximate):

- Capture: ~20 ms
- Encode: ~10 ms
- Network (local Wi‑Fi): ~50 ms
- Decode: ~10 ms
- Playback: ~20 ms
- **Total:** ~110 ms (acceptable for announcements)

This design should be kept in mind when designing channel naming and authentication but does not need implementation for the MVP.

---

For UX around each endpoint/channel (e.g., how triage and stations use them), see `07-ui-ux-specs.md`. For edge‑case handling logic, see `08-edge-cases.md`.

