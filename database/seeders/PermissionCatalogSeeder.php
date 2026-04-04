<?php

namespace Database\Seeders;

use App\Models\RbacTeam;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Idempotent catalog + role bundles (parity with routes + SpatieRbacSyncService).
 */
class PermissionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $guard = PermissionCatalog::guardName();
        $globalTeamId = RbacTeam::GLOBAL_TEAM_ID;
        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId($globalTeamId);
        try {
            foreach (PermissionCatalog::all() as $name) {
                Permission::query()->firstOrCreate(
                    ['name' => $name, 'guard_name' => $guard]
                );
            }

            $superAdmin = Role::query()->firstOrCreate(
                ['name' => 'super_admin', 'guard_name' => $guard, 'team_id' => $globalTeamId]
            );
            $admin = Role::query()->firstOrCreate(
                ['name' => 'admin', 'guard_name' => $guard, 'team_id' => $globalTeamId]
            );
            $staff = Role::query()->firstOrCreate(
                ['name' => 'staff', 'guard_name' => $guard, 'team_id' => $globalTeamId]
            );

            // Super admin: platform + shared admin APIs; not site program CRUD (admin.manage) nor staff ops.
            $superAdmin->syncPermissions([
                PermissionCatalog::PLATFORM_MANAGE,
                PermissionCatalog::ADMIN_SHARED,
                PermissionCatalog::PROFILE_SELF,
                PermissionCatalog::PUBLIC_DISPLAY_SETTINGS_APPLY,
                PermissionCatalog::PUBLIC_DEVICE_AUTHORIZE,
            ]);

            // Site admin: full site admin surface + dashboard + supervisor tools + staff routes.
            $admin->syncPermissions([
                PermissionCatalog::ADMIN_MANAGE,
                PermissionCatalog::ADMIN_SHARED,
                PermissionCatalog::DASHBOARD_VIEW,
                PermissionCatalog::AUTH_SUPERVISOR_TOOLS,
                PermissionCatalog::STAFF_OPERATIONS,
                PermissionCatalog::PROFILE_SELF,
                PermissionCatalog::PUBLIC_DISPLAY_SETTINGS_APPLY,
                PermissionCatalog::PUBLIC_DEVICE_AUTHORIZE,
            ]);

            // Staff: queue/session/client flows; supervisors get extra via SpatieRbacSyncService direct grants.
            $staff->syncPermissions([
                PermissionCatalog::STAFF_OPERATIONS,
                PermissionCatalog::PROFILE_SELF,
                PermissionCatalog::PUBLIC_DISPLAY_SETTINGS_APPLY,
                PermissionCatalog::PUBLIC_DEVICE_AUTHORIZE,
            ]);
        } finally {
            setPermissionsTeamId($previousTeam);
        }
    }
}
