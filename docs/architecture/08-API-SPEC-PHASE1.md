# FlexiQueue — Phase 1 API Specification

**Scope:** All HTTP endpoints required for Phase 1 MVP.
**Auth:** Laravel session-based authentication unless marked `[PUBLIC]`.
**Format:** All request/response bodies are JSON. All timestamps are ISO 8601.

---

## 1. Authentication

### 1.1 `POST /login`

**Purpose:** Staff login.
**Auth:** None (public form submission).
**Request (form):**
```json
{
  "email": "juan@mswdo.gov.ph",
  "password": "secret123"
}
```
**Success (302):** Redirect to role-appropriate dashboard.
**Failure (422):**
```json
{
  "message": "The provided credentials do not match our records.",
  "errors": { "email": ["The provided credentials do not match our records."] }
}
```
**Rate Limit:** 5 attempts / 15 minutes per IP.

---

### 1.2 `POST /logout`

**Purpose:** End staff session.
**Auth:** Authenticated.
**Response (302):** Redirect to `/login`.

---

### 1.3 `POST /api/auth/verify-pin`

**Purpose:** One-time supervisor PIN verification for override actions.
**Auth:** Authenticated (any role can initiate, but the PIN must belong to a supervisor/admin).
**Request:**
```json
{
  "user_id": 5,
  "pin": "123456"
}
```
**Success (200):**
```json
{
  "verified": true,
  "user_id": 5,
  "role": "supervisor"
}
```
**Failure (401):**
```json
{
  "verified": false,
  "message": "Invalid PIN."
}
```
**Rate Limit:** 5 attempts / minute per `user_id`.

---

## 2. Public Endpoints `[PUBLIC]`

### 2.1 `GET /api/check-status/{qr_hash}`

**Purpose:** Client scans token at kiosk to see their queue status.
**Auth:** None.
**Path Params:** `qr_hash` — SHA-256 hash from QR code.
**Success (200):**
```json
{
  "alias": "A1",
  "track": "Priority",
  "client_category": "PWD",
  "status": "waiting",
  "current_station": "Interview",
  "progress": {
    "total_steps": 3,
    "current_step": 2,
    "steps": [
      { "name": "Triage", "station_name": "Reception", "status": "complete" },
      { "name": "Interview", "station_name": "Table 2", "status": "in_progress" },
      { "name": "Cashier", "station_name": "Table 4", "status": "pending" }
    ]
  },
  "estimated_wait_minutes": 5,
  "started_at": "2026-02-10T10:35:00Z"
}
```
**Not Found (404):**
```json
{ "message": "Token not found." }
```
**No Active Session (200):**
```json
{
  "alias": "A1",
  "status": "available",
  "message": "This token is not currently in use."
}
```
**Business Logic:**
- Look up token by `qr_code_hash`.
- If `status = 'in_use'`, load current session with track steps and build progress.
- If `status = 'available'`, return minimal response.
- If `status IN ('lost', 'damaged')`, return `{ "status": "unavailable", "message": "Token marked as [status]." }`.
- **No internal IDs** are exposed in the response (per `05-SECURITY-CONTROLS.md`).

---

## 3. Session Endpoints (Staff Auth)

All session endpoints require `auth` middleware and either `role:admin,supervisor,staff` or higher.

### 3.1 `POST /api/sessions/bind`

**Purpose:** Triage scans a token and creates a new session.
**Auth:** Any authenticated staff.
**Request:**
```json
{
  "qr_hash": "a1b2c3d4e5f6...",
  "track_id": 2,
  "client_category": "PWD"
}
```
**Note:** `program_id` is derived server-side from the currently active program.

**Success (201):**
```json
{
  "session": {
    "id": 101,
    "alias": "A1",
    "track": { "id": 2, "name": "Priority" },
    "client_category": "PWD",
    "status": "waiting",
    "current_station": { "id": 1, "name": "Triage" },
    "current_step_order": 1,
    "started_at": "2026-02-10T10:35:00Z"
  },
  "token": {
    "physical_id": "A1",
    "status": "in_use"
  }
}
```
**Validation Errors (422):**
```json
{
  "message": "Validation failed.",
  "errors": {
    "qr_hash": ["Token not found."],
    "track_id": ["Track does not belong to the active program."]
  }
}
```
**Token Already Active (409):**
```json
{
  "message": "Token is already in use.",
  "active_session": {
    "id": 99,
    "alias": "A1",
    "status": "serving",
    "current_station": "Table 2 - Interview",
    "started_at": "2026-02-10T09:15:00Z"
  }
}
```
**Token Unavailable (409):**
```json
{
  "message": "Token is marked as lost.",
  "token_status": "lost"
}
```
**No Active Program (400):**
```json
{ "message": "No active program. Please activate a program first." }
```

**Business Logic (ref: `05-flow-engine` Section 5):**
1. Validate `qr_hash` exists in `tokens`.
2. Check `token.status`:
   - `available` → proceed.
   - `in_use` → return 409 with active session details.
   - `lost`/`damaged` → return 409.
3. Validate `track_id` belongs to the active program.
4. Get first `track_step` (step_order = 1) for the track.
5. Create `Session` with `status = 'waiting'`, `current_station_id` = first step's station, `current_step_order = 1`.
6. Update `Token`: `status = 'in_use'`, `current_session_id = session.id`.
7. Log `TransactionLog`: `action_type = 'bind'`, `station_id` = NULL (triage is not a station), `next_station_id` = first step's station.
8. Broadcast `client_arrived` to `station.{first_station_id}`.
9. Broadcast `queue_length` to `global.queue`.

---

### 3.2 `POST /api/sessions/{id}/transfer`

**Purpose:** Move a session to the next station (standard path or custom target).
**Auth:** Any authenticated staff.
**Request (standard path):**
```json
{
  "mode": "standard"
}
```
**Request (custom target):**
```json
{
  "mode": "custom",
  "target_station_id": 4
}
```

**Success (200):**
```json
{
  "session": {
    "id": 101,
    "alias": "A1",
    "status": "waiting",
    "current_station": { "id": 3, "name": "Cashier" },
    "current_step_order": 3,
    "previous_station": { "id": 2, "name": "Interview" }
  }
}
```
**Flow Complete — No Next Station (200):**
```json
{
  "message": "No next station in track. Session is ready to complete.",
  "session": { "id": 101, "alias": "A1", "status": "serving" },
  "action_required": "complete"
}
```
**Invalid State (409):**
```json
{ "message": "Session is not currently being served. Cannot transfer." }
```

**Business Logic (ref: `05-flow-engine` Sections 1-3):**
1. Validate session exists and `status = 'serving'`.
2. If `mode = 'standard'`:
   - Call `FlowEngine::calculateNextStation(session)`.
   - If NULL → return "flow complete" response.
   - Else → set `current_station_id` to next station, increment `current_step_order`.
3. If `mode = 'custom'`:
   - Validate `target_station_id` exists and is active.
   - Set `current_station_id` to target. Update `current_step_order` to matching step if it exists in the track, or leave as-is for off-track transfers.
4. Set `session.status = 'waiting'`.
5. Log `TransactionLog`: `action_type = 'transfer'`, `previous_station_id`, `next_station_id`.
6. Broadcast `status_update` to old station channel.
7. Broadcast `client_arrived` to new station channel.
8. Broadcast `now_serving` + `queue_length` to `global.queue`.

---

### 3.3 `POST /api/sessions/{id}/override`

**Purpose:** Supervisor-approved deviation from standard flow.
**Auth:** Requires `role:admin,supervisor` OR supervisor PIN verification.
**Request:**
```json
{
  "target_station_id": 3,
  "reason": "Needs legal assistance before documentation review",
  "supervisor_user_id": 5,
  "supervisor_pin": "123456"
}
```
**Success (200):**
```json
{
  "session": {
    "id": 101,
    "alias": "A1",
    "status": "waiting",
    "current_station": { "id": 3, "name": "Legal Assistance" }
  },
  "override": {
    "authorized_by": "Maria Cruz (Supervisor)",
    "reason": "Needs legal assistance before documentation review"
  }
}
```
**PIN Invalid (401):**
```json
{ "message": "Invalid supervisor PIN." }
```
**Reason Missing (422):**
```json
{ "errors": { "reason": ["Reason is required for overrides."] } }
```

**Business Logic (ref: `05-flow-engine` Section 4.3, `05-SECURITY-CONTROLS` Section 4):**
1. Validate session exists and `status IN ('waiting', 'serving')`.
2. Verify supervisor PIN: hash `supervisor_pin`, compare against `users.override_pin` where `id = supervisor_user_id` and `role IN ('admin', 'supervisor')`.
3. Validate `target_station_id` exists and is active.
4. Validate `reason` is non-empty.
5. Update session: `current_station_id = target`, `status = 'waiting'`.
6. Log `TransactionLog`: `action_type = 'override'`, `remarks = reason`, `metadata = { supervisor_id, override_reason }`.
7. Broadcast to affected station channels + `global.queue`.

---

### 3.4 `POST /api/sessions/{id}/complete`

**Purpose:** Mark session as completed at the final station.
**Auth:** Any authenticated staff.
**Request:**
```json
{}
```
(No body required — the session ID in the URL is sufficient.)

**Success (200):**
```json
{
  "session": {
    "id": 101,
    "alias": "A1",
    "status": "completed",
    "completed_at": "2026-02-10T11:20:00Z"
  },
  "token": {
    "physical_id": "A1",
    "status": "available"
  }
}
```
**Required Steps Remaining (409):**
```json
{
  "message": "Cannot complete: required steps remaining.",
  "remaining_steps": [
    { "step_order": 3, "station": "Cashier", "is_required": true }
  ]
}
```

**Business Logic (ref: `05-flow-engine` Section 5):**
1. Validate session `status = 'serving'`.
2. Check all required `track_steps` with `step_order <= max` have been visited (i.e., `current_step_order >= max required step_order`). If not → 409.
3. Set `session.status = 'completed'`, `session.completed_at = now()`, `session.current_station_id = NULL`.
4. Set `token.status = 'available'`, `token.current_session_id = NULL`.
5. Log `TransactionLog`: `action_type = 'complete'`.
6. Broadcast to station channel + `global.queue`.

---

### 3.5 `POST /api/sessions/{id}/cancel`

**Purpose:** Cancel an active session. Any authenticated user can cancel.
**Auth:** Any authenticated staff.
**Request:**
```json
{
  "remarks": "Client decided to leave"
}
```
(`remarks` is optional but recommended.)

**Success (200):**
```json
{
  "session": {
    "id": 101,
    "alias": "A1",
    "status": "cancelled",
    "completed_at": "2026-02-10T11:05:00Z"
  },
  "token": {
    "physical_id": "A1",
    "status": "available"
  }
}
```
**Already Terminal (409):**
```json
{ "message": "Session is already completed." }
```

**Business Logic:**
1. Validate session `status IN ('waiting', 'serving')`.
2. Set `session.status = 'cancelled'`, `session.completed_at = now()`, `session.current_station_id = NULL`.
3. Set `token.status = 'available'`, `token.current_session_id = NULL`.
4. Log `TransactionLog`: `action_type = 'cancel'`, `remarks` if provided.
5. Broadcast to station channel + `global.queue`.

---

### 3.6 `POST /api/sessions/{id}/no-show`

**Purpose:** Mark a client as no-show after repeated calls.
**Auth:** Any authenticated staff.
**Request:**
```json
{}
```

**Success (200):**
```json
{
  "session": {
    "id": 101,
    "alias": "A1",
    "status": "no_show",
    "no_show_attempts": 3,
    "completed_at": "2026-02-10T11:10:00Z"
  },
  "token": {
    "physical_id": "A1",
    "status": "available"
  }
}
```

**Business Logic (ref: `08-edge-cases` Section 2):**
1. Validate session `status IN ('waiting', 'serving')`.
2. Set `session.status = 'no_show'`, `session.completed_at = now()`, `session.current_station_id = NULL`.
3. Set `token.status = 'available'`, `token.current_session_id = NULL`.
4. Log `TransactionLog`: `action_type = 'no_show'`.
5. Broadcast to station channel + `global.queue`.

---

### 3.7 `POST /api/sessions/{id}/call`

**Purpose:** Increment no-show attempt counter when calling a client. Used BEFORE `no-show` to track attempts.
**Auth:** Any authenticated staff.
**Request:**
```json
{}
```
**Success (200):**
```json
{
  "session_id": 101,
  "alias": "A1",
  "no_show_attempts": 2,
  "threshold_reached": false
}
```
**Threshold Reached (200):**
```json
{
  "session_id": 101,
  "alias": "A1",
  "no_show_attempts": 3,
  "threshold_reached": true,
  "message": "No-show threshold reached. Prompt staff to mark no-show or keep waiting."
}
```

**Business Logic:**
1. Validate session `status = 'waiting'` at the current station.
2. Increment `session.no_show_attempts`.
3. If `no_show_attempts >= 3`, return `threshold_reached: true`.
4. Set `session.status = 'serving'` (staff is now "serving" this client, even if absent).

---

### 3.8 `POST /api/sessions/{id}/force-complete`

**Purpose:** Supervisor force-ends an active session (used in double-scan scenarios).
**Auth:** Requires supervisor/admin role + PIN.
**Request:**
```json
{
  "reason": "Token was accidentally reused. Ending previous session.",
  "supervisor_user_id": 5,
  "supervisor_pin": "123456"
}
```
**Success (200):** Same shape as complete response.

**Business Logic:**
1. Verify supervisor PIN.
2. Validate `reason` is non-empty.
3. Set `session.status = 'completed'`, `completed_at = now()`, `current_station_id = NULL`.
4. Unbind token.
5. Log `TransactionLog`: `action_type = 'force_complete'`, `remarks = reason`, `metadata = { supervisor_id }`.

---

## 4. Station Endpoints (Staff Auth)

### 4.1 `GET /api/stations/{id}/queue`

**Purpose:** Get current queue for a station.
**Auth:** Authenticated staff assigned to this station, OR supervisor/admin.
**Response (200):**
```json
{
  "station": {
    "id": 2,
    "name": "Interview"
  },
  "now_serving": {
    "session_id": 101,
    "alias": "A1",
    "track": "Priority",
    "client_category": "PWD",
    "status": "serving",
    "current_step_order": 2,
    "total_steps": 3,
    "started_at": "2026-02-10T10:35:00Z",
    "no_show_attempts": 0
  },
  "waiting": [
    {
      "session_id": 102,
      "alias": "B3",
      "track": "Regular",
      "client_category": "Regular",
      "status": "waiting",
      "queued_at": "2026-02-10T10:38:00Z"
    },
    {
      "session_id": 103,
      "alias": "C1",
      "track": "Priority",
      "client_category": "Senior",
      "status": "waiting",
      "queued_at": "2026-02-10T10:40:00Z"
    }
  ],
  "stats": {
    "total_waiting": 2,
    "total_served_today": 15,
    "avg_service_time_minutes": 4.2
  }
}
```
**Station Access Denied (403):**
```json
{ "message": "You are not assigned to this station." }
```

**Queue Ordering:**
- Waiting sessions ordered by `queue_sessions.started_at` ASC (FIFO).
- Priority tracks do NOT automatically jump the queue (staff manually calls next in the order they choose). This matches the real-world MSWDO process where staff has discretion.

---

### 4.2 `GET /api/stations`

**Purpose:** List all stations for the active program.
**Auth:** Any authenticated staff.
**Response (200):**
```json
{
  "stations": [
    {
      "id": 1,
      "name": "Verification Desk",
      "is_active": true,
      "queue_count": 0,
      "assigned_staff": [{ "id": 1, "name": "Juan Cruz" }]
    },
    {
      "id": 2,
      "name": "Interview",
      "is_active": true,
      "queue_count": 3,
      "assigned_staff": [{ "id": 2, "name": "Maria Santos" }]
    }
  ]
}
```

---

## 5. Admin Endpoints (Admin Role)

All admin endpoints require `auth` + `role:admin` middleware.

### 5.1 Programs

| Method | Path | Purpose |
|--------|------|---------|
| `GET /api/admin/programs` | List all programs | |
| `POST /api/admin/programs` | Create program | Body: `{ name, description }` |
| `GET /api/admin/programs/{id}` | Get program details | Includes tracks, stations, stats |
| `PUT /api/admin/programs/{id}` | Update program | Body: `{ name, description }` |
| `POST /api/admin/programs/{id}/activate` | Activate program | Deactivates current active program |
| `POST /api/admin/programs/{id}/deactivate` | Deactivate program | Requires no active sessions |
| `DELETE /api/admin/programs/{id}` | Delete program | Requires no sessions at all |

**Create Program Request:**
```json
{
  "name": "Cash Assistance Distribution",
  "description": "Social pension payout for Q1 2026"
}
```
**Create Program Response (201):**
```json
{
  "program": {
    "id": 1,
    "name": "Cash Assistance Distribution",
    "description": "Social pension payout for Q1 2026",
    "is_active": false,
    "created_at": "2026-02-10T08:00:00Z"
  }
}
```

---

### 5.2 Service Tracks

| Method | Path | Purpose |
|--------|------|---------|
| `GET /api/admin/programs/{programId}/tracks` | List tracks for program |
| `POST /api/admin/programs/{programId}/tracks` | Create track | Body: `{ name, description, is_default, color_code }` |
| `PUT /api/admin/tracks/{id}` | Update track |
| `DELETE /api/admin/tracks/{id}` | Delete track | Blocked if active sessions use it |

---

### 5.3 Stations

| Method | Path | Purpose |
|--------|------|---------|
| `GET /api/admin/programs/{programId}/stations` | List stations for program |
| `POST /api/admin/programs/{programId}/stations` | Create station | Body: `{ name, capacity }` |
| `PUT /api/admin/stations/{id}` | Update station |
| `DELETE /api/admin/stations/{id}` | Delete station | Blocked if referenced by track steps |

---

### 5.4 Track Steps

| Method | Path | Purpose |
|--------|------|---------|
| `GET /api/admin/tracks/{trackId}/steps` | List steps for track (ordered) |
| `POST /api/admin/tracks/{trackId}/steps` | Add step | Body: `{ station_id, step_order, is_required, estimated_minutes }` |
| `PUT /api/admin/steps/{id}` | Update step |
| `DELETE /api/admin/steps/{id}` | Delete step | Auto-reorders remaining steps |
| `POST /api/admin/tracks/{trackId}/steps/reorder` | Reorder steps | Body: `{ step_ids: [3, 1, 2] }` |

---

### 5.5 Tokens

| Method | Path | Purpose |
|--------|------|---------|
| `GET /api/admin/tokens` | List all tokens | Filterable: `?status=available&search=A1` |
| `POST /api/admin/tokens/batch` | Create token batch | Body: `{ prefix: "A", count: 50 }` |
| `PUT /api/admin/tokens/{id}` | Update token status | Body: `{ status: "lost" }` |

**Batch Create Request:**
```json
{
  "prefix": "A",
  "count": 50,
  "start_number": 1
}
```
**Batch Create Response (201):**
```json
{
  "created": 50,
  "tokens": [
    { "id": 1, "physical_id": "A1", "qr_code_hash": "abc123...", "status": "available" },
    { "id": 2, "physical_id": "A2", "qr_code_hash": "def456...", "status": "available" }
  ]
}
```

---

### 5.6 Users

| Method | Path | Purpose |
|--------|------|---------|
| `GET /api/admin/users` | List all users | Filterable: `?role=staff&active=true` |
| `POST /api/admin/users` | Create user | Body: `{ name, email, password, role, override_pin }` |
| `PUT /api/admin/users/{id}` | Update user |
| `DELETE /api/admin/users/{id}` | Deactivate user | Soft delete: sets `is_active = false` |

**Create User Request:**
```json
{
  "name": "Juan Cruz",
  "email": "juan@mswdo.gov.ph",
  "password": "temporary123",
  "role": "staff",
  "override_pin": null
}
```

---

### 5.7 Staff Assignment

| Method | Path | Purpose |
|--------|------|---------|
| `POST /api/admin/users/{userId}/assign-station` | Assign staff to station | Body: `{ station_id: 3 }` |
| `POST /api/admin/users/{userId}/unassign-station` | Remove station assignment |

---

### 5.8 Reports

| Method | Path | Purpose |
|--------|------|---------|
| `GET /api/admin/reports/audit` | Get audit log entries | Query: `?program_id=1&from=2026-02-10&to=2026-02-10&action_type=override&station_id=2` |
| `GET /api/admin/reports/audit/export` | Download CSV | Same query params as above |
| `GET /api/admin/reports/daily-summary` | Get daily operations summary | Query: `?program_id=1&date=2026-02-10` |
| `GET /api/admin/reports/daily-summary/pdf` | Download PDF | Daily Operations Summary template |
| `GET /api/admin/reports/session-detail/pdf` | Download PDF | Session Detail Report. Query: `?session_id=101` OR `?program_id=1&date=2026-02-10` |

**Audit Log Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "session_alias": "A1",
      "action_type": "bind",
      "station": "Triage",
      "staff": "Juan Cruz",
      "remarks": null,
      "created_at": "2026-02-10T10:35:00Z"
    }
  ],
  "meta": {
    "total": 245,
    "per_page": 50,
    "current_page": 1
  }
}
```

**Daily Summary Response (200):**
```json
{
  "program": "Cash Assistance Distribution",
  "date": "2026-02-10",
  "summary": {
    "total_sessions": 87,
    "completed": 72,
    "cancelled": 3,
    "no_show": 8,
    "still_active": 4,
    "avg_total_time_minutes": 18.5,
    "avg_wait_time_minutes": 6.2
  },
  "by_track": [
    { "track": "Regular", "count": 45, "avg_time": 22.1 },
    { "track": "Priority", "count": 30, "avg_time": 12.3 },
    { "track": "Incomplete", "count": 12, "avg_time": 28.7 }
  ],
  "by_station": [
    { "station": "Interview", "served": 82, "avg_service_time": 4.2, "no_shows": 3 }
  ],
  "overrides": {
    "total": 5,
    "by_supervisor": [
      { "name": "Maria Cruz", "count": 3 }
    ]
  }
}
```

---

## 6. Dashboard Endpoints (Admin/Supervisor)

### 6.1 `GET /api/dashboard/stats`

**Purpose:** Live system health for admin dashboard.
**Auth:** `role:admin,supervisor`.
**Response (200):**
```json
{
  "active_program": {
    "id": 1,
    "name": "Cash Assistance Distribution"
  },
  "sessions": {
    "active": 42,
    "waiting": 12,
    "serving": 8,
    "completed_today": 65,
    "cancelled_today": 2,
    "no_show_today": 4
  },
  "stations": {
    "total": 5,
    "active": 5,
    "with_queue": 3
  },
  "staff_online": 6
}
```

---

## 7. WebSocket Channels

### 7.1 `station.{id}` (Private)

**Auth:** User must be assigned to this station, or be supervisor/admin.

**Events:**
| Event | Payload | Trigger |
|-------|---------|---------|
| `ClientArrived` | `{ session_id, alias, category, track }` | Session transferred/bound to this station |
| `StatusUpdate` | `{ session_id, alias, new_status }` | Session status changed at this station |
| `QueueUpdated` | `{ waiting_count, now_serving: { alias, category } }` | Any queue change |
| `OverrideAlert` | `{ session_id, alias, from_station, reason }` | Override routed a session here |

### 7.2 `global.queue` (Public Broadcast)

**Auth:** None (public channel for informant displays).

**Events:**
| Event | Payload | Trigger |
|-------|---------|---------|
| `NowServing` | `{ entries: [{ alias, station_name, track }] }` | Any session starts being served |
| `QueueLength` | `{ station_id, station_name, count }` | Queue count changes at any station |
| `SystemAnnouncement` | `{ message, priority }` | Admin broadcasts a message |
| `SessionCompleted` | `{ alias, station_name }` | Session completed (update display) |

---

## 8. Error Response Convention

All error responses follow this structure:

```json
{
  "message": "Human-readable error description.",
  "errors": {
    "field_name": ["Specific validation error."]
  },
  "code": "MACHINE_READABLE_CODE"
}
```

**Standard HTTP Status Codes Used:**
| Code | Meaning |
|------|---------|
| 200 | Success |
| 201 | Created |
| 302 | Redirect (after login/logout) |
| 400 | Bad request (missing active program, etc.) |
| 401 | Unauthenticated / invalid PIN |
| 403 | Forbidden (wrong role or station) |
| 404 | Resource not found |
| 409 | Conflict (duplicate, already active, terminal state) |
| 422 | Validation error |
| 429 | Rate limited |
| 500 | Server error |
