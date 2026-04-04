<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Site;
use App\Models\User;
use App\Services\ProgramSupervisorGrantService;
use App\Services\SpatieRbacSyncService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SpatieRbacSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_provisioning_syncs_spatie_role_from_enum(): void
    {
        $site = Site::create([
            'name' => 'S',
            'slug' => 's-'.Str::random(6),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->can(PermissionCatalog::ADMIN_MANAGE));
    }

    public function test_staff_supervisor_gets_dashboard_and_auth_supervisor_direct_permissions(): void
    {
        $site = Site::create([
            'name' => 'S',
            'slug' => 's-'.Str::random(6),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'P',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        app(ProgramSupervisorGrantService::class)->grantProgramTeamSupervise($staff, $program);

        app(SpatieRbacSyncService::class)->syncSupervisorDirectPermissions($staff->fresh());

        $staff->refresh();
        $this->assertTrue($staff->can(PermissionCatalog::DASHBOARD_VIEW));
        $this->assertTrue($staff->can(PermissionCatalog::AUTH_SUPERVISOR_TOOLS));
        $this->assertFalse(
            $staff->can(PermissionCatalog::PROGRAMS_SUPERVISE),
            'R4: programs.supervise is not granted globally; use program team checks',
        );
        $this->assertTrue($staff->isSupervisorForProgram($program->id));
    }

    public function test_super_admin_role_has_platform_and_shared_not_admin_manage(): void
    {
        $role = Role::findByName('super_admin', PermissionCatalog::guardName());
        $this->assertTrue($role->hasPermissionTo(PermissionCatalog::PLATFORM_MANAGE));
        $this->assertTrue($role->hasPermissionTo(PermissionCatalog::ADMIN_SHARED));
        $this->assertFalse($role->hasPermissionTo(PermissionCatalog::ADMIN_MANAGE));
    }

    public function test_permission_catalog_api_returns_assignable_names(): void
    {
        $site = Site::create([
            'name' => 'S',
            'slug' => 's-'.Str::random(6),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->getJson('/api/admin/permissions');

        $response->assertOk();
        $response->assertJsonStructure(['permissions']);
        $names = $response->json('permissions');
        $this->assertContains(PermissionCatalog::STAFF_OPERATIONS, $names);
    }
}
