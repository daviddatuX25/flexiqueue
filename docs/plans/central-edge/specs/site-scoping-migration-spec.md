# Site Scoping Migration — Execution Spec

**Reference:** [SITE-SEPARATION-STUDY.md](../SITE-SEPARATION-STUDY.md), [central-edge-v2-final.md](central-edge-v2-final.md) Phase B, [SUPER-ADMIN-VS-ADMIN-SPEC.md](../SUPER-ADMIN-VS-ADMIN-SPEC.md)  
**Task list:** [central-edge-tasks.md](../central-edge-tasks.md) Phase S

**Scope:** Central multi-tenant only. Extend site scoping from programs and users (Phase B) to tokens, clients, and print settings. No triage/display URL or flow changes; site is implicit from `user.site_id` (and program context for triage/display).

---

## 1. Severity and order

| Severity | Entity | Rationale |
|----------|--------|-----------|
| **High** | Tokens, Clients | Cross-site visibility today; admin APIs list all rows with no site filter |
| **Medium** | Print settings | Single global row shared by all sites |
| **Low** | Report/audit guards; site isolation regression | Defense-in-depth; verification |

**Order:** Schema migrations (tokens, clients, print settings) first, then API/service changes, then verification. Tokens (S.1–S.2) and clients (S.3–S.4) can proceed in parallel after their schema is in place.

---

## 2. Tokens (S.1–S.2)

### Schema
- Add `tokens.site_id` (nullable, foreign key to `sites.id`). Index on `site_id`.
- Migration: SQLite + MariaDB (both drivers).
- Backfill: Set from `program_token` → `program.site_id`. If token is in multiple sites, use the first program's site or a deterministic rule (e.g. min program.site_id). Tokens with no program_token row: assign to default site.

### Model
- `Token` model: add `site_id` to fillable; add `site()` BelongsTo; add `scopeForSite($query, $siteId)`.

### API and services

| File | Change |
|------|--------|
| `TokenController` | `index`: scope `Token::query()->forSite($siteId)` for site admin; super_admin: optional `?site_id=` or all. `store`, `update`, `destroy`, batch methods: ensure token(s) belong to user's site or 403/404. |
| `TokenService::batchCreate` | Accept `site_id` (from auth); insert into tokens. Caller passes `$request->user()->site_id`; 403 if null. |
| `ProgramTokenService::bulkAssignByPattern` | Restrict token query to `Token::where('site_id', $program->site_id)` so only same-site tokens can be bulk-assigned. |
| `TokenPrintController` | Token list for print: scope by auth user's `site_id`. |
| `TokenTtsSettingsController` | Token queries scoped by site. |
| `AnalyticsService` | Any token queries in admin context: scope by site (or by program which is already site-scoped). |
| `SystemStorageService` | Token iterations: scope by site when used in admin context. |

### Super_admin
- Optional `?site_id=` query param on token list; if absent, see all tokens. No change to site admin (strict site filter).

---

## 3. Clients (S.3–S.4)

### Schema
- Add `clients.site_id` (nullable, foreign key to `sites.id`). Index on `site_id`.
- Migration: SQLite + MariaDB.
- Backfill: From first `queue_sessions.program_id` or `identity_registrations.program_id` → `program.site_id`. If none, assign to default site.

### Model
- `Client` model: add `site_id` to fillable; add `site()` BelongsTo; add `scopeForSite($query, $siteId)`.

### API and services

| File | Change |
|------|--------|
| `ClientService::createClient` | Accept optional `site_id`; set on create. Callers pass site from program or auth. |
| `ClientService::searchClients` | Accept `site_id`; scope `Client::query()->where('site_id', $siteId)`. |
| `ClientPageController::index` | Scope by `$request->user()->site_id`; super_admin optional filter. |
| `ClientPageController::show` | Ensure client belongs to user's site; 404 if not. |
| `ClientAdminController::destroy` | Ensure client belongs to user's site; 404 if not. |
| `ClientController` (attachIdDocument, etc.) | Client loaded after site check; ensure caller has validated. |
| `IdentityRegistrationController` | On client create, pass `program.site_id` (or auth site) to `createClient`. |

### Triage context
- Client search during triage: pass `program.site_id` to `searchClients` (program is already in context from triage URL or station).

---

## 4. Print settings (S.5)

### Schema
- Add `print_settings.site_id` (nullable initially; FK to `sites.id`). Unique on `site_id` (one row per site).
- Migration: SQLite + MariaDB. Create one row per existing site (from programs or users), or allow nullable and create on first access per site.

### API and services

| File | Change |
|------|--------|
| `PrintSettingRepository::getInstance` | Accept `?int $siteId`; `PrintSetting::firstWhere('site_id', $siteId)` or create for that site. |
| `PrintSettingsController` | Get `$request->user()->site_id`; 403 if null (site admin must have site). Pass to repository. |
| `TokenPrintController` | Ensure print settings fetched for same site as tokens. |

---

## 5. Verification (S.6)

- **ReportController / ReportService:** Restrict `getProgramSessions` and `getAuditLog` to program_ids that belong to the user's site (or super_admin can pass any). Add server-side guard: filter program_ids by `Program::where('site_id', $siteId)`.
- **Site isolation regression:** Feature test: two sites seeded (e.g. default + LGU mirror), two admin users; assert admin A cannot see or mutate admin B's tokens, clients, or print settings.

---

## 6. File touchpoint summary

| Entity | Files to touch |
|--------|----------------|
| **Tokens** | `database/migrations/` (tokens.site_id), `app/Models/Token.php`, `app/Http/Controllers/Api/Admin/TokenController.php`, `app/Services/TokenService.php`, `app/Services/ProgramTokenService.php`, `app/Http/Controllers/Admin/TokenPrintController.php`, `app/Http/Controllers/Api/Admin/TokenTtsSettingsController.php`, `app/Services/AnalyticsService.php`, `app/Services/SystemStorageService.php` |
| **Clients** | `database/migrations/` (clients.site_id), `app/Models/Client.php`, `app/Services/ClientService.php`, `app/Http/Controllers/Admin/ClientPageController.php`, `app/Http/Controllers/Api/Admin/ClientAdminController.php`, `app/Http/Controllers/Api/ClientController.php`, `app/Http/Controllers/Api/IdentityRegistrationController.php` |
| **Print settings** | `database/migrations/` (print_settings.site_id), `app/Models/PrintSetting.php`, `app/Repositories/PrintSettingRepository.php`, `app/Http/Controllers/Api/Admin/PrintSettingsController.php` |
| **Verification** | `app/Services/ReportService.php`, `app/Http/Controllers/Api/Admin/ReportController.php`, `tests/Feature/` (site isolation test) |

---

## 7. Test plan

- **Token site isolation:** Feature test: site admin A lists tokens; only site A's tokens. Site admin B lists tokens; only site B's. Token create sets site_id. Bulk assign only attaches same-site tokens.
- **Client site isolation:** Feature test: site admin A lists clients; only site A's. Client create sets site_id. Client show/destroy 404 for other site's clients. searchClients scoped.
- **Print settings per site:** Feature test: two sites; admin A updates print settings; admin B sees unchanged (separate row).
- **Site isolation regression:** Two sites, two admins; full assertion: no cross-site visibility for tokens, clients, print settings.
- **Regression:** Existing tests pass with site_id set (e.g. default site for seeded data).

---

## 8. Stabilize (S.S) — completed

- **Backfill edge cases:** S.1 and S.3 migrations already assign default site to tokens with no `program_token` and clients with no session/identity_registration; no additional data fix.
- **LGU seeder:** `LguMirrorSiteSeeder` does not create tokens or clients (only site, users, programs, stations, etc.); no change required. If it is extended to create tokens later, set `site_id` to the LGU site.
- **UI polish:** No explicit spec requirement for site indicator or breadcrumb on admin token/client/print pages; skipped. Tests that create tokens/clients in site-scoped flows use `site_id` (e.g. `TokenSiteIsolationTest`, `ClientSiteIsolationTest`, `SiteIsolationRegressionTest`). Admin tests that hit site-scoped routes use a site-backed user; integration routes that are super_admin-only use `UserRole::SuperAdmin`.
