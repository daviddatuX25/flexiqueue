# FlexiQueue — Phase 1 Data Model

**Scope:** Phase 1 only. `hardware_units` and `device_events` tables are excluded entirely.
**Database:** MariaDB 10.6+
**ORM:** Laravel Eloquent

---

## Entity Hierarchy

```text
PROGRAM (The Event)
  │
  ├── SERVICE_TRACK (Demographic Lane)
  │     │
  │     └── TRACK_STEP (Ordered Sequence)
  │           └── references STATION
  │
  └── STATION (Service Point)

USER (Staff Account)
  └── assigned to STATION (nullable)

TOKEN (Physical QR Card)
  │
  └── SESSION (Active Client Journey)
        │
        ├── belongs to PROGRAM
        ├── belongs to SERVICE_TRACK
        ├── currently at STATION
        │
        └── TRANSACTION_LOG (Audit Trail)
              └── performed by USER
```

---

## Table 1: `programs`

Represents a government assistance event (e.g., "Social Pension Distribution").

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PK |
| `name` | VARCHAR(100) | NO | — | e.g., "Cash Assistance Distribution" |
| `description` | TEXT | YES | NULL | Optional details |
| `is_active` | BOOLEAN | NO | FALSE | Only ONE program can be active at a time |
| `created_by` | BIGINT UNSIGNED | NO | — | FK → `users.id` |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Laravel managed |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Laravel managed |

**Constraints:**
- Application-level: only one row with `is_active = TRUE` at any time.
- Cannot delete a program that has active sessions (`sessions.status IN ('waiting', 'serving')`).
- Must have at least one `service_track` before activation.

**Indexes:**
- `PRIMARY KEY (id)`

---

## Table 2: `service_tracks`

Defines a demographic-specific pathway (lane) through a program.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PK |
| `program_id` | BIGINT UNSIGNED | NO | — | FK → `programs.id` ON DELETE CASCADE |
| `name` | VARCHAR(50) | NO | — | e.g., "Priority Lane (PWD/Senior)" |
| `description` | TEXT | YES | NULL | |
| `is_default` | BOOLEAN | NO | FALSE | Exactly one per program must be TRUE |
| `color_code` | VARCHAR(7) | YES | NULL | Hex color for UI (e.g., "#F59E0B") |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | |

**Constraints:**
- UNIQUE (`program_id`, `name`) — no duplicate track names within a program.
- Application-level: exactly one `is_default = TRUE` per program.
- Cannot delete if referenced by active sessions.

**Indexes:**
- `PRIMARY KEY (id)`
- `UNIQUE INDEX idx_track_name_program (program_id, name)`

---

## Table 3: `track_steps`

Ordered sequence of stations for each track. This is the routing blueprint.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PK |
| `track_id` | BIGINT UNSIGNED | NO | — | FK → `service_tracks.id` ON DELETE CASCADE |
| `station_id` | BIGINT UNSIGNED | NO | — | FK → `stations.id` ON DELETE RESTRICT |
| `step_order` | INT UNSIGNED | NO | — | 1, 2, 3... within a track |
| `is_required` | BOOLEAN | NO | TRUE | Whether the step is mandatory |
| `estimated_minutes` | INT UNSIGNED | YES | NULL | For queue time estimation |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | |

**Constraints:**
- UNIQUE (`track_id`, `step_order`) — no duplicate ordering within a track.
- Application-level: `step_order` must be contiguous (1, 2, 3... — no gaps like 1, 2, 4).
- A station may appear in multiple tracks (shared service points).

**Indexes:**
- `PRIMARY KEY (id)`
- `UNIQUE INDEX idx_step_order (track_id, step_order)`

---

## Table 4: `stations`

Logical service point (desk/table) where staff serve clients.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PK |
| `program_id` | BIGINT UNSIGNED | NO | — | FK → `programs.id` ON DELETE CASCADE |
| `name` | VARCHAR(50) | NO | — | e.g., "Verification Desk", "Cashier" |
| `role_type` | ENUM('triage', 'processing', 'release') | NO | 'processing' | Station purpose classification |
| `capacity` | INT UNSIGNED | NO | 1 | Max concurrent staff operating this station |
| `is_active` | BOOLEAN | NO | TRUE | Can be deactivated without deletion |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | |

**Constraints:**
- UNIQUE (`program_id`, `name`) — no duplicate station names within a program.
- Cannot delete if referenced by `track_steps` (use RESTRICT).
- Cannot delete if sessions are actively at this station.

**Indexes:**
- `PRIMARY KEY (id)`
- `UNIQUE INDEX idx_station_name_program (program_id, name)`

---

## Table 5: `tokens`

Physical reusable QR card that clients carry during an event.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PK |
| `qr_code_hash` | VARCHAR(64) | NO | — | SHA-256 of QR code contents; immutable after creation |
| `physical_id` | VARCHAR(10) | NO | — | Human-readable label printed on card (e.g., "A1", "B15") |
| `status` | ENUM('available', 'in_use', 'lost', 'damaged') | NO | 'available' | |
| `current_session_id` | BIGINT UNSIGNED | YES | NULL | FK → `sessions.id`; set when bound |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | |

**Constraints:**
- UNIQUE (`qr_code_hash`) — globally unique hash.
- `qr_code_hash` is immutable after creation (application-enforced).
- When `status = 'in_use'`, `current_session_id` MUST be NOT NULL (application-enforced).
- When `status != 'in_use'`, `current_session_id` MUST be NULL (application-enforced).
- Only one active session per token at a time.

**Indexes:**
- `PRIMARY KEY (id)`
- `UNIQUE INDEX idx_tokens_hash (qr_code_hash)`
- `INDEX idx_tokens_physical (physical_id)`

---

## Table 6: `sessions`

A single client's journey through a program, bound to one token.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PK |
| `token_id` | BIGINT UNSIGNED | NO | — | FK → `tokens.id` |
| `program_id` | BIGINT UNSIGNED | NO | — | FK → `programs.id` |
| `track_id` | BIGINT UNSIGNED | NO | — | FK → `service_tracks.id` |
| `alias` | VARCHAR(10) | NO | — | Display name, derived from `tokens.physical_id` (e.g., "A1") |
| `client_category` | VARCHAR(50) | YES | NULL | e.g., "PWD", "Senior", "Pregnant", "Regular" |
| `current_station_id` | BIGINT UNSIGNED | YES | NULL | FK → `stations.id`; NULL when completed/cancelled |
| `current_step_order` | INT UNSIGNED | YES | NULL | Matches `track_steps.step_order` for current position |
| `status` | ENUM('waiting', 'serving', 'completed', 'cancelled', 'no_show') | NO | 'waiting' | |
| `started_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | When session was created (bind time) |
| `completed_at` | TIMESTAMP | YES | NULL | When session reached terminal state |
| `no_show_attempts` | INT UNSIGNED | NO | 0 | Incremented on repeated calls without response |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | |

**Constraints:**
- `alias` must be unique among **active** sessions (status IN `waiting`, `serving`).
- `current_step_order` must correspond to a valid `track_steps.step_order` for the session's track.
- Cannot mark `completed` if required future steps remain (application-enforced).
- All status transitions must produce a `transaction_log` entry.

**Indexes:**
- `PRIMARY KEY (id)`
- `INDEX idx_sessions_active (status, current_station_id)` — fast queue lookups per station.
- `INDEX idx_sessions_program (program_id, status)` — program-level stats.
- Conditional unique on alias for active sessions (application-enforced; MariaDB lacks partial unique indexes, so enforce in app layer).

---

## Table 7: `transaction_logs`

Immutable audit trail. Every significant state change writes exactly one row. **No UPDATE or DELETE operations permitted in application code.**

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PK |
| `session_id` | BIGINT UNSIGNED | NO | — | FK → `sessions.id` |
| `station_id` | BIGINT UNSIGNED | YES | NULL | FK → `stations.id`; station where action occurred |
| `staff_user_id` | BIGINT UNSIGNED | NO | — | FK → `users.id`; who performed the action |
| `action_type` | ENUM('bind', 'check_in', 'transfer', 'override', 'complete', 'cancel', 'no_show', 'force_complete', 'identity_mismatch') | NO | — | |
| `previous_station_id` | BIGINT UNSIGNED | YES | NULL | FK → `stations.id`; where client came from |
| `next_station_id` | BIGINT UNSIGNED | YES | NULL | FK → `stations.id`; where client is going |
| `remarks` | TEXT | YES | NULL | **Required** for: override, force_complete, identity_mismatch, cancel (recommended) |
| `metadata` | JSON | YES | NULL | Structured context (see schema below) |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Immutable creation timestamp |

**Constraints:**
- **APPEND-ONLY**: no UPDATE or DELETE allowed. Enforced in application layer (no `save()` on existing records, no `delete()` method).
- When `action_type` IN (`override`, `force_complete`, `identity_mismatch`): `remarks` MUST be NOT NULL (application-enforced).
- `staff_user_id` is always set — every action has an actor.

**Metadata JSON Schema:**
```json
{
  "override_reason": "string — human-readable reason (mirrors remarks for structured queries)",
  "supervisor_id": "int — ID of supervisor who authorized the override",
  "request_id": "string — UUID for idempotency (future offline replay)",
  "ip_address": "string — device IP for traceability"
}
```

**Indexes:**
- `PRIMARY KEY (id)`
- `INDEX idx_logs_session (session_id, created_at)` — chronological log per session.
- `INDEX idx_logs_staff (staff_user_id, created_at)` — audit per staff member.
- `INDEX idx_logs_action (action_type, created_at)` — filter by action type for reports.

---

## Table 8: `users`

Staff accounts. Laravel's default `users` table extended with role and station assignment.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PK |
| `name` | VARCHAR(100) | NO | — | Full name |
| `email` | VARCHAR(100) | NO | — | Login credential, unique |
| `password` | VARCHAR(255) | NO | — | Bcrypt hash (Laravel default field name) |
| `role` | ENUM('admin', 'supervisor', 'staff') | NO | 'staff' | |
| `override_pin` | VARCHAR(255) | YES | NULL | Bcrypt-hashed 6-digit PIN; required for supervisor/admin |
| `assigned_station_id` | BIGINT UNSIGNED | YES | NULL | FK → `stations.id`; current station assignment |
| `is_active` | BOOLEAN | NO | TRUE | Soft disable without deletion |
| `email_verified_at` | TIMESTAMP | YES | NULL | Laravel default (unused in Phase 1 but kept for compat) |
| `remember_token` | VARCHAR(100) | YES | NULL | Laravel default |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | |

**Constraints:**
- UNIQUE (`email`).
- `override_pin` is required (non-NULL) when `role` IN (`admin`, `supervisor`) — application-enforced.
- A user can be assigned to at most one station at a time.

**Indexes:**
- `PRIMARY KEY (id)`
- `UNIQUE INDEX idx_users_email (email)`

---

## Relationship Summary

```text
programs (1) ──→ (M) service_tracks     ON DELETE CASCADE
programs (1) ──→ (M) stations           ON DELETE CASCADE
programs (1) ──→ (M) sessions           ON DELETE RESTRICT

service_tracks (1) ──→ (M) track_steps  ON DELETE CASCADE
service_tracks (1) ──→ (M) sessions     ON DELETE RESTRICT

track_steps (M) ──→ (1) stations        ON DELETE RESTRICT

tokens (1) ──→ (0..1) sessions          (current, via current_session_id)
tokens (1) ──→ (M) sessions             (historical, via token_id)

sessions (1) ──→ (M) transaction_logs   ON DELETE RESTRICT

users (1) ──→ (M) transaction_logs      (as staff_user_id)
users (M) ──→ (0..1) stations           (as assigned_station_id)
```

---

## Key Business Rules (Enforced in Application Layer)

1. **One active program**: Only one `programs.is_active = TRUE` at any time. Activating a new program must deactivate the current one (with confirmation).

2. **One default track per program**: Exactly one `service_tracks.is_default = TRUE` per program. Setting a new default unsets the previous one.

3. **Contiguous step ordering**: `track_steps.step_order` within a track must be 1, 2, 3, ... with no gaps. Reordering must renumber all affected steps.

4. **Token-session binding invariant**: `tokens.status = 'in_use'` ↔ `tokens.current_session_id IS NOT NULL`. These must always be in sync.

5. **Unique active alias**: No two sessions with `status IN ('waiting', 'serving')` may share the same `alias`. Enforced on bind.

6. **Transaction logs are immutable**: No Eloquent model should ever call `update()` or `delete()` on `TransactionLog`. The model should disable these operations.

7. **Override requires remarks**: Any transaction with `action_type` IN (`override`, `force_complete`, `identity_mismatch`) must have non-empty `remarks`.

8. **Completion requires all required steps**: `Session.complete()` must verify that `current_step_order` >= max required `track_steps.step_order` for the track.

---

## Session State Machine

```text
           ┌─────────────────────────────────────┐
           │         Token: available             │
           └──────────────┬──────────────────────┘
                          │ bind()
                          ▼
           ┌─────────────────────────────────────┐
           │       Session: waiting               │
           │       (at first station in track)    │
           └──────────────┬──────────────────────┘
                          │ call_next()
                          ▼
           ┌─────────────────────────────────────┐
           │       Session: serving               │
           │       (staff processing client)      │
           └──┬────────┬────────┬────────┬───────┘
              │        │        │        │
   transfer() │  override()  complete() │ cancel()
              │        │        │        │
              ▼        ▼        ▼        ▼
          waiting   waiting  completed cancelled
         (next stn) (custom) (token     (token
                     stn)    freed)     freed)
              │
              │ (also from 'waiting' directly:)
              │   mark_no_show()
              ▼
           no_show
           (token freed)

Terminal states: completed, cancelled, no_show
  → Token.status = 'available', Token.current_session_id = NULL
```

**Cancel can occur from `waiting` or `serving` status — by any authenticated user.**
