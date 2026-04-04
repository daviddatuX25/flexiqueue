# RBAC policy cleanup — inventory and conventions

**Purpose:** Companion to [`RBAC_SPATIE_PERMISSIONS_MIGRATION_PLAN.md`](RBAC_SPATIE_PERMISSIONS_MIGRATION_PLAN.md) §17.3–17.7. One vocabulary for authorization: `$user->can(...)`, `Gate::authorize`, and [`RbacContextService::hasPermissionInContext`](../../app/Services/RbacContextService.php) for site/program context. Avoid new ad-hoc `isAdmin()` / pivot checks in controllers; prefer policies + catalog permissions.

**Related:** [`docs/architecture/PERMISSIONS-MATRIX.md`](../architecture/PERMISSIONS-MATRIX.md) (route groups), [`PermissionCatalog`](../../app/Support/PermissionCatalog.php), **R1 PR gate** [`PR-CHECKLIST-RBAC-R1.md`](PR-CHECKLIST-RBAC-R1.md).

---

## Conventions (reusable policy shapes)

| Pattern | Use when | Example |
|--------|----------|---------|
| **`view` (resource)** | Read-only access to a domain object (queue, session details) | `StationPolicy::view`, `SessionPolicy::view` |
| **`update` (resource)** | Mutations that staff at the assigned station may perform (call, serve, transfer, hold, …) | `SessionPolicy::update` — `Gate::authorize('update', $session)` |
| **`managePriority` / domain-specific** | Action is *stricter* than `view` (e.g. only admin/supervisor, not line staff) | `StationPolicy::managePriority` — priority-first override on a station |
| **Thin `User` helpers** | Only wrappers around `can()` + context service; no duplicated business rules | Prefer policy methods first |

`users.role` remains for Spatie sync and UI labels until §17.7; **new** checks go through permissions and policies.

---

## Hotspot inventory (rolling)

| Area | Location | Previous / current check | Target |
|------|----------|---------------------------|--------|
| Session API — queue ops | `SessionController` `call`, `serve`, `transfer`, `complete`, `cancel`, `forceComplete`, `override` | ~~Rely on `staff.operations` + service rules~~ | Done: `Gate::authorize('update', $session)` → `SessionPolicy::update` |
| Session API — hold / no-show / enqueue | `SessionController` | Already `Gate::authorize('update', …)` | No change |
| Station API — queue / display | `StationController` | `Gate::authorize('view', $station)` | Unchanged |
| Station API — priority-first | `StationController`, `SetStationPriorityFirstRequest` | ~~FormRequest: `isAdmin()` \|\| `isSupervisorForProgram`~~ | Done: `StationPolicy::managePriority` + `$user->can('managePriority', $station)` |
| Routing / triage | `HomeController`, `TriagePageController` | Capability-based redirects | Phase B follow-up |
| Device approval | `DeviceUnlockRequestController`, `DeviceAuthorizationRequestController`, related | `isAdmin()` + `site_id` | Phase B follow-up — align with `admin.manage` / site scope |
| Admin UI branches | `SitesPageController`, `UserPageController`, `TokenPrintController` | Duplicate super-admin checks | Prefer `platform.manage` where authorization duplicates |
| Staff — program picker / supervisor PIN bypass | `StaffProgramAccessService`, `SessionController` (`call`, `forceComplete`, `override`) | ~~Pivot + global `programs.supervise` only~~ | Done (R2): program-team `canInProgramTeamOnly` in site scope + session’s program for bypass |

Add rows here as controllers are refactored; shrink the list until the hotspot table is empty.

---

## Exit criteria (Phase A + Session/Station slice)

- Written conventions (this doc) + rolling hotspot table.
- Session and Station API paths above use policies / `can()` for the listed behaviors, with PHPUnit parity on status codes.
