# FlexiQueue: Central + Edge — Robust Implementation Plan (v2)

**Status:** Future feature — not in active scope until explicitly prioritized.
**This document:** Critiques v1, identifies gaps and false assumptions, then produces the complete, hardened plan.

---

## Part 1 — Critique of the v1 Plan

These are not minor polish items. Several of these are architectural holes that would cause a failed build or a broken Pi deployment if carried forward uncorrected.

---

### Critique 1: Phase A is under-specified on the hardest problem

The plan correctly identifies the 21 locations and says "pass `$programId` as an explicit parameter." What it does not address is **where `$programId` comes from in the first place at the request boundary.**

On the central server, a single request can legitimately involve multiple programs (e.g., an admin page listing all programs). At the station level, the station itself has a `program_id` — that's the natural source. At the triage level, there's no URL segment for program — triage is currently program-agnostic. At the display level, `?program=X` is proposed, but unauthenticated display pages can't use session context.

The plan says "passed from the request, URL, or session context" and leaves it there. That is not good enough. Each of the 21 locations needs a specified resolution strategy, not a hand-wave. Some will use URL routing, some will use the authenticated user's `assigned_station_id → station.program_id`, some will need a middleware that resolves the active program from context. Without this, Phase A gets implemented inconsistently across the codebase and Phase D (which builds on the multi-program model) inherits the mess.

**Also missing from Phase A:** `HandleInertiaRequests.php:49` is the shared data provider for **every page load**. Changing what it passes down is a frontend-wide change. The plan mentions it but does not address the Svelte side — 24 pages receive shared Inertia props. Some of those pages will break the moment the shared `program` prop changes shape from a single object to `null` or a program-scoped object. This needs a frontend compatibility plan.

---

### Critique 2: Phase B stores `api_key` as plaintext — the plan contradicts itself

In Phase B schema: `api_key VARCHAR(255) UNIQUE` — stored in the `sites` table.

In the Cross-Cutting Concerns section: "It must be stored hashed on central (like passwords), not in plaintext."

These two statements are in direct conflict. The schema shows plaintext. The security note says hash it. The plan never resolves this contradiction. This matters: if `api_key` is stored hashed on central (bcrypt), then the sync endpoint receives the raw key in the `Authorization: Bearer` header and bcrypt-compares it. If it's stored plaintext, the comparison is direct equality but the database is now a liability if compromised.

The plan should pick one, specify the implementation, and be consistent.

**Correct answer:** Store as `api_key_hash` (bcrypt or SHA-256-HMAC). Central generates the key once, shows it to the admin once (never again), stores only the hash. The Pi stores the raw key in its `.env`. On sync upload, central hashes the incoming key and compares. This is the standard API key pattern.

---

### Critique 3: Phase D ignores the `identity_registrations` sync problem

The plan correctly states that `id_number_encrypted` never goes into the package. But it does not address what happens to `identity_registrations` created **on the Pi when the bridge is active**.

When bridge is active, identity registration proxies to central via `BridgeService`. That registration gets an integer ID on central. The Pi's local session references this registration by ID. But at sync-up time (Phase G), the Pi's session record has `identity_registration_id = 5` — a local central ID, not a UUID. This is an ID mapping problem that will corrupt sessions on sync if not handled.

The plan introduces UUIDs on `identity_registrations` in Phase D. But the bridge flow creates registrations on central (not on the Pi), so the Pi only has the central integer ID, not a UUID it generated itself. The UUID must be returned by central's bridge API response and stored on the Pi session immediately.

This is a gap in Phase E's bridge API design: every proxied creation endpoint (client, identity registration) must return both the central integer ID **and** the UUID so the Pi can store the UUID for later sync reference.

---

### Critique 4: Phase E's connectivity detection is too simplistic for a field deployment

The plan specifies a 30-second cache ping to `{centralUrl}/api/health`. In a field MSWDO deployment:

- The Orange Pi connects via a mobile hotspot or a PLDT broadband router.
- DNS resolution can succeed while the upstream internet is dropping (router is up, internet is down).
- The central server can be unreachable (503, overloaded) while the local network is fine.
- A ping might succeed because the health endpoint cached a response.

A single HTTP GET with a 30-second cache is not sufficient. The plan also says "sticky mode switching" to prevent flapping, but never defines what "sticky" means in implementation terms. How many consecutive failures before switching to offline? How many consecutive successes before switching back to online? Without this, staff will experience the bridge flipping in and out during a session, which is exactly what "sticky" was meant to prevent.

**The bigger issue:** The plan specifies "connectivity is checked at session start. The Pi does not switch modes mid-session." But the bridge is not session-aware in the proposed `BridgeService` design — it's a stateless per-request check. There is no mechanism to lock a session to a connectivity mode at bind time and maintain it through transfer and completion. The plan states a policy but does not specify the implementation that enforces it.

---

### Critique 5: Phase G's sync payload is missing `identity_registrations`

The Phase G upload payload includes: `sessions`, `transaction_logs`, `program_audit_log`, `staff_activity_log`. 

The deep analysis document (`Q5.2`) explicitly lists `identity_registrations` as something that should sync Pi → Central. The Phase G plan omits this entirely. An identity registration created on the Pi (when bridge was briefly active and proxied to central — handled above) or created locally under some future offline registration flow — either way, the central record must be complete. If `identity_registrations` are missing from the sync payload, the audit trail has holes.

Also missing: `client_id_audit_log`. If any ID reveal happened on the Pi (even if the plan says "admin reveal is central-only," a future edge case might allow it), these audit records must sync up.

---

### Critique 6: Phase G has no partial-batch recovery strategy

The plan says: "If upload fails mid-batch (simulate network drop), the next upload retries the full pending set." This is stated as a success criterion but the mechanism is never designed.

What defines a "batch"? Is it a single HTTP POST with all pending records? What happens if the batch is 500 sessions and the request times out at record 300? Which records are marked `synced_to_central_at`? The plan says "central returns accepted UUIDs and Pi marks those" — but if the request never completes, Pi gets no response at all, marks nothing, and retries the whole 500 next time. That's fine for idempotency but it means every retry is potentially a multi-MB payload with growing latency.

The plan needs: a maximum batch size, chunked uploading, and a clear definition of what constitutes an "accepted" record.

---

### Critique 7: The `source` column in Phase H is in the wrong phase

The `source` column on `queue_sessions` (`'central'` or `'edge:{site_id}'`) is described as a Phase H addition. But Phase F (Edge Mode App) is where sessions are created on the Pi. If `source` isn't set at session creation time in Phase F, then every session created on the Pi before Phase H is completed will have `source = NULL` or `source = 'central'` (the default), corrupting the analytics classification retroactively.

`source` must be added in Phase F, at session creation time, as part of the edge mode session context. Phase H merely builds the analytics views on top of it. This is a sequencing error in the v1 plan.

---

### Critique 8: No rollback or data integrity plan for the Phase A refactor

Phase A touches 21 locations across 11 files on a live production system. The plan says "all existing tests pass" as the success criterion — but nowhere is there a rollback plan if Phase A is deployed to a Pi that is currently mid-program with active sessions.

The Orange Pi is the production deployment today. If Phase A introduces a regression that breaks session binding while a real MSWDO payout is happening, there is no documented recovery path. This is not a theoretical risk — it is a live production system.

---

### Critique 9: The "mode switch mid-session" rule has an unaddressed edge case

The plan says connectivity is checked at session start and the Pi does not switch modes mid-session. But what happens to the **current active sessions** when the Pi first transitions from offline to bridge? Those sessions were started in offline mode. Are they now switched to bridge mode for remaining steps? Or do they stay in offline mode for their entire lifetime?

This matters because: a session started offline may have been bound to a locally-synced client (not verified against central's ID). When bridge comes online, should the system now offer to verify that client's identity via central? Or freeze the offline client binding?

The v1 plan does not address this. It needs a clear stated policy.

---

### Critique 10: Package import re-run semantics are underspecified

The plan states: "Re-importing a package (same program, newer timestamp) updates config without destroying existing local session data." This is a success criterion in Phase D but the implementation is not specified.

What does "updates config" mean exactly? If a station was renamed on central, does the Pi update the name? What if a station was deleted on central — does the Pi delete it too, even if there are active sessions at that station? What if tracks were reordered? The package import is essentially a migration operation on a live local database. The conflict rules between incoming package data and existing Pi session data are not defined anywhere in the v1 plan.

---

## Part 2 — Expanded and Hardened Plan (v2)

The following is the complete rewrite, addressing every critique above and filling every gap.

---

## System-Level Success Criteria (Unchanged from v1, but binding on every phase)

1. Central holds all programs, clients, tokens, users. Single source of truth.
2. A Pi can run a complete queue session offline — bind through complete — with local data only.
3. On reconnect, Pi uploads all session data and logs. Central's dashboard is complete.
4. In bridge mode, Pi proxies client/identity operations to central in real time and falls back gracefully.
5. Staff see accurate UI signals: bridge/offline state, pending sync count, last sync time.
6. Central serves multiple programs simultaneously with independent display boards.
7. No `id_number_encrypted` ever leaves central. Pi holds only name, birth_year, and hashes.

---

## Dependency Order (Revised)

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

**New sequencing note:** `source` column on `queue_sessions` moves to Phase F, not Phase H. See Critique 7.

---

## Pre-Work: Test Coverage Baseline (Before Phase A Begins)

This is not a phase — it is a mandatory gate before Phase A touches a single line.

Phase A is a codebase-wide refactor on a live production system. Without a regression baseline, you cannot confirm Phase A is complete. The risk is not "tests fail in CI" — it is "a real MSWDO payout operation breaks mid-session."

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

## Phase A — Multi-Program Foundation (Hardened)

### What this phase does

Removes the single-active-program assumption from all 21 locations. This is a code refactor — no data is destroyed, no migrations are destructive.

### How `$programId` is resolved at the request boundary (the missing piece from v1)

This is the core design question Phase A must answer. Each request context has a different resolution strategy:

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

`ProgramService::activate()` currently deactivates all other programs before activating the new one. After Phase A, it must allow multiple simultaneous active programs. The change:

- Remove the "deactivate all others" logic.
- Add a new method `ProgramService::activateExclusive($programId)` that preserves the old single-program behavior — this is still needed for edge mode where exactly one program is ever active on the Pi.
- The default `activate()` no longer deactivates anything. `activateExclusive()` is called explicitly where single-program semantics are required (Pi import command in Phase D).

### Broadcasting channel migration

| Old channel | New channel | Notes |
|-------------|-------------|-------|
| `display.activity` | `display.activity.{programId}` | All station events scoped to program |
| `display.station.{id}` | Unchanged — station ID is already unique | No change needed |
| `global.queue` | `queue.{programId}` | Scoped per program |
| `station.{id}` | Unchanged | Station ID is unique; program is implicit |

Frontend subscribers (Svelte display board, station page) must update their Echo channel subscriptions to include `programId`.

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

## Phase B — Multi-Tenant / Sites (Hardened)

### Schema (corrected from v1)

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

Note: `api_key_hash` — not `api_key`. Raw key is generated once, shown to admin once, never stored. Central stores only the hash. This resolves Critique 2.

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
  "offline_binding_mode_override": "optional"
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

## Phase C — Token–Program Association (Hardened)

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

Token deactivation on central does not propagate to Pi until the next package re-download. This is not a bug — it is a documented limitation of offline operation.

**Mitigation at the package level:** The package `manifest.json` includes a `token_deactivation_list` — an array of token UUIDs that have been deactivated on central since the last package export for this site. Pi's import command applies these deactivations locally. This narrows the gap between "token deactivated on central" and "Pi knows about it" to the next package re-download, not the next sync.

This mitigation is part of Phase D, not Phase C. Phase C only builds the pivot.

### ✅ Phase C Success Criteria

- [ ] `program_token` pivot migrated (SQLite + MariaDB).
- [ ] A token can be in zero, one, or many programs simultaneously via the pivot.
- [ ] Assigning a token to a program does not affect its `status` field or any active session.
- [ ] Package exporter (verified in Phase D) only includes tokens present in the pivot for the target program.
- [ ] Bulk assignment: admin can assign a range (e.g., tokens with `physical_id` matching `A*`) to a program in one operation.

---

## Phase D — Program Package API (Hardened)

### Package format (unchanged from v1 but with additions)

```
flexiqueue-package-{program_uuid}-{timestamp}.tar.gz
├── manifest.json
├── program.json
├── stations.json
├── service_tracks.json
├── processes.json
├── station_process.json          ← NEW: explicit M:M join table export
├── users.json
├── tokens.json                   (if sync_tokens=true)
├── clients.json                  (if sync_clients=true)
├── id_document_hashes.json
├── identity_registrations.json   ← NEW: existing registrations for this program
├── print_settings.json
├── program_diagram.json
├── temporary_authorizations.json
├── edge_settings.json
└── tts/
    ├── tokens/
    └── stations/
```

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

### Package import — re-run semantics (the missing piece from v1)

When importing a package onto a Pi that already has local session data:

| Entity type | Import action | Conflict rule |
|------------|--------------|---------------|
| Program config (settings, name) | Always overwrite | Central is authoritative for config |
| Stations | Upsert by UUID | Central is authoritative for config. If a station was deleted on central: mark `is_active = false` on Pi if no active sessions reference it. If active sessions exist at that station: do NOT delete — flag as `orphaned_on_central = true`, let the session complete, then deactivate. |
| Tracks + steps | Overwrite entirely | No sessions reference track steps directly during execution; sessions reference current_station_id |
| Processes | Upsert by UUID | Central authoritative |
| Users | Upsert by UUID | Central authoritative. Never delete a user record — only deactivate. |
| Tokens | Upsert by UUID. Apply `token_deactivation_list`. | Central authoritative for status. |
| Clients | Upsert by UUID. Never overwrite name/birth_year if Pi has a locally-created client with the same UUID. | Pi authoritative for records it created locally. |
| TTS files | Replace if `sync_tts=true` and file hash differs | Use manifest checksums to skip unchanged files. |
| Existing local sessions | **Never touched by import** | Sessions created on Pi are Pi's data. Import never modifies, overwrites, or deletes any `queue_sessions`, `transaction_logs`, or `program_audit_log` records. |

The import command must be transactional. If any step fails, roll back all DB changes from that import. TTS file writes are staged to a temp directory and moved atomically after the DB transaction commits.

### Bridge API contract — UUID return requirement (fixes Critique 3)

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

## Phase E — Bridge Layer (Hardened)

### Connectivity detection — hardened specification (fixes Critique 4)

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

### Session-level connectivity lock (fixes Critique 4 — the mid-session rule)

The "no mode switch mid-session" policy requires an enforcement mechanism, not just a documented policy.

Implementation:

```php
// queue_sessions table: add column
ALTER TABLE queue_sessions ADD COLUMN connectivity_mode ENUM('bridge', 'offline') NOT NULL DEFAULT 'offline';

// Set at bind time
$session->connectivity_mode = $bridge->getCurrentMode();
$session->save();
```

All subsequent actions on a session (call, serve, transfer, complete) check `session.connectivity_mode`, not `ConnectivityMonitor::getCurrentMode()`. The session is frozen to the mode it was started in.

**What happens when bridge comes online after a session was started offline:**
- The session stays in offline mode for its lifetime.
- The *next new session* (new token scan) is started in bridge mode.
- No retroactive client verification is offered. The session's client binding was done offline and is final for that session. Staff can always cancel and re-bind on a new session with bridge active if verification is needed.

**What happens when bridge goes offline after a session was started in bridge mode:**
- The session stays in bridge mode in terms of its `connectivity_mode` record.
- But `BridgeService::isOnline()` now returns false for real-time checks.
- Actions on the session that require bridge (client lookup mid-session) fall back to local data with a staff-visible warning: "Connection to central lost. Using local data."
- Session lifecycle (call/serve/transfer/complete) is always local — not bridge-dependent. This is unaffected.

### Bridge endpoints on central — full specification

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
- [ ] `queue_sessions.connectivity_mode` is set at bind time. A session bound in bridge mode retains `connectivity_mode = 'bridge'` even after connectivity drops. A session bound offline retains `connectivity_mode = 'offline'` even after bridge comes online.
- [ ] All bridge creation endpoints return `uuid` alongside integer `id`. Pi stores UUID on the local record. Verified.
- [ ] Bridge rate limiting: more than 60 requests/minute from one site API key returns 429.
- [ ] If bridge is online and then goes offline mid-operation (simulate timeout at 2s, under the 3s limit), the request returns an error but the Pi does not crash, does not corrupt local state, and staff see a recoverable warning.

---

## Phase F — Edge Mode Application (Hardened)

### `source` column introduced here (not Phase H)

```sql
-- Added in Phase F, not Phase H
ALTER TABLE queue_sessions ADD COLUMN source VARCHAR(50) NOT NULL DEFAULT 'central';
-- On Pi: SessionService::bind() sets source = 'edge:{SITE_ID}' when APP_MODE=edge
-- On Central: SessionService::bind() sets source = 'central'
```

This must be set at session creation in `SessionService::bind()`. It is non-nullable with a `'central'` default so existing central sessions are automatically classified correctly.

### Feature gates — implementation pattern

All edge mode feature gates use a single `EdgeModeService` (not ad-hoc `APP_MODE` checks scattered through controllers):

```php
class EdgeModeService
{
    public function isEdgeMode(): bool;                      // APP_MODE === 'edge'
    public function canCreateClients(): bool;                // isEdgeMode() ? bridge->isOnline() : true
    public function canRegisterIdentity(): bool;             // isEdgeMode() ? bridge->isOnline() : true
    public function canGenerateTts(): bool;                  // isEdgeMode() ? false : true
    public function canEditPrograms(): bool;                 // isEdgeMode() ? false : true
    public function canEditUsers(): bool;                    // isEdgeMode() ? false : true
    public function canEditTokens(): bool;                   // isEdgeMode() ? false : true
    public function getEffectiveBindingMode(string $configured): string;
    // If isEdgeMode() && !bridge->isOnline() && $configured === 'required' → return 'optional'
    // Otherwise → return $configured
}
```

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
Client creation is unavailable. You can bind tokens to clients in the local registry.
Identity binding is optional while offline. You may skip it or bind to a known client.
[Search Local Registry] [Skip Binding]
```

**Admin program edit — edge mode:**
```
⚠️ Program settings are read-only on this device.
To modify program settings, log in to the central server.
```

**Save/Delete buttons:** Hidden in edge mode for program, user, and token management pages. Not just disabled — hidden, with the explanatory notice above.

### ✅ Phase F Success Criteria

- [ ] `EdgeModeService::getEffectiveBindingMode('required')` returns `'optional'` when offline, `'required'` when online (bridge). Verified by unit test with mocked bridge state.
- [ ] `queue_sessions.source` is set correctly: sessions created on Pi = `'edge:{SITE_ID}'`, sessions on central = `'central'`. Verified on both environments.
- [ ] `queue_sessions.connectivity_mode` is set at bind time (from Phase E). Verified: two sessions bound — one during bridge-online, one during bridge-offline. Each has the correct mode stored.
- [ ] Full session lifecycle on Pi with no internet: bind → call → serve → transfer → complete. All `transaction_logs` created. Display board updates via local Reverb. TTS plays from packaged MP3s or browser fallback.
- [ ] Edge banner shows correct state within 35 seconds of actual connectivity change.
- [ ] Admin program edit: Save button is absent (hidden, not disabled) in edge mode. `PUT /api/admin/programs/{id}` from edge Pi returns 403.
- [ ] Sync widget pending count: after 5 offline sessions, count = 5. After sync (stubbed for now), count = 0.
- [ ] No controller directly checks `APP_MODE`. All checks go through `EdgeModeService`. Verified by grep: `grep -r "APP_MODE" app/Http` returns zero results.

---

## Phase G — Sync API (Hardened)

### Complete upload payload (fixes Critique 5)

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

Note additions over v1: `identity_registrations`, `client_id_audit_log`, `new_clients`, `new_id_document_hashes`, and chunking fields (`chunk_index`, `chunk_total`).

### Chunked upload — partial batch recovery (fixes Critique 6)

Maximum payload size per chunk: **200 records** (sessions + logs combined). Larger pending sets are split into multiple sequential POST requests.

```
Chunk 0 of 3: sessions[0..66] + logs[0..133]
Chunk 1 of 3: sessions[67..133] + logs[134..266]
Chunk 2 of 3: sessions[134..199] + logs[267..399]
```

Central processes each chunk atomically (DB transaction per chunk). After each chunk, central returns the accepted UUIDs. Pi marks those records `synced_to_central_at = now()` before sending the next chunk.

If a chunk fails:
- Pi has already marked all records from previous chunks as synced.
- Pi retries only the current chunk.
- The retry payload is identical (idempotent — central ignores duplicate UUIDs).

```php
// Pi-side sync job:
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
                // Log failure, stop chunking, reschedule
                $this->scheduleRetry();
                break;
            }
        }
    }
}
```

### Conflict resolution — complete (expanded from v1)

| Data | Direction | Authority | Resolution |
|------|-----------|-----------|------------|
| `queue_sessions` | Pi → Central | Pi | Upsert by UUID. Central does not modify Pi sessions while Pi is offline. |
| `transaction_logs` | Pi → Central | Pi | Append-only. Merge by UUID. Duplicate UUIDs silently ignored. |
| `identity_registrations` | Pi → Central | Pi (for locally-created) / Central (for bridge-proxied) | Bridge-proxied: Pi sends UUID (from Phase E bridge response). Central upserts by UUID. Locally-created on Pi (future): Pi is authoritative, central creates new record. |
| `clients` (new) | Pi → Central | Hash-based dedup | If `id_number_hash` matches existing central client: merge (link UUID, update name if different → flag for review). If no hash match: create new central client record. |
| `new_id_document_hashes` | Pi → Central | Pi | Append new hash records for clients created on Pi. |
| `client_id_audit_log` | Pi → Central | Pi | Append-only. Merge by UUID. |
| `program_audit_log` | Pi → Central | Pi | Append-only. Merge by UUID. |
| `staff_activity_log` | Pi → Central | Pi | Append-only. Merge by UUID. |
| `tokens` (status) | Derived | Central | Central recalculates token status from synced sessions. Not sent directly. |
| `program` config | Central → Pi | Central | Not in upload. Flows down via package only. |

### Sync triggers (unchanged from v1 — correct)

| Trigger | When | Behavior |
|---------|------|----------|
| Manual "Sync Now" | Admin clicks button | Immediate chunked upload of all pending |
| Scheduled end-of-day | Configurable (default 5:00 PM) | Same as manual |
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

When central imports a Pi record and creates a new central integer ID, it writes to `sync_id_map`. On subsequent syncs of the same record (idempotency), `sync_id_map` lookup confirms the record was already imported and skips creation.

### ✅ Phase G Success Criteria

- [ ] Upload payload includes: `sessions`, `transaction_logs`, `identity_registrations`, `program_audit_log`, `staff_activity_log`, `client_id_audit_log`, `new_clients`, `new_id_document_hashes`. Verified by schema test.
- [ ] Chunked upload: 500 pending sessions are split into 3 chunks of ≤200 each. Central processes each chunk in its own DB transaction. Verified by test.
- [ ] Partial failure recovery: simulate network drop after chunk 1 of 3. Chunk 1's records are marked `synced_to_central_at`. Chunks 2 and 3 are still pending. Retry uploads only chunks 2 and 3. Central receives no duplicates.
- [ ] Client dedup: upload a client with `id_number_hash` that matches an existing central client. Central merges (links UUID); no new client record created. Verified by central client count before and after.
- [ ] `sync_id_map` records created for all synced entities. Uploading the same batch twice: `sync_id_map` lookup prevents duplicates. Central entity counts unchanged.
- [ ] `identity_registrations` present in upload. After sync, central's `identity_registrations` table contains Pi's registrations under the correct `session_uuid`.
- [ ] Background auto-sync (bridge active): create a session on Pi. Within 60 seconds, that session appears on central without manual trigger.
- [ ] Scheduled job runs at configured time. Verified by setting time to 2 minutes in future during test, waiting, confirming sync ran.

---

## Phase H — Analytics Views

`source` column was added in Phase F. This phase only adds the views and filters.

### AnalyticsService changes

```php
public function getSummary(int $programId, ?string $source = null, ?string $siteId = null): array
{
    $query = Session::where('program_id', $programId);
    if ($source !== null) $query->where('source', $source);
    if ($siteId !== null) $query->where('source', 'edge:' . $siteId);
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

### ✅ Phase H Success Criteria

- [ ] `source` column has no NULLs in either environment. Pre-migration sessions default to `'central'`. Verified by `SELECT COUNT(*) FROM queue_sessions WHERE source IS NULL` = 0.
- [ ] Pi analytics shows only today's Pi sessions. Sessions synced from other sites do not appear.
- [ ] Central all-sites aggregate = sum of all per-site totals. Verified by arithmetic check in test.
- [ ] A session synced from Pi never appears in two analytics views simultaneously. No double-counting.
- [ ] `source = 'edge:mswdo-dagupan'` filter returns only sessions from that site.

---

## Cross-Cutting Concerns (Hardened)

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
- **Online identity registration on offline Pi** — Disabled. Not "limited" — fully disabled when offline.

---

## Open Decisions Still Required

These must be answered before the corresponding phase begins. They cannot be deferred past their phase gate.

| # | Decision | Must resolve before | Options | Current recommendation |
|---|----------|--------------------|---------|-----------------------|
| 1 | Which clients go into package by default? | Phase D | `all` / `program_history` / `site_scoped` | `program_history` — minimal, relevant |
| 2 | Can new users be created on Pi? | Phase F | Yes / No | No — central-only user management |
| 3 | What Pi data protection level is required? | Phase D (deployment guide) | None / Disk encryption / App-level encryption | At minimum: Armbian full-disk encryption + document it |
| 4 | Max chunk size for sync upload? | Phase G | 50 / 100 / 200 / 500 records | 200 — balances payload size and retry cost |
| 5 | Scheduled sync time configurable per-site or global? | Phase G | Per-site (in edge_settings) / Global (in Pi .env) | Per-site — stored in `edge_settings.scheduled_sync_time` |

---

*End of v2 Plan.*
