# Edge Mode — Comprehensive Plan

| | |
|---|---|
| **Status** | **Draft — canonical plan for all Edge Mode work** |
| **Supersedes** | `docs/CI-CD-and-Edge-plan.md` (Part B), `docs/final-edge-mode-rush-plann.md` (Phase D+F) — those remain historical references; this document is the current source of truth. |
| **Complements** | [`HYBRID_AUTH_ADMIN_FIRST_PRD.md`](HYBRID_AUTH_ADMIN_FIRST_PRD.md) (identity), [`RBAC_AND_IDENTITY_END_STATE.md`](RBAC_AND_IDENTITY_END_STATE.md) (authorization), `docs/CI-CD-and-Edge-plan.md` (Part A — CI/CD) |
| **Stack** | Central: Laravel 12 + MariaDB + Pusher. Edge: Laravel 12 + SQLite + Reverb (local). Both: Inertia + Svelte 5. |

---

## Table of Contents

1. [Vision and Topology](#1-vision-and-topology)
2. [Authentication on Edge](#2-authentication-on-edge)
3. [Program Locking](#3-program-locking)
4. [Sync Modes](#4-sync-modes)
5. [Package Sync (Central → Edge)](#5-package-sync-central--edge)
6. [Reverse Sync (Edge → Central)](#6-reverse-sync-edge--central)
7. [Edge Device Allocation and Limits](#7-edge-device-allocation-and-limits)
8. [Edge Device Lifecycle](#8-edge-device-lifecycle)
9. [Central Management UI](#9-central-management-ui)
10. [Data Isolation and ID Strategy](#10-data-isolation-and-id-strategy)
11. [Conflict Resolution](#11-conflict-resolution)
12. [TTS on Edge](#12-tts-on-edge)
13. [Broadcasting on Edge](#13-broadcasting-on-edge)
14. [Security](#14-security)
15. [Error Handling and Resilience](#15-error-handling-and-resilience)
16. [UI on Edge](#16-ui-on-edge)
17. [Edge .env and Configuration](#17-edge-env-and-configuration)
18. [Deployment and Golden Image](#18-deployment-and-golden-image)
19. [Schema Changes Required](#19-schema-changes-required)
20. [API Endpoints (New and Modified)](#20-api-endpoints-new-and-modified)
21. [Phased Delivery](#21-phased-delivery)
22. [Risks and Mitigations](#22-risks-and-mitigations)
23. [Resolved Questions](#23-resolved-questions)

---

## 1. Vision and Topology

FlexiQueue operates in a **hub-and-spoke** topology:

```
                    ┌──────────────────┐
                    │  Central Server  │
                    │  (MariaDB/Pusher)│
                    │  flexiqueue.click│
                    └──────┬───────────┘
                           │
              ┌────────────┼────────────┐
              │            │            │
        ┌─────▼────┐ ┌────▼─────┐ ┌────▼─────┐
        │  Edge Pi  │ │  Edge Pi │ │  Edge Pi │
        │  Site A   │ │  Site A  │ │  Site B  │
        │  Prog 1   │ │  Prog 2  │ │  Prog 1  │
        │  SQLite   │ │  SQLite  │ │  SQLite  │
        │  Reverb   │ │  Reverb  │ │  Reverb  │
        └──────────┘ └──────────┘ └──────────┘
```

**Central** is the canonical data store; it manages all sites, programs, users, and configuration. **Edge devices** (Orange Pi / Raspberry Pi) are lightweight field units that run a full local FlexiQueue instance (SQLite + Reverb) and sync with central as needed.

### Core invariants

1. **Central is the source of truth** for configuration (programs, users, tokens, settings).
2. **Edge is the source of truth** for data it produces during a session (queue sessions, transaction logs, client records created locally).
3. **One program per edge device** at any given time — exclusively locked.
4. **Edge never modifies program configuration** — admin panel is read-only on edge.
5. **All user provisioning happens on central** — edge receives users via package sync.

---

## 2. Authentication on Edge

### 2.1 Google OAuth is disabled on edge

**Rule:** When `APP_MODE=edge`, all Google OAuth routes are disabled.

| Route | Central | Edge |
|-------|---------|------|
| `GET /auth/google` | Active | **404 / redirect to login** |
| `GET /auth/google/callback` | Active | **404 / redirect to login** |
| `GET /auth/google/link` | Active | **404 / redirect to profile** |
| `DELETE /api/profile/google` | Active | **404** |

**Implementation:**
- Add a route-level guard in `routes/web.php`: wrap Google OAuth routes in `if (!app(EdgeModeService::class)->isEdge())` or use middleware.
- Login page Svelte component: hide "Sign in with Google" button when `$page.props.edge_mode?.is_edge` is true.
- Profile page: hide "Link Google Account" section on edge.

**Rationale:** Edge devices may be offline or on a local network with no internet. Google OAuth requires a round-trip to Google servers. Edge users authenticate with **username + password only**. Users are synced from central via package (with password hashes included).

### 2.2 Local login on edge

- Standard username + password login via `LoginController` — no changes needed.
- User records (including hashed passwords) arrive via `EdgePackageImportService`.
- `user_credentials` rows are also synced for local provider entries.
- **Forgot password is disabled on edge** — no SMTP server on a Pi. Admin-only password reset remains available from central.

**Implementation:** Hide the "Forgot password?" link on the login page when `edge_mode.is_edge` is true.

### 2.3 Supervisor admin access on edge

Per `edge_device_state.supervisor_admin_access` (set by central admin on assignment):

| Value | Who can access edge admin panel |
|-------|----|
| `false` (default) | Only users with `admin` role |
| `true` | Users with `admin` role **plus** users who are supervisors of the currently active program (via program `RbacTeam` grants) |

**Implementation:**
- Middleware or gate check on edge admin routes reads `EdgeDeviceState::current()->supervisor_admin_access`.
- If `true`: check `$user->hasPermissionInContext('programs.supervise', programTeam)` OR user has admin role.
- If `false`: require admin role only.

### 2.4 Session management

- Laravel session auth works identically on edge (file/cookie driver with SQLite backend).
- Session regeneration on login: standard Laravel behavior, no change.
- No cross-device session sharing (edge is isolated).

---

## 3. Program Locking

### 3.1 One program per edge, exclusively locked

When central assigns a program to an edge device:

1. The `edge_device_state.active_program_id` is set on the edge.
2. Central records the assignment in `edge_devices.assigned_program_id`.
3. **The program is marked as "edge-locked"** on central: `programs.edge_locked_by_device_id` is set.

### 3.2 Locking semantics

| Scenario | Behavior |
|----------|----------|
| Program assigned to Edge A | `programs.edge_locked_by_device_id = A` |
| Central user tries to start a queue session for that program | **Blocked**: "This program is currently assigned to an edge device." |
| Another edge device tries to get the same program assigned | **Blocked**: "Program is already assigned to device [name]." |
| Edge session ends normally | Lock is released: `edge_locked_by_device_id = null` |
| Edge session is dumped from central | Lock is released after dump completes |
| Edge device is revoked from central | Lock is released immediately |
| Edge device goes offline (no heartbeat for >X hours) | Lock **persists** — admin must manually release or revoke the device |

### 3.3 Central-side lock enforcement

Programs locked by an edge device are restricted on central:

| Central action | Locked program | Unlocked program |
|----------------|---------------|------------------|
| Start queue session | **Blocked** | Allowed |
| Edit program config (settings, diagram, tracks, stations) | **Allowed** (changes sync on next package pull) | Allowed |
| Delete program | **Blocked** (must unassign edge first) | Allowed |
| View program | Allowed (shows "Assigned to Edge: [device name]" badge) | Allowed |

### 3.4 Multiple edges, same program — NOT allowed (v1)

For the first version, **one program can only be assigned to one edge device at a time**. This avoids token ID collisions, queue session conflicts, and data merge complexity.

**Future consideration (v2):** If we need the same program template on multiple edges (e.g., same service at two field locations), we would clone the program (new `program.id`, new token pool) and assign the clone. This plan does not implement that.

### 3.5 Edge-side program isolation

- Edge admin panel shows **only** the assigned program — no program picker, no program list.
- Edge triage, station, and display views are scoped to the active program.
- If `active_program_id` is null, edge redirects to `/edge/waiting`.

---

## 4. Sync Modes

### 4.1 Overview

Two sync modes, selected during edge setup wizard and stored in `edge_device_state.sync_mode`:

| Mode | Config value | When data goes to central | Internet required during session | Use case |
|------|-------------|--------------------------|--------------------------------|----------|
| **Auto** | `auto` | On every queue event (local write + HTTP push to central) | Yes, stable | Reliable internet; central always current |
| **End of Event** | `end_of_event` | Only after session ends, via manual or scheduled sync | No | Unreliable / no internet; offline-capable |

### 4.2 Auto sync mode ("save to both")

Every state-changing queue operation (bind token, call, transfer, complete, cancel, hold, enqueue-back, etc.) does:

1. **Write to local SQLite** (primary — always succeeds).
2. **HTTP POST to central** `POST /api/edge/event` with the event payload (async, non-blocking to the user).

```
Edge event flow (auto mode):
┌──────────────┐     ┌──────────────┐     ┌─────────────────┐
│ Staff action  │────▶│  Local DB    │────▶│  Central push   │
│ (bind/call/…) │     │  (SQLite)    │     │  (HTTP POST)    │
└──────────────┘     └──────────────┘     └─────────┬───────┘
                                                     │
                                          ┌──────────▼───────┐
                                          │  Success?        │
                                          │  Yes → mark sent │
                                          │  No → retry queue│
                                          └──────────────────┘
```

**Sync SLA (Q6 resolved):** Events should appear on central within **60 seconds** of being created on edge under normal network conditions. This is best-effort, not a hard guarantee.

**Retry logic:**
- Failed pushes go into a **local retry queue** (database table `edge_sync_queue`).
- A background job (`EdgeSyncRetryJob`) retries pending items every 30 seconds.
- After 5 consecutive failures (~2.5 min), the system **degrades to end-of-event mode** automatically and shows a warning banner: "Sync connection lost — data will sync after session."
- When connectivity returns (successful push), auto-sync resumes and drains the retry queue.

**What gets pushed per event:**
- The `transaction_log` row (the canonical audit event).
- Associated `queue_session` state update (current station, status, timestamps).
- New `client` records created during triage (if any).

### 4.3 End-of-event sync mode ("local then batch")

During the session:
- All writes go to **local SQLite only**.
- No HTTP calls to central.
- Edge operates fully offline — internet is not needed.
- The `EdgeModeBanner` shows "Offline — local only" amber state.

After the session:
- Staff (or scheduled task) triggers **sync**.
- Edge pushes **all** session data to central via `POST /api/edge/sync`.
- The sync payload includes everything produced during the session.

### 4.4 End-of-event sync payload

When the edge pushes data to central after a session, the following are included:

| Data | Table on edge | Merge strategy on central |
|------|--------------|--------------------------|
| Queue sessions | `queue_sessions` | Upsert by `id` (edge-generated IDs use offset range — see §10) |
| Transaction logs | `transaction_logs` | Insert (append-only; dedupe by edge-origin + log ID) |
| Client records | `clients` | Upsert by `id`; identity hash merge |
| Identity registrations | `identity_registrations` | Upsert by `id` |
| Program audit log | `program_audit_log` | Insert (append-only) |
| Staff activity log | `staff_activity_log` | Insert (append-only) |
| Token status changes | `tokens` (status only) | Update `status` where `id` matches |

### 4.5 Scheduled sync (end-of-event only)

The `edge_settings.scheduled_sync_time` (HH:MM format, already in `EdgeSettingsValidator`) defines when an automatic sync attempt occurs.

- A Laravel scheduled command (`edge:auto-sync`) runs at the configured time.
- Only triggers if there is unsent data (`edge_sync_pending` flag or unsynced transaction logs).
- If edge is unreachable to central at that time, retry every 15 minutes for 2 hours, then give up and wait for manual trigger.

### 4.6 Sync mode switching

- Sync mode is chosen during setup wizard.
- It can be **changed from central** via the Edge Devices management page.
- Change takes effect **after the current session ends** (not mid-session).
- When central changes it, the new mode is pushed to edge on the next heartbeat/assignment poll.

---

## 5. Package Sync (Central → Edge)

### 5.1 What exists today

- `ProgramPackageExporter` builds a JSON package from central's DB (program, site, users, tracks, stations, processes, tokens, clients, TTS files).
- `EdgePackageImportService` fetches and applies the package on edge (HTTP + DB upsert + TTS file download).
- `EdgeImportController` triggers import via HTTP; `edge:import-package` does it via CLI.

### 5.2 Package versioning

**Problem:** The edge may have an outdated package. It needs to know when to re-sync.

**Solution:** Add a `package_version` to the export manifest — a hash of all section checksums.

```php
// In ProgramPackageExporter::export()
$manifest['package_version'] = hash('sha256', json_encode($manifest['checksums']));
```

**On edge:**
- `edge_package_imported.json` already stores `manifest_hash`. This serves as the local version.
- On assignment poll (`GET /api/edge/assignment`), central returns the current `package_version`.
- If local `manifest_hash !== remote package_version`, edge triggers a re-sync.

### 5.3 Package staleness detection

| Trigger | What happens |
|---------|-------------|
| **Assignment poll** (every 60s while waiting or active) | Central returns `package_version`; edge compares; triggers re-import if stale. |
| **Manual "Sync Now"** in edge admin | Always fetches fresh package regardless of version. |
| **Central admin edits program config** | Central bumps the program's `package_version_at` timestamp; edge picks up on next poll. |
| **Session start** | Edge checks package freshness before starting; warns if stale but doesn't block. |

### 5.4 Incremental vs full re-sync

**v1: Always full re-sync.** The package is small enough (programs, tracks, stations are low-row-count tables) that a full export/import is fast (< 5 seconds for most programs). TTS files are the heaviest part — the import service already skips re-downloading files that already exist locally (add existence check).

**Future (v2):** Incremental sync using `updated_at` timestamps and section-level checksums. Only sections whose checksums differ are re-imported.

### 5.5 Package sync during active session

If a program's config changes on central while the edge has an active session:

- Edge **does not auto-apply** the new package mid-session (could disrupt active queues).
- Edge shows an **info banner**: "Program configuration updated on central. Changes will apply after the current session ends."
- After session ends → waiting state → re-import triggers automatically before next session can start.

---

## 6. Reverse Sync (Edge → Central)

### 6.1 Auto mode reverse sync

Each event is pushed individually as it happens (see §4.2). The central endpoint (`POST /api/edge/event`) processes each event atomically:

```json
{
  "device_token": "...",
  "event_type": "transaction_log",
  "payload": {
    "id": 100000001,
    "session_id": 100000042,
    "action_type": "bind",
    "token_id": 15,
    "staff_user_id": 3,
    "created_at": "2026-03-23T10:15:00Z",
    "metadata": {}
  }
}
```

Central validates the device token, verifies the program is assigned to this device, and upserts the data.

### 6.2 End-of-event batch sync

After a session, the full sync payload is sent via `POST /api/edge/sync`:

```json
{
  "device_token": "...",
  "session_summary": {
    "program_id": 5,
    "started_at": "2026-03-23T08:00:00Z",
    "ended_at": "2026-03-23T17:00:00Z",
    "tokens_served": 142,
    "tokens_cancelled": 3
  },
  "queue_sessions": [ ... ],
  "transaction_logs": [ ... ],
  "clients": [ ... ],
  "identity_registrations": [ ... ],
  "program_audit_log": [ ... ],
  "staff_activity_log": [ ... ],
  "token_updates": [ { "id": 15, "status": "available" }, ... ]
}
```

### 6.3 Sync confirmation

Central returns a sync receipt:

```json
{
  "status": "complete",
  "synced_at": "2026-03-23T17:05:00Z",
  "records_received": {
    "queue_sessions": 142,
    "transaction_logs": 580,
    "clients": 12,
    "identity_registrations": 8
  },
  "conflicts": []
}
```

Edge stores the receipt and updates `edge_device_state.last_synced_at`.

### 6.4 Partial sync / resume

If a batch sync is interrupted (network failure mid-transfer):
- Edge tracks which records have been acknowledged by central (using the receipt).
- Next sync attempt only sends un-acknowledged records.
- Each record has a `sync_status` column: `pending`, `sent`, `confirmed`.

---

## 7. Edge Device Allocation and Limits

### 7.1 `max_edge_devices` per site

Super admin defines how many edge devices each site can have.

| Setting | Where stored | Default | Who can change |
|---------|-------------|---------|---------------|
| `max_edge_devices` | `sites.settings` JSON (key `max_edge_devices`) | `0` (no edge devices allowed) | Super admin only |

### 7.2 Enforcement

| Action | Check |
|--------|-------|
| **Pairing a new device** | Count `edge_devices` rows where `site_id = X AND revoked_at IS NULL`. If `count >= max_edge_devices`, reject pairing with error: "Site has reached its edge device limit ({max}). Revoke an existing device or contact your super admin to increase the limit." |
| **Generating a pairing code** | Same check — don't even generate a code if limit reached. |
| **Super admin lowers the limit** | Existing devices are **grandfathered** — they remain active. No new pairings until count drops below limit (via revocation). |
| **Super admin sets limit to 0** | No new pairings; existing devices continue until revoked. |

### 7.3 Super admin UI

On the **Super Admin → Sites → Edit Site** page:

- "Edge Devices" section with:
  - `max_edge_devices` number input (min 0, max 50).
  - Current device count: "3 of 5 edge devices allocated."
  - Warning if current count exceeds new limit: "Reducing the limit won't disconnect existing devices, but no new devices can be paired until the count drops below the new limit."

### 7.4 Site admin visibility

Site admin can see the allocation on their site's Edge Devices page:
- "Edge device slots: 3 / 5 used"
- "Add Device" button disabled with tooltip when at limit.

---

## 8. Edge Device Lifecycle

### 8.1 State machine

```
┌──────────────────────────────────────────────────────────────────┐
│                    EDGE DEVICE LIFECYCLE                         │
│                                                                  │
│  [Flashed] ──▶ [Unpaired] ──▶ [Setup Wizard] ──▶ [Paired]      │
│                                                     │            │
│                                            ┌────────▼─────────┐  │
│                                            │    Waiting       │  │
│                                            │  (no program)    │  │
│                                            └────────┬─────────┘  │
│                                                     │ assign     │
│                                            ┌────────▼─────────┐  │
│                                            │   Importing      │  │
│                                            │  (package sync)  │  │
│                                            └────────┬─────────┘  │
│                                                     │ ready      │
│                                            ┌────────▼─────────┐  │
│                                            │  Session Active  │◀─┤── re-assign
│                                            │  (locked to pgm) │  │   (after dump)
│                                            └────────┬─────────┘  │
│                                                     │ end/dump   │
│                                            ┌────────▼─────────┐  │
│                                            │   Syncing Back   │  │
│                                            │  (if end_of_evt) │  │
│                                            └────────┬─────────┘  │
│                                                     │ done       │
│                                                     └──▶ Waiting │
│                                                                  │
│  At any point:                                                   │
│    Central admin ──▶ [Revoke] ──▶ Device is invalidated          │
│    Device token becomes invalid; device must re-pair (new code)  │
└──────────────────────────────────────────────────────────────────┘
```

### 8.2 Setup wizard (runs once per device)

**Trigger:** `edge_device_state.paired_at` is `null` → `EdgeBootGuard` middleware redirects to `/edge/setup`.

**Steps:**

1. **Welcome screen** — "Welcome to FlexiQueue Edge. Let's connect this device to your central server."
2. **Central URL** — Editable text field, defaults to `https://flexiqueue.click`. Device pings the URL to verify connectivity.
3. **Pairing code** — Admin generates a short-lived code from the central Edge Devices page (e.g., `ABCD-1234`). Staff enters it here.
4. **Pair** — `POST {central_url}/api/edge/pair` with `{ pairing_code }`. Central validates the code and returns `{ device_token, site_id, site_name, device_id }`.
5. **Sync mode** — "How should this device sync data?"
   - **Auto (recommended when internet is reliable):** "Data is saved locally and pushed to the central server in real time."
   - **End of Event:** "Data is saved locally only. After the event session ends, everything syncs to the central server at once."
6. **Confirm** — `EdgeDeviceSetupService` writes `edge_device_state`, updates `.env`, runs `config:clear && config:cache`, sets `paired_at`.
7. **Redirect** to `/edge/waiting`.

**The wizard never re-runs.** Returning to waiting or re-assigning does not re-trigger it. To "factory reset" an edge device, wipe `edge_device_state` (or reflash the golden image).

### 8.3 Waiting state

`/edge/waiting` — shown when `edge_device_state.active_program_id` is `null`.

- Displays: "Paired to **[site_name]**. Awaiting program assignment from your administrator."
- Polls `GET {central_url}/api/edge/assignment` every 60 seconds using device token.
- When assignment arrives: triggers `edge:import-package` automatically → progress bar → redirect to login / dashboard.

### 8.4 Heartbeat

A background scheduled task (`edge:heartbeat`) runs every 5 minutes on edge:

- `POST {central_url}/api/edge/heartbeat` with `{ device_token, session_active, sync_mode, last_synced_at, package_version }`.
- Central updates `edge_devices.last_seen_at`, `session_active`, and can push config changes back in the response (e.g., new sync mode, supervisor toggle change, "dump session" signal).

### 8.5 Revoking a device

Central admin clicks "Revoke" on the Edge Devices page:

1. Central sets `edge_devices.revoked_at = now()`.
2. Central releases program lock: `programs.edge_locked_by_device_id = null`.
3. On the next heartbeat, edge receives a `{ revoked: true }` signal.
4. Edge clears `edge_device_state` (or marks it revoked) and redirects to a "Device Revoked" page.
5. To use this device again, admin must generate a new pairing code and repeat the setup wizard.

### 8.6 Stale device detection

| Condition | Status shown on central |
|-----------|------------------------|
| Heartbeat within last 10 minutes | **Online** (green) |
| Heartbeat 10–60 minutes ago | **Idle** (yellow) |
| No heartbeat for > 1 hour | **Offline** (red) |
| No heartbeat for > 24 hours | **Stale** (red + warning icon) — admin should investigate or revoke |

### 8.7 Central force-cancel (unsynced session)

**Problem (Q3 resolved):** An edge device goes offline with an active session and never comes back. The program is locked indefinitely, and unsynced session data sits on an unreachable device.

**Solution:** Central admin can **force-cancel** the session for a device that is offline/stale and has `session_active = true`.

**Flow:**

1. Admin clicks **"Force Cancel Session"** on the Edge Devices page (only enabled when device is offline/stale + session_active).
2. Central:
   - Sets `edge_devices.session_active = false`.
   - Releases program lock: `programs.edge_locked_by_device_id = null`.
   - Logs an entry in `program_audit_log`: "Session force-cancelled from central. Device [name] was unreachable."
   - Sets a flag: `edge_devices.force_cancelled_at = now()`.
3. Edge behavior when it eventually comes back online (heartbeat or manual reconnect):
   - Heartbeat response includes `{ session_voided: true, voided_at: "..." }`.
   - Edge clears its local active session: `edge_device_state.active_program_id = null`.
   - Edge archives (does not delete) local session data to `storage/app/voided-sessions/{date}/` for potential manual recovery.
   - Edge transitions to the **waiting** state.
4. If the edge **never** comes back: the data is lost. This is an accepted trade-off — the admin made a conscious decision to force-cancel.

**Safeguards:**
- Confirmation dialog: "This device has not synced since [last_synced_at]. Cancelling will discard any unsynced session data. Are you sure?"
- Audit trail: the force-cancel action, who performed it, and the timestamp are logged.
- The voided edge session data is archived locally (not deleted) in case the device is later recovered physically.

**Schema addition:** `edge_devices.force_cancelled_at` — `timestamp`, nullable. Reset to null when a new session starts.

---

## 9. Central Management UI

### 9.1 Edge Devices page

**Location:** Site Settings → "Edge Devices" tab (visible only when `site.settings.max_edge_devices > 0`).

**Accessible to:** Site admins and super admins.

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│  Edge Devices                          [+ Add Device]           │
│  3 of 5 slots used                                              │
├─────────────────────────────────────────────────────────────────┤
│  Device Name   │ Status  │ Program     │ Mode        │ Actions  │
│────────────────┼─────────┼─────────────┼─────────────┼──────────│
│  Field Pi 1    │ 🟢 Active │ Enrollment │ Auto        │ [···]   │
│  Field Pi 2    │ 🟡 Waiting│ —          │ End of Evt  │ [···]   │
│  Field Pi 3    │ 🔴 Offline│ Payroll    │ Auto        │ [···]   │
└─────────────────────────────────────────────────────────────────┘
```

**"Add Device" flow:**
1. Click "Add Device" → modal.
2. Enter device name.
3. System generates a **pairing code** (8 chars, uppercase alphanumeric, 10-minute TTL, single-use).
4. Code shown + QR code for easy scanning.
5. After successful pairing, device appears in the list as "Waiting."

**Per-device actions (dropdown):**

| Action | When available | What it does |
|--------|---------------|-------------|
| Assign Program | Device is waiting or has ended session | Dropdown of active programs for this site (excluding programs locked by other devices) |
| Change Sync Mode | Always (takes effect after session) | Auto / End of Event |
| Toggle Supervisor Access | Always | On/Off |
| Dump Session | Device has active session | Sends dump signal; waits for edge to push data and end session; then releases program lock |
| Unassign Program | Device has no active session | Clears assignment; device goes to waiting |
| Revoke Device | Always | Removes device permanently; frees slot |

### 9.2 Program lock indicators

On the central **Programs** list and detail page:

- Programs assigned to an edge device show a badge: **"Edge: [device name]"** with a lock icon.
- "Start Session" button is hidden/disabled for edge-locked programs.
- Tooltip: "This program is assigned to edge device [name]. Unassign the device to use this program on central."

### 9.3 Super admin site settings

On the **Super Admin → Sites → Edit** page, add:

- **Max Edge Devices:** number input (0–50).
- **Current allocation:** "X of Y devices paired."

---

## 10. Data Isolation and ID Strategy

### 10.1 The ID collision problem

Both central and edge create rows in the same tables (`queue_sessions`, `transaction_logs`, `clients`, etc.). If both use auto-increment starting from 1, IDs will collide on sync.

### 10.2 Solution: ID offset ranges per device

Each edge device is assigned an **ID offset** at pairing time. Edge-generated IDs start from that offset.

| Device | Offset | ID range |
|--------|--------|----------|
| Central | 0 | 1 – 9,999,999 |
| Edge Device 1 | 10,000,000 | 10,000,000 – 19,999,999 |
| Edge Device 2 | 20,000,000 | 20,000,000 – 29,999,999 |
| Edge Device N | N × 10,000,000 | N×10M – (N+1)×10M - 1 |

**Implementation:**
- Central returns `id_offset` in the pairing response.
- Edge stores `id_offset` in `edge_device_state`.
- SQLite `AUTOINCREMENT` is configured to start from the offset (via a migration or `sqlite_sequence` update after pairing).
- Tables with edge-generated IDs: `queue_sessions`, `transaction_logs`, `clients`, `identity_registrations`, `program_audit_log`, `staff_activity_log`, `triage_scan_log`.
- Tables with central-only IDs (synced to edge, never created there): `programs`, `sites`, `users`, `tokens`, `stations`, `service_tracks`, `track_steps`, `processes`.

### 10.3 Edge-origin marker

All records created on edge include an `edge_device_id` column (nullable, null = created on central).

This allows:
- Central to attribute data to the correct edge device.
- Deduplication during sync (same `edge_device_id` + `id` = same record).
- Audit trail: "This session was served by Field Pi 2."

### 10.4 Site scoping

Edge only ever holds data for **one site** and **one program**. All existing `site_id` scoping on models (`Program::scopeForSite`, `User::scopeForSite`, `Token::forSite`, `Client::forSite`) already ensures isolation. No cross-site data can leak to or from an edge device.

---

## 11. Conflict Resolution

### 11.1 General rule

**Edge writes win for data created on edge.** Central writes win for configuration data.

### 11.2 Specific scenarios

| Scenario | Resolution |
|----------|-----------|
| **Same token scanned on edge and central simultaneously** | Not possible — program is edge-locked, so no central sessions can exist for that program. |
| **Client created on edge, same person exists on central with different ID** | `identity_hash` match: central merges records, keeping the lower ID (central's). Edge-created sessions referencing the edge client ID get a `client_id` remap in the sync receipt. |
| **Edge creates a client; central also created the same client (from a different program)** | Same identity_hash merge. Both client records survive; one becomes canonical. Sessions retain their original client_id with a mapping note. |
| **Token status conflict** | After edge session ends, tokens are reset to `available` on both edge and central during sync. If central shows a token as `deactivated` (admin action while edge was offline), central's deactivation wins. |
| **User record changed on central while edge has old version** | Next package sync overwrites the edge user record. Edge never modifies user records. |
| **Transaction log duplicate** | Append-only; dedupe by `(edge_device_id, id)` composite. Duplicate inserts are ignored (UPSERT with no update columns). |
| **Edge syncs data for a force-cancelled session** | If an edge comes back online and tries to sync data for a session that was force-cancelled on central, central rejects the sync with `{ error: "session_voided" }`. Edge archives the data locally. Central logs the attempt in `edge_sync_conflicts`. |

### 11.3 Conflict logging

Any conflict resolved during sync is logged in `edge_sync_conflicts`:

| Column | Purpose |
|--------|---------|
| `id` | Auto-increment |
| `edge_device_id` | Which device |
| `table_name` | Which table |
| `record_id` | Which record |
| `conflict_type` | `id_collision`, `identity_merge`, `status_override` |
| `resolution` | What was done |
| `created_at` | When |

This table lives on central and is visible to super admins for audit purposes.

---

## 12. TTS on Edge

### 12.1 Pre-synced audio

TTS audio files are synced from central via the package sync (already implemented in `EdgePackageImportService`). The `sync_tts` flag in `edge_settings` controls whether TTS files are included.

### 12.2 No on-demand TTS generation on edge

Edge devices do **not** have ElevenLabs API access (no API key, no internet guarantee). All TTS audio must be pre-generated on central and synced.

If a token has no TTS audio on edge:
- The announcement uses **browser SpeechSynthesis** (Web Speech API) as fallback.
- This is already the existing behavior when `tts_audio_path` is empty.

### 12.3 TTS budget on edge

TTS budget tracking (`site_tts_usage_events`, `tts_platform_budgets`) is **central-only**. Edge does not write usage events for pre-synced audio playback — those were already counted when generated on central.

---

## 13. Broadcasting on Edge

### 13.1 Local Reverb

Edge uses **Laravel Reverb** running locally (same Pi, port 6001 proxied through nginx).

- `BROADCAST_CONNECTION=reverb` in edge `.env`.
- Reverb serves only the local network (LAN).
- Display boards, station views, and triage views on the same LAN receive real-time updates.

### 13.2 No cross-device broadcasting

Events broadcast on edge are **local only**. They do not propagate to central or other edge devices. Central has its own broadcasting (Pusher) that is independent.

In auto sync mode, central receives data via HTTP push, not via broadcasting relay.

---

## 14. Security

### 14.1 Device token

- Issued by central on pairing (`POST /api/edge/pair`).
- Stored encrypted in `edge_device_state.device_token` (Laravel `encrypted` cast).
- Used as Bearer token for all edge → central API calls.
- **Not** stored in `.env` (lives in the database only).
- Rotation: central can force token rotation by returning a new token in a heartbeat response; edge updates its stored token.

### 14.2 Pairing code security

| Property | Value |
|----------|-------|
| Format | 8 uppercase alphanumeric characters (e.g., `ABCD1234`) |
| TTL | 10 minutes from generation |
| Use limit | Single-use — consumed on successful pairing |
| Storage | `edge_pairing_codes` table on central (code hash + site_id + expires_at) |
| Brute force | Rate-limited: 5 attempts per IP per 15 minutes |

### 14.3 Edge API authentication

All central API endpoints used by edge (`/api/edge/*`) authenticate via:
1. `Authorization: Bearer {device_token}` header.
2. Central resolves the device from the token.
3. If device is revoked or token invalid → 401.
4. If device's site doesn't match the requested resource → 403.

### 14.4 Data at rest on edge — SQLCipher encryption

**Decision (Q7 resolved):** The SQLite database on edge is encrypted with **SQLCipher**.

| Property | Value |
|----------|-------|
| Library | SQLCipher 4.x (community edition, BSD-licensed) |
| PHP driver | `ext-sqlite3` compiled with SQLCipher support — installed in the golden image |
| Encryption key | Derived from `APP_KEY` via `hash('sha256', config('app.key'))` — unique per device |
| Key delivery | `APP_KEY` is already in `.env` (generated at image build or wizard). No additional secret management needed. |
| Algorithm | AES-256-CBC (SQLCipher default) |
| Performance | ~5–15% overhead on ARM Cortex-A55+; acceptable for queue workloads (low row counts, simple queries) |

**Implementation:**
1. Golden image ships PHP compiled with SQLCipher (`--enable-sqlcipher` or the `sqlcipher` package replacing `libsqlite3`).
2. Laravel's SQLite connection config adds a `pragma` callback that issues `PRAGMA key = '<derived_key>';` on every connection open.
3. Existing migrations work unchanged — SQLCipher is transparent to the query layer.
4. Backup scripts must use `sqlcipher_export` or the same key to access the DB file directly.

**What this protects against:**
- SD card physically removed from the device → database file is unreadable without `APP_KEY`.
- Does **not** protect against an attacker with root shell access on the running device (they could read the key from `.env`). Kiosk mode (§18.4) mitigates that vector.

**Additional at-rest protections (unchanged):**
- `device_token` column: Laravel `encrypted` cast (encrypted with `APP_KEY`).
- User passwords in the DB: bcrypt/argon2 hashed (standard).
- TTS files: not sensitive (public audio of token names).

### 14.5 Transport security

- All edge → central communication over HTTPS.
- Self-signed certificates on edge for local Reverb/nginx (LAN only).
- Central has a proper TLS certificate (Let's Encrypt via Hestia).

### 14.6 Source code protection on edge

Edge devices ship with the full Laravel application on disk. To prevent casual extraction or modification of source code:

**Layer 1 — Kiosk mode (primary defense, see §18.4):**
The device boots directly into a locked fullscreen browser. There is no desktop, no terminal, no file manager. Users cannot access the filesystem through normal interaction.

**Layer 2 — Filesystem permissions and read-only mount:**

| Measure | Detail |
|---------|--------|
| App directory ownership | `root:www-data`, permissions `750` (owner=root can write, group=www-data can read/execute, others=nothing) |
| Writable paths | Only `storage/` and `bootstrap/cache/` are writable by `www-data` |
| Read-only overlay (optional, v2) | Mount `/var/www/flexiqueue` as an OverlayFS read-only layer; writes go to a tmpfs that is discarded on reboot |
| SSH disabled by default | Golden image ships with `sshd` disabled. Enable only for maintenance via physical serial console or a toggle in the setup wizard (requires pairing code re-entry). |
| No sudo for www-data | The web process user has no sudo privileges |

**Layer 3 — PHP obfuscation (optional, evaluate post-MVP):**
Commercial obfuscators (ionCube, SourceGuardian) can encode `.php` files so they're not human-readable on disk. This adds:
- Build pipeline complexity (encode step before tarball).
- Runtime dependency (loader extension must be in the golden image).
- Cost (license per project or per device).

**Recommendation for v1:** Layers 1 + 2 cover the practical threat model (field staff and casual tampering). Layer 3 is deferred unless a contractual or compliance requirement demands it. If adopted later, the golden image build would add an ionCube encoding step and bundle the loader extension.

**What this does NOT protect against:**
An attacker with physical access and Linux expertise can always extract the SD card and mount it. SQLCipher (§14.4) protects the database in that scenario; source code on disk would still be readable unless Layer 3 is applied. Accept this as an inherent risk of self-hosted field hardware.

### 14.7 Rate limiting

| Endpoint | Limit |
|----------|-------|
| `POST /api/edge/pair` | 5 per IP per 15 min |
| `POST /api/edge/event` | 60 per device per minute |
| `POST /api/edge/sync` | 5 per device per hour |
| `POST /api/edge/heartbeat` | 30 per device per hour |

---

## 15. Error Handling and Resilience

### 15.1 Network failures

| Scenario | Behavior |
|----------|----------|
| **Auto mode: push fails** | Queued for retry; retry every 30s; after 5 failures → degrade to local-only with banner warning |
| **Auto mode: push succeeds after degradation** | Resume auto-push; drain retry queue; clear warning |
| **End-of-event: sync fails** | Show error with retry button; preserve all local data; retry automatically every 15 min |
| **Heartbeat fails** | Silently retry on next schedule (5 min); edge continues operating normally |
| **Package import fails** | Show error; allow manual "Retry" button; don't start session with incomplete package |
| **Pairing fails** | Show error message; allow user to retry with same or different code |

### 15.2 Database issues

| Scenario | Behavior |
|----------|----------|
| **SQLite corruption** | Edge shows "Database error" page; admin can attempt recovery via SSH or reflash |
| **Disk full** | Edge detects low disk space (< 100MB) and shows warning; prevents new sessions from starting |
| **Migration mismatch** | Edge checks schema version on boot; if migrations are pending, runs them automatically |

### 15.3 Sync integrity

- All sync payloads include checksums for each section.
- Central validates checksums before importing.
- On mismatch: reject the sync with error details; edge retries with fresh data.
- All sync operations are wrapped in database transactions (all-or-nothing).

### 15.4 Edge device recovery

| Problem | Resolution |
|---------|-----------|
| Device lost/stolen | Admin revokes from central; device token becomes invalid |
| Device clock drift | Edge uses central's `synced_at` timestamp from last successful sync as reference; transaction logs use `now()` which may drift — acceptable for queue operations |
| Device reflashed | Fresh golden image → setup wizard runs again → new pairing code needed |
| Admin password forgotten on edge | Reset from central (admin override per `HYBRID_AUTH_ADMIN_FIRST_PRD.md` §3.3 PWD-5); syncs to edge on next package import |

---

## 16. UI on Edge

### 16.1 Modified pages on edge

| Page | Modification |
|------|-------------|
| **Login** | Hide "Sign in with Google" button; hide "Forgot password?" link |
| **Admin Dashboard** | Show only the assigned program; hide program picker; show edge mode banner |
| **Admin Settings** | Read-only; disable all form inputs; show "Configuration is managed from central" |
| **Admin Programs** | Show only assigned program; no create/delete |
| **Admin Users** | Read-only list; no create/edit/delete (users come from central) |
| **Admin Sites** | Hidden entirely on edge |
| **Edge Mode Banner** | Already implemented: amber (offline), green (online+bridge). Enhance with sync status. |
| **Station/Triage/Display** | No changes — works as-is with local data |

### 16.2 New pages on edge

| Page | Route | Purpose |
|------|-------|---------|
| **Setup Wizard** | `/edge/setup` | One-time pairing flow (currently placeholder) |
| **Waiting** | `/edge/waiting` | Polling for assignment (currently placeholder) |
| **Sync Status** | `/edge/sync` | Detailed sync status: last synced, pending records, retry queue, errors |
| **Device Revoked** | `/edge/revoked` | Shown after central revokes the device |

### 16.3 Enhanced Edge Mode Banner

Replace the current banner with a richer status bar:

```
┌──────────────────────────────────────────────────────────────────┐
│ 🟡 Edge Mode — [Program Name]  │  Sync: Auto (3 pending)  │  [Sync Now]  │
│    Last synced: 5 min ago       │  Package: Current ✓       │              │
└──────────────────────────────────────────────────────────────────┘
```

States:
- **Auto + all synced:** Green. "Edge Mode — [Program]. Sync: Up to date."
- **Auto + items pending:** Yellow. "Edge Mode — [Program]. Sync: Auto (N pending)."
- **Auto + connection lost:** Amber. "Edge Mode — [Program]. Sync: Connection lost. Data saved locally."
- **End of Event + session active:** Blue. "Edge Mode — [Program]. Local only. Sync after session."
- **End of Event + session ended + not synced:** Amber. "Edge Mode — [Program]. Session ended. [Sync Now] to upload data."
- **End of Event + synced:** Green. "Edge Mode — [Program]. All data synced."

---

## 17. Edge .env and Configuration

### 17.1 Required edge .env variables

```env
APP_MODE=edge
APP_KEY=base64:...          # Generated during golden image build or wizard
APP_URL=https://flexiqueue.local
APP_ENV=production
APP_DEBUG=false

DB_CONNECTION=sqlite
DB_DATABASE=/var/www/flexiqueue/database/database.sqlite

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=flexiqueue-edge
REVERB_APP_KEY=flexiqueue-edge-key
REVERB_APP_SECRET=flexiqueue-edge-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=6001
REVERB_SCHEME=http

VITE_REVERB_APP_KEY=flexiqueue-edge-key
VITE_REVERB_HOST=flexiqueue.local
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
VITE_REVERB_VIA_PROXY=true

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database

DEMO=false
```

### 17.2 Variables NOT in .env (stored in database)

| Value | Where | Why |
|-------|-------|-----|
| `CENTRAL_URL` | `edge_device_state.central_url` | Set during wizard; database is the source of truth |
| `device_token` | `edge_device_state.device_token` (encrypted) | Never in plaintext in env |
| `sync_mode` | `edge_device_state.sync_mode` | Can change from central without env edit |
| `active_program_id` | `edge_device_state.active_program_id` | Changes on assignment |

### 17.3 Config that the wizard writes to .env

Only essential runtime config that Laravel needs at boot (before DB access):

- `CENTRAL_URL` — needed for `config('app.central_url')` before middleware runs.
- `EDGE_BRIDGE_MODE` — for backward compat; may be deprecated in favor of reading sync_mode from DB.

After writing, the wizard runs `config:clear && config:cache`.

---

## 18. Deployment and Golden Image

### 18.1 Golden image contents

The golden image (flashable `.img`) includes:

- Armbian/Debian base with PHP 8.3, nginx, SQLite3, required PHP extensions.
- FlexiQueue app code at `/var/www/flexiqueue` (from latest edge tarball).
- `APP_MODE=edge` in `.env`.
- `APP_KEY` pre-generated.
- Database migrated (schema only, no data).
- systemd services: `flexiqueue-reverb.service`, `flexiqueue-queue.service`, `flexiqueue-scheduler.service`.
- nginx vhost configured for flexiqueue.
- Self-signed TLS certificate for `flexiqueue.local`.
- `edge_device_state` table exists but is empty → boot triggers setup wizard.

### 18.2 OTA updates — notification-first model

**Decision (Q4 resolved):** Edge devices do **not** auto-update. Updates follow a notification → approval → apply flow.

**How it works:**

1. **Central knows the latest edge version** — set by super admin or CI (stored in `site_settings` or a global config key `edge_latest_version`).
2. **Heartbeat carries version info** — edge sends its current `app_version`; central compares and responds with `{ update_available: true, version: "1.2.3", release_url: "..." }` when a newer version exists.
3. **Edge shows a notification** — a non-blocking banner in the edge admin panel: "Update available: v1.2.3. [View details] [Update now]". The banner does **not** appear during an active session (avoid disruption).
4. **Admin triggers the update** — either:
   - **From the edge** (local admin clicks "Update now") — edge downloads and applies the tarball.
   - **From central** (admin clicks "Push Update" on the Edge Devices page) — central sends an `{ apply_update: true, release_url: "..." }` signal in the next heartbeat response. Edge auto-applies on receipt (only if no active session).
5. **Update procedure** (unchanged mechanics):
   1. Download tarball from the release URL.
   2. Preserve `.env`, `APP_KEY`, and SQLCipher database.
   3. Extract over app directory.
   4. Run `php artisan migrate --force`.
   5. Restart services (`flexiqueue-reverb`, `flexiqueue-queue`, `flexiqueue-scheduler`, nginx).
   6. `php artisan config:cache && php artisan route:cache`.
   7. Edge sends a heartbeat with the new `app_version` to confirm success.
6. **Failure handling** — if the update fails (migration error, download failure), edge reverts to the previous version (kept in a backup directory) and reports the failure in the next heartbeat. Central shows "Update failed" status for the device.

**Safeguards:**
- Updates are blocked while a session is active (both edge-initiated and central-pushed).
- Rollback: the update script keeps a backup of the previous app directory at `/var/www/flexiqueue.bak` and restores it on failure.
- Central tracks `edge_devices.app_version` and `edge_devices.update_status` (`up_to_date`, `update_available`, `updating`, `update_failed`).

### 18.3 Kiosk mode — locked browser on edge

Edge devices are field hardware operated by staff who should interact **only** with the FlexiQueue web interface. The golden image ships in kiosk mode to prevent access to the underlying OS.

#### 18.3.1 Boot-to-browser flow

```
Power on → Armbian auto-login (user: flexiqueue, no password)
         → No desktop environment (no XFCE, GNOME, etc.)
         → Cage/Sway (minimal Wayland compositor) launches
         → Chromium opens in --kiosk mode → https://flexiqueue.local
```

**Components:**

| Component | Role |
|-----------|------|
| **Cage** (or `labwc`) | Minimal Wayland compositor that runs exactly one application fullscreen. No window decorations, no taskbar, no app switcher. Lightweight enough for SBCs. |
| **Chromium `--kiosk`** | Fullscreen, no address bar, no tabs, no dev tools, no right-click context menu. |
| **systemd user service** | `flexiqueue-kiosk.service` auto-starts Cage+Chromium on boot under the `flexiqueue` system user. Restarts automatically if Chromium crashes. |

#### 18.3.2 Lockdown measures

| Vector | Mitigation |
|--------|-----------|
| **Keyboard shortcuts** (Ctrl+Alt+T, Alt+F2, Alt+Tab, Ctrl+Alt+F1–F6) | Cage does not expose TTY switching or app launching. Chromium `--kiosk` disables browser shortcuts. |
| **TTY switching** (Ctrl+Alt+F1–F6) | Disable virtual consoles in systemd: `NAutoVTs=0` and `ReserveVT=0` in `logind.conf`. |
| **USB keyboard escape** | Even with a keyboard plugged in, there is no shell to escape to. The only interactive surface is Chromium rendering FlexiQueue. |
| **SSH** | Disabled by default in the golden image (`systemctl disable sshd`). |
| **Physical serial console** | Available for maintenance (requires opening the device case + UART adapter). This is intentional — it's the recovery path for operators. |
| **File manager / terminal** | Not installed. No desktop environment means no file manager, terminal emulator, or settings GUI. |

#### 18.3.3 Maintenance access

For legitimate admin access (debugging, log retrieval, manual recovery):

| Method | How | When |
|--------|-----|------|
| **Serial console** | UART adapter to the SBC's debug pins | Physical access to device internals |
| **Temporary SSH** | Enable via FlexiQueue edge admin panel: "Enable SSH for 30 minutes" (writes a systemd timer that re-disables after TTL). Requires admin login + confirmation. | Remote debugging during a support call |
| **Reflash** | Write a fresh golden image to the SD card | Factory reset / unrecoverable state |

#### 18.3.4 Chromium policies

Additional Chromium flags/policies applied in the kiosk launcher:

```bash
chromium-browser \
  --kiosk \
  --no-first-run \
  --disable-translate \
  --disable-infobars \
  --disable-session-crashed-bubble \
  --disable-component-update \
  --autoplay-policy=no-user-gesture-required \
  --check-for-update-interval=31536000 \
  --disable-features=Translate \
  --overscroll-history-navigation=0 \
  https://flexiqueue.local
```

- `--autoplay-policy=no-user-gesture-required` — needed for TTS audio playback without user click.
- `--disable-session-crashed-bubble` — prevents "Chromium didn't shut down correctly" dialog.
- `--overscroll-history-navigation=0` — prevents swipe-back on touchscreens from navigating away.

#### 18.3.5 Display and touch considerations

- Edge devices may use HDMI monitors or touchscreen panels.
- Chromium `--kiosk` works with both mouse and touch input.
- If using a touchscreen, the Cage compositor handles touch events natively.
- Screen rotation (for portrait kiosk displays) is configured in Cage's config or via `wlr-randr`.

### 18.4 Version tracking

- Edge reads its version from `storage/app/version.txt` (written during CI build).
- Central tracks each device's version via heartbeat (`edge_devices.app_version`).
- Super admin can see which devices need updates.

---

## 19. Schema Changes Required

### 19.1 New tables (central only)

#### `edge_devices`

Tracks all paired edge devices on central.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `bigint` PK | Auto-increment |
| `site_id` | `bigint` FK → sites | Which site |
| `name` | `varchar(255)` | Human-readable device name |
| `device_token_hash` | `varchar(255)` | Hash of the issued device token (for auth lookup) |
| `id_offset` | `bigint` | The ID range offset assigned to this device |
| `sync_mode` | `enum('auto','end_of_event')` | Current sync mode |
| `supervisor_admin_access` | `boolean` | Default false |
| `assigned_program_id` | `bigint` FK → programs, nullable | Currently assigned program |
| `session_active` | `boolean` | Default false |
| `app_version` | `varchar(50)` nullable | From heartbeat |
| `last_seen_at` | `timestamp` nullable | From heartbeat |
| `last_synced_at` | `timestamp` nullable | From sync/event push |
| `paired_at` | `timestamp` | When pairing completed |
| `revoked_at` | `timestamp` nullable | Null = active; set = revoked |
| `force_cancelled_at` | `timestamp` nullable | Set when central force-cancels an unsynced session (§8.7). Reset to null on next session start. |
| `update_status` | `enum('up_to_date','update_available','updating','update_failed')` | OTA update state (§18.2). Default `up_to_date`. |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

#### `edge_pairing_codes`

Short-lived pairing codes.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `bigint` PK | |
| `site_id` | `bigint` FK → sites | |
| `code_hash` | `varchar(255)` | Hashed code |
| `device_name` | `varchar(255)` | Pre-filled from "Add Device" |
| `expires_at` | `timestamp` | TTL |
| `consumed_at` | `timestamp` nullable | Set on successful pairing |
| `created_at` | `timestamp` | |

#### `edge_sync_queue` (edge only)

Retry queue for auto-mode events that failed to push.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `bigint` PK | |
| `event_type` | `varchar(50)` | `transaction_log`, `session_update`, `client_create`, etc. |
| `payload` | `text` (JSON) | The event data |
| `attempts` | `integer` | Retry count |
| `last_attempted_at` | `timestamp` nullable | |
| `created_at` | `timestamp` | |

#### `edge_sync_conflicts` (central only)

Conflict log.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `bigint` PK | |
| `edge_device_id` | `bigint` FK → edge_devices | |
| `table_name` | `varchar(100)` | |
| `record_id` | `bigint` | |
| `conflict_type` | `varchar(50)` | |
| `resolution` | `text` | |
| `created_at` | `timestamp` | |

### 19.2 Modified tables

#### `programs`

| Column | Change |
|--------|--------|
| `edge_locked_by_device_id` | **Add**: `bigint` nullable FK → `edge_devices.id`. Null = not locked. |

#### `sites.settings` (JSON)

| Key | Change |
|-----|--------|
| `max_edge_devices` | **Add**: integer, default 0 |

#### `edge_device_state` (edge only — already exists)

| Column | Change |
|--------|--------|
| `sync_mode` | **Modify**: enum values from `('bridge','sync')` to `('auto','end_of_event')` |
| `id_offset` | **Add**: `bigint` nullable — the ID offset for this device |
| `app_version` | **Add**: `varchar(50)` nullable |
| `package_version` | **Add**: `varchar(255)` nullable — hash of last imported package |

#### `queue_sessions`, `transaction_logs`, `clients`, `identity_registrations`, `program_audit_log`, `staff_activity_log`

| Column | Change |
|--------|--------|
| `edge_device_id` | **Add**: `bigint` nullable. Null = created on central. |

---

## 20. API Endpoints (New and Modified)

### 20.1 Edge pairing and lifecycle (central-side)

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `POST` | `/api/edge/pair` | Pairing code (in body) | Exchange code for device token + site binding |
| `GET` | `/api/edge/assignment` | Device token | Poll: returns assigned program + package version (or null) |
| `POST` | `/api/edge/heartbeat` | Device token | Update last_seen; receive config changes |
| `POST` | `/api/edge/event` | Device token | Auto mode: push a single event to central |
| `POST` | `/api/edge/sync` | Device token | End-of-event mode: push full session data batch |
| `POST` | `/api/edge/session/start` | Device token | Notify central: session started; lock program |
| `POST` | `/api/edge/session/end` | Device token | Notify central: session ended; release lock; trigger sync |
| `POST` | `/api/edge/session/dump` | Device token | Central-initiated: edge pushes data + ends session |
| `GET` | `/api/ping` | None | Simple health check for `isOnline()` detection |

### 20.2 Central admin APIs for edge management

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `GET` | `/api/admin/sites/{site}/edge-devices` | Admin (site/super) | List edge devices for a site |
| `POST` | `/api/admin/sites/{site}/edge-devices/pairing-code` | Admin | Generate a pairing code |
| `PUT` | `/api/admin/edge-devices/{device}` | Admin | Update: assign program, change mode, toggle supervisor |
| `POST` | `/api/admin/edge-devices/{device}/dump` | Admin | Signal dump (notified on next heartbeat) |
| `POST` | `/api/admin/edge-devices/{device}/force-cancel` | Admin | Force-cancel an unsynced session on an unreachable device (§8.7) |
| `POST` | `/api/admin/edge-devices/{device}/push-update` | Admin | Signal device to apply OTA update on next heartbeat (§18.2) |
| `DELETE` | `/api/admin/edge-devices/{device}` | Admin | Revoke device |

---

## 21. Phased Delivery

### Phase E1 — Foundation (no UI)

| # | Task | Depends on |
|---|------|-----------|
| E1.1 | `edge_devices` + `edge_pairing_codes` migrations (central) | — |
| E1.2 | `EdgeDevice` + `EdgePairingCode` Eloquent models | E1.1 |
| E1.3 | Add `edge_locked_by_device_id` to `programs` | E1.1 |
| E1.4 | Add `max_edge_devices` to site settings validation | — |
| E1.5 | Modify `edge_device_state` migration: new enum values + new columns | — |
| E1.6 | Add `edge_device_id` nullable to session/log/client tables (both central and edge migration) | — |
| E1.7 | `edge_sync_queue` + `edge_sync_conflicts` migrations | — |
| E1.8 | Implement `isOnline()` in `EdgeModeService` (HTTP ping + 30s cache) | — |
| E1.9 | `POST /api/ping` endpoint on central | — |
| E1.10 | Disable Google OAuth routes when `APP_MODE=edge` | — |
| E1.11 | PHPUnit tests for E1.1–E1.10 | E1.1–E1.10 |

### Phase E2 — Pairing and Device Management

| # | Task | Depends on |
|---|------|-----------|
| E2.1 | `EdgePairingService` — generate code, validate, consume | E1.2 |
| E2.2 | `POST /api/edge/pair` endpoint | E2.1 |
| E2.3 | `EdgeDeviceSetupService` — write edge_device_state, update .env | E1.5 |
| E2.4 | Setup wizard Svelte pages (3 steps) | E2.2, E2.3 |
| E2.5 | `/edge/waiting` Svelte page with poll | E2.4 |
| E2.6 | `EdgeBootGuard` middleware update (wizard → waiting → normal) | E2.4, E2.5 |
| E2.7 | `GET /api/edge/assignment` endpoint | E1.2 |
| E2.8 | `POST /api/edge/heartbeat` endpoint | E1.2 |
| E2.9 | `edge:heartbeat` scheduled command on edge | E2.8 |
| E2.10 | PHPUnit tests for E2.1–E2.9 | E2.1–E2.9 |

### Phase E3 — Central Management UI

| # | Task | Depends on |
|---|------|-----------|
| E3.1 | Edge Devices admin page (list + add + assign) | E2.1, E2.7 |
| E3.2 | Pairing code generation UI | E2.1 |
| E3.3 | Device assignment UI (program dropdown, mode, supervisor toggle) | E3.1 |
| E3.4 | Program lock badge on Programs list/detail | E1.3 |
| E3.5 | Super admin: `max_edge_devices` on site settings | E1.4 |
| E3.6 | Device status display (online/idle/offline/stale) | E2.8 |
| E3.7 | Revoke device action | E3.1 |
| E3.8 | PHPUnit + Playwright tests for E3.1–E3.7 | E3.1–E3.7 |

### Phase E4 — Program Locking and Session Control

| # | Task | Depends on |
|---|------|-----------|
| E4.1 | Program lock service (lock/unlock/check) | E1.3 |
| E4.2 | Block central session start for locked programs | E4.1 |
| E4.3 | `POST /api/edge/session/start` endpoint | E4.1 |
| E4.4 | `POST /api/edge/session/end` endpoint | E4.1 |
| E4.5 | Dump session flow (central signal → edge push → unlock) | E4.3, E4.4 |
| E4.6 | Session-active guard on device reassignment | E4.1 |
| E4.7 | `POST /api/admin/edge-devices/{device}/force-cancel` endpoint + `ForceCancel` service logic (§8.7) | E4.1 |
| E4.8 | Edge-side handling of `session_voided` heartbeat signal (archive local data, transition to waiting) | E4.7, E2.9 |
| E4.9 | PHPUnit tests for E4.1–E4.8 | E4.1–E4.8 |

### Phase E5 — Sync: Auto Mode

| # | Task | Depends on |
|---|------|-----------|
| E5.1 | `POST /api/edge/event` endpoint (central-side) | E1.2 |
| E5.2 | `EdgeEventPushService` (edge-side: push after local write) | E1.8 |
| E5.3 | `edge_sync_queue` retry logic + `EdgeSyncRetryJob` | E1.7, E5.2 |
| E5.4 | Auto-degrade to local-only on persistent failures | E5.3 |
| E5.5 | Resume auto-push when connectivity returns | E5.4 |
| E5.6 | Wire into `SessionService`, `FlowEngine`, `ClientService` | E5.2 |
| E5.7 | PHPUnit tests for E5.1–E5.6 | E5.1–E5.6 |

### Phase E6 — Sync: End of Event Mode

| # | Task | Depends on |
|---|------|-----------|
| E6.1 | `POST /api/edge/sync` endpoint (central-side batch import) | E1.2 |
| E6.2 | `EdgeBatchSyncService` (edge-side: collect + push) | — |
| E6.3 | Sync Now button and sync status UI | E6.2 |
| E6.4 | Scheduled auto-sync (`edge:auto-sync` command) | E6.2 |
| E6.5 | Partial sync / resume with acknowledgment tracking | E6.1 |
| E6.6 | Sync receipt display in edge admin | E6.1 |
| E6.7 | PHPUnit tests for E6.1–E6.6 | E6.1–E6.6 |

### Phase E7 — Package Versioning and Freshness

| # | Task | Depends on |
|---|------|-----------|
| E7.1 | Add `package_version` to export manifest | — |
| E7.2 | Staleness check in assignment poll response | E2.7, E7.1 |
| E7.3 | Auto re-import when package is stale (waiting state) | E7.2 |
| E7.4 | "Package outdated" info banner during active session | E7.2 |
| E7.5 | PHPUnit tests for E7.1–E7.4 | E7.1–E7.4 |

### Phase E8 — Enhanced Edge UI

| # | Task | Depends on |
|---|------|-----------|
| E8.1 | Enhanced edge mode banner (sync status, package version, pending count) | E5.2, E6.3 |
| E8.2 | Edge admin: read-only mode enforcement on all admin pages | — |
| E8.3 | Edge admin: hide/disable Google auth, forgot password, user management write actions | E1.10 |
| E8.4 | `/edge/sync` status page | E6.3 |
| E8.5 | `/edge/revoked` page | E2.6 |
| E8.6 | Playwright E2E for edge UI flows | E8.1–E8.5 |

### Phase E9 — Conflict Resolution and Audit

| # | Task | Depends on |
|---|------|-----------|
| E9.1 | ID offset assignment in pairing | E2.1 |
| E9.2 | SQLite auto-increment offset configuration on edge | E9.1 |
| E9.3 | Central-side conflict detection and resolution in sync endpoints | E5.1, E6.1 |
| E9.4 | `edge_sync_conflicts` logging | E1.7, E9.3 |
| E9.5 | Super admin conflict audit view | E9.4 |
| E9.6 | PHPUnit tests for E9.1–E9.5 | E9.1–E9.5 |

### Phase E10 — Hardening, OTA, and Edge Cases

| # | Task | Depends on |
|---|------|-----------|
| E10.1 | Stale device detection and admin warnings | E2.8 |
| E10.2 | Disk space monitoring on edge | — |
| E10.3 | Graceful degradation banner improvements | E5.4 |
| E10.4 | Device token rotation via heartbeat | E2.8 |
| E10.5 | Rate limiting on all edge API endpoints | E5.1, E6.1 |
| E10.6 | OTA notification-first flow: heartbeat carries `update_available`, edge shows banner, admin triggers update (§18.2) | E2.8 |
| E10.7 | `POST /api/admin/edge-devices/{device}/push-update` endpoint (central pushes update signal) | E10.6 |
| E10.8 | Edge-side OTA apply script with rollback on failure | E10.6 |
| E10.9 | `update_status` tracking on `edge_devices` table + central UI indicator | E10.6, E10.7 |
| E10.10 | Force-cancel UI: "Force Cancel Session" button on Edge Devices page (only for offline+session_active devices) | E4.7 |
| E10.11 | Full integration testing: golden image → pair → assign → session → sync → dump → re-assign | All |

### Phase E11 — SQLCipher, Source Protection, and Kiosk Mode (Golden Image)

These tasks are **deployment/image-level** — they affect the golden image build, not the Laravel application code (except the SQLCipher connection config).

| # | Task | Depends on |
|---|------|-----------|
| E11.1 | Build PHP with SQLCipher support in golden image (replace `libsqlite3` with `sqlcipher`) | — |
| E11.2 | Laravel SQLite connection config: issue `PRAGMA key` on connect using derived `APP_KEY` hash (§14.4) | E11.1 |
| E11.3 | Verify existing migrations and queries work transparently with SQLCipher | E11.2 |
| E11.4 | Filesystem lockdown: app dir ownership `root:www-data 750`, only `storage/` + `bootstrap/cache/` writable (§14.6) | — |
| E11.5 | Disable SSH by default in golden image; add "Enable SSH for 30 min" toggle in edge admin (§18.3.3) | — |
| E11.6 | Cage + Chromium kiosk systemd service (`flexiqueue-kiosk.service`) — auto-start fullscreen browser on boot (§18.3) | — |
| E11.7 | Disable TTY switching (`NAutoVTs=0`) and remove desktop packages from golden image | E11.6 |
| E11.8 | Chromium policy flags: `--kiosk`, `--autoplay-policy`, `--disable-session-crashed-bubble`, etc. (§18.3.4) | E11.6 |
| E11.9 | Auto-restart Chromium on crash (systemd `Restart=always`) | E11.6 |
| E11.10 | Touchscreen and screen rotation configuration in Cage | E11.6 |
| E11.11 | Golden image build script integrating E11.1–E11.10 | E11.1–E11.10 |
| E11.12 | Manual QA: boot golden image on Orange Pi, verify kiosk lockdown, SQLCipher, OTA flow | E11.11 |

---

## 22. Risks and Mitigations

| Risk | Severity | Mitigation |
|------|----------|-----------|
| **SQLite data loss on edge (SD card failure)** | High | Encourage auto-sync mode for critical events; sync confirmation tracking so central knows what it has received; backup SQLite to second partition nightly |
| **ID collision despite offset** | Medium | 10M range per device is generous (would need 10M sessions per event to overflow); monitor and alert if any device approaches 80% of its range |
| **Clock drift on edge** | Medium | Use `created_at` from edge as-is (queue ordering is local); central records `synced_at` separately; document that edge timestamps are edge-local |
| **Network flapping in auto mode** | Medium | Retry queue + auto-degrade + auto-resume; no data loss since local DB is always written first |
| **Pairing code interception** | Low | 10-min TTL + single-use + HTTPS; code only grants access to an empty device slot, not to data |
| **Edge device stolen** | Medium | Revoke from central; device token invalidated; SQLite contains only one program's data; no admin credentials stored beyond hashed passwords |
| **Central outage during edge session** | Medium | Edge operates fully independently; data waits in local DB and sync queue until central is back |
| **Package too large to sync (huge TTS library)** | Low | TTS sync is optional per `edge_settings.sync_tts`; incremental sync (v2) would reduce payload further |
| **Multiple edges, same program needed** | Low (v1) | Not supported in v1; workaround: clone program on central, assign clone to second edge |
| **Force-cancelled session data permanently lost** | Medium | Admin confirmation dialog warns that data is unrecoverable; edge archives locally if it reconnects; accepted trade-off for unblocking the program |
| **OTA update bricks edge device** | Medium | Rollback mechanism (backup app dir); updates blocked during active sessions; notification-first model gives admin control; reflash as last resort |
| **Kiosk escape via USB keyboard** | Low | No shell, no TTY, no desktop; Cage compositor exposes only Chromium; determined attacker would need serial console access (physical device internals) |
| **SQLCipher performance on low-power SBC** | Low | AES-256-CBC adds ~5–15% overhead; acceptable for queue workloads; benchmark on target hardware during E11 QA |
| **Source code extraction from SD card** | Medium | Kiosk + filesystem permissions prevent casual access; SQLCipher protects data; source remains readable on extracted SD (accepted unless PHP obfuscation added in v2) |

---

## 23. Resolved Questions

All questions from the initial draft have been resolved. Decisions are recorded here for traceability; implementation details are folded into the relevant sections above.

| # | Question | Decision | Rationale | Affects sections |
|---|----------|----------|-----------|-----------------|
| 1 | Should edges generate TTS locally (e.g., Piper)? | **No.** Edges do not generate TTS. All TTS audio is pre-generated on central and synced via package. Browser SpeechSynthesis remains the fallback for missing audio. | Keeps edge image small, avoids GPU/CPU requirements on low-power SBCs, and centralises ElevenLabs budget control. | §12 |
| 2 | Shared program mode (multiple edges, same program)? | **No (v1).** One program per edge, exclusively locked. Workaround: clone the program on central and assign the clone to a second edge. | Avoids token ID collisions, queue session conflicts, and complex merge logic. Revisit in v2 if field demand emerges. | §3.4 |
| 3 | What happens to edge data if the device never syncs back? | **Central can force-cancel the session.** Central marks the session as cancelled, releases the program lock, and flags the device as "unsynced-cancelled." If the edge later comes online it receives a "session voided" signal and discards or archives its local data. See §8.7. | Prevents a permanently locked program from one unreachable device. Data loss is accepted — the admin makes a conscious choice to cancel. | §8.7, §11.2 |
| 4 | Should edge devices auto-update OTA? | **No auto-update.** Notification-first model: central tells the edge (via heartbeat) that a new version is available. Edge shows a notification to the local admin. The admin (on edge or from central) explicitly triggers the update. See §18.2. | Avoids bricking a field device mid-session; gives operators control over when downtime occurs. | §18.2 |
| 5 | Sync receipt: per-record status or totals? | **Totals only (v1).** The receipt returns aggregate counts per table plus a `conflicts` array listing only records that triggered conflict resolution. | Good enough for debugging without O(N) response payloads. Per-record status can be added in v2 if conflict rates warrant it. | §6.3 |
| 6 | Maximum acceptable sync delay (auto mode)? | **60-second SLA under normal conditions.** Events should appear on central within 60 seconds of being created on edge. Retry interval: 30 s. Degradation threshold: 5 consecutive failures (~2.5 min). | Matches the existing retry logic; sets a concrete expectation without requiring real-time WebSocket relay. | §4.2 |
| 7 | Encrypt SQLite at rest (SQLCipher)? | **Yes.** Use SQLCipher for the SQLite database on edge. The encryption key derives from `APP_KEY` (already unique per device). See §14.4. | Protects queue data, client PII, and hashed passwords if the SD card is physically removed. Acceptable performance overhead on modern SBCs (ARM Cortex-A55+). | §14.4 |

---

## Document History

| Date | Change |
|------|--------|
| **2026-03-23** | Initial comprehensive plan: vision, auth, program locking, sync modes (auto/end-of-event), package versioning, device allocation, lifecycle, central UI, ID strategy, conflict resolution, TTS, broadcasting, security, error handling, edge UI, deployment, schema, APIs, phased delivery, risks, open questions. |
| **2026-03-23** | Resolved all 7 open questions. Added: §8.7 central force-cancel for unsynced sessions; §14.4 SQLCipher encryption at rest; §14.6 source code protection; §14.7 rate limiting (renumbered); §18.2 OTA notification-first model; §18.3 kiosk mode (locked browser); §18.4 version tracking (renumbered). Added Phase E11 (SQLCipher, source protection, kiosk). Updated Phase E4 (force-cancel tasks), Phase E10 (OTA notification tasks). Added 60-second auto-sync SLA to §4.2. Added force-cancel conflict scenario to §11.2. Added new risks. Added `force-cancel` and `push-update` admin API endpoints to §20.2. Added `force_cancelled_at` and `update_status` columns to `edge_devices` schema. |
