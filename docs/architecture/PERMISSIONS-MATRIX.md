# Permissions matrix (Phase 0 inventory + Phase 3 enforcement)

| | |
|---|---|
| **Purpose** | Map **route middleware groups** in [`routes/web.php`](../../routes/web.php) to [`App\Support\PermissionCatalog`](../../app/Support/PermissionCatalog.php) string names and **PHPUnit** coverage. **Phase 3 (done):** HTTP uses Spatie [`permission:`](https://spatie.be/docs/laravel-permission/v6/basic-usage/routes) middleware (pipe `|` = **any** permission passes). [`EnsureRole`](../../app/Http/Middleware/EnsureRole.php) remains registered as `role` alias for backwards compatibility but is **not** used on migrated routes. |
| **Companion** | [`docs/plans/RBAC_SPATIE_PERMISSIONS_MIGRATION_PLAN.md`](../plans/RBAC_SPATIE_PERMISSIONS_MIGRATION_PLAN.md) §17 |

## Sources

- **Catalog (code):** `App\Support\PermissionCatalog` + `database/seeders/PermissionCatalogSeeder.php`
- **Role bundles:** Seeder assigns permissions to Spatie roles `super_admin`, `admin`, `staff`; [`SpatieRbacSyncService`](../../app/Services/SpatieRbacSyncService.php) adds `dashboard.view`, `auth.supervisor_tools`, and `programs.supervise` as **direct** grants to **staff** users in `program_supervisors` (pivot sync on attach/detach via [`ProgramSupervisor`](../../app/Models/ProgramSupervisor.php) pivot).
- **Routes:** All application HTTP routes live in `routes/web.php` (there is no separate `routes/api.php`).

## PermissionCatalog ↔ string

| Constant | String |
|----------|--------|
| `PermissionCatalog::PLATFORM_MANAGE` | `platform.manage` |
| `PermissionCatalog::ADMIN_MANAGE` | `admin.manage` |
| `PermissionCatalog::ADMIN_SHARED` | `admin.shared` |
| `PermissionCatalog::DASHBOARD_VIEW` | `dashboard.view` |
| `PermissionCatalog::AUTH_SUPERVISOR_TOOLS` | `auth.supervisor_tools` |
| `PermissionCatalog::STAFF_OPERATIONS` | `staff.operations` |
| `PermissionCatalog::PROFILE_SELF` | `profile.self` |
| `PermissionCatalog::PUBLIC_DISPLAY_SETTINGS_APPLY` | `public.display_settings.apply` |
| `PermissionCatalog::PUBLIC_DEVICE_AUTHORIZE` | `public.device.authorize` |
| `PermissionCatalog::PROGRAMS_SUPERVISE` | `programs.supervise` |
| `PermissionCatalog::KIOSK_SESSION_CREATE` | `kiosk.session.create` |
| `PermissionCatalog::KIOSK_ACCESS` | `kiosk.access` |

## Route groups → middleware (Phase 3) + tests

**Legend:** **OR** in middleware = Spatie pipe syntax (`permission:a|b`). Supervisors (staff + pivot) receive direct grants from `SpatieRbacSyncService` when the [`program_supervisors`](../../app/Models/ProgramSupervisor.php) pivot changes.

| ID | Middleware (Phase 3) | Permission name(s) | Primary tests |
|----|----------------------|----------------------|-----------------|
| **G1** | `auth`, `permission:admin.manage` | `admin.manage` | `Api/Admin/*`, `SuperAdminVsAdminAccessTest`, `RbacPermissionRouteMiddlewareTest` |
| **G2** | `auth`, `permission:platform.manage` | `platform.manage` | `Api/Admin/ElevenLabsIntegrationTest`, `TtsPlatformBudgetControllerTest`, `RbacPermissionRouteMiddlewareTest` |
| **G3** | `auth`, `permission:admin.shared` | `admin.shared` | `Api/Admin/UserControllerTest`, `SiteControllerTest`, `SpatieRbacSyncTest` |
| **G4** | `auth`, `permission:dashboard.view` | `dashboard.view` | `Api/DashboardControllerTest` |
| **G5** | `auth`, `permission:staff.operations`, `throttle:5,1` | `staff.operations` | `Api/VerifyPinTest` |
| **G6** | `auth`, `permission:profile.self` | `profile.self` | `Api/UserAvailabilityControllerTest` |
| **G7** | `auth`, `permission:profile.self` (`api/profile`) | `profile.self` | `Api/ProfileApiTest` |
| **G8** | `auth`, `permission:auth.supervisor_tools` | `auth.supervisor_tools` | `Api/TemporaryPinTest`, `TemporaryQrTest`, `PinQrAuthEdgeCasesTest` |
| **G9** | `auth`, `permission:staff.operations` | `staff.operations` | `Api/PermissionRequestApiTest`, `PermissionRequestServiceOverrideTest` |
| **G10** | `auth`, `permission:admin.manage` | `admin.manage` | `Api/ClientIdentityApiTest` (`reveal-phone`) |
| **G11** | `auth`, `permission:staff.operations` | `staff.operations` | Session/station API suite, `StationQueueApiTest`, `RbacPermissionRouteMiddlewareTest` |
| **G12** | `site.api_key` | **N/A** (API key) | `Api/SiteApiKeyAuthTest` |
| **G13** | `site.api_key` + `api/admin` prefix | **N/A** | `Api/Admin/ProgramPackageExportTest` |
| **G14** | Public / throttles | Middleware N/A; [`PublicDisplaySettingsAuthService`](../../app/Services/PublicDisplaySettingsAuthService.php) + PIN resolution; [`DeviceAuthorizeController`](../../app/Http/Controllers/Api/DeviceAuthorizeController.php) / [`PublicDeviceUnlockRequestController`](../../app/Http/Controllers/Api/PublicDeviceUnlockRequestController.php) assert `public.device.authorize` on resolved authorizer | `PublicDisplaySettingsTest`, `PublicTriageTest`, `DeviceAuthorizeTest` |
| **G15** | `auth`, `permission:admin.manage|platform.manage`, `throttle:60,1` | `admin.manage` **or** `platform.manage` (resolved Phase 3 gap) | `Api/TtsControllerTest` |
| **G16** | (none) | Public | `Api/TtsControllerTest` |
| **G17** | `auth`, `permission:admin.shared`, `prefix admin` | `admin.shared` | `Auth/RoleAccessTest`, `Admin/SitesPageTest` |
| **G18** | `auth`, `permission:admin.manage`, `prefix admin` | `admin.manage` | `Auth/RoleAccessTest`, `HandleInertiaRequestsAdminProgramsTest` |
| **G19** | `auth`, `permission:admin.manage|staff.operations`, `prefix admin` | `admin.manage` **or** `staff.operations` | `Admin/ClientPageTest`, `RbacPermissionRouteMiddlewareTest` (staff/admin split) |
| **G20** | `auth`, `permission:staff.operations` | `staff.operations` | `TriagePageControllerTest`, `Auth/RoleAccessTest` |
| **G21** | `auth`, `permission:profile.self` | `profile.self` | (broadcast-test dev routes) |
| **G22** | `require.site.access` / `require.program.access` | **N/A** session RBAC | `DisplayBoardTest` |
| **G23** | `guest` / `auth` | **N/A** | `Auth/LoginTest` |

### Supporting behavior (Phase 3)

- **[`StationPolicy`](../../app/Policies/StationPolicy.php) / [`SessionPolicy`](../../app/Policies/SessionPolicy.php):** `admin.manage` **or** (`programs.supervise` **and** `isSupervisorForProgram`) plus existing station-assignment rules.
- **[`HandleInertiaRequests`](../../app/Http/Middleware/HandleInertiaRequests.php):** `auth.can.*` from `$user->can()` (Phase 5); `can_approve_requests` duplicated for backward compat; `device_locked` false when `can(public.device.authorize)`; `server_tts_configured` uses `can(admin.manage)`.
- **[`EdgeBootGuard`](../../app/Http/Middleware/EdgeBootGuard.php):** Skips redirect for `api/*` so JSON APIs are not sent to `/edge/setup` in edge mode.
- **`docs/architecture/PERMISSIONS.md`:** Human-readable catalog aligned with [`PermissionCatalog`](../../app/Support/PermissionCatalog.php) (constants, assignability, deploy note).

## Catalog coverage vs `PermissionCatalog::all()`

| Catalog string | Route / enforcement |
|----------------|---------------------|
| `platform.manage` | G2 |
| `admin.manage` | G1, G10, G15, G18, G19 (with `staff.operations`) |
| `admin.shared` | G3, G17 |
| `dashboard.view` | G4 (+ supervisor direct) |
| `auth.supervisor_tools` | G8 (+ supervisor direct) |
| `staff.operations` | G5, G9, G11, G19, G20 |
| `profile.self` | G6, G7, G21 |
| `public.display_settings.apply` | Seeded on all roles; [`PublicDisplaySettingsAuthService`](../../app/Services/PublicDisplaySettingsAuthService.php) |
| `public.device.authorize` | G14 + [`HandleInertiaRequests`](../../app/Http/Middleware/HandleInertiaRequests.php) `auth.can.public_device_authorize` / `device_locked` |
| `programs.supervise` | Supervisor direct sync + policies |
| `kiosk.*` | Reserved |

---

*Phase 3: routes migrated to `permission:` middleware; matrix updated 2026-03-22.*

## Phase 4 — Admin Users UI (direct permissions)

| Area | Implementation |
|------|----------------|
| Assignable catalog | Inertia prop `assignable_permissions` (= `PermissionCatalog::assignableDirect()`), same as `GET /api/admin/permissions` |
| Edit user | `PUT /api/admin/users/{id}` with `direct_permissions: string[]`; validation errors surfaced on field + toaster |
| Effective list | Prop per user: `effective_permissions` (`getAllPermissions()`); `direct_permissions` for checkbox state; supervisor note when `supervisor_program_count > 0` |
| Guardrails | API: last active admin demotion/deactivation; `platform.manage` only for super admin. UI: `platform.manage` checkbox disabled unless `auth_is_super_admin` |

## Phase 5 — Public auth shell + server-driven capabilities

| Route / area | Permission(s) checked (server) | Inertia shared props |
|--------------|-------------------------------|----------------------|
| `POST /api/public/display-settings` | Session bypass: [`PublicAuthCapabilityService::userMaySkipInteractiveAuthFor`](../../app/Services/PublicAuthCapabilityService.php) → `public.display_settings.apply` + program/site rules; PIN path: authorizer `can(public.display_settings.apply)` in [`PublicDisplaySettingsAuthService`](../../app/Services/PublicDisplaySettingsAuthService.php) | — |
| `POST /api/public/device-authorize` | After PIN/QR match: authorizer `can(public.device.authorize)` | — |
| `POST /api/public/device-unlock-with-auth` | Same as device-authorize for authorizer | — |
| Global (Inertia) | `device_locked`: false iff `can(public.device.authorize)`; else follows [`DeviceLock`](../../app/Support/DeviceLock.php) | `auth.can.public_device_authorize`, `auth.can.public_display_settings_apply`, `auth.can.approve_requests` (= `admin.manage` \| `admin.shared` \| `auth.supervisor_tools`), `auth.can.staff_operations`, `auth.can.admin_manage` |
| Display / triage / station UI | — | `Board.svelte`, `StationBoard.svelte`, `PublicStart.svelte`, `DisplayLayout.svelte`, `MobileLayout.svelte` branch on `auth.can.public_device_authorize` / `auth.can.approve_requests` (not raw `role` for bypass / QR) |

**Prop → permission string:** `auth.can.public_device_authorize` → `public.device.authorize`; `auth.can.public_display_settings_apply` → `public.display_settings.apply`; `auth.can.approve_requests` → same union as legacy `can_approve_requests`; `auth.can.staff_operations` → `staff.operations`; `auth.can.admin_manage` → `admin.manage`.
