## Domain Model

**Purpose:** Define the core business entities, their attributes, rules, and relationships—independent of database implementation.

Related docs: `02-architecture-overview.md`, `04-database-schema.md`, `05-flow-engine.md`

---

### 1. High‑Level Entity Hierarchy

Conceptual view from the architecture’s Section 4.1:

```text
PROGRAM (The Event)
  │
  ├── SERVICE_TRACK (Demographic Lane)
  │     │
  │     └── TRACK_STEP (Ordered Sequence)
  │           └── references STATION
  │
  └── STATION (Service Point)
        │
        └── HARDWARE_UNIT (Physical Device)
              └── has CAPABILITIES (JSON)

TOKEN (Physical QR Card)
  │
  └── SESSION (Active Client Journey)
        │
        ├── belongs to TRACK
        ├── currently at STATION
        │
        └── TRANSACTION_LOG (Audit Trail)
              └── performed by STAFF
```

Supporting entities:

- **Users** – staff accounts and roles.
- **DeviceEvents** – logs for hardware‑level events.

---

### 2. Core Entities

#### 2.1 Program

**Purpose:** Represents a specific government assistance event.

**Key Attributes**

- `id` – primary key.
- `name` – e.g., “Social Pension Distribution”.
- `description`.
- `is_active` – indicates the currently running program.
- `created_at`.
- `created_by` – FK → `users.id`.

**Business Rules**

- Only **one** program can have `is_active = true` at a time.
- A program must have at least **one** `ServiceTrack`.
- A program with active sessions **cannot** be deleted.

**Relationships**

- Has many `ServiceTrack`.
- Has many `Station`.
- Has many `Session`.

---

#### 2.2 ServiceTrack

**Purpose:** Defines a demographic‑specific pathway (lane) through a program.

**Key Attributes**

- `id`.
- `program_id` – FK → `Program`.
- `name` – e.g., “Priority Lane (PWD/Senior)”.
- `description`.
- `is_default` – marks the default track for a program.
- `color_code` – UI differentiation.

**Business Rules**

- Each program must have **exactly one** `is_default = true` track.
- Track `name` must be unique **within a program**.
- A track in use by active sessions **cannot** be deleted.

**Relationships**

- Belongs to `Program`.
- Has many ordered `TrackStep`.
- Has many `Session`.

---

#### 2.3 TrackStep

**Purpose:** Represents an ordered step in a track’s sequence.

**Key Attributes**

- `id`.
- `track_id` – FK → `ServiceTrack`.
- `station_id` – FK → `Station`.
- `step_order` – 1, 2, 3, … within a track.
- `is_required` – whether the step is mandatory.
- `estimated_duration_minutes` – for queue estimates.

**Business Rules**

- `step_order` must be **unique within a track**.
- There must be no gaps in `step_order` (1,2,4 is invalid).
- A `Station` may appear in multiple tracks.

**Relationships**

- Belongs to `ServiceTrack`.
- References one `Station`.

---

#### 2.4 Station

**Purpose:** Logical service point (formerly “table”) where staff serve clients.

**Key Attributes**

- `id`.
- `program_id` – FK → `Program`.
- `name` – e.g., “Verification Desk”.
- `role_type` – one of `triage`, `processing`, `release`.
- `capacity` – how many staff can operate simultaneously.
- `is_active`.

**Business Rules**

- `name` must be unique **within a program**.
- A station must have at least:
  - one associated `HardwareUnit`, **or**
  - a staff phone assigned (logical capability).
- A station referenced by `TrackStep` **cannot** be deleted.

**Relationships**

- Belongs to `Program`.
- Has many `HardwareUnit`.
- Has many `Session` (via `current_station_id`).
- Is referenced by `TrackStep` and `TransactionLog`.

---

#### 2.5 HardwareUnit

**Purpose:** Represents a physical device (ESP32 or phone) attached to a station.

**Key Attributes**

- `id`.
- `station_id` – FK → `Station` (nullable for unassigned devices).
- `mac_address` – globally unique identifier.
- `device_type` – enum:
  - `esp32_combo`, `esp32_display`, `esp32_button`, `esp32_speaker`, `mobile_phone`.
- `capabilities` – JSON, capability flags (see below).
- `status` – enum: `online`, `offline`, `disabled`.
- `last_heartbeat` – timestamp of last health ping.

**Business Rules**

- `mac_address` is globally unique and immutable.
- A device can be assigned to **only one** station at a time.
- `status` is auto‑derived from heartbeats (e.g., > 60s = `offline`).

**Capability Schema (Conceptual)**

```json
{
  "has_display": true,
  "display_orientation": "landscape",
  "has_buttons": true,
  "button_count": 4,
  "has_speaker": false,
  "has_microphone": false,
  "has_scanner": true
}
```

**Relationships**

- Belongs to `Station` (nullable).
- Has many `DeviceEvent`.

> MVP Note: phones are modeled as `mobile_phone` units to keep the capability model consistent, even if hardware orchestration is implemented later.

---

#### 2.6 Token

**Purpose:** Reusable physical QR card that clients carry.

**Key Attributes**

- `id`.
- `qr_code_hash` – SHA‑256 or similar hash of the QR contents; globally unique.
- `physical_id` – human‑readable label printed on the card (e.g., “A1”).
- `status` – `available`, `in_use`, `lost`, `damaged`.
- `current_session_id` – FK → `Session` (nullable).

**Business Rules**

- `qr_code_hash` is immutable after creation.
- A token can have **at most one** active session (`status = in_use`).
- Any change to `status` must be logged via `TransactionLog`.

**Relationships**

- Has one current `Session` (nullable).
- Has many historical `Session` records.

---

#### 2.7 Session

**Purpose:** Represents a single client’s journey through a program, bound to one token.

**Key Attributes**

- `id`.
- `token_id` – FK → `Token`.
- `program_id` – FK → `Program`.
- `track_id` – FK → `ServiceTrack`.
- `alias` – display alias, usually derived from `Token.physical_id` (e.g., “A1”).
- `client_category` – e.g., “PWD”, “Senior”, “Pregnant”.
- `current_station_id` – FK → `Station` (nullable when completed).
- `current_step_order` – matches `TrackStep.step_order`.
- `status` – `waiting`, `serving`, `completed`, `cancelled`, `no_show`.
- `started_at`, `completed_at` (nullable).
- `no_show_attempts` – count of repeated calls without response.

**Business Rules**

- `alias` must be unique **among active sessions** (waiting/serving).
- `current_step_order` must be consistent with the track’s steps.
- Status transitions must be **loggable and auditable**.
- A session cannot be marked `completed` while there are required future steps remaining.

**Relationships**

- Belongs to `Token`, `Program`, `ServiceTrack`.
- Belongs to current `Station` (nullable).
- Has many `TransactionLog`.

---

#### 2.8 TransactionLog

**Purpose:** Immutable audit trail of every important state change in a session.

**Key Attributes**

- `id`.
- `session_id` – FK → `Session`.
- `station_id` – FK → `Station` (context station).
- `staff_user_id` – FK → `User`.
- `action_type` – enum:
  - `bind`, `check_in`, `transfer`, `override`, `complete`, `cancel`, `no_show`, plus possible extensions like `identity_mismatch`.
- `previous_station_id` – FK → `Station` (nullable).
- `next_station_id` – FK → `Station` (nullable).
- `remarks` – required for certain actions (e.g., overrides).
- `metadata` – JSON with structured context (e.g., override reason, hardware info).
- `timestamp` – set at insertion time.

**Business Rules**

- Append‑only: **no updates or deletes** allowed.
- `action_type = override` and similar critical actions **require** remarks.
- Logs are generated by the application/services layer (middleware/services), not manually edited by staff.

**Metadata Examples**

```json
{
  "override_reason": "Missing documents - return to verification",
  "hardware_triggered": true,
  "device_mac": "AA:BB:CC:DD:EE:FF"
}
```

**Relationships**

- Belongs to `Session`.
- References `Station` (current / previous / next).
- References `User` (staff performing the action).

---

### 3. Supporting Entities

#### 3.1 User (Staff)

**Purpose:** Represents staff accounts and their roles within the system.

**Key Attributes**

- `id`.
- `name`.
- `email`.
- `password_hash`.
- `role` – `admin`, `supervisor`, `staff`.
- `assigned_station_id` – FK → `Station` (nullable).
- `is_active`.

**Relationships**

- Has many `TransactionLog` entries (as `staff_user_id`).
- Optionally associated to one `Station` as the current assignment.

---

#### 3.2 DeviceEvent

**Purpose:** Stores low‑level logs from `HardwareUnit` devices.

**Key Attributes**

- `id`.
- `hardware_unit_id` – FK → `HardwareUnit`.
- `event_type` – e.g., `heartbeat`, `button_press`, `display_error`.
- `payload` – JSON with event‑specific data.
- `timestamp`.

**Relationships**

- Belongs to `HardwareUnit`.

---

### 4. Relationship Summary (Conceptual)

Key cardinalities:

- `Program (1) → (M) ServiceTrack`
- `Program (1) → (M) Station`
- `Program (1) → (M) Session`
- `ServiceTrack (1) → (M) TrackStep`
- `ServiceTrack (1) → (M) Session`
- `TrackStep (M) → (1) Station`
- `Station (1) → (M) HardwareUnit`
- `Station (1) → (M) Session` (via `current_station_id`)
- `Token (1) → (1) Session` (current, optional)  
  `Token (1) → (M) Session` (historical)
- `Session (1) → (M) TransactionLog`
- `User (1) → (M) TransactionLog`
- `HardwareUnit (1) → (M) DeviceEvent`

For concrete column types, foreign keys, and indexes, see `04-database-schema.md`.

