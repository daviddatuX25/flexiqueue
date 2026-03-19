# Phase D — Program Package API — Execution Plan

**Reference:** [central-edge-tasks.md](../../central-edge-tasks.md) (Phase D), [central-edge-v2-final.md](../../central-edge-v2-final.md) (Phase D, §Decision 1, Cross-Cutting)  
**Goal:** Deliver the Program Package API: UUID migration, package export (manifest + conditional clients/tokens), streaming/background export endpoint, and transactional import command with `activateExclusive()`. Enforce the security invariant that **no `id_number_encrypted`** ever leaves central in a package.

**Status:** Draft — ready for implementation.

---

## Security Invariant (CI-Blocking)

- **PII invariant:** An automated test runs on every package export and asserts that `id_number_encrypted` does **not** appear anywhere in the package contents (extracted `.tar.gz`). This test is in the CI pipeline; **failure blocks the build**.
- **Enforcement:** After building a package in tests, extract it to a temp directory and run a content check (e.g. `grep -r "id_number_encrypted" extracted/` or equivalent in PHP). Assert zero matches.

---

## Test Scenarios (API + Feature)

| # | Scenario | Expected |
|---|----------|----------|
| 1 | `GET /api/admin/programs/{id}/export-package` (small package) | 200, streaming `.tar.gz`; extract yields manifest + program, stations, tracks, processes, users, etc. per package structure |
| 2 | Export with `sync_clients=true`, `client_scope=program_history` | Package contains `clients.json` with only `name`, `birth_year`, `uuid`; no `id_number_encrypted` |
| 3 | Export with `sync_clients=false` | Package does **not** contain `clients.json` |
| 4 | Export with `sync_tokens=true` / `false` | `tokens.json` present only when true; manifest reflects |
| 5 | Security invariant test | Extract package, search all files for `id_number_encrypted` → 0 matches (CI gate) |
| 6 | Large package (TTS) — background job | Export returns job ID or redirect; poll for completion; download URL returns valid `.tar.gz` |
| 7 | `php artisan flexiqueue:import-package package.tar.gz` on empty DB | Program active, staff can log in, tokens recognized, display board loads; `activateExclusive(program_id)` was called |
| 8 | Re-import on Pi with existing sessions | `queue_sessions` count unchanged; no session rows modified or deleted |
| 9 | Import with invalid/corrupt manifest or checksum mismatch | Command fails; no DB changes applied (transaction rollback) |
| 10 | Station deleted on central, re-import on Pi with active session at that station | Session preserved; station marked `orphaned_on_central = true`; session can complete |
| 11 | Kill process mid-import (e.g. during station upsert) | Re-run import succeeds; DB consistent (no partial state) |
| 12 | Bridge creation endpoints (client, identity registration) | Response includes `uuid` alongside `id`; Pi can store UUID for sync |

---

## Delegateable Tasks

### Task D.1 — UUID migration

**Scope:** Add `uuid` column to all 8 tables; backfill; PHP fallback for drivers without DEFAULT expression.

**Steps:**
1. **Migrations** — Add `uuid` column (CHAR(36) / TEXT) with driver-specific DEFAULT: MariaDB `DEFAULT (UUID())`; SQLite use the spec’s expression or nullable first then backfill. Tables: `queue_sessions`, `transaction_logs`, `clients`, `client_id_documents`, `identity_registrations`, `programs`, `tokens`, `stations`.
2. **Backfill** — For each table, generate UUIDs for existing rows (e.g. in migration or dedicated command). Ensure NOT NULL after backfill.
3. **Models** — In each model’s `booted()`, if `uuid` is null on creating, set it (e.g. `Str::uuid()->toString()`) so drivers without DEFAULT still get a UUID.
4. **Tests** — Unit or migration test: new records get `uuid`; backfilled records have non-null `uuid`. Verify on both SQLite and MariaDB in CI.

**Files:**  
`database/migrations/xxxx_add_uuid_to_*_tables.php` (or one migration per table), `app/Models/Program.php`, `app/Models/Station.php`, `app/Models/Token.php`, `app/Models/Client.php`, `app/Models/ClientIdDocument.php`, `app/Models/IdentityRegistration.php`, `app/Models/QueueSession.php` (or Session), `app/Models/TransactionLog.php`, plus tests (e.g. `tests/Unit/Models/*UuidTest.php` or feature migration tests).

---

### Task D.2 — Package exporter (manifest, files, clients/tokens conditional)

**Scope:** Build the package content (manifest + JSON + TTS) per v2-final package format; conditional inclusion for clients and tokens from site/program config.

**Steps:**
1. **Manifest** — Generate `manifest.json` with SHA-256 checksum for every file in the package. Include `token_deactivation_list` (array of token UUIDs deactivated on central since last export for this site/program). Manifest schema: file path → checksum; plus metadata (program_uuid, timestamp, sync_clients, sync_tokens, sync_tts, client_scope).
2. **Core exports** — Export `program.json`, `stations.json`, `service_tracks.json`, `processes.json`, `station_process.json`, `users.json`, `print_settings.json`, `program_diagram.json`, `temporary_authorizations.json`, `edge_settings.json`. Use UUIDs for references where specified (e.g. program_uuid).
3. **Conditional tokens** — If `sync_tokens=true` (from query or site edge_settings), include `tokens.json` with tokens from `program_token` for this program; never include sensitive data beyond what’s in spec.
4. **Conditional clients** — If `sync_clients=true`, include `clients.json` and `id_document_hashes.json`. Client scope: `program_history` (only clients who have had sessions in this program). Fields: `name`, `birth_year`, `uuid`; associated ID document **hashes** only. **Never** include `id_number_encrypted` or raw ID numbers.
5. **Identity registrations** — Export `identity_registrations.json` for this program (existing registrations).
6. **TTS** — If `sync_tts=true`, bundle `tts/tokens/` and `tts/stations/` per manifest; include checksums in manifest.
7. **Package assembly** — Write all files into a temp directory, then create `flexiqueue-package-{program_uuid}-{timestamp}.tar.gz`. Checksums in manifest must match actual file contents.
8. **Tests** — Feature/unit tests: export with different query flags; assert presence/absence of `clients.json`, `tokens.json`; assert manifest checksums verify; assert no `id_number_encrypted` in any file (invariant test).

**Files:**  
`app/Services/PackageExportService.php` (or equivalent), `app/Services/ManifestBuilder.php` (optional), support in `Program`, `Station`, `Token`, `Client` for export serialization. Tests: `tests/Feature/PackageExportTest.php`, `tests/Unit/PackageExportServiceTest.php`.

---

### Task D.3 — Security invariant CI test

**Scope:** Automated test that runs on every package export and fails CI if PII leaks.

**Steps:**
1. In test, trigger a full package export (with `sync_clients=true` and any client data that could theoretically include encrypted ID).
2. Extract the `.tar.gz` to a temporary directory.
3. Recursively search all extracted files (including JSON) for the string `id_number_encrypted` (or equivalent PII field names from spec). Use PHP `str_contains`/file_get_contents or shell `grep -r`.
4. Assert zero occurrences. Document that this test must run in CI and block the build on failure.

**Files:**  
`tests/Feature/PackageExportSecurityTest.php` or extend `PackageExportTest.php`.

---

### Task D.4 — Export endpoint (streaming + background job)

**Scope:** `GET /api/admin/programs/{program}/export-package` with optional query overrides; streaming for small packages; background job + polling for large (e.g. TTS).

**Steps:**
1. **Auth & authorization** — Endpoint requires admin (or appropriate role). Resolve program by id/slug; ensure user/site can export that program.
2. **Query params** — Support overrides: `sync_clients`, `client_scope`, `sync_tokens`, `sync_tts` (from spec). Default from site’s `edge_settings` when applicable.
3. **Small package** — When export size is below a threshold (e.g. no TTS or small TTS), generate package synchronously and return streaming response: `Content-Type: application/gzip`, `Content-Disposition: attachment; filename="..."`. Use Symfony StreamedResponse or Laravel streamDownload.
4. **Large package** — When TTS or size exceeds threshold, create a background job (e.g. `ExportPackageJob`) that builds the package and stores it (e.g. storage disk or temp URL). Return 202 with job ID and polling URL (e.g. `GET /api/admin/programs/{id}/export-package/status/{jobId}`). When job completes, polling returns download URL or redirect to download.
5. **Tests** — Feature test: authenticated admin gets 200 and valid `.tar.gz` for small export; for large, 202 + poll until complete + download; unauthenticated or non-admin gets 401/403.

**Files:**  
`app/Http/Controllers/Api/Admin/ProgramExportController.php` (or under existing Admin), `app/Jobs/ExportPackageJob.php`, routes in `api.php`, `tests/Feature/Api/Admin/ProgramExportTest.php`.

---

### Task D.5 — Import command (manifest verify, transactional import, activateExclusive)

**Scope:** `php artisan flexiqueue:import-package {path}`: verify manifest checksums, import in a single DB transaction, apply conflict rules, stage TTS atomically, call `ProgramService::activateExclusive()` for the imported program.

**Steps:**
1. **Command** — Register `flexiqueue:import-package`. Accept path to `.tar.gz`. Extract to temp dir.
2. **Manifest verification** — Read `manifest.json`; for each listed file, verify SHA-256. If any mismatch, abort and delete temp files; no DB changes.
3. **Transactional import** — Open a single DB transaction. In order: program (upsert by UUID) → stations (upsert by UUID; mark `orphaned_on_central` when station deleted on central but session still references it) → service_tracks, processes, station_process → users (upsert by UUID; deactivate, never delete) → tokens (upsert by UUID; apply `token_deactivation_list`) → clients (upsert by UUID; Pi-authoritative for locally-created: do not overwrite name/birth_year if Pi-created) → id_document_hashes, identity_registrations → print_settings, program_diagram, temporary_authorizations, edge_settings. Never modify or delete existing `queue_sessions`, `transaction_logs`, or `program_audit_log`.
4. **TTS** — If present, write TTS files to a staging directory; after transaction commit, atomically move/replace target TTS directory so that a partial write is not visible.
5. **Activation** — After successful commit, call `ProgramService::activateExclusive($programId)` so the imported program is the single active program on the Pi.
6. **Failure handling** — On any exception during import, roll back the transaction; leave filesystem and DB in a consistent state. Re-run of the command after fix should succeed (idempotent where applicable).
7. **Tests** — Feature test: import on empty DB → program active, staff login, tokens work; re-import with existing sessions → session count unchanged; corrupt checksum → no DB change; simulated failure mid-import → re-run succeeds.

**Files:**  
`app/Console/Commands/ImportPackageCommand.php`, `app/Services/PackageImportService.php`, `app/Services/ProgramService.php` (ensure `activateExclusive` exists). Tests: `tests/Feature/ImportPackageCommandTest.php`.

---

### Task D.6 — Bridge API UUID contract (D.7 in task list)

**Scope:** Ensure all bridge creation endpoints return `uuid` alongside `id` so Pi can store UUID for Phase G sync.

**Steps:**
1. **Client create** — `POST /api/bridge/clients` response must include `uuid` and `id`. Ensure Client model has `uuid` (from D.1) and resource/controller returns it.
2. **Identity registration create** — `POST /api/bridge/identity-registrations` response must include `uuid` and `id`. Same for IdentityRegistration model and response.
3. **Tests** — Feature test: POST to each endpoint; assert response JSON has both `id` and `uuid`; optionally assert Pi can store and later use UUID (if Pi code exists in repo).

**Files:**  
`app/Http/Controllers/Api/Bridge/*` (or equivalent), API resources/transforms for Client and IdentityRegistration. Tests: `tests/Feature/Api/Bridge/BridgeClientApiTest.php`, `tests/Feature/Api/Bridge/BridgeIdentityRegistrationApiTest.php`.

---

## File List (Phase D)

| Area | Files |
|------|--------|
| Migrations | `database/migrations/*_add_uuid_*.php` (8 tables) |
| Models | `app/Models/Program.php`, `Station.php`, `Token.php`, `Client.php`, `ClientIdDocument.php`, `IdentityRegistration.php`, Session/QueueSession, `TransactionLog.php` |
| Export | `app/Services/PackageExportService.php`, `app/Http/Controllers/Api/Admin/ProgramExportController.php`, `app/Jobs/ExportPackageJob.php` |
| Import | `app/Console/Commands/ImportPackageCommand.php`, `app/Services/PackageImportService.php` |
| Program | `app/Services/ProgramService.php` (activateExclusive) |
| Bridge | `app/Http/Controllers/Api/Bridge/*`, response shapes for client + identity registration |
| Tests | `tests/Feature/PackageExportTest.php`, `tests/Feature/PackageExportSecurityTest.php`, `tests/Feature/Api/Admin/ProgramExportTest.php`, `tests/Feature/ImportPackageCommandTest.php`, `tests/Feature/Api/Bridge/*`, migration/model UUID tests |
| Routes | `routes/api.php` (export, export status, bridge routes) |

---

## Notes

- **Re-import semantics:** Import never touches existing `queue_sessions`, `transaction_logs`, or `program_audit_log`. Only config and reference data are overwritten/upserted per spec.
- **Client scope:** When `sync_clients=true`, only clients with program history (sessions in this program) are included; hashes only, no `id_number_encrypted`.
- **Token deactivation:** Manifest’s `token_deactivation_list` is applied during import so Pi deactivates tokens that were turned off on central after the last export.
