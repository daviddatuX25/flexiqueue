# Phase E + G — Bridge Layer & Sync API — Execution Plan

**Reference:** [central-edge-tasks.md](../../central-edge-tasks.md) (Phase E, Phase G), [central-edge-v2-final.md](../../central-edge-v2-final.md) (Phase E, Phase G, Cross-Cutting)  
**Goal:** Deliver the Bridge Layer (ConnectivityMonitor, session connectivity lock, bridge endpoints on central, auth + rate limit, BridgeService on Pi) and the Sync API (upload endpoint, chunking, conflict resolution, `sync_id_map`). Staff see correct connectivity state; Pi can sync sessions and logs to central with idempotent, chunked uploads.

**Status:** Draft — ready for implementation.

---

## API Test Scenarios (Bridge + Sync)

### Bridge (Phase E)

| # | Scenario | Expected |
|---|----------|----------|
| 1 | `ConnectivityMonitor`: 2 consecutive failures | Still reports online (sticky). |
| 2 | `ConnectivityMonitor`: 3 consecutive failures | Switches to offline. |
| 3 | `ConnectivityMonitor`: 1 success after offline | Still offline (need 2 consecutive successes). |
| 4 | `ConnectivityMonitor`: 2 consecutive successes after offline | Switches to online. |
| 5 | Central `GET /api/health` | Returns 200 with live timestamp (not cached); two rapid requests return different timestamps. |
| 6 | Bridge endpoints without `Authorization: Bearer {site_api_key}` | 401 Unauthorized. |
| 7 | Bridge endpoints with valid site API key | 200/201 per endpoint contract; responses include `uuid` where applicable. |
| 8 | Bridge: >60 requests/minute from same site key | 429 Too Many Requests. |
| 9 | Session bound while bridge online | `queue_sessions.connectivity_mode = 'bridge'`; remains bridge after connectivity drops. |
| 10 | Session bound while offline | `queue_sessions.connectivity_mode = 'offline'`. |
| 11 | Update to `connectivity_mode` on existing session | Rejected (model or policy): immutable after creation. |
| 12 | Bridge timeout/error during proxy | Pi does not crash; no local state corruption; staff see recoverable warning. |

### Sync (Phase G)

| # | Scenario | Expected |
|---|----------|----------|
| 1 | `POST /api/sync/upload` without auth | 401. |
| 2 | `POST /api/sync/upload` with valid site API key, valid payload | 200/202; body includes `accepted_uuids` for the chunk. |
| 3 | Payload with 500 sessions | Central accepts in chunks of 200; Pi sends 3 sequential requests; each chunk processed in its own DB transaction. |
| 4 | Chunk 2 fails (e.g. network drop) | Chunk 1 already marked synced on Pi; retry sends only chunk 2 (and 3); no duplicate inserts on central for chunk 1 (idempotent by UUID). |
| 5 | Duplicate upload (same chunk sent twice) | Central ignores duplicate UUIDs; `sync_id_map` prevents duplicate records; 200 and same `accepted_uuids`. |
| 6 | New client with `id_number_hash` matching existing central client | Central merges (links UUID); no new client row created. |
| 7 | Session with `binding_status = 'unconfirmed'` in payload | Central creates session; creates row in `sync_binding_review` for admin review. |
| 8 | Upload includes `identity_registrations` | Stored under correct `session_uuid`; bridge-proxied registrations matched by UUID. |

---

## Delegateable Tasks

### Task E.1 — ConnectivityMonitor (sticky state, 3 fail / 2 success)

**Scope:** Service that checks central reachability; does not flip on a single check; uses consecutive failure/success counts per spec.

**Steps:**
1. **Class** — Implement `ConnectivityMonitor` with: `PING_TIMEOUT_SECONDS = 3`, `ONLINE_CACHE_TTL = 30`, `REQUIRED_CONSECUTIVE_FAILURES = 3`, `REQUIRED_CONSECUTIVE_SUCCESSES = 2`.
2. **Health check** — First attempt TCP connection to `CENTRAL_URL:443`; on success, call `GET {CENTRAL_URL}/api/health` with 3s timeout. Central’s `/api/health` must return a non-cached response with current timestamp (e.g. `{"timestamp": "..."}`). No caching on health endpoint.
3. **State** — Maintain sliding window or consecutive counter: after 3 consecutive failures → mode `offline`; after 2 consecutive successes when offline → mode `bridge` (online). Expose `isOnline(): bool`, `getCurrentMode(): 'bridge'|'offline'`, `getLastCheckedAt()`, `getConsecutiveResult(): int` (+N successes, -N failures).
4. **Cache** — Use 30s TTL so rapid checks don’t hammer central; internal state (consecutive counts) still updated per check when cache is refreshed.
5. **Tests** — Unit tests with mocked HTTP/TCP: 2 failures → still online; 3 failures → offline; 1 success after offline → still offline; 2 successes → online. Integration test: central health returns different timestamps on two quick requests.

**Files:**  
`app/Services/ConnectivityMonitor.php`, `app/Http/Controllers/Api/HealthController.php` (or add action to existing), `tests/Unit/Services/ConnectivityMonitorTest.php`, `tests/Feature/Api/HealthTest.php`.

---

### Task E.2 — connectivity_mode on queue_sessions

**Scope:** Add column; set at bind time; make immutable after creation.

**Steps:**
1. **Migration** — `ALTER TABLE queue_sessions ADD COLUMN connectivity_mode ENUM('bridge', 'offline') NOT NULL DEFAULT 'offline'`.
2. **Bind time** — In `SessionService::bind()` (or equivalent), set `$session->connectivity_mode = $connectivityMonitor->getCurrentMode()` before save. Ensure this runs on both central and Pi (Pi uses ConnectivityMonitor when in edge mode).
3. **Immutability** — In Session model `updating` hook, if `connectivity_mode` is dirty, throw `\RuntimeException('connectivity_mode is immutable after session creation.')`.
4. **Tests** — Feature test: bind session when monitor reports bridge → row has `connectivity_mode = 'bridge'`; attempt to update `connectivity_mode` on existing session → exception. Migration test on SQLite and MariaDB.

**Files:**  
`database/migrations/*_add_connectivity_mode_to_queue_sessions.php`, `app/Models/Session.php` (or QueueSession), `app/Services/SessionService.php`, `tests/Feature/SessionConnectivityModeTest.php`.

---

### Task E.3 — Bridge endpoints on central

**Scope:** Implement all bridge routes on central; return UUID in creation responses; accept site API key.

**Steps:**
1. **Client** — `GET /api/bridge/clients/search?name=&birth_year=`, `POST /api/bridge/clients/lookup-by-id` (body: `id_type`, `id_number`), `POST /api/bridge/clients` (body: `name`, `birth_year`). Create returns `id` + `uuid`.
2. **Identity registration** — `POST /api/bridge/identity-registrations` (body: `program_uuid`, `session_uuid`, `name`, `birth_year`, `id_type`, `id_number`); `GET /api/bridge/identity-registrations/{uuid}/status`. Create returns `id` + `uuid`.
3. **Token** — `GET /api/bridge/tokens/{qr_hash}/availability`.
4. **Auth** — All routes require `Authorization: Bearer {CENTRAL_API_KEY}` (site API key). Resolve site by key (bcrypt verify against `sites.api_key_hash`); reject invalid key with 401.
5. **Tests** — Feature tests per endpoint: 401 without key; 200/201 with valid key; creation responses include `uuid`; search/lookup return expected data.

**Files:**  
`app/Http/Controllers/Api/Bridge/ClientBridgeController.php`, `IdentityRegistrationBridgeController.php`, `TokenBridgeController.php` (or single BridgeController), `app/Http/Middleware/VerifySiteApiKey.php`, `routes/api.php`, `tests/Feature/Api/Bridge/*`.

---

### Task E.4 — Bridge auth + rate limit

**Scope:** Same as E.3 auth; add per-site rate limiting.

**Steps:**
1. **Middleware** — Extract site from `Authorization: Bearer {key}`; attach site to request; verify bcrypt against `sites.api_key_hash`. Return 401 if invalid or missing.
2. **Rate limit** — Apply throttle: 60 requests per minute per site (e.g. by `api_key_hash` or site_id). Return 429 when exceeded. Use Laravel rate limiter with a key like `bridge:site:{id}`.
3. **Tests** — Feature test: 61st request within 1 minute from same key → 429; different site key has its own limit.

**Files:**  
`app/Http/Middleware/VerifySiteApiKey.php`, `app/Http/Middleware/ThrottleBridgeBySite.php`, `app/Providers/RouteServiceProvider.php` or bootstrap (rate limit definition), `routes/api.php`, `tests/Feature/Api/Bridge/BridgeRateLimitTest.php`.

---

### Task E.5 — BridgeService on Pi

**Scope:** Client on Pi that proxies bridge operations to central; graceful fallback and staff-visible warning.

**Steps:**
1. **Service** — `BridgeService` methods for each bridge endpoint: `searchClients()`, `lookupClientById()`, `createClient()`, `createIdentityRegistration()`, `getIdentityRegistrationStatus()`, `getTokenAvailability()`. Use HTTP client to `CENTRAL_URL` with `Authorization: Bearer CENTRAL_API_KEY`.
2. **Timeout** — Use 3s (or configurable) timeout; on timeout or 5xx, do not throw into UI — return a result that indicates failure (e.g. optional DTO with `success: false`, `error: '...'`).
3. **Fallback** — Callers (e.g. triage) use local data when bridge fails; show staff message: "Connection to central lost. Using local data."
4. **Tests** — Unit test with mocked HTTP: success returns data; timeout/error returns failure without exception. Feature test (if Pi app in repo): proxy call when central down shows warning and uses local path.

**Files:**  
`app/Services/BridgeService.php` (on Pi codebase or shared), HTTP client config, `tests/Unit/Services/BridgeServiceTest.php`. Frontend or controller that shows warning (e.g. `resources/js/Pages/...` or `app/Http/Controllers/...`).

---

### Task G.1 — Upload endpoint on central

**Scope:** `POST /api/sync/upload` accepts full payload schema; auth by site API key; returns accepted UUIDs per chunk.

**Steps:**
1. **Auth** — Same as bridge: `Authorization: Bearer {site_api_key}`; resolve site; 401 if invalid.
2. **Payload** — Accept JSON: `site_id`, `program_uuid`, `synced_at`, `chunk_index`, `chunk_total`, `sessions`, `transaction_logs`, `identity_registrations`, `program_audit_log`, `staff_activity_log`, `client_id_audit_log`, `new_clients`, `new_id_document_hashes`. Validate required keys and types (e.g. Form Request).
3. **Processing** — Process single chunk in one DB transaction. Upsert/merge per Phase G conflict resolution (sessions by UUID, logs append-only, etc.). Build list of accepted UUIDs for this chunk.
4. **Response** — 200 with `{ "accepted_uuids": [...], "chunk_index": N }`. On validation error, 422; on server error, 500 and no partial commit.
5. **Tests** — Feature test: valid payload → 200 and `accepted_uuids`; invalid auth → 401; invalid payload → 422; duplicate UUIDs in payload → idempotent (no duplicate rows).

**Files:**  
`app/Http/Controllers/Api/SyncUploadController.php`, `app/Http/Requests/SyncUploadRequest.php`, `app/Services/SyncImportService.php` (or equivalent), `routes/api.php`, `tests/Feature/Api/SyncUploadTest.php`.

---

### Task G.2 — Chunking (Pi-side) and partial failure recovery

**Scope:** Pi chunks pending records (e.g. 200 per chunk); sends sequentially; marks synced only after successful response; retries only failed chunk.

**Steps:**
1. **Pending** — Query all pending records (e.g. `queue_sessions`, `transaction_logs`, etc.) where `synced_to_central_at IS NULL`. Order consistently (e.g. by id or created_at).
2. **Chunk** — Split into chunks of 200 (configurable constant). Build payload per chunk with `chunk_index`, `chunk_total`, and the corresponding entities.
3. **Send loop** — For each chunk, POST to central. On success: mark those records with `synced_to_central_at = now()`; continue to next chunk. On failure: do not mark; schedule retry (e.g. job retry or queue); exit loop.
4. **Retry** — Retry job sends only pending records again (same chunking); central is idempotent so duplicate UUIDs from previous run are ignored.
5. **Tests** — Unit/feature test: 500 pending → 3 chunks sent; simulate failure on chunk 2 → chunk 1 marked synced, chunk 2 and 3 still pending; retry sends chunk 2 and 3.

**Files:**  
`app/Jobs/SyncToCentralJob.php`, `app/Services/SyncToCentralService.php` (optional), `tests/Feature/SyncToCentralJobTest.php` or `tests/Unit/Jobs/SyncToCentralJobTest.php`.

---

### Task G.3 — Conflict resolution implementation

**Scope:** Implement authority and merge rules for each entity type in the upload payload.

**Steps:**
1. **queue_sessions** — Upsert by UUID; Pi authoritative. Set `binding_status` as provided; create `sync_binding_review` row when `binding_status = 'unconfirmed'`.
2. **transaction_logs** — Append-only; merge by UUID; duplicate UUID → skip (no error).
3. **identity_registrations** — Upsert by UUID; bridge-proxied already have central UUID; locally-created from Pi get new central record and store in `sync_id_map`.
4. **new_clients** — Hash-based dedup: if `id_number_hash` matches existing central client, merge (link UUID, update name if different and flag for review); else create new client.
5. **new_id_document_hashes** — Append new hash records for clients created/merged from Pi.
6. **program_audit_log, staff_activity_log, client_id_audit_log** — Append-only; merge by UUID; duplicate UUID ignored.
7. **sync_binding_review** — Insert row per session with `binding_status = 'unconfirmed'` (unique on session_uuid).
8. **Tests** — Feature tests: upload session with unconfirmed binding → `sync_binding_review` row; upload client with matching hash → no duplicate client; upload same UUID twice → single record.

**Files:**  
`app/Services/SyncImportService.php`, `app/Services/SyncConflictResolver.php` (optional), `tests/Feature/Api/SyncUploadConflictTest.php`.

---

### Task G.4 — sync_id_map on central

**Scope:** Store edge UUID → central ID mapping for idempotency and deduplication.

**Steps:**
1. **Migration** — `CREATE TABLE sync_id_map ( id, site_id, entity_type, edge_uuid, central_id, created_at, UNIQUE(site_id, entity_type, edge_uuid) )`.
2. **Usage** — Before inserting/upserting an entity from sync payload, check `sync_id_map` for `(site_id, entity_type, edge_uuid)`. If present, use `central_id` for updates or skip duplicate; if absent, insert new row and record mapping after insert.
3. **Entity types** — e.g. `'session'`, `'transaction_log'`, `'client'`, `'identity_registration'`, etc. Populate for all synced entity types that have central IDs.
4. **Tests** — Upload same chunk twice; assert no duplicate rows in sessions/logs and `sync_id_map` has one row per UUID.

**Files:**  
`database/migrations/*_create_sync_id_map_table.php`, `app/Models/SyncIdMap.php`, `app/Services/SyncImportService.php` (read/write map), `tests/Feature/Api/SyncIdMapTest.php`.

---

## File List (Phase E + G)

### Phase E — Bridge

| Area | Files |
|------|--------|
| Connectivity | `app/Services/ConnectivityMonitor.php`, `app/Http/Controllers/Api/HealthController.php` |
| Session lock | `database/migrations/*_add_connectivity_mode_to_queue_sessions.php`, `app/Models/Session.php`, `app/Services/SessionService.php` |
| Bridge API | `app/Http/Controllers/Api/Bridge/ClientBridgeController.php`, `IdentityRegistrationBridgeController.php`, `TokenBridgeController.php` |
| Auth & limit | `app/Http/Middleware/VerifySiteApiKey.php`, `app/Http/Middleware/ThrottleBridgeBySite.php`, `app/Providers/RouteServiceProvider.php` (or AuthServiceProvider), `routes/api.php` |
| Pi client | `app/Services/BridgeService.php` |
| Tests | `tests/Unit/Services/ConnectivityMonitorTest.php`, `tests/Feature/Api/HealthTest.php`, `tests/Feature/SessionConnectivityModeTest.php`, `tests/Feature/Api/Bridge/*`, `tests/Unit/Services/BridgeServiceTest.php` |

### Phase G — Sync

| Area | Files |
|------|--------|
| Upload | `app/Http/Controllers/Api/SyncUploadController.php`, `app/Http/Requests/SyncUploadRequest.php`, `app/Services/SyncImportService.php`, `routes/api.php` |
| Id map | `database/migrations/*_create_sync_id_map_table.php`, `app/Models/SyncIdMap.php` |
| Binding review | `database/migrations/*_create_sync_binding_review_table.php`, `app/Models/SyncBindingReview.php` (if not in G.3) |
| Pi job | `app/Jobs/SyncToCentralJob.php`, `app/Services/SyncToCentralService.php` (optional) |
| Tests | `tests/Feature/Api/SyncUploadTest.php`, `tests/Feature/Api/SyncUploadConflictTest.php`, `tests/Feature/Api/SyncIdMapTest.php`, `tests/Feature/SyncToCentralJobTest.php` |

---

## Notes

- **Session connectivity lock:** All actions for a session (call, serve, transfer, complete) should use `session.connectivity_mode`, not the current `ConnectivityMonitor` state, so behavior is stable for the lifetime of the session.
- **Bridge rate limit:** 60 req/min per site key is per spec; consider separate limits for sync upload if needed (e.g. higher limit for bulk upload with larger payload).
- **Chunk size:** 200 records per chunk (sessions + logs combined) per v2-final; Pi splits so each POST has at most 200 of the combined record types that count toward the limit.
- **sync_binding_review:** Table and population are part of G.3; admin review UI is out of scope (future feature).
