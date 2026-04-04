<?php

namespace App\Support;

/**
 * Canonical permission names for Spatie (RBAC migration).
 * See docs/architecture/PERMISSIONS.md and docs/plans/RBAC_SPATIE_PERMISSIONS_MIGRATION_PLAN.md.
 */
final class PermissionCatalog
{
    /** Platform-only (integrations, platform TTS budgets, cross-site defaults). Super admin routes. */
    public const PLATFORM_MANAGE = 'platform.manage';

    /** Site admin: programs, tokens, analytics, print/TTS site settings, program defaults (admin-only API group). */
    public const ADMIN_MANAGE = 'admin.manage';

    /** Shared admin + super_admin: users, sites, audit logs, system storage. */
    public const ADMIN_SHARED = 'admin.shared';

    /** Dashboard stats/stations API (admin, or staff supervisor). */
    public const DASHBOARD_VIEW = 'dashboard.view';

    /** Temporary PIN/QR and authorization list (admin, or staff supervisor). */
    public const AUTH_SUPERVISOR_TOOLS = 'auth.supervisor_tools';

    /** Session/station/client/permission-request flows (staff; admins pass same routes). */
    public const STAFF_OPERATIONS = 'staff.operations';

    /** Authenticated self-service: profile, availability, broadcast-test (any logged-in role). */
    public const PROFILE_SELF = 'profile.self';

    /** Reserved for policy / future enforcement (not granted via roles in v1). */
    public const PUBLIC_DISPLAY_SETTINGS_APPLY = 'public.display_settings.apply';

    public const PUBLIC_DEVICE_AUTHORIZE = 'public.device.authorize';

    /** Program supervisor pivot; program-scoped checks remain in policies until expanded. */
    public const PROGRAMS_SUPERVISE = 'programs.supervise';

    public const KIOSK_SESSION_CREATE = 'kiosk.session.create';

    public const KIOSK_ACCESS = 'kiosk.access';

    /**
     * Permissions created in DB for catalog completeness (may have no role grants yet).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PLATFORM_MANAGE,
            self::ADMIN_MANAGE,
            self::ADMIN_SHARED,
            self::DASHBOARD_VIEW,
            self::AUTH_SUPERVISOR_TOOLS,
            self::STAFF_OPERATIONS,
            self::PROFILE_SELF,
            self::PUBLIC_DISPLAY_SETTINGS_APPLY,
            self::PUBLIC_DEVICE_AUTHORIZE,
            self::PROGRAMS_SUPERVISE,
            self::KIOSK_SESSION_CREATE,
            self::KIOSK_ACCESS,
        ];
    }

    /**
     * Permissions that may be assigned directly to users (admin UI allow-list).
     * Excludes reserved/catalog-only names that are not meant for direct assignment in v1.
     *
     * @return list<string>
     */
    public static function assignableDirect(): array
    {
        return [
            self::PLATFORM_MANAGE,
            self::ADMIN_MANAGE,
            self::ADMIN_SHARED,
            self::DASHBOARD_VIEW,
            self::AUTH_SUPERVISOR_TOOLS,
            self::STAFF_OPERATIONS,
            self::PROFILE_SELF,
            self::PUBLIC_DISPLAY_SETTINGS_APPLY,
            self::PUBLIC_DEVICE_AUTHORIZE,
            self::PROGRAMS_SUPERVISE,
            self::KIOSK_SESSION_CREATE,
            self::KIOSK_ACCESS,
        ];
    }

    public static function guardName(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }
}
