# Central + Edge — Implementation Task Beads

**Reference:** [central-edge-v2-final.md](specs/central-edge-v2-final.md)  
**Status:** Future — do not begin until explicitly prioritized.

> [!NOTE]
> Each bead is a deliverable checkpoint. Sub-beads break it down further. Every phase includes a **🔧 Stabilize** bead for bugs, edge cases, and real-life adjustments discovered during implementation.
> Beads are intentionally light on detail — the final plan document is the source of truth for specs.

---

## Pre-Work — Test Coverage Baseline

- [x] **PW.1** — Session lifecycle integration tests (bind → call → serve → transfer → complete)
- [x] **PW.2** — Display board event tests (correct channels, correct events)
- [x] **PW.3** — Triage integration tests (staff triage, public triage, identity registration)
- [x] **PW.4** — Baseline coverage confirmation for all 21 single-active-program files
- [x] **PW.5** — Tag `pre-phase-a-stable` on main
- [x] **🔧 PW.S** — Fix any test gaps or flaky tests discovered during baseline

---

## Phase A — Multi-Program Foundation

- [x] **A.1** — `ProgramService` refactor
  - [x] A.1.1 — Remove "deactivate all others" from `activate()`
  - [x] A.1.2 — Add `activateExclusive()` method
  - [x] A.1.3 — Unit tests for both methods
- [x] **A.2** — `$programId` resolution per request context
  - [x] A.2.1 — Station routes: resolve from `station.program_id`
  - [x] A.2.2 — Staff triage: resolve from `user.assigned_station_id → station.program_id`
  - [x] A.2.3 — Public triage: URL segment `/public/triage/{program}`
  - [x] A.2.4 — Display board: query param `?program={id}` + program selector fallback
  - [x] A.2.5 — Admin pages: program selector in sidebar/URL
- [x] **A.3** — Refactor all 21 single-active-program locations
  - [x] A.3.1 — `SessionService`, `DisplayBoardService`, `StaffDashboardService`
  - [x] A.3.2 — All controllers (`StaffDashboard`, `StationPage`, `Home`, `Triage`, etc.)
  - [x] A.3.3 — `HandleInertiaRequests` — shared data restructure (verified: admin gets `programs` only)
  - [x] A.3.4 — `IdentityRegistrationController` (6 locations) — verified: uses `assignedStation?->program` only
  - [x] A.3.5 — `PublicTriageController` (3 locations) — verified: uses request `program_id` only
  - [x] A.3.6 — Grep verification: zero remaining `where('is_active', true)->first()` calls (ProgramService retains for activateExclusive only)
- [x] **A.4** — Frontend compatibility
  - [x] A.4.1 — Introduce `currentProgram` prop + deprecate `program`
  - [x] A.4.2 — Update station/triage/display pages to `currentProgram`
  - [x] A.4.3 — Update admin pages to `programs` array
  - [x] A.4.4 — Remove deprecated `program` alias, grep confirms zero references
  - [x] A.4.5 — Program selector in StatusFooter replaces first-active fallback (dropdown on admin + station/triage; chip preserves program when switching views; backend redirects to base path on ?program=)
- [x] **A.5** — Broadcasting channel migration
  - [x] A.5.1 — `display.activity` → `display.activity.{programId}`
  - [x] A.5.2 — `global.queue` → `queue.{programId}`
  - [x] A.5.3 — Frontend Echo subscriptions updated
    - Implementation verified: backend event channels covered by `tests/Unit/Events/BroadcastingChannelsTest.php`; frontend Display board and public triage subscribe only to `display.activity.{programId}` and `queue.{programId}` with `programId` from the current program context.
- [x] **A.6** — Multi-program verification
  - [x] A.6.1 — Two programs active simultaneously, 5 sessions each, zero cross-contamination
  - [x] A.6.2 — Display board isolation verified
  - [x] A.6.3 — All pre-work tests still pass
- [x] **🔧 A.S** — Stabilize: fix regressions, edge cases, unexpected frontend breakage
  - Staff multi-program: API allows assignment; returns `warning` when staff already in another program (no toast on assign page). On activate, show warning toast if staff in multiple active programs; still proceed. Admin/supervisor (no station): shared program selector on Station/Triage — done. **Staff with multiple programs:** program selector on Station/Triage/Overrides (same UX) — implemented with shared `staff_selected_program_id` session key and restricted program list to active assignments; see tests in `StaffMultiProgramSelectorTest`. See also [staff-assignment](follow-up-backlog/staff-assignment-one-program-per-staff.md), [staff-shared-program-selector](follow-up-backlog/staff-shared-program-selector.md).

---

## Phase B — Multi-Tenant / Sites

- [x] **B.1** — `sites` table migration (SQLite + MariaDB)
  - [x] B.1.1 — Schema: `id, name, slug, api_key_hash, settings, edge_settings`
  - [x] B.1.2 — Add `site_id` FK to `programs` and `users`
  - [x] B.1.3 — Default site seeder for existing data
- [x] **B.2** — API key lifecycle
  - [x] B.2.1 — Key generation (40-char, `sk_live_` prefix)
  - [x] B.2.2 — bcrypt hash storage, raw key shown once
  - [x] B.2.3 — Regenerate key endpoint
  - [x] B.2.4 — Auth middleware for sync/bridge endpoints (bcrypt verify)
- [x] **B.3** — `edge_settings` JSON validation
  - [x] B.3.1 — JSON Schema validator implementation
  - [x] B.3.2 — Unknown key rejection, enum enforcement, defaults
  - [x] B.3.3 — Include `scheduled_sync_time` and `offline_allow_client_creation`
- [x] **B.4** — Site scoping
  - [x] B.4.1 — Programs scoped by `site_id`
  - [x] B.4.2 — Users scoped by `site_id`
  - [x] B.4.3 — Cross-site isolation verification
- [x] **B.5** — Admin UI for site management
  - [x] B.5.1 — CRUD for sites
  - [x] B.5.2 — API key display/regenerate UI
  - [x] B.5.3 — Edge settings form
- [x] **🔧 B.S** — Stabilize: existing data migration issues, auth edge cases

---

## Phase S — Site Scoping (Multi-Tenant Data Isolation)

**Reference:** [site-scoping-migration-spec.md](specs/site-scoping-migration-spec.md), [SITE-SEPARATION-STUDY.md](SITE-SEPARATION-STUDY.md)

- [x] **S.1** — Tokens: schema and backfill
  - [x] S.1.1 — Add tokens.site_id migration (SQLite + MariaDB)
  - [x] S.1.2 — Backfill existing tokens (from program_token or default site)
  - [x] S.1.3 — Token model: site_id, site(), scopeForSite
- [x] **S.2** — Tokens: API and services scope by site
  - [x] S.2.1 — TokenController scope index/store/update/delete/batch/TTS by site
  - [x] S.2.2 — TokenService::batchCreate sets site_id; ProgramTokenService bulk assign same-site only
  - [x] S.2.3 — TokenPrintController, AnalyticsService, SystemStorageService: token queries scoped
  - [x] S.2.4 — Feature tests: token site isolation
- [x] **S.3** — Clients: schema and backfill
  - [x] S.3.1 — Add clients.site_id migration (SQLite + MariaDB)
  - [x] S.3.2 — Backfill existing clients
  - [x] S.3.3 — Client model: site_id, site(), scopeForSite
- [x] **S.4** — Clients: API and services scope by site
  - [x] S.4.1 — ClientService createClient/searchClients scope by site
  - [x] S.4.2 — ClientPageController, ClientAdminController scope by site; triage passes program.site_id
  - [x] S.4.3 — Feature tests: client site isolation
- [x] **S.5** — Print settings per site
  - [x] S.5.1 — Add print_settings.site_id migration; one row per site
  - [x] S.5.2 — PrintSettingRepository getInstance(siteId); PrintSettingsController scope
  - [x] S.5.3 — Feature test: two sites have separate print settings
- [x] **S.6** — Verification
  - [x] S.6.1 — ReportController/ReportService: restrict getProgramSessions and getAuditLog to user's site programs; super_admin can pass any
  - [x] S.6.2 — Site isolation regression: two sites, two admins, no cross-site visibility (tokens, clients, print settings)
- [x] **🔧 S.S** — Stabilize: backfill edge cases, LGU seeder tokens with site_id, UI polish

---

## Phase C — Token–Program Association

- [x] **C.1** — `program_token` pivot table migration (SQLite + MariaDB)
- [x] **C.2** — Model relationships (`Program::tokens()`, `Token::programs()`)
- [x] **C.3** — Admin UI: assign/unassign tokens to programs
  - [x] C.3.1 — Individual assignment
  - [x] C.3.2 — Bulk assignment by pattern (e.g., `A*`)
- [x] **C.4** — Verification: token in multiple programs, no side effects on status or sessions
- [x] **🔧 C.S** — Stabilize: bulk assignment edge cases, UI polish

---

## Phase D — Program Package API

- [ ] **D.1** — UUID columns migration
  - [ ] D.1.1 — Add `uuid` to all 8 tables (both SQLite + MariaDB expressions)
  - [ ] D.1.2 — PHP model `booted()` fallback UUID generation
  - [ ] D.1.3 — Backfill existing records with UUIDs
- [ ] **D.2** — Package exporter
  - [ ] D.2.1 — `manifest.json` with SHA-256 checksums
  - [ ] D.2.2 — Program, stations, tracks, processes, station_process exports
  - [ ] D.2.3 — Users export (hashed passwords, assignments)
  - [ ] D.2.4 — Tokens export (conditional on `sync_tokens`)
  - [ ] D.2.5 — Clients export (conditional on `sync_clients`, scope = `program_history`)
  - [ ] D.2.6 — ID document hashes export (never encrypted values)
  - [ ] D.2.7 — Identity registrations export
  - [ ] D.2.8 — TTS files bundling (conditional on `sync_tts`)
  - [ ] D.2.9 — `token_deactivation_list` in manifest
  - [ ] D.2.10 — Print settings, program diagram, temp authorizations, edge settings
- [ ] **D.3** — Security invariant CI test (`grep` for `id_number_encrypted` in package = 0 matches)
- [ ] **D.4** — Export API endpoint (`GET /api/admin/programs/{id}/export-package`)
  - [ ] D.4.1 — Streaming response for small packages
  - [ ] D.4.2 — Background job + polling for large packages (TTS included)
- [ ] **D.5** — Package importer (`php artisan flexiqueue:import-package`)
  - [ ] D.5.1 — Manifest verification (checksums)
  - [ ] D.5.2 — Transactional DB import (rollback on failure)
  - [ ] D.5.3 — Station upsert with orphan handling
  - [ ] D.5.4 — User upsert (deactivate, never delete)
  - [ ] D.5.5 — Token upsert + deactivation list
  - [ ] D.5.6 — Client upsert (Pi authoritative for locally-created)
  - [ ] D.5.7 — TTS file staging + atomic move
  - [ ] D.5.8 — `activateExclusive()` for imported program
- [ ] **D.6** — Re-import verification: existing sessions untouched
- [ ] **D.7** — Bridge API UUID contract
  - [ ] D.7.1 — Client creation returns `uuid` + `id`
  - [ ] D.7.2 — Identity registration creation returns `uuid` + `id`
- [ ] **🔧 D.S** — Stabilize: large package edge cases, import failures, checksum mismatches

---

## Phase E — Bridge Layer

- [ ] **E.1** — `ConnectivityMonitor` service
  - [ ] E.1.1 — TCP connection check + HTTP health endpoint
  - [ ] E.1.2 — Sticky switching: 3 consecutive failures → offline, 2 consecutive successes → online
  - [ ] E.1.3 — 30-second cache TTL
  - [ ] E.1.4 — Non-cached health endpoint on central (returns live timestamp)
- [ ] **E.2** — Session connectivity lock
  - [ ] E.2.1 — `connectivity_mode` column on `queue_sessions`
  - [ ] E.2.2 — Set at bind time, immutable after
  - [ ] E.2.3 — Model-level enforcement (`isDirty` check in `updating`)
- [ ] **E.3** — Bridge endpoints on central
  - [ ] E.3.1 — `GET /api/bridge/clients/search`
  - [ ] E.3.2 — `POST /api/bridge/clients/lookup-by-id`
  - [ ] E.3.3 — `POST /api/bridge/clients` (create)
  - [ ] E.3.4 — `POST /api/bridge/identity-registrations`
  - [ ] E.3.5 — `GET /api/bridge/identity-registrations/{uuid}/status`
  - [ ] E.3.6 — `GET /api/bridge/tokens/{qr_hash}/availability`
- [ ] **E.4** — Bridge auth + rate limiting
  - [ ] E.4.1 — `Authorization: Bearer` with site API key verification
  - [ ] E.4.2 — Rate limit: 60 req/min per site key → 429
- [ ] **E.5** — `BridgeService` on Pi
  - [ ] E.5.1 — Proxy methods for all bridge endpoints
  - [ ] E.5.2 — Graceful fallback on timeout/error (no crash, no data corruption)
  - [ ] E.5.3 — Staff-visible warning on fallback
- [ ] **E.6** — Connectivity flap tests (mock sequences of up/down)
- [ ] **🔧 E.S** — Stabilize: real network conditions, timeout tuning, flap scenarios

---

## Phase F — Edge Mode Application

- [ ] **F.1** — New columns
  - [ ] F.1.1 — `queue_sessions.source` (default `'central'`)
  - [ ] F.1.2 — `queue_sessions.binding_status` (default `'verified'`)
- [ ] **F.2** — `EdgeModeService` implementation
  - [ ] F.2.1 — All feature gate methods per spec
  - [ ] F.2.2 — `getEffectiveBindingMode()` — auto-downgrade `required` → `optional` offline
  - [ ] F.2.3 — `getBindingStatus()` — `unconfirmed` when offline, `verified` when bridge
  - [ ] F.2.4 — `canCreateClients()` = `true` in both tiers
  - [ ] F.2.5 — `canShowIdBindingPage()` = `false` offline, `true` on bridge
- [ ] **F.3** — Pi environment config
  - [ ] F.3.1 — `APP_MODE=edge`, `CENTRAL_URL`, `CENTRAL_API_KEY`, `SITE_ID`
  - [ ] F.3.2 — Config validation on boot
- [ ] **F.4** — Edge mode UI
  - [ ] F.4.1 — Edge banner component (online/offline state)
  - [ ] F.4.2 — Sync status widget (pending count, last sync, "Sync Now" button)
  - [ ] F.4.3 — Triage page: offline state messaging
  - [ ] F.4.4 — Triage page: bridge state messaging
  - [ ] F.4.5 — Admin read-only mode: hidden save/delete buttons + explanatory notices
  - [ ] F.4.6 — Offline client creation form (name + birth_year only, no ID binding page)
- [ ] **F.5** — Session lifecycle on Pi (full offline test)
  - [ ] F.5.1 — Bind → call → serve → transfer → complete (no internet)
  - [ ] F.5.2 — Transaction logs created correctly
  - [ ] F.5.3 — Display board updates via local Reverb
  - [ ] F.5.4 — TTS from packaged MP3s + browser fallback
- [ ] **F.6** — `APP_MODE` grep enforcement
  - [ ] F.6.1 — `grep -r "APP_MODE" app/Http` = zero results
  - [ ] F.6.2 — `grep -r "APP_MODE" app/Services` = zero results (except `EdgeModeService`)
- [ ] **🔧 F.S** — Stabilize: real Pi testing, UI state transitions, unexpected offline scenarios

---

## Phase G — Sync API (Pi → Central)

- [ ] **G.1** — Upload endpoint on central (`POST /api/sync/upload`)
  - [ ] G.1.1 — Auth via site API key
  - [ ] G.1.2 — Accept full payload schema (sessions, logs, clients, etc.)
  - [ ] G.1.3 — Return accepted UUIDs per chunk
- [ ] **G.2** — Chunked upload (200 records/chunk)
  - [ ] G.2.1 — Pi-side chunking logic
  - [ ] G.2.2 — Sequential chunk sending with per-chunk sync marking
  - [ ] G.2.3 — Partial failure recovery (retry current chunk only)
- [ ] **G.3** — Conflict resolution implementation
  - [ ] G.3.1 — Sessions: upsert by UUID
  - [ ] G.3.2 — Transaction logs: append-only, duplicate UUID ignored
  - [ ] G.3.3 — Identity registrations: bridge-proxied vs locally-created
  - [ ] G.3.4 — New clients: hash-based dedup
  - [ ] G.3.5 — All audit logs: append-only merge
- [ ] **G.4** — `sync_id_map` table on central
  - [ ] G.4.1 — Migration
  - [ ] G.4.2 — Idempotency: duplicate upload doesn't create new records
- [ ] **G.5** — `sync_binding_review` table on central
  - [ ] G.5.1 — Migration
  - [ ] G.5.2 — Populate on sync of `binding_status = 'unconfirmed'` sessions
- [ ] **G.6** — `SyncToCentralJob` on Pi
  - [ ] G.6.1 — Get pending records (`synced_to_central_at IS NULL`)
  - [ ] G.6.2 — Chunk + upload loop
  - [ ] G.6.3 — Mark synced on success
  - [ ] G.6.4 — Schedule retry on failure
- [ ] **G.7** — Sync triggers
  - [ ] G.7.1 — Manual "Sync Now" button
  - [ ] G.7.2 — Scheduled end-of-day job (per-site `scheduled_sync_time`)
  - [ ] G.7.3 — Background auto-sync when bridge active (within 60s of creation)
- [ ] **G.8** — End-to-end sync verification
  - [ ] G.8.1 — 5 offline sessions on Pi → sync → appear on central
  - [ ] G.8.2 — Pending count goes to 0 after sync
  - [ ] G.8.3 — Duplicate upload has no side effects
- [ ] **🔧 G.S** — Stabilize: network failures, large batch testing, data integrity spot-checks

---

## Phase H — Analytics Views

- [ ] **H.1** — `AnalyticsService` changes
  - [ ] H.1.1 — Add `source`, `siteId`, `bindingStatus` filters to `getSummary()`
- [ ] **H.2** — Analytics views
  - [ ] H.2.1 — Edge local view (Pi — today's sessions only)
  - [ ] H.2.2 — Central per-site view
  - [ ] H.2.3 — Central all-sites aggregate
  - [ ] H.2.4 — Central edge-only view
  - [ ] H.2.5 — Central unconfirmed bindings view
- [ ] **H.3** — Admin UI for analytics filtering
  - [ ] H.3.1 — Source filter dropdown (all / central / edge / specific site)
  - [ ] H.3.2 — Binding status filter
- [ ] **H.4** — Verification
  - [ ] H.4.1 — No NULLs in `source` or `binding_status` columns
  - [ ] H.4.2 — Aggregate = sum of parts (no double-counting)
  - [ ] H.4.3 — Pi analytics shows only local sessions
- [ ] **🔧 H.S** — Stabilize: analytics accuracy, performance on large datasets, UI polish

---

## Cross-Cutting (Ongoing Throughout All Phases)

- [ ] **X.1** — SQLite ↔ MariaDB dual-driver testing on every migration
- [ ] **X.2** — CI security invariant tests (PII in package = 0, no `api_key` column, cross-APP_KEY decrypt fails)
- [ ] **X.3** — CI lint rule: no `APP_MODE` in `app/Http` or `app/Services`
- [ ] **X.4** — Deployment guide: Armbian full-disk encryption instructions
- [ ] **X.5** — Pi hardware testing (real Orange Pi, real network conditions)

---

> **Total beads:** ~95 sub-tasks across 8 phases + pre-work + cross-cutting.  
> Each **🔧 Stabilize** bead is intentionally open-ended — it absorbs bugs, edge cases, and adjustments that surface during real implementation.
