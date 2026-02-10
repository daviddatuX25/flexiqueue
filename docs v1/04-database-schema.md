## Database Schema

**Purpose:** Provide the concrete MariaDB schema—tables, columns, constraints, and indexes—for implementing the conceptual domain model.

Related docs: `03-domain-model.md`, `05-flow-engine.md`

---

### 1. Core Tables

#### 1.1 `programs`

Represents a government assistance event.

**Columns (summary)**

- `id` BIGINT PRIMARY KEY
- `name` VARCHAR(100)
- `description` TEXT
- `is_active` BOOLEAN DEFAULT FALSE
- `created_at` TIMESTAMP
- `created_by` BIGINT FK → `users.id`

Key points:

- Enforce **only one** active program at a time at the application level or via a partial unique index if supported.

---

#### 1.2 `service_tracks`

Demographic‑specific pathways for a program.

**Columns**

- `id` BIGINT PRIMARY KEY
- `program_id` BIGINT NOT NULL FK → `programs.id`
- `name` VARCHAR(50)
- `description` TEXT
- `is_default` BOOLEAN DEFAULT FALSE
- `color_code` VARCHAR(7)

Constraints:

- (`program_id`, `name`) should be unique.
- Each program must have exactly one `is_default = TRUE` track (enforced in application logic).

---

#### 1.3 `track_steps`

Ordered sequence of stations for each track.

**Columns**

- `id` BIGINT PRIMARY KEY
- `track_id` BIGINT NOT NULL FK → `service_tracks.id`
- `station_id` BIGINT NOT NULL FK → `stations.id`
- `step_order` INT NOT NULL
- `is_required` BOOLEAN DEFAULT TRUE
- `estimated_minutes` INT

Constraints:

- Unique (`track_id`, `step_order`) to prevent duplicates and enforce ordering.
- Application‑level rule to prevent gaps in `step_order`.

---

#### 1.4 `stations`

Logical service points mapped to physical tables/desks.

**Columns**

- `id` BIGINT PRIMARY KEY
- `program_id` BIGINT NOT NULL FK → `programs.id`
- `name` VARCHAR(50)
- `role_type` ENUM('triage','processing','release')
- `capacity` INT
- `is_active` BOOLEAN DEFAULT TRUE

Constraints:

- Unique (`program_id`, `name`) to avoid duplicates per program.

---

#### 1.5 `hardware_units`

Registered hardware devices (ESP32 units or phones).

**Columns**

- `id` BIGINT PRIMARY KEY
- `station_id` BIGINT NULL FK → `stations.id`
- `mac_address` VARCHAR(17) UNIQUE
- `device_type` ENUM('esp32_combo','esp32_display','esp32_button','esp32_speaker','mobile_phone')
- `capabilities` JSON
- `status` ENUM('online','offline','disabled')
- `last_heartbeat` TIMESTAMP

Notes:

- `station_id` NULL ⇒ unassigned spare device.
- `capabilities` uses the JSON schema described in `03-domain-model.md`.

---

#### 1.6 `tokens`

Physical QR cards used by clients.

**Columns**

- `id` BIGINT PRIMARY KEY
- `qr_code_hash` VARCHAR(64) UNIQUE
- `physical_id` VARCHAR(10)
- `status` ENUM('available','in_use','lost','damaged')
- `current_session_id` BIGINT NULL FK → `sessions.id`

Constraints:

- When `status = 'in_use'`, `current_session_id` must be NOT NULL (enforced in application logic).

---

#### 1.7 `sessions`

Client journeys through a program.

**Columns**

- `id` BIGINT PRIMARY KEY
- `token_id` BIGINT NOT NULL FK → `tokens.id`
- `program_id` BIGINT NOT NULL FK → `programs.id`
- `track_id` BIGINT NOT NULL FK → `service_tracks.id`
- `alias` VARCHAR(10) NOT NULL
- `client_category` VARCHAR(50)
- `current_station_id` BIGINT NULL FK → `stations.id`
- `current_step_order` INT
- `status` ENUM('waiting','serving','completed','cancelled','no_show')
- `started_at` TIMESTAMP
- `completed_at` TIMESTAMP NULL
- `no_show_attempts` INT DEFAULT 0

Key semantics:

- `alias` corresponds to the token’s physical label (e.g., “A1”) and must be unique among **active** sessions.
- `current_step_order` should mirror the `track_steps.step_order` the session is currently at.

---

#### 1.8 `transaction_logs`

Immutable audit trail of state changes.

**Columns**

- `id` BIGINT PRIMARY KEY
- `session_id` BIGINT NOT NULL FK → `sessions.id`
- `station_id` BIGINT NOT NULL FK → `stations.id`
- `staff_user_id` BIGINT NOT NULL FK → `users.id`
- `action_type` ENUM('bind','check_in','transfer','override','complete','cancel','no_show')
- `previous_station_id` BIGINT NULL FK → `stations.id`
- `next_station_id` BIGINT NULL FK → `stations.id`
- `remarks` TEXT NULL
- `metadata` JSON
- `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP

**Metadata JSON Example**

```json
{
  "override_reason": "Missing documents",
  "hardware_triggered": true,
  "device_mac": "AA:BB:CC:DD:EE:FF"
}
```

Rules:

- Table is **append‑only**; no UPDATE/DELETE in application code.
- For `action_type = 'override'`, `remarks` must be non‑null (enforced in services).

---

#### 1.9 `users`

Staff accounts.

**Columns**

- `id` BIGINT PRIMARY KEY
- `name` VARCHAR(100)
- `email` VARCHAR(100) UNIQUE
- `password_hash` VARCHAR(255)
- `role` ENUM('admin','supervisor','staff')
- `assigned_station_id` BIGINT NULL FK → `stations.id`
- `is_active` BOOLEAN DEFAULT TRUE
- `created_at` TIMESTAMP

---

#### 1.10 `device_events`

Low‑level logs from hardware units.

**Columns**

- `id` BIGINT PRIMARY KEY
- `hardware_unit_id` BIGINT NOT NULL FK → `hardware_units.id`
- `event_type` VARCHAR(50)
- `payload` JSON
- `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP

---

### 2. JSON Columns

#### 2.1 `hardware_units.capabilities`

Represents what a unit can do:

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

Usage:

- Aggregated per station to determine what UI/UX features are possible.
- Drives warnings when required capabilities are missing (post‑MVP).

#### 2.2 `transaction_logs.metadata`

Flexible context for each log entry:

- `override_reason` – human‑readable reason for supervisor overrides.
- `hardware_triggered` – whether an action came from a hardware signal.
- `device_mac` – originating device MAC address.

---

### 3. Key Constraints and Business Rules in Schema Form

High‑level rules to implement via migrations + application logic:

- Only **one** program with `is_active = TRUE` at any time.
- Only **one** default track (`is_default = TRUE`) per program.
- Unique (`track_id`, `step_order`) in `track_steps`.
- `tokens.status = 'in_use'` ⇒ `current_session_id IS NOT NULL`.
- `sessions.alias` unique among **active** sessions (waiting/serving).
- `transaction_logs` is append‑only.

---

### 4. Indexing Strategy

Recommended indexes (adapted from architecture Section 14):

- `sessions`
  - `INDEX idx_sessions_active ON sessions(status, current_station_id)`
  - Optional partial unique index on `alias` for active statuses:
    - `UNIQUE INDEX idx_alias_active ON sessions(alias) WHERE status IN ('waiting','serving')`
- `tokens`
  - `INDEX idx_tokens_hash ON tokens(qr_code_hash)`
- `hardware_units`
  - `INDEX idx_hardware_mac ON hardware_units(mac_address)`
- `transaction_logs`
  - `INDEX idx_logs_session ON transaction_logs(session_id, timestamp)`

These support common operations:

- Looking up a session by alias or active station.
- Looking up a token by QR hash at triage.
- Tracing transactions for a session in chronological order.
- Managing hardware units and detecting rogue devices by MAC address.

---

### 5. Sample Data Flow (SQL Walkthrough)

Illustrative example from architecture Section 14 showing a typical lifecycle:

1. **Create a program**

```sql
INSERT INTO programs (name, is_active)
VALUES ('Cash Assistance', TRUE);
```

2. **Create tracks**

```sql
INSERT INTO service_tracks (program_id, name, is_default)
VALUES (1, 'Regular', TRUE),
       (1, 'Priority', FALSE);
```

3. **Define steps**

```sql
INSERT INTO track_steps (track_id, station_id, step_order)
VALUES (1, 1, 1),  -- Triage
       (1, 2, 2),  -- Interview
       (1, 3, 3);  -- Cashier
```

4. **Bind a token**

```sql
UPDATE tokens
SET status = 'in_use', current_session_id = 101
WHERE id = 1;

INSERT INTO sessions (id, token_id, track_id, alias, status)
VALUES (101, 1, 1, 'A1', 'waiting');
```

5. **Transfer to next station**

```sql
UPDATE sessions
SET current_station_id = 2,
    current_step_order = 2
WHERE id = 101;

INSERT INTO transaction_logs (session_id, action_type, next_station_id)
VALUES (101, 'transfer', 2);
```

6. **Complete the session**

```sql
UPDATE sessions
SET status = 'completed',
    completed_at = NOW()
WHERE id = 101;

UPDATE tokens
SET status = 'available',
    current_session_id = NULL
WHERE id = 1;
```

This flow should be mirrored in application services and tests to ensure the schema and business logic stay aligned.

---

For higher‑level business semantics, see `03-domain-model.md`. For how sessions move through steps and stations, see `05-flow-engine.md`.

