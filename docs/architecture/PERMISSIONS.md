# Permissions catalog (Spatie)

| | |
|---|---|
| **Source of truth (code)** | [`app/Support/PermissionCatalog.php`](../../app/Support/PermissionCatalog.php) — constants and `all()` / `assignableDirect()`. |
| **Database** | [`database/seeders/PermissionCatalogSeeder.php`](../../database/seeders/PermissionCatalogSeeder.php) — idempotent `firstOrCreate` for each name; role bundles + direct grants. |
| **Route mapping** | [`docs/architecture/PERMISSIONS-MATRIX.md`](PERMISSIONS-MATRIX.md) — middleware groups, public flows, Inertia `auth.can.*` props. |
| **Epic** | [`docs/plans/RBAC_SPATIE_PERMISSIONS_MIGRATION_PLAN.md`](../plans/RBAC_SPATIE_PERMISSIONS_MIGRATION_PLAN.md) |

## Naming

- Lowercase, dot-separated: `domain.resource.action`.
- Do **not** use `triage.create_session` as a permission id — reserved **`kiosk.session.create`** (and `kiosk.access`) per product language.

## Catalog

| Permission | `PermissionCatalog` constant | Purpose | In admin “direct” UI |
|------------|------------------------------|---------|----------------------|
| `platform.manage` | `PLATFORM_MANAGE` | Platform-only: integrations, platform TTS budgets, cross-site defaults; super-admin routes. | Yes |
| `admin.manage` | `ADMIN_MANAGE` | Site admin: programs, tokens, analytics, print/TTS site settings, program defaults. | Yes |
| `admin.shared` | `ADMIN_SHARED` | Shared admin + super_admin: users, sites, audit logs, system storage. | Yes |
| `dashboard.view` | `DASHBOARD_VIEW` | Dashboard stats/stations API (admin or staff supervisor). | Yes |
| `auth.supervisor_tools` | `AUTH_SUPERVISOR_TOOLS` | Temporary PIN/QR and authorization lists (admin or staff supervisor). | Yes |
| `staff.operations` | `STAFF_OPERATIONS` | Session/station/client/permission-request flows. | Yes |
| `profile.self` | `PROFILE_SELF` | Profile, availability, broadcast-test (any logged-in role). | Yes |
| `public.display_settings.apply` | `PUBLIC_DISPLAY_SETTINGS_APPLY` | Apply public display settings after PIN/QR or session bypass. | Yes |
| `public.device.authorize` | `PUBLIC_DEVICE_AUTHORIZE` | Device authorization / cookie flows; pairs with `device_locked` Inertia prop. | Yes |
| `programs.supervise` | `PROGRAMS_SUPERVISE` | Program supervisor; synced with `program_supervisors` pivot + policies. | Yes |
| `kiosk.session.create` | `KIOSK_SESSION_CREATE` | **Reserved** — future kiosk session creation when programs restrict self-serve. | Yes |
| `kiosk.access` | `KIOSK_ACCESS` | **Reserved** — future restricted kiosk entry. | Yes |

## Roles (Spatie slugs)

Mirror [`UserRole`](../../app/Enums/UserRole.php): `super_admin`, `admin`, `staff`. Supervisors are staff (or admin) with `program_supervisors` pivot; [`SpatieRbacSyncService`](../../app/Services/SpatieRbacSyncService.php) grants extra permissions (e.g. `dashboard.view`, `programs.supervise`) as **direct** grants where needed.

## Single writer (enum vs Spatie)

- **`users.role`** remains the **source for the Spatie role name** (`SpatieRbacSyncService::syncRoleFromEnum` on user save and related flows).
- **Direct permission** changes go through **admin APIs** (`PUT /api/admin/users/...`, scoped `PUT /api/admin/rbac-teams/...`); seeders/catalog define names.
- **Authorization in code** should use **`$user->can(PermissionCatalog::…)`** and policies — not `UserRole` enum checks — except where the spec still ties UX to role display.

See [`docs/plans/RBAC_POLICY_CLEANUP.md`](../plans/RBAC_POLICY_CLEANUP.md).

## Operational note

After changing permissions in production, run `php artisan permission:cache-reset` (see [`docs/DEPLOYMENT.md`](../DEPLOYMENT.md)).
