# Phase B — Multi-Tenant / Sites — Execution Plan

**Reference:** [central-edge-tasks.md](../../central-edge-tasks.md) (Phase B), [central-edge-v2-final.md](../central-edge-v2-final.md) (Phase B — Multi-Tenant / Sites)  
**Applied rules:** Database Optimizer (schema, indexes, migrations, SQLite + MariaDB), Security Engineer (API key handling, least privilege, no raw secrets in storage)

**Goal:** Introduce multi-tenant sites: `sites` table, API key lifecycle (hash-only storage, show raw once), validated `edge_settings` JSON, and site-scoped programs/users. No raw `api_key` in DB; invalid/revoked keys return 401.

**Status:** Draft — ready for implementation when Phase B is prioritized.

---

## Security Checklist (enforce in implementation)

- [ ] **No raw API key in DB** — `sites` has only `api_key_hash` (bcrypt). `DESCRIBE sites` / schema assertion in CI must confirm no `api_key` column.
- [ ] **Raw key shown exactly once** — Only in create-site response (or regenerate response). `GET /api/admin/sites/{id}` returns masked value (e.g. `sk_live_...****`), never raw.
- [ ] **401 on invalid/revoked key** — Sync and bridge endpoints return 401 Unauthorized for wrong key or after regenerate (old hash no longer matches).
- [ ] **Least privilege** — Site API key authenticates the site only; no admin escalation. Sync/bridge middleware validates key and binds request to `site_id`.
- [ ] **Input validation** — `edge_settings` validated with JSON Schema; unknown keys rejected (422).

---

## Delegateable tasks

### Task B.1 — Sites table migration (SQLite + MariaDB)

**Scope:** Schema, FKs, indexes, default site seeder. **Out of scope:** API key lifecycle endpoints and middleware (B.2), `edge_settings` JSON validation (B.3), and site scoping in controllers (B.4).

**Steps:**

1. **Migration: `sites` table**
   - Columns: `id` (bigInteger unsigned, auto-increment PK), `name` (string 255), `slug` (string 100, unique, used as SITE_ID in Pi `.env`), `api_key_hash` (string 255, unique, not null), `settings` (json nullable), `edge_settings` (json not null default `'{}'`), `created_at`, `updated_at`.
   - Implement for **both** SQLite and MariaDB/MySQL using Laravel’s schema builder so JSON columns remain portable (`json()` → TEXT + check on SQLite, JSON on MariaDB). Default `'{}'` is acceptable as a string representation of an empty JSON object on both engines.
   - Indexes: unique on `slug`, unique on `api_key_hash` (per Database Optimizer: index columns used in lookups).
   - **Security invariant:** Table must not contain any `api_key` column; only `api_key_hash` is allowed (mirrors v2-final §Phase B and cross-cutting CI rule).

2. **Migration: add `site_id` to `programs` and `users`**
   - `programs`: add `site_id` (nullable, foreign key to `sites.id`). Index `site_id` (every FK gets an index for joins).
   - `users`: add `site_id` (nullable, foreign key to `sites.id`). Index `site_id`.
   - Use `foreignId()->nullable()->constrained()->nullOnDelete()` (or equivalent) so existing rows remain valid. Reversible: down() drops columns.
   - **Compatibility note:** `site_id` remains nullable so that pre-B.4 application code that creates programs/users without setting `site_id` does not break. B.4 will introduce controller/service-level scoping and enforce `site_id` on new records.

3. **Default site seeder (existing data migration)**
   - Create a single default site (e.g. `name: "Default", slug: "default"`) with conservative `edge_settings`: at minimum `bridge_enabled: false`, `sync_clients: false`; other keys may use v2-final defaults where convenient.
   - Generate a cryptographically random key, bcrypt hash it, and set `api_key_hash`; do **not** persist or log the raw key (default site’s key is not wired into any runtime behavior until B.2).
   - Update all existing `programs` and `users` with `site_id = null` to `site_id = <default_site_id>` in the same seeder or a follow-up data migration. This guarantees legacy installations have a consistent `default` site without behavior change.

4. **Tests**
   - Schema tests asserting:
     - `sites` table exists with columns: `id`, `name`, `slug`, `api_key_hash`, `settings`, `edge_settings`, timestamps.
     - Column list does **not** include `api_key` (security invariant from v2-final, §Cross-Cutting).
     - `programs` and `users` tables have a nullable `site_id` column.
   - Seeder behavior:
     - Running the default site seeder on a database with existing programs/users creates a `default` site and assigns all `programs.site_id` and `users.site_id` to that site.
     - The default site’s `edge_settings` JSON includes `bridge_enabled: false` and `sync_clients: false`.
   - Portability:
     - Migrations rely on Laravel schema builder only (no driver-specific raw SQL), so they run on both SQLite and MariaDB. Dual-driver execution is covered by the global X.1 CI task.

**Files:**

- `database/migrations/xxxx_create_sites_table.php`
- `database/migrations/xxxx_add_site_id_to_programs_and_users.php` (or two migrations)
- `database/seeders/DefaultSiteSeeder.php` (or equivalent)
- `tests/Feature/Migrations/SitesMigrationTest.php` (or similar)

---

### Task B.2 — API key lifecycle

**Scope:** Generate `sk_live_` site API keys (length ≥ 40 chars including prefix), store only bcrypt hashes in `sites.api_key_hash`, show raw keys exactly once on create/regenerate, and authenticate sync/bridge requests via a dedicated middleware that binds a `site` context (least privilege; no admin user escalation).

**Endpoints & middleware (Phase B only):**

1. **Admin Site API (session-auth, role:admin)**
   - `POST /api/admin/sites` → create site, generate key, store `api_key_hash`, return raw key once.
     - Controller: `App\Http\Controllers\Api\Admin\SiteController@store`.
     - Form request: `StoreSiteRequest` (validates `name`, `slug` uniqueness; leaves `edge_settings` mostly unvalidated until B.3).
     - Response example:
       ```json
       {
         "site": {
           "id": 1,
           "name": "Dagupan CSWDO",
           "slug": "mswdo-dagupan",
           "settings": {},
           "edge_settings": {}
         },
         "api_key": "sk_live_...REDACTED..."
       }
       ```
     - Raw key is never logged or cached; only the API response shows it.
   - `GET /api/admin/sites/{site}` → show site details **without** raw key.
     - Controller: `SiteController@show`.
     - Returns `site` plus a generic masked indicator, e.g. `"api_key_masked": "sk_live_...****"`. This does not reveal the actual key.
   - `POST /api/admin/sites/{site}/regenerate-key` → generate new key and replace `api_key_hash`.
     - Controller: `SiteController@regenerateKey`.
     - Response includes the new raw key once (same shape as create); old key is immediately invalid for auth.

2. **Site API key auth middleware (sync/bridge)**
   - Middleware: `App\Http\Middleware\AuthenticateSiteByApiKey`.
   - Behaviour:
     - Reads `Authorization` header and expects `Bearer {raw_key}`.
     - Missing header, malformed scheme, or empty token → `401 Unauthorized` JSON response.
     - Iterates all `Site` records and uses `Hash::check($rawKey, $site->api_key_hash)` to find a match (cannot index by raw key); on first match:
       - Binds site context to the request:
         - `$request->attributes->set('site', $site)`
         - `$request->attributes->set('site_id', $site->id)`
       - Proceeds to next middleware/handler.
     - No match → `401 Unauthorized`.
     - This middleware authenticates the **site only**; it does not create an authenticated user or grant admin privileges.
   - For Phase B, wire a minimal sync stub for end-to-end verification:
     - `POST /api/sync/test-site-auth` (no session auth; site-key only).
     - Returns `200` with `{"site_id": <id>, "slug": "<slug>"}` using the bound site context.
     - Real sync/bridge endpoints in Phases E/G will reuse this middleware.

3. **Key generation & storage details**
   - Service: `App\Services\SiteApiKeyService`.
   - `generateKey(): string`
     - Uses `Str::random()` or `random_bytes` to generate a high-entropy token.
     - Prefixes with `sk_live_`.
     - Asserts `strlen($key) >= 40` to satisfy v2-final §Phase B.
   - `assignNewKey(Site $site): string`
     - Calls `generateKey()`, computes `Hash::make($rawKey)`, assigns to `$site->api_key_hash`, saves, and returns raw key.
     - Used for both initial assignment and regeneration.
   - Optional helper: `maskedPlaceholder(): string` returning `"sk_live_...****"` for GET responses.

**Tests (minimum scenarios for B.2):**

1. **Admin Site endpoints (Feature tests, role:admin)**
   - `test_store_creates_site_and_returns_raw_api_key_once`
     - `POST /api/admin/sites` with valid payload.
     - Asserts `201`, presence of `api_key` starting with `sk_live_` and `strlen(api_key) >= 40`.
     - Asserts DB has site row with non-null `api_key_hash` that does **not** equal the raw `api_key`.
   - `test_show_site_does_not_expose_raw_api_key`
     - Creates a `Site` with a real `api_key_hash` (via `SiteApiKeyService`).
     - `GET /api/admin/sites/{site}` returns `200`, includes `site` data, includes `api_key_masked === "sk_live_...****"`, and does **not** contain the raw key string anywhere.
   - `test_regenerate_key_replaces_hash_and_returns_new_raw_key`
     - Creates a `Site` with an initial key via `assignNewKey()`.
     - Calls `POST /api/admin/sites/{site}/regenerate-key`.
     - Asserts response `200`, new `api_key` different from old, DB `api_key_hash` changed and matches new key via `Hash::check`, and old key fails auth (see middleware tests).

2. **Site API key auth middleware (Feature tests)**
   - `test_request_with_missing_authorization_header_returns_401`
     - `POST /api/sync/test-site-auth` without `Authorization` header → `401`.
   - `test_request_with_malformed_authorization_header_returns_401`
     - `Authorization: Basic abc123` or `Bearer` without token → `401`.
   - `test_request_with_invalid_api_key_returns_401`
     - Creates one or more `Site` records with hashes; calls stub with `Authorization: Bearer invalid` → `401`.
   - `test_request_with_valid_api_key_returns_200_and_is_site_scoped`
     - Creates two `Site` records, each with distinct keys via `SiteApiKeyService`.
     - For each key, `POST /api/sync/test-site-auth` returns `200` and the correct `site_id`/`slug`.
   - `test_old_key_fails_and_new_key_succeeds_after_regeneration`
     - Creates a `Site` with initial key; hits regenerate endpoint to get new key.
     - Old key → `401`; new key → `200` on stub route.

**Files:**

- `app/Services/SiteApiKeyService.php`
- `app/Http/Controllers/Api/Admin/SiteController.php`
- `app/Http/Middleware/AuthenticateSiteByApiKey.php`
- `app/Http/Requests/StoreSiteRequest.php`
- `routes/web.php` (admin site routes under existing `/api/admin` group; `POST /api/sync/test-site-auth` with middleware)
- `tests/Feature/Api/Admin/SiteControllerTest.php`
- `tests/Feature/Api/SiteApiKeyAuthTest.php`

---

### Task B.3 — `edge_settings` JSON validation

**Scope:** Strict validation/normalization for `edge_settings`; unknown keys rejected; enums and defaults applied on write via a dedicated validator.

**Steps:**

1. **Define schema (per v2-final §Phase B)**
   - Keys and types:
     - `sync_clients` (bool)
     - `sync_client_scope` (enum: `program_history`, `all`)
     - `sync_tokens` (bool)
     - `sync_tts` (bool)
     - `bridge_enabled` (bool)
     - `offline_binding_mode_override` (enum: `optional`, `required`)
     - `scheduled_sync_time` (string, `HH:MM` 24-hour time)
     - `offline_allow_client_creation` (bool)
   - Defaults applied by the validator when keys are absent:
     - `sync_clients: true`
     - `sync_client_scope: "program_history"`
     - `sync_tokens: true`
     - `sync_tts: true`
     - `bridge_enabled: false`
     - `offline_binding_mode_override: "optional"`
     - `scheduled_sync_time: "17:00"`
     - `offline_allow_client_creation: true`

2. **Validation on save**
   - Implement a dedicated `EdgeSettingsValidator` that:
     - Accepts an associative array payload and validates:
       - Payload is an object/array.
       - Only known keys from the schema are present; unknown keys are rejected.
       - Types match the schema and enums are enforced.
       - `scheduled_sync_time` is a valid `HH:MM` 24-hour time (00–23, 00–59).
     - Returns a **normalized** array with all keys present and defaults applied for any missing keys.
   - Integration points:
     - Default site creation (`DefaultSiteSeeder`) uses the validator so the initial `default` site gets a fully-populated, spec-compliant `edge_settings` document (with conservative overrides for `bridge_enabled` and `sync_clients`).
     - Admin Site create/update APIs (B.2) call the same validator before persisting `edge_settings`; invalid payloads surface as 422 validation errors.

3. **Implementation**
   - Implementation uses Laravel’s validation facilities in a dedicated class:
     - `App\Validation\EdgeSettingsValidator::validate(array $payload): array`
       - Uses `Validator::make` with nested rules for each key, including `in:` enums.
       - Adds a post-validation hook to:
         - Reject unknown keys with clear messages (e.g. `"Unknown key 'foo' in edge_settings."`).
         - Enforce strict `HH:MM` format and range checks for `scheduled_sync_time`.
       - On success, merges the payload with the default map to produce a normalized array (keys sorted for determinism).
       - On failure, throws `Illuminate\Validation\ValidationException` for upstream handlers (Form Requests/controllers) to convert to 422 responses.

4. **Tests**
   - **Unit (validator)**
     - Empty payload → returns fully-populated defaults array (all keys present with spec defaults).
     - Full valid payload (all keys set) → returned normalized result matches provided values (only ordering differs).
     - Payload with `"foo": "bar"` → `ValidationException` with error on `edge_settings.foo`.
     - Invalid enum for `sync_client_scope` or `offline_binding_mode_override` → `ValidationException`.
     - Invalid types (e.g., `"yes"` for `sync_clients`) → `ValidationException`.
     - Invalid `scheduled_sync_time` formats (`"9:00"`, `"24:01"`) → `ValidationException`.
   - **Feature (Phase B integration)**
     - `DefaultSiteSeeder`-created site has `edge_settings` containing all schema keys with expected defaults:
       - `bridge_enabled: false` (overridden),
       - `sync_clients: false` (overridden),
       - other keys at their spec defaults including `scheduled_sync_time` and `offline_allow_client_creation`.
     - Future B.2 Site admin API tests assert that invalid `edge_settings` payloads (unknown keys, bad enums/types, invalid time) result in HTTP 422.

**Files:**

- `app/Validation/EdgeSettingsValidator.php` (schema + validation)
- `database/seeders/DefaultSiteSeeder.php` (default site uses validator for normalized edge_settings)
- `app/Http/Requests/StoreSiteRequest.php`, `UpdateSiteRequest.php` (or inline in controller) calling the validator (B.2)
- `tests/Unit/Validation/EdgeSettingsValidatorTest.php`
- `tests/Feature/PhaseB/SitesMigrationTest.php` (extended assertions for default `edge_settings`; future `tests/Feature/Api/Admin/SiteEdgeSettingsTest.php` under B.2)

---

### Task B.4 — Site scoping (programs, users)

**Scope:** Programs and users filtered by `site_id`; cross-site isolation.

**Decisions (Step 4):** site_id from `$request->user()->site_id`; no super-admin. Cross-site by ID → 404. Admin with null site_id → index empty, show/store 403. Model scopes `forSite($siteId)` (null = no rows).

**Steps:**

1. **Program scoping**
   - All admin (and any future) listing/editing of programs must be scoped by current user’s `site_id` (or by requested site for super-admin if applicable). Program index: `Program::where('site_id', $siteId)`. Create/update: set `site_id` from authenticated context. Ensure no program from Site A is visible or editable in Site B’s context.

2. **User scoping**
   - Same for users: list and manage users by `site_id`. User index: `User::where('site_id', $siteId)`. New users get `site_id` from context. Isolate Site A vs Site B users.

3. **Auth context**
   - Ensure authenticated admin user has `site_id` (from `users.site_id`). Use that for all program and user scoping. If super-admin can see all sites, implement explicitly (e.g. role check) and scope by selected site or by user’s `site_id`.

4. **Tests**
   - Create two sites, programs and users per site. As admin of Site A, list programs → only Site A programs. Same for users. Attempt to access Site B program by ID (e.g. show/edit) → 403 or 404 as per design.

**Files:**

- `app/Http/Controllers/Api/Admin/ProgramController.php`, `UserController.php`, `ProgramStaffController.php`
- `app/Http/Controllers/Admin/ProgramPageController.php`, `UserPageController.php`, `ReportPageController.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Models/Program.php`, `app/Models/User.php` (scopeForSite)
- `tests/Feature/Api/Admin/ProgramSiteScopingTest.php`, `UserSiteScopingTest.php`

---

## File list (Phase B)

| Area | Files |
|------|--------|
| Migrations | `database/migrations/xxxx_create_sites_table.php`, `database/migrations/xxxx_add_site_id_to_programs_and_users.php` |
| Seeders | `database/seeders/DefaultSiteSeeder.php` |
| Models | `app/Models/Site.php` (with casts for `settings`, `edge_settings`) |
| Services | `app/Services/SiteApiKeyService.php`, `app/Validation/EdgeSettingsValidator.php` (or under Services) |
| Controllers | `app/Http/Controllers/Api/Admin/SiteController.php`; updates to Program/User controllers for site scoping |
| Middleware | `app/Http/Middleware/AuthenticateSiteByApiKey.php` |
| Requests | `app/Http/Requests/StoreSiteRequest.php`, `UpdateSiteRequest.php` (if used) |
| Routes | `routes/api.php` (admin sites CRUD, regenerate-key, sync route with API key middleware) |
| Tests | SitesMigrationTest, SiteControllerTest, SiteApiKeyAuthTest, EdgeSettingsValidationTest, SiteEdgeSettingsTest, ProgramSiteScopingTest, UserSiteScopingTest |

---

## Notes

- **B.5 (Admin UI for site management)** implemented: SitesPageController, Index/Create/Show Svelte pages, masked key + regenerate + edge settings form, Sites nav, feature tests (SitesPageTest, SiteControllerTest index/update/422).
- Default site’s initial API key: options are (1) generated in seeder but only printed to log once in dev, (2) set via artisan command `site:create-default-key`, or (3) first site created via API. Choose one and document.
- Sync and bridge routes are protected by the same site API key middleware; exact route names and middleware group can be defined when implementing sync (Phase G) and bridge (Phase E).

---

## 🔧 B.S — Stabilize (completed)

**Outcome:** No regressions found. Full test suite passes. One additional edge-case test was added to satisfy the spec.

**Checks performed:**
- Ran full test suite: all Phase B–related tests pass (SitesMigrationTest, SiteControllerTest, SiteApiKeyAuthTest, ProgramSiteScopingTest, UserSiteScopingTest, SitesPageTest, HandleInertiaRequestsAdminProgramsTest, EdgeSettingsValidatorTest).
- Edge cases verified by existing tests: admin with null `site_id` (empty program/user list, 403 on create/show); duplicate slug on create (422); invalid `edge_settings` (422 for bad time); regenerate key then use old key (401); cross-site program/user access (404); unknown key in `edge_settings` on update (422) — test added in B.S.

**Change made:**
- **tests/Feature/Api/Admin/SiteControllerTest.php:** Added `test_update_site_unknown_key_in_edge_settings_returns_422` to assert that updating a site with an unknown key in `edge_settings` (e.g. `foo` => `bar`) returns 422 and validation error on `edge_settings.foo`, per B.3 / v2-final.

**Known limitations (unchanged):**
- Sites list/index is not scoped by admin `site_id`; all admins see all sites (by design for Phase B).
- Default site seeder does not log or expose the initial API key; it is generated and stored as hash only.
