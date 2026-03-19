# FlexiQueue: Central + Edge — Final Implementation Plan (v2-Final)

**Status:** Future feature — not in active scope until explicitly prioritized.  
**Supersedes:** `central-edge-v2-robust.md` (which critiqued and expanded v1)  
**This document:** The definitive, final plan with all open decisions resolved, all critiques addressed, and all phases fully specified.

---

## Resolved Decisions

All five open decisions from the v2-robust plan are now closed:

| # | Decision | Resolution | Rationale |
|---|----------|------------|-----------|
| 1 | Which clients go into package? | **Mode-dependent** — see §Decision 1 below | Offline edge gets `program_history`; edge-bridge gets `all` since connected to server |
| 2 | Can new users be created on Pi? | **Yes, with constraints** — see §Decision 2 below | Users can be created offline but without ID binding; edge-bridge enables direct verified binding |
| 3 | Pi data protection level? | **Armbian full-disk encryption** (documented in deployment guide) | Minimum viable protection for PII on removable media |
| 4 | Max chunk size for sync upload? | **200 records** per chunk | Balances payload size and retry cost |
| 5 | Scheduled sync time configurable? | **Per-site** — stored in `edge_settings.scheduled_sync_time` | Different sites may have different operating hours |

### §Decision 1: Client Packaging Strategy (Mode-Dependent)

The client sync strategy differs by connectivity tier:

| Edge Tier | `sync_clients` | Client Scope | ID Binding Behavior |
|-----------|----------------|-------------|---------------------|
| **Offline Edge** (no internet, no bridge) | `true` | `program_history` — only clients who have had sessions in this program | Bindings are created as **unverified** (`binding_status = 'unconfirmed'`). Sessions proceed through the queue with unverified bindings, similar to current `allow_unverified` behavior. No ID binding page is shown. |
| **Edge-Bridge** (connected to central) | Fetched live via bridge — no package dependency | `all` — full client database accessible via API | Full ID binding with real-time verification against central. Bindings are **verified** on creation. |

**Unverified bindings — sync reconciliation (future feature):**

When offline-edge sessions sync to central, unverified bindings are flagged for review. An admin can then:
- **Confirm** the binding (client data matches a real account)
- **Match** the binding to an existing client account on central
- **Attach** a new ID document to the matched/confirmed account
- **Reject** the binding (mark as orphaned — data stays for audit)

> [!NOTE]
> The sync reconciliation/data cleaning UI is a **future feature** — not part of this plan. This plan only ensures the data model supports it (via `binding_status` on synced sessions and a `sync_binding_review` table on central).

### §Decision 2: User Creation on Edge Pi

| Edge Tier | Can Create Users (Client Records)? | ID Binding Page? | Binding Behavior |
|-----------|-----------------------------------|-------------------|-----------------|
| **Offline Edge** | ✅ Yes — staff can create new client records locally | ❌ **Not shown** — no access to central's ID verification | New clients remain **unbound** (no `client_id_documents` created). They can still be bound to sessions by name/birth_year match only. |
| **Edge-Bridge** | ✅ Yes — proxied to central via bridge API | ✅ **Shown** — full ID binding page with real-time verification | Clients are created on central; ID verification happens immediately. Binding is confirmed. |

**Offline-created clients — sync reconciliation (future feature):**

When synced to central, offline-created clients are handled by the same reconciliation system as Decision 1:
- Match to existing central client by `id_number_hash` (if hash was available in package)
- Create new central client record if no match
- Admin reviews unmatched clients and can merge/link accounts

> [!NOTE]
> This reconciliation feature is deferred to a future iteration. This plan ensures the sync payload includes locally-created clients with sufficient data for future matching.

---

## System-Level Success Criteria (Binding on Every Phase)

1. Central holds all programs, clients, tokens, users. Single source of truth.
2. A Pi can run a complete queue session offline — bind through complete — with local data only.
3. On reconnect, Pi uploads all session data and logs. Central's dashboard is complete.
4. In bridge mode, Pi proxies client/identity operations to central in real time and falls back gracefully.
5. Staff see accurate UI signals: bridge/offline state, pending sync count, last sync time.
6. Central serves multiple programs simultaneously with independent display boards.
7. No `id_number_encrypted` ever leaves central. Pi holds only name, birth_year, and hashes.
8. Offline-created bindings are flagged `unconfirmed` and allowed to proceed through the queue.
9. Edge-bridge bindings are verified against central in real time.

---

## Dependency Order

```
Phase A: Multi-Program Foundation
    ↓
Phase B: Multi-Tenant / Sites ──────┐ (parallel after A)
Phase C: Token–Program Association ─┘
    ↓ (both must finish before D starts)
Phase D: Program Package API        ← GATE
    ↓
Phase E: Bridge Layer ─────────────┐ (parallel after D spec is complete)
Phase F: Edge Mode App (+ source column) ┘
    ↓ (both must finish)
Phase G: Sync API (Pi → Central)
    ↓
Phase H: Analytics Views
```

**Critical path:** A → D → F → G

**Sequencing note:** `source` column on `queue_sessions` is introduced in Phase F, not Phase H. `binding_status` column is introduced in Phase F alongside `source`.

---

## Pre-Work: Test Coverage Baseline (Before Phase A Begins)

This is not a phase — it is a mandatory gate before Phase A touches a single line.

**Required before Phase A:**

1. Write integration tests covering the full session lifecycle end-to-end:
   - Bind a token to a session
   - Call the session at a station
   - Serve the session
   - Transfer to next station
   - Complete the session
   - Verify `transaction_logs` contains all expected entries in correct order
2. Write tests covering display board events: binding a session fires `ClientArrived` on the correct channel; completing fires the correct completion events.
3. Write tests covering triage: staff triage binds correctly; public triage with identity registration binds correctly.
4. Confirm all 21 files have tests exercising their current behavior before any file is changed.

**Rollback plan for Phase A:**
- Before starting, tag the current git HEAD as `pre-phase-a-stable`.
- Phase A is implemented on a feature branch, never directly on main until all success criteria pass.
- If Phase A is deployed to Pi and a regression is detected mid-payout, the rollback is: `git checkout pre-phase-a-stable && php artisan migrate:rollback` back to the pre-A migration state. Session data is preserved because Phase A adds no destructive migrations — it is a code-only refactor.

---

## Phase A — Multi-Program Foundation

### What this phase does

Removes the single-active-program assumption from all 21 locations. This is a code refactor — no data is destroyed, no migrations are destructive.

### How `$programId` is resolved at the request boundary

| Request context | How `$programId` is resolved |
|----------------|------------------------------|
| Staff at `/station/{station}` | `station.program_id` — the station knows its program |
| Staff triage at `/triage` | Auth middleware resolves from `user.assigned_station_id → station.program_id`. If user has no station assignment, return 422 with "No station assigned." |
| Public triage at `/public/triage` | URL segment: `/public/triage/{program}`. Requires program to be explicitly in the URL. |
| Display board at `/display` | Query param: `?program={id}`. If absent, show program selector. Unauthenticated route — no session context available. |
| Admin pages | Admin selects program from sidebar or URL. Admin can see all programs regardless of site. |
| `HandleInertiaRequests.php` | Passes `programs` (plural, all active) to admin pages; passes `currentProgram` (resolved per context above) to station/triage pages. **No longer passes a single `program` object globally.** |

### Frontend compatibility plan for `HandleInertiaRequests` change

Currently all 24 Svelte pages receive a single `program` prop via shared Inertia data. After Phase A, this prop changes. The strategy:

1. Introduce `currentProgram` (nullable) as the new scoped prop. Keep `program` as a deprecated alias for `currentProgram` during the transition — same object, two names.
2. Pages that only work in a program context (station, triage, display board) receive `currentProgram` from their specific controller, not from shared data.
3. Admin pages that list all programs receive `programs` (array) from shared data.
4. Remove the deprecated `program` alias only after all 24 pages have been updated to use `currentProgram` or `programs`. Grep confirms zero remaining `$page.props.program` references.

### The ProgramService activation change

- Remove the "deactivate all others" logic from `activate()`.
- Add `ProgramService::activateExclusive($programId)` that preserves the old single-program behavior — needed for edge mode where exactly one program is active on the Pi.
- Default `activate()` no longer deactivates anything. `activateExclusive()` is called explicitly where single-program semantics are required (Pi import command in Phase D).

### Broadcasting channel migration

| Old channel | New channel | Notes |
|-------------|-------------|-------|
| `display.activity` | `display.activity.{programId}` | All station events scoped to program |
| `display.station.{id}` | Unchanged — station ID is already unique | No change needed |
| `global.queue` | `queue.{programId}` | Scoped per program |
| `station.{id}` | Unchanged | Station ID is unique; program is implicit |

Frontend subscribers (Svelte display board, station page) must update their Echo channel subscriptions to include `programId`.

Implementation notes:

- Backend events `DisplaySettingsUpdated`, `StationActivity`, `ProgramStatusChanged`, `StaffAvailabilityUpdated`, `NowServing`, and `QueueLengthUpdated` all accept an explicit `programId` and broadcast only on `display.activity.{programId}` and/or `queue.{programId}` (plus unchanged `display.station.{id}`), verified by `tests/Unit/Events/BroadcastingChannelsTest.php`.
- Frontend Echo subscriptions are program-scoped only: `resources/js/Pages/Display/Board.svelte` subscribes to `display.activity.{programId}` and `queue.{programId}` using `effectiveCurrentProgram.id`, and `resources/js/Pages/Triage/PublicStart.svelte` subscribes to `display.activity.{program_id}` from the public triage context; there are no remaining listeners on bare `display.activity` or `global.queue`.

### ✅ Phase A Success Criteria

- [ ] `grep -r "where('is_active', true)->first()" app/` returns zero results.
- [ ] `grep -r "->first()" app/ | grep program` is reviewed manually; any remaining instance is justified with a comment.
- [ ] Two programs (`Program A`, `Program B`) both active simultaneously. Bind 5 sessions to A, 5 to B. `/display?program=1` shows only A's queue. `/display?program=2` shows only B's queue. Zero cross-contamination in display events.
- [ ] Staff assigned to a station in Program A cannot see Program B's sessions at their station.
- [ ] Public triage at `/public/triage/{programA}` binds to Program A. `/public/triage/{programB}` binds to Program B. Confirmed by `queue_sessions.program_id` on each.
- [ ] `HandleInertiaRequests` change: admin dashboard receives `programs` array. Station page receives `currentProgram` object. Zero Svelte pages throw a prop-undefined error in the browser console.
- [ ] `ProgramService::activate()` does not deactivate other programs. `ProgramService::activateExclusive()` does.
- [ ] All pre-work integration tests pass. No regressions. Test suite is green.
- [ ] Feature branch passes CI. Merged to main only after all criteria above are verified.

---

## Phase B — Multi-Tenant / Sites

### Schema

```sql
CREATE TABLE sites (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(255) NOT NULL,
    slug           VARCHAR(100) NOT NULL UNIQUE,   -- SITE_ID in Pi .env
    api_key_hash   VARCHAR(255) NOT NULL UNIQUE,   -- bcrypt hash of raw key; raw key shown once
    settings       JSON,
    edge_settings  JSON NOT NULL DEFAULT '{}',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

ALTER TABLE programs ADD COLUMN site_id BIGINT UNSIGNED NULL REFERENCES sites(id);
ALTER TABLE users    ADD COLUMN site_id BIGINT UNSIGNED NULL REFERENCES sites(id);
```

Note: `api_key_hash` — not `api_key`. Raw key is generated once, shown to admin once, never stored. Central stores only the hash.

### API key lifecycle

1. Admin creates a site on central.
2. Central generates a cryptographically random 40-character key (e.g., `sk_live_...`).
3. Central bcrypt-hashes the key and stores `api_key_hash`.
4. Central shows the raw key to the admin **once** in the creation response. Admin copies it to the Pi's `.env` as `CENTRAL_API_KEY`.
5. If the key is lost, admin clicks "Regenerate" — new key generated, hash updated, Pi `.env` must be updated manually.
6. On sync upload: Pi sends `Authorization: Bearer {raw_key}`. Central bcrypt-verifies against `api_key_hash`.

### `edge_settings` schema (full, validated)

```json
{
  "sync_clients": true,
  "sync_client_scope": "program_history",
  "sync_tokens": true,
  "sync_tts": true,
  "bridge_enabled": false,
  "offline_binding_mode_override": "optional",
  "scheduled_sync_time": "17:00",
  "offline_allow_client_creation": true
}
```

Validated on save with a JSON Schema validator. Unknown keys rejected. Enum values enforced. Defaults applied if a key is absent.

### Existing data migration

Pre-existing programs and users (from before sites was introduced) are assigned to a `default` site automatically by the migration seeder. The default site has `bridge_enabled = false` and `sync_clients = false` — conservative defaults that do not change existing behavior.

### ✅ Phase B Success Criteria

- [ ] `sites` table migrated on SQLite and MariaDB. Both migration paths tested in CI.
- [ ] Creating a site returns the raw `api_key` exactly once. A subsequent `GET /api/admin/sites/{id}` never returns the raw key — only a masked indicator (`sk_live_...****`).
- [ ] Attempting to sync with an invalid API key returns 401. Attempting with a revoked key (regenerated) returns 401.
- [ ] `edge_settings` with an unknown key (e.g., `"foo": "bar"`) returns 422 on save.
- [ ] Programs from Site A are not visible in Site B's program list.
- [ ] Existing programs are assigned to the default site and continue working without any behavioral change.

---

## Phase C — Token–Program Association

### Schema

```sql
CREATE TABLE program_token (
    program_id BIGINT UNSIGNED NOT NULL REFERENCES programs(id),
    token_id   BIGINT UNSIGNED NOT NULL REFERENCES tokens(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (program_id, token_id)
);
```

### Known limitation — formalized

Token deactivation on central does not propagate to Pi until the next package re-download. This is a documented limitation of offline operation.

**Mitigation at the package level:** The package `manifest.json` includes a `token_deactivation_list` — an array of token UUIDs that have been deactivated on central since the last package export for this site. Pi's import command applies these deactivations locally.

### ✅ Phase C Success Criteria

- [ ] `program_token` pivot migrated (SQLite + MariaDB).
- [ ] A token can be in zero, one, or many programs simultaneously via the pivot.
- [ ] Assigning a token to a program does not affect its `status` field or any active session.
- [ ] Package exporter (verified in Phase D) only includes tokens present in the pivot for the target program.
- [ ] Bulk assignment: admin can assign a range (e.g., tokens with `physical_id` matching `A*`) to a program in one operation.

---

## Phase D — Program Package API

### Package format

```
flexiqueue-package-{program_uuid}-{timestamp}.tar.gz
├── manifest.json
├── program.json
├── stations.json
├── service_tracks.json
├── processes.json
├── station_process.json          ← Explicit M:M join table export
├── users.json
├── tokens.json                   (if sync_tokens=true)
├── clients.json                  (if sync_clients=true, scope=program_history)
├── id_document_hashes.json
├── identity_registrations.json   ← Existing registrations for this program
├── print_settings.json
├── program_diagram.json
├── temporary_authorizations.json
├── edge_settings.json
└── tts/
    ├── tokens/
    └── stations/
```

### Client packaging rules (Decision 1 applied)

| Edge Tier | `sync_clients` | Client Scope | Rationale |
|-----------|----------------|-------------|-----------|
| Offline Edge | `true` | `program_history` | Minimal, relevant subset for offline lookup |
| Edge-Bridge | N/A (live via API) | `all` (fetched from central) | Full database accessible via bridge |

**`clients.json` contents (when `sync_clients=true`):**
- `name`, `birth_year`, `uuid` — for matching
- Associated `id_document_hashes` — for hash-based ID lookup
- **Never:** `id_number_encrypted`

### UUID strategy — complete specification

Add `uuid` column (CHAR(36), `DEFAULT (UUID())`, immutable after creation) to:

| Table | Why |
|-------|-----|
| `queue_sessions` | Pi creates sessions; central must identify them on sync |
| `transaction_logs` | Pi creates logs; central must merge them idempotently |
| `clients` | New clients created via bridge must be deduplicated on sync |
| `client_id_documents` | New ID documents created via bridge must be linked |
| `identity_registrations` | Registrations proxied via bridge have a central ID; Pi needs UUID for sync reference |
| `programs` | Package manifest references program by UUID, not integer ID |
| `tokens` | Package references tokens by UUID for cross-instance matching |
| `stations` | Sessions reference `current_station_id`; sync must map Pi station IDs to central station IDs |

SQLite: `uuid TEXT NOT NULL DEFAULT (lower(hex(randomblob(4))) || '-' || lower(hex(randomblob(2))) || '-4' || substr(lower(hex(randomblob(2))),2) || '-' || substr('89ab',abs(random()) % 4 + 1, 1) || substr(lower(hex(randomblob(2))),2) || '-' || lower(hex(randomblob(6))))`.  
MariaDB: `uuid CHAR(36) NOT NULL DEFAULT (UUID())`.

Both must be covered in migration. Generate UUID in PHP model `booted()` as a fallback for any driver that doesn't support default expressions.

### Package import — re-run semantics

When importing a package onto a Pi that already has local session data:

| Entity type | Import action | Conflict rule |
|------------|--------------|---------------|
| Program config (settings, name) | Always overwrite | Central is authoritative for config |
| Stations | Upsert by UUID | Central is authoritative for config. If a station was deleted on central: mark `is_active = false` on Pi if no active sessions reference it. If active sessions exist at that station: do NOT delete — flag as `orphaned_on_central = true`, let the session complete, then deactivate. |
| Tracks + steps | Overwrite entirely | No sessions reference track steps directly during execution |
| Processes | Upsert by UUID | Central authoritative |
| Users | Upsert by UUID | Central authoritative. Never delete a user record — only deactivate. |
| Tokens | Upsert by UUID. Apply `token_deactivation_list`. | Central authoritative for status. |
| Clients | Upsert by UUID. Never overwrite name/birth_year if Pi has a locally-created client with the same UUID. | Pi authoritative for records it created locally. |
| TTS files | Replace if `sync_tts=true` and file hash differs | Use manifest checksums to skip unchanged files. |
| Existing local sessions | **Never touched by import** | Sessions created on Pi are Pi's data. Import never modifies, overwrites, or deletes any `queue_sessions`, `transaction_logs`, or `program_audit_log` records. |

The import command must be transactional. If any step fails, roll back all DB changes from that import. TTS file writes are staged to a temp directory and moved atomically after the DB transaction commits.

### Bridge API contract — UUID return requirement

Every proxied creation endpoint on central must return the UUID alongside the integer ID:

```json
// POST /api/bridge/clients → response
{
  "id": 142,
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "name": "Juan Dela Cruz",
  "birth_year": 1975
}

// POST /api/bridge/identity-registrations → response
{
  "id": 88,
  "uuid": "661f9511-f30c-52e5-b827-557766551111",
  "status": "pending"
}
```

Pi stores the UUID immediately on the local session/registration record. When Phase G syncs, it uses the UUID — not the integer ID — for central matching.

### Package export endpoint

```
GET /api/admin/programs/{program}/export-package
Authorization: Bearer {admin_token}
Query params (override site defaults):
  ?sync_clients=true
  &client_scope=program_history
  &sync_tokens=true
  &sync_tts=true
```

Response: streams the `.tar.gz` file. For large packages (TTS included), this is a background job: admin triggers export, receives a job ID, polls for completion, then downloads.

### ✅ Phase D Success Criteria

- [ ] `GET /api/admin/programs/{id}/export-package` returns a valid `.tar.gz`. Extracting it produces all expected files per the package structure above.
- [ ] `manifest.json` contains SHA-256 checksums for every file. Importer verifies all before applying any change.
- [ ] **Security invariant test:** Automated test extracts package and `grep -r "id_number_encrypted" extracted/` returns zero matches. This test runs in CI on every package export. Failure blocks the build.
- [ ] `uuid` columns exist and are populated on all 8 tables listed above. Verified on both SQLite and MariaDB.
- [ ] `php artisan flexiqueue:import-package package.tar.gz` on an empty Pi DB: program is active, staff can log in, tokens recognized, display board loads, TTS plays.
- [ ] Re-import on a Pi with 10 existing sessions: sessions are untouched after import. `queue_sessions` count unchanged. No session status changes.
- [ ] Station deleted on central + re-imported on Pi with active session at that station: session is NOT destroyed. Station marked `orphaned_on_central = true`. Session can complete normally.
- [ ] Import is atomic: simulate a failure (kill process during station upsert). Re-run import succeeds cleanly. DB is in a consistent state after the failed attempt.
- [ ] Bridge creation endpoints (client, identity registration) return `uuid` in response. Pi stores it. Verified by checking Pi's local record after a proxied creation.

---

## Phase E — Bridge Layer

### Connectivity detection — hardened specification

```php
class ConnectivityMonitor
{
    private const PING_TIMEOUT_SECONDS = 3;
    private const ONLINE_CACHE_TTL = 30;
    private const REQUIRED_CONSECUTIVE_FAILURES = 3;    // must fail 3 times before switching to offline
    private const REQUIRED_CONSECUTIVE_SUCCESSES = 2;   // must succeed 2 times before switching back to online

    // Sticky state: does NOT flip on a single check result
    // Uses a sliding window of last N checks to determine mode
    public function isOnline(): bool;
    public function getCurrentMode(): string; // 'bridge' | 'offline'
    public function getLastCheckedAt(): Carbon;
    public function getConsecutiveResult(): int; // +N = N consecutive successes, -N = N consecutive failures
}
```

The health check must:
1. Attempt a TCP connection to `{CENTRAL_URL}:443` (not just DNS) to confirm the upstream is reachable, not just the local router.
2. Only after TCP success, issue `GET {CENTRAL_URL}/api/health` with a 3-second timeout.
3. Central's `/api/health` endpoint must return a non-cached response with a current timestamp (prevents false positives from CDN edge caching).

### Session-level connectivity lock

The "no mode switch mid-session" policy requires an enforcement mechanism:

```sql
ALTER TABLE queue_sessions ADD COLUMN connectivity_mode ENUM('bridge', 'offline') NOT NULL DEFAULT 'offline';
```

Set at bind time:
```php
$session->connectivity_mode = $bridge->getCurrentMode();
$session->save();
```

All subsequent actions on a session (call, serve, transfer, complete) check `session.connectivity_mode`, not `ConnectivityMonitor::getCurrentMode()`. The session is frozen to the mode it was started in.

**When bridge comes online after a session was started offline:**
- The session stays in offline mode for its lifetime.
- The *next new session* (new token scan) is started in bridge mode.
- No retroactive client verification is offered. The session's client binding was done offline and is final for that session.

**When bridge goes offline after a session was started in bridge mode:**
- The session stays in bridge mode in terms of its `connectivity_mode` record.
- Actions on the session that require bridge (client lookup mid-session) fall back to local data with a staff-visible warning: "Connection to central lost. Using local data."
- Session lifecycle (call/serve/transfer/complete) is always local — not bridge-dependent.

### Bridge endpoints on central

```
// Client operations
GET  /api/bridge/clients/search?name={}&birth_year={}
POST /api/bridge/clients/lookup-by-id  { id_type, id_number }
POST /api/bridge/clients               { name, birth_year }

// Identity registration
POST /api/bridge/identity-registrations  { program_uuid, session_uuid, name, birth_year, id_type, id_number }
GET  /api/bridge/identity-registrations/{uuid}/status

// Token
GET  /api/bridge/tokens/{qr_hash}/availability
```

All bridge endpoints on central:
- Require `Authorization: Bearer {CENTRAL_API_KEY}` (same Pi site API key)
- Return UUID in all creation responses
- Are rate-limited per site to prevent Pi from hammering central during an event

### ✅ Phase E Success Criteria

- [ ] `ConnectivityMonitor` does not flip to offline on a single failed ping. Requires 3 consecutive failures. Verified by test: mock 2 failures → still reports online. Mock 3 failures → reports offline.
- [ ] `ConnectivityMonitor` does not flip back to online on a single success. Requires 2 consecutive successes after being offline.
- [ ] Health check endpoint on central returns a live timestamp (not cached). Verified by two rapid requests returning different timestamps.
- [ ] `queue_sessions.connectivity_mode` is set at bind time. A session bound in bridge mode retains `connectivity_mode = 'bridge'` even after connectivity drops.
- [ ] All bridge creation endpoints return `uuid` alongside integer `id`. Pi stores UUID on the local record.
- [ ] Bridge rate limiting: more than 60 requests/minute from one site API key returns 429.
- [ ] If bridge goes offline mid-operation, the request returns an error but the Pi does not crash, does not corrupt local state, and staff see a recoverable warning.

---

## Phase F — Edge Mode Application

### New columns introduced in this phase

```sql
-- Source classification (was Phase H in v1 — moved here per Critique 7)
ALTER TABLE queue_sessions ADD COLUMN source VARCHAR(50) NOT NULL DEFAULT 'central';
-- On Pi: SessionService::bind() sets source = 'edge:{SITE_ID}' when APP_MODE=edge
-- On Central: SessionService::bind() sets source = 'central'

-- Binding verification status (Decision 1 — unverified bindings)
ALTER TABLE queue_sessions ADD COLUMN binding_status VARCHAR(20) NOT NULL DEFAULT 'verified';
-- Values: 'verified' (default for central), 'unconfirmed' (offline edge bindings), 'reviewed' (after admin review)
```

### Client creation on Pi (Decision 2 applied)

```php
class EdgeModeService
{
    public function isEdgeMode(): bool;                      // APP_MODE === 'edge'
    public function canCreateClients(): bool;                // Always true — both offline and bridge allow client creation
    public function canShowIdBindingPage(): bool;            // isEdgeMode() ? bridge->isOnline() : true
    public function canRegisterIdentity(): bool;             // isEdgeMode() ? bridge->isOnline() : true
    public function canVerifyBinding(): bool;                // isEdgeMode() ? bridge->isOnline() : true
    public function canGenerateTts(): bool;                  // isEdgeMode() ? false : true
    public function canEditPrograms(): bool;                 // isEdgeMode() ? false : true
    public function canEditUsers(): bool;                    // isEdgeMode() ? false : true
    public function canEditTokens(): bool;                   // isEdgeMode() ? false : true
    public function getEffectiveBindingMode(string $configured): string;
    // If isEdgeMode() && !bridge->isOnline() && $configured === 'required' → return 'optional'
    // Otherwise → return $configured
    public function getBindingStatus(): string;
    // If isEdgeMode() && !bridge->isOnline() → return 'unconfirmed'
    // Otherwise → return 'verified'
}
```

**Critical difference from v2-robust:** `canCreateClients()` now returns `true` in both tiers. The constraint is on *ID binding verification*, not on client creation itself.

| Feature | Offline Edge | Edge-Bridge | Central |
|---------|-------------|-------------|---------|
| Create new client record | ✅ Local DB | ✅ Proxied to central | ✅ Direct |
| Show ID binding page | ❌ Hidden | ✅ Full | ✅ Full |
| ID registration | ❌ Disabled | ✅ Via central API | ✅ Direct |
| Binding verification | ❌ All bindings are `unconfirmed` | ✅ Verified against central | ✅ Verified |
| Client search | ✅ Local synced data | ✅ Central API | ✅ Direct |

### Feature gates — implementation pattern

Every controller that has an edge-restricted feature calls `EdgeModeService` — never checks `APP_MODE` directly. This makes the behavior centrally testable and mockable.

### UI specification — what staff see

**Edge banner (top of every page in edge mode):**
```
🟢 Edge Mode · Connected to Central · Last sync: 14 minutes ago · [Sync Now]
🟠 Edge Mode · Offline · Last sync: 2 hours ago · [Sync Now] · 47 records pending
```

**Triage page — offline state:**
```
ℹ️ Offline Mode
You can create clients and bind tokens. ID verification is unavailable while offline.
Bindings made now will be marked as unconfirmed and reviewed when synced to central.
[Search Local Registry] [Create New Client] [Skip Binding]
```

**Triage page — bridge state:**
```
🟢 Connected
Full client creation and ID verification available.
[Search All Clients] [Create New Client] [Verify ID]
```

**Admin program edit — edge mode:**
```
⚠️ Program settings are read-only on this device.
To modify program settings, log in to the central server.
```

**Save/Delete buttons:** Hidden in edge mode for program, user, and token management pages. Not just disabled — hidden, with the explanatory notice above.

### ✅ Phase F Success Criteria

- [ ] `EdgeModeService::getEffectiveBindingMode('required')` returns `'optional'` when offline, `'required'` when online (bridge). Verified by unit test with mocked bridge state.
- [ ] `EdgeModeService::canCreateClients()` returns `true` in both offline and bridge modes.
- [ ] `EdgeModeService::canShowIdBindingPage()` returns `false` when offline, `true` when bridge is active.
- [ ] `queue_sessions.source` is set correctly: sessions created on Pi = `'edge:{SITE_ID}'`, sessions on central = `'central'`.
- [ ] `queue_sessions.binding_status` is `'unconfirmed'` for sessions bound offline, `'verified'` for bridge-bound and central sessions.
- [ ] `queue_sessions.connectivity_mode` is set at bind time (from Phase E).
- [ ] Full session lifecycle on Pi with no internet: bind → call → serve → transfer → complete. All `transaction_logs` created. Display board updates via local Reverb. TTS plays from packaged MP3s or browser fallback.
- [ ] Offline client creation: staff can create a new client with name/birth_year. Client record saved locally. No ID binding page shown.
- [ ] Bridge client creation: staff can create a new client with full ID binding. Client created on central. Binding verified.
- [ ] Edge banner shows correct state within 35 seconds of actual connectivity change.
- [ ] Admin program edit: Save button is absent (hidden, not disabled) in edge mode. `PUT /api/admin/programs/{id}` from edge Pi returns 403.
- [ ] Sync widget pending count: after 5 offline sessions, count = 5. After sync, count = 0.
- [ ] No controller directly checks `APP_MODE`. All checks go through `EdgeModeService`. Verified by grep: `grep -r "APP_MODE" app/Http` returns zero results.

---

## Phase G — Sync API (Pi → Central)

### Complete upload payload

```json
{
  "site_id": "mswdo-dagupan",
  "program_uuid": "...",
  "synced_at": "2026-03-12T17:00:00Z",
  "chunk_index": 0,
  "chunk_total": 3,
  "sessions": [ ... ],
  "transaction_logs": [ ... ],
  "identity_registrations": [ ... ],
  "program_audit_log": [ ... ],
  "staff_activity_log": [ ... ],
  "client_id_audit_log": [ ... ],
  "new_clients": [ ... ],
  "new_id_document_hashes": [ ... ]
}
```

### Chunked upload — partial batch recovery

Maximum payload size per chunk: **200 records** (sessions + logs combined). Larger pending sets are split into multiple sequential POST requests.

Central processes each chunk atomically (DB transaction per chunk). After each chunk, central returns the accepted UUIDs. Pi marks those records `synced_to_central_at = now()` before sending the next chunk.

If a chunk fails:
- Pi has already marked all records from previous chunks as synced.
- Pi retries only the current chunk.
- The retry payload is identical (idempotent — central ignores duplicate UUIDs).

```php
class SyncToCentralJob
{
    public function handle(): void
    {
        $pending = $this->getPendingRecords(); // WHERE synced_to_central_at IS NULL
        $chunks = array_chunk($pending, 200);
        
        foreach ($chunks as $i => $chunk) {
            $response = $this->bridge->upload($chunk, $i, count($chunks));
            
            if ($response->successful()) {
                $this->markAsSynced($response->json('accepted_uuids'));
            } else {
                $this->scheduleRetry();
                break;
            }
        }
    }
}
```

### Unconfirmed binding sync (Decision 1 — reconciliation support)

When sessions with `binding_status = 'unconfirmed'` are synced to central:

1. Central imports them normally (sessions are valid, data is complete).
2. Central flags them in a `sync_binding_review` queue table:

```sql
CREATE TABLE sync_binding_review (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id       BIGINT UNSIGNED NOT NULL REFERENCES sites(id),
    session_uuid  CHAR(36) NOT NULL,
    client_uuid   CHAR(36) NULL,
    client_name   VARCHAR(255) NOT NULL,
    birth_year    INT NULL,
    status        VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending, confirmed, matched, rejected
    matched_client_id BIGINT UNSIGNED NULL REFERENCES clients(id),
    reviewed_by   BIGINT UNSIGNED NULL REFERENCES users(id),
    reviewed_at   TIMESTAMP NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (session_uuid)
);
```

3. An admin can later review these via a "Sync Review" dashboard (future feature — not part of this plan, but the data structure supports it).

### Conflict resolution — complete

| Data | Direction | Authority | Resolution |
|------|-----------|-----------|------------|
| `queue_sessions` | Pi → Central | Pi | Upsert by UUID. Central does not modify Pi sessions while Pi is offline. `binding_status` preserved as-is. |
| `transaction_logs` | Pi → Central | Pi | Append-only. Merge by UUID. Duplicate UUIDs silently ignored. |
| `identity_registrations` | Pi → Central | Pi (for locally-created) / Central (for bridge-proxied) | Bridge-proxied: Pi sends UUID (from Phase E bridge response). Central upserts by UUID. Locally-created on Pi (future): Pi is authoritative, central creates new record. |
| `clients` (new) | Pi → Central | Hash-based dedup | If `id_number_hash` matches existing central client: merge (link UUID, update name if different → flag for review). If no hash match: create new central client record. |
| `new_id_document_hashes` | Pi → Central | Pi | Append new hash records for clients created on Pi. |
| `client_id_audit_log` | Pi → Central | Pi | Append-only. Merge by UUID. |
| `program_audit_log` | Pi → Central | Pi | Append-only. Merge by UUID. |
| `staff_activity_log` | Pi → Central | Pi | Append-only. Merge by UUID. |
| `tokens` (status) | Derived | Central | Central recalculates token status from synced sessions. Not sent directly. |
| `program` config | Central → Pi | Central | Not in upload. Flows down via package only. |

### Sync triggers

| Trigger | When | Behavior |
|---------|------|----------|
| Manual "Sync Now" | Admin clicks button | Immediate chunked upload of all pending |
| Scheduled end-of-day | Per-site configurable (default 5:00 PM, stored in `edge_settings.scheduled_sync_time`) | Same as manual |
| Background auto-sync | When bridge is active | New sessions/logs uploaded within 60 seconds of creation |

### `sync_id_map` table on central

```sql
CREATE TABLE sync_id_map (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id     BIGINT UNSIGNED NOT NULL REFERENCES sites(id),
    entity_type VARCHAR(50) NOT NULL,  -- 'session', 'transaction_log', 'client', etc.
    edge_uuid   CHAR(36) NOT NULL,
    central_id  BIGINT UNSIGNED NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (site_id, entity_type, edge_uuid)
);
```

### ✅ Phase G Success Criteria

- [ ] Upload payload includes: `sessions`, `transaction_logs`, `identity_registrations`, `program_audit_log`, `staff_activity_log`, `client_id_audit_log`, `new_clients`, `new_id_document_hashes`. Verified by schema test.
- [ ] Chunked upload: 500 pending sessions are split into 3 chunks of ≤200 each. Central processes each chunk in its own DB transaction.
- [ ] Partial failure recovery: simulate network drop after chunk 1 of 3. Chunk 1's records are marked `synced_to_central_at`. Chunks 2 and 3 are still pending. Retry uploads only chunks 2 and 3.
- [ ] Client dedup: upload a client with `id_number_hash` that matches an existing central client. Central merges (links UUID); no new client record created.
- [ ] `sync_id_map` records created for all synced entities. Uploading the same batch twice: `sync_id_map` lookup prevents duplicates.
- [ ] `identity_registrations` present in upload. After sync, central's `identity_registrations` table contains Pi's registrations under the correct `session_uuid`.
- [ ] Sessions with `binding_status = 'unconfirmed'` create entries in `sync_binding_review` on central.
- [ ] Background auto-sync (bridge active): create a session on Pi. Within 60 seconds, that session appears on central without manual trigger.
- [ ] Scheduled job runs at per-site configured time. Verified by setting time to 2 minutes in future during test, waiting, confirming sync ran.

---

## Phase H — Analytics Views

`source` column was added in Phase F. This phase only adds the views and filters.

### AnalyticsService changes

```php
public function getSummary(
    int $programId,
    ?string $source = null,
    ?string $siteId = null,
    ?string $bindingStatus = null
): array {
    $query = Session::where('program_id', $programId);
    if ($source !== null) $query->where('source', $source);
    if ($siteId !== null) $query->where('source', 'edge:' . $siteId);
    if ($bindingStatus !== null) $query->where('binding_status', $bindingStatus);
    return $this->compute($query);
}
```

### Analytics views

| View | Filter | Available on |
|------|--------|--------------|
| Edge local | `source = 'edge:{SITE_ID}'` AND `created_at >= today` | Pi only |
| Central — per site | `source LIKE 'edge:{siteId}%'` OR (`source = 'central'` AND site-scoped program) | Central |
| Central — all sites | No source filter, all programs | Central |
| Central — edge only | `source LIKE 'edge:%'` | Central |
| Central — unconfirmed bindings | `binding_status = 'unconfirmed'` | Central (admin) |

### ✅ Phase H Success Criteria

- [ ] `source` column has no NULLs in either environment. Pre-migration sessions default to `'central'`. Verified by `SELECT COUNT(*) FROM queue_sessions WHERE source IS NULL` = 0.
- [ ] `binding_status` column has no NULLs. Pre-migration sessions default to `'verified'`.
- [ ] Pi analytics shows only today's Pi sessions. Sessions synced from other sites do not appear.
- [ ] Central all-sites aggregate = sum of all per-site totals. No double-counting.
- [ ] `source = 'edge:mswdo-dagupan'` filter returns only sessions from that site.
- [ ] Unconfirmed binding filter shows only sessions with `binding_status = 'unconfirmed'`.

---

## Cross-Cutting Concerns

### SQLite ↔ MariaDB portability

Every new migration has both driver variants. UUID default expressions differ by driver — always implement in both PHP model `booted()` and SQL `DEFAULT` clause. Test both drivers in CI on every migration.

### Security invariants — automated enforcement

1. **PII invariant:** An automated test runs on every package export and asserts `id_number_encrypted` does not appear in the package contents. This test is in the CI pipeline. Failure blocks deployment.
2. **API key invariant:** `sites` table has no `api_key` column — only `api_key_hash`. Verified by: `DESCRIBE sites` in CI asserts `api_key` is not a column.
3. **Cross-APP_KEY invariant:** Integration test provisions a Pi with a different `APP_KEY` than central. Attempts to decrypt `client_id_documents.id_number_encrypted` from the Pi fail with `DecryptException`. This is the expected and desired outcome.

### No direct `APP_MODE` checks in controllers

`grep -r "APP_MODE" app/Http` and `grep -r "APP_MODE" app/Services` must return zero results. All behavior flags go through `EdgeModeService`. This is enforced as a CI lint rule.

### The session connectivity lock

`queue_sessions.connectivity_mode` is immutable after creation. No code path may update `connectivity_mode` on an existing session. Enforced in the `Session` model:

```php
protected static function booted(): void
{
    static::updating(function (Session $session) {
        if ($session->isDirty('connectivity_mode')) {
            throw new \RuntimeException('connectivity_mode is immutable after session creation.');
        }
    });
}
```

---

## What Phase 1 Intentionally Does NOT Include

These are hard out-of-scope items. If any of these appear in a PR during Phase 1 work, it should be rejected:

- **Multi-program Pi** — Phase 1: one imported program per Pi. `activateExclusive()` enforces this.
- **Delta sync for program config** — Full package re-download only. Delta is a Phase 2 optimization.
- **Remote wipe of Pi** — Acknowledged risk (stolen Pi with client data). Deferred. Disk encryption is a deployment practice, not a software feature.
- **Native mobile app** — Out of scope indefinitely.
- **National DSWD database integration** — Out of scope.
- **Multi-site Pi** — Phase 2.
- **Predictive analytics** — Out of scope.
- **Sync binding reconciliation UI** — Future feature. Data structures are in place (`sync_binding_review` table, `binding_status` column) but the admin review dashboard is deferred.
- **Offline identity registration** — Disabled. Not "limited" — fully disabled when offline.

---

## Future Feature: Sync Reconciliation & Data Cleaning

> [!IMPORTANT]
> This section documents a **future feature** that is not part of this plan. It is included here to show that the data model designed above supports it.

When offline-edge sessions and locally-created clients sync to central, an admin can:

1. **Review unconfirmed bindings** — Sessions where `binding_status = 'unconfirmed'` appear in a review queue (`sync_binding_review` table).
2. **Confirm** — The binding data (name, birth_year) matches a known client. Admin confirms, status → `confirmed`.
3. **Match to existing** — The offline client matches an existing central client. Admin links them, merging records.
4. **Attach ID** — Admin can attach an ID document to the matched/confirmed client, completing the identity chain.
5. **Reject** — The binding data is invalid or a duplicate. Admin rejects, status → `rejected`. Session data preserved for audit.

This feature enables the "clean up later" workflow that is essential for emergency/field deployments where offline operation is the norm.

---

*End of v2-Final Plan.*
