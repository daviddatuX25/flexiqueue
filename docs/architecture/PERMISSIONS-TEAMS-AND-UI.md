# Plan: Dynamic scoped permissions (site + program) — companion to RBAC epic

| Field | Value |
|-------|--------|
| **Status** | **Done** — `RbacTeam`, Spatie teams, `RbacContextService::hasPermissionInContext`, admin API (`GET`/`PUT` `/api/admin/rbac-teams/{team}/users/{user}`), Site/Program **Permissions — {name}** panels with scoped matrix (`ScopedRbacTeamAccessPanel`). See [`docs/DEPLOYMENT.md`](../DEPLOYMENT.md) for migrate + cache reset. |
| **Companion doc** | [`docs/plans/RBAC_SPATIE_PERMISSIONS_MIGRATION_PLAN.md`](../plans/RBAC_SPATIE_PERMISSIONS_MIGRATION_PLAN.md) |
| **Version** | **1.0 (finalized)** — scope and ordering are fixed; implementation details may refine in ADRs. |

---

## What “dynamic permissions” means here

**Dynamic permissions** (in FlexiQueue) means administrators can **change who can do what** without code deploys, at three levels:

| Level | Mechanism | Doc |
|-------|-----------|-----|
| **Global** | Spatie **roles** + **direct** `model_has_permissions` on users | RBAC plan Phases 2–4, `PermissionCatalog`, user API |
| **Site-scoped** | Spatie **teams** with `team_id` = **`RbacTeam`** row for a **site** | This doc §2–4 |
| **Program-scoped** | Same, for a **program** (supervisor, future kiosk officer, etc.) | This doc §2–4 |

**Static** permissions are still the **named catalog** (`admin.manage`, `kiosk.access`, …) seeded in code — you *add new names* via migration/seeder when product adds capabilities. **Dynamic** is *assignment* of those names to people at global/site/program scope, via UI and APIs.

---

## 1. Why Spatie teams for scoping?

Spatie’s **teams** feature adds **`team_id`** to `roles`, `model_has_roles`, and `model_has_permissions`. That allows:

- The same role **name** to carry different permission bundles **per team**
- Direct grants like “`kiosk.access` **only** for program 12”

**Constraint:** You **cannot** use raw `sites.id` and `programs.id` as `team_id` in one column — **ID collisions** (e.g. site `5` vs program `5`). Use a **surrogate** table (`RbacTeam`) whose **primary key** is the only `team_id` Spatie sees.

---

## 2. Model: `RbacTeam` (surrogate `team_id`)

| Column | Purpose |
|--------|---------|
| `id` | **This** is Spatie’s `team_id` everywhere. |
| `type` | `site` \| `program` (enum or string). |
| `site_id` | Set when `type = site`; FK to `sites`. |
| `program_id` | Set when `type = program`; FK to `programs`. |
| `name` | Optional label for admin UI. |

**Rules**

- For each row, exactly one of `site_id` / `program_id` is set according to `type`.
- Uniqueness: one `RbacTeam` per site; one per program (enforce with unique indexes).
- Create **eagerly** (Site/Program observers) or **lazily** on first assignment (with transaction/lock).

**Spatie**

- Enable `config('permission.teams')` **only after** a follow-up migration adds `team_id` to Spatie tables (existing installs without teams require Spatie’s upgrade path — never enable blindly in production without a runbook).

---

## 3. Effective permissions (single entry point)

Site- and program-scoped rows use **different** `team_id` values. Spatie does not automatically union contexts. Implement **one** application helper, e.g.:

`User::hasPermissionInContext(string $permission, ?Site $site, ?Program $program): bool`

…that evaluates **global** ∪ **site team** (if `$site`) ∪ **program team** (if `$program`), in line with product rules (document order if “deny wins” is ever added). Use this from **policies** and **public auth services** — not ad hoc triple `can()` calls scattered in controllers.

---

## 4. Admin UI — progressive disclosure

Design so **simple** orgs only use global user edit; **advanced** orgs use site/program matrices.

### 4.1 Platform (super admin)

- Platform integrations and global settings (existing).
- Optional later: catalog labels / feature flags per permission (not v1).

### 4.2 Site admin — “Site access”

**Placement:** Admin → **Site** → tab **“Access”** / **“People & permissions”**.

| Phase | UI |
|-------|-----|
| **v1** | User list + link to **user edit** with **direct permissions** (global API today). |
| **v2** | Matrix: assignments under **site** `RbacTeam` (`team_id` = that site’s `RbacTeam.id`). |
| **v3** | Templates: “Apply default staff bundle for this site.” |

### 4.3 Program admin — “Program access”

**Placement:** **Program** settings → **“Access”** tab and/or extend **Staff & supervisors**.

| Phase | UI |
|-------|-----|
| **v1** | Current supervisor pivot; optional **effective permissions** readout. |
| **v2** | Program-scoped roles / direct permissions on **program** `RbacTeam`. |
| **v3** | Optional “inherit defaults from site” for program team. |

**UX**

- Page title always states scope: “Permissions — **{Site name}**” or “**{Program name}**”.
- Searchable permission picker, grouped by namespace (`admin.*`, `kiosk.*`, `public.*`).
- Read-only **effective** summary (global ∪ site ∪ program) for support (gated role).

---

## 5. Implementation order (mandatory)

1. Complete RBAC plan **Phase 3** (routes/policies use global `can()`; parity tests).
2. Add **`RbacTeam`** model + migrations; backfill for existing sites/programs.
3. Add Spatie **`team_id`** columns (follow-up migration); enable `permission.teams`; `permission:cache-reset`.
4. Implement **`hasPermissionInContext`** (or equivalent) + middleware/resolver for request context.
5. Ship **site** UI (v1→v2), then **program** UI (v1→v2).
6. Deprecate redundant pivots only when tests prove parity.

---

## 6. Acceptance criteria (when this plan is “done”)

- [x] Admins can assign **site-scoped** direct permission rows without developer intervention (Site show → Permissions panel; `PUT` scoped API).
- [x] Admins can assign **program-scoped** direct permission rows (Program overview → Permissions panel; same API).
- [x] Effective access: `RbacContextService` unions global ∪ site team ∪ program team; policies wired for station/session supervision-style checks; PHPUnit covers scoped API.
- [x] Deploy runbook: [`docs/DEPLOYMENT.md`](../DEPLOYMENT.md) — teams migration, `permission:cache-reset`, rollback note.

---

## 7. Risks

| Risk | Mitigation |
|------|------------|
| Enabling teams on live DB | Staging first; backup; feature flag; Spatie docs for adding columns post-hoc. |
| N+1 queries | Eager load `RbacTeam`; cache discipline. |
| Admin confusion | Scope in UI; templates; effective-permissions panel. |

---

## 8. References

- [Spatie Laravel Permission — teams (v6)](https://spatie.be/docs/laravel-permission/v6/basic-usage/teams-permissions)
- [`docs/architecture/PERMISSIONS.md`](PERMISSIONS.md)
- [`docs/plans/RBAC_SPATIE_PERMISSIONS_MIGRATION_PLAN.md`](../plans/RBAC_SPATIE_PERMISSIONS_MIGRATION_PLAN.md) — §8, §17, master phase table

---

## Document history

| Date | Change |
|------|--------|
| **2026-03-22** | **Implemented Phase 6:** `rbac_teams` + backfill, Spatie `team_id` migration, `SetGlobalPermissionsTeam` middleware, `RbacContextService`, scoped admin API + UI matrix, docs + deployment runbook. |
