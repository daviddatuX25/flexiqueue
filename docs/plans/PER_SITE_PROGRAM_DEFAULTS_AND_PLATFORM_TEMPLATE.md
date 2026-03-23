# Per-site program defaults + platform template (robust plan)

## Executive summary

**Today:** `program_default_settings` is effectively **one global row** (id `1`). Site `admin` and `super_admin` both read/write **the same** JSON via `GET/PUT /api/admin/program-default-settings`. New programs copy that row when created (`ProgramController::store`). **Any site admin can change “defaults for everyone.”**

**Target:** Mirror the **print settings** model:

| Layer | Role | Storage | Purpose |
| ----- | ---- | ------- | ------- |
| **Platform template** | `super_admin` only | One logical row with `site_id = null` | Canonical defaults for **new sites** and optional “reset to platform” |
| **Site template** | Site `admin` (scoped to their `site_id`) | One row per `site_id` | Defaults used when **creating programs** in that site |

**Token / print settings:** Already per-site (`print_settings.site_id`) with `PrintSettingRepository::copyPlatformTemplateToSite()` on `SiteController::store`. This plan **does not replace** that; it **aligns program defaults** with the same mental model and operations.

---

## Problem statement (why this matters)

1. **Isolation:** Customers/sites must not overwrite each other’s “default scenario” for new programs.
2. **Governance:** Only **super admin** should define the **platform** baseline; site admins adjust **their** baseline.
3. **Onboarding:** When a **new site** is created, it should **inherit** the super-admin platform template once, then evolve independently (same as print).
4. **Consistency:** Developers and support should see one pattern: *platform row (`site_id` null) + per-site rows*, for both print and program defaults.

---

## Implementation status

Implemented (2026-03): migration `2026_03_21_000001_add_site_id_to_program_default_settings_table.php`, `ProgramDefaultSettingRepository` (platform + per-site), `ProgramPlatformDefaultSettingsController`, site-scoped `ProgramDefaultSettingsController`, `SiteController::store` copies template, routes split (`program-platform-default-settings` = `super_admin` only), `ProgramDefaultsTab` `variant="platform"` for super admin Configuration.

---

## Current implementation (audit)

| Piece | Current behavior |
| ----- | ---------------- |
| DB | `program_default_settings`: single table, no `site_id`; code uses fixed id `1` (`ProgramDefaultSettingRepository::ROW_ID`). |
| API | `ProgramDefaultSettingsController` global read/write; registered under `role:admin` **and** `role:admin,super_admin` (duplicate registrations in `routes/web.php` — should be consolidated during implementation). |
| New program | `ProgramController::store` sets `settings` from `getNormalizedFromDatabase()` — **global**, not per creating user’s site. |
| New site | `SiteController::store` calls `PrintSettingRepository::copyPlatformTemplateToSite($site->id)` — **already correct** for print. |
| UI | `ProgramDefaultsTab` / `ProgramDefaultSettings.svelte` edit the **global** template. |

---

## Target architecture

### Data model (recommended)

**Single table** extended to match the print pattern (familiar, one migration path):

- Table: `program_default_settings` (keep name; minimize churn).
- Columns:
  - `id`
  - `site_id` — `nullable` FK → `sites.id`, `nullOnDelete()`
  - `settings` — JSON (same shape as today: normalized `ProgramSettings` subset).
  - `timestamps`
- **Uniqueness:**
  - At most **one row per non-null `site_id`** (unique index on `site_id` where not null, or unique `site_id` if your DB treats multiple NULLs as allowed — see §Migration notes).
  - **Platform template:** exactly **one** row with `site_id IS NULL` (enforce in repository + optional DB check / application transaction; MySQL allows multiple NULLs in UNIQUE — same caveat as `print_settings`; `PrintSettingRepository` already documents “one logical row”).

**Semantics:**

- `site_id IS NULL` → **platform template** (super_admin only).
- `site_id = N` → **site N’s program default template** (site admin for site N; super_admin may read for support).

**Alternative (if you prefer stricter DB):** separate `program_platform_default_settings` (1 row) and `site_program_default_settings` (`site_id` unique). More tables, clearer constraints, more code paths. **Recommendation:** one table + repository discipline (matches print).

### Repository responsibilities

Refactor / extend `ProgramDefaultSettingRepository`:

1. `getPlatformTemplate(): array` — normalized settings from `site_id` null row; create from baseline if missing (like `PrintSettingRepository::getPlatformTemplate()`).
2. `getNormalizedForSite(int $siteId): array` — site row; if missing, **copy platform template** into a new row and return (like `getInstance` / first-or-copy).
3. `persistPlatformTemplate(array $normalized): void` — super_admin only (caller enforces).
4. `persistForSite(int $siteId, array $normalized): void` — site admin for own site.
5. `copyPlatformTemplateToSite(int $siteId): void` — called from `SiteController::store` **after** site insert (parallel to print).

**Program creation:** `ProgramController::store` should use `getNormalizedForSite($user->site_id)` instead of `getNormalizedFromDatabase()` without site.

---

## API & authorization

### Split endpoints (clear mental model; aligns with print)

| Endpoint | Role | Resource |
| -------- | ---- | -------- |
| `GET/PUT /api/admin/program-platform-default-settings` | `super_admin` only | Platform template (`site_id` null) |
| `GET/PUT /api/admin/program-default-settings` | `admin` only (site-scoped) | Current user’s **site** row |

**Rules:**

- Site `admin` **never** writes the platform row.
- `super_admin` **without** `site_id` may **not** use site-scoped program APIs (or define explicit behavior: read-only preview vs 403 — product decision).
- `PUT` payloads stay `UpdateProgramDefaultSettingsRequest`-shaped; controller picks target row by role + site.

**Apply to program:** Existing “apply defaults to this program” action should load defaults via `getNormalizedForSite($program->site_id)`, not the global row.

### Route cleanup

- Remove duplicate `program-default-settings` route registrations; single source per endpoint.
- Place `program-platform-default-settings` in `role:super_admin` group (same area as `print-platform-default-settings`).

---

## Site lifecycle

### Create site (`SiteController::store`)

1. Create `Site` record.
2. `PrintSettingRepository::copyPlatformTemplateToSite($siteId)` — **existing**.
3. **New:** `ProgramDefaultSettingRepository::copyPlatformTemplateToSite($siteId)` — copy JSON from platform row to new site row.

Order: site must exist before FK; same as print.

### Create program (`ProgramController::store`)

- `settings` = `getNormalizedForSite($user->site_id)`.

### Optional product features (later)

- **“Reset site program defaults to platform”** — copy platform → site row (admin confirm).
- **Super admin “push”** — out of scope unless explicitly required (dangerous; prefer docs + manual export/import).

---

## Frontend

| Actor | UI | Data source |
| ----- | -- | ----------- |
| `super_admin` | Configuration → **Platform program defaults** (rename for clarity) | `program-platform-default-settings` |
| Site `admin` | Settings → **Program defaults** (site) | `program-default-settings` (scoped) |

Copy/strings:

- Platform tab: “Defaults **copied to new sites**. Editing does **not** change existing sites until they reset or you edit that site.”
- Site tab: “New programs in **this site** start from these defaults.”

**Program Show → “Apply default settings”:** Fetch site-scoped defaults (same as create).

---

## Migration plan (data)

1. Add `site_id` to `program_default_settings` (nullable FK).
2. **Platform row:** Keep **one** row with `site_id = null`, `settings` = current global content (from id `1` or single row).
3. **Per-site rows:** For each existing `sites.id`, insert a row copying platform `settings` (or copy from previous global — same snapshot for everyone at cutover).
4. Stop using fixed `ROW_ID = 1`; use `whereNull('site_id')` for platform and `where('site_id', $id)` for site.
5. Remove orphan legacy rows if any duplicate platform rows existed.

**Downtime:** Not required if migration is careful; prefer deploy in low traffic.

---

## Testing (PHPUnit)

1. **Platform:** `super_admin` can `PUT` platform; `admin` receives **403** on platform routes.
2. **Site:** `admin` A `PUT` defaults only affects **their** site row; `admin` B sees different values.
3. **New site:** `POST /sites` creates site row for program defaults with same JSON as platform at creation time.
4. **New program:** `POST /programs` applies **site** defaults, not another site’s.
5. **Apply to program:** Uses site-scoped defaults.
6. Regression: normalization still matches `ProgramSettings` / TTS keys.

---

## Rollout phases (suggested)

| Phase | Scope |
| ----- | ----- |
| **1** | Migration + repository + `ProgramController` + `SiteController` + split API + tests |
| **2** | Frontend tabs wiring + copy + remove duplicate routes |
| **3** | Docs: `docs/architecture/04-DATA-MODEL.md` (or program settings doc) + `SUPER-ADMIN-VS-ADMIN-SPEC` pointer |
| **4** | Optional: “Reset site defaults to platform” button |

---

## Risks & mitigations

| Risk | Mitigation |
| ---- | ---------- |
| MySQL UNIQUE + multiple NULLs | Repository asserts single platform row; log + warn duplicates (print pattern). |
| Super admin has no `site_id` | Platform endpoints only; no site-scoped program create — already true. |
| Stale UI caching | After PUT, return full normalized payload; invalidate Inertia props if needed. |
| Large JSON migrations | Test on copy of prod; transaction per site insert. |

---

## Relation to print settings (explicit)

- **Print:** Platform row `print_settings.site_id IS NULL`; per-site rows; copy on site create — **done**.
- **Program defaults:** This plan brings **the same** structure; no change to print logic required except cross-linking docs (“both behave the same way”).

---

## Open decisions (confirm before implementation)

1. Should **super_admin** be able to **read** site-scoped program defaults for support (`GET /sites/{site}/program-default-settings`)?
2. On migration, should existing sites get a **copy** of the current global row, or re-copy from platform row only (identical at cutover)?
3. **Naming:** keep `program_default_settings` table name vs rename to `site_program_default_settings` for clarity (rename = more migration churn).

---

## References

- `app/Repositories/ProgramDefaultSettingRepository.php` — today’s global row.
- `app/Repositories/PrintSettingRepository.php` — template pattern to mirror.
- `app/Http/Controllers/Api/Admin/SiteController.php` — `copyPlatformTemplateToSite`.
- `app/Http/Controllers/Api/Admin/ProgramController.php` — `store` uses global defaults.
- `.cursor/plans/super_admin_settings_nav.plan.md` — historical note: “same row” for admin + super_admin; **superseded** by this plan for product correctness.
- `routes/web.php` — route groups; consolidate duplicates when implementing.

---

## Summary

**Yes — today there is only one shared program default for all sites, and any site admin can change it.** That matches the old “global template” implementation, not multi-tenant isolation.

**Robust fix:** Per-site program default rows + platform template row (`site_id` null), seed new sites from the platform (like print), and create new programs from **that site’s** row. This document is the implementation-ready blueprint; execution should follow TDD (tests in §Testing) and update architecture docs when shipped.
