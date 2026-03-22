<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Site;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Parity tests for Phase 3: routes use Spatie `permission:*` middleware (see routes/web.php).
 */
class RbacPermissionRouteMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_receives_403_on_admin_manage_api_group(): void
    {
        $site = Site::create([
            'name' => 'S',
            'slug' => 's-'.Str::random(6),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $staff = User::factory()->create(['role' => 'staff', 'site_id' => $site->id]);

        $this->actingAs($staff)->getJson('/api/admin/programs')->assertStatus(403);
    }

    public function test_super_admin_receives_403_on_admin_manage_api_group(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'site_id' => null]);

        $this->actingAs($superAdmin)->getJson('/api/admin/programs')->assertStatus(403);
    }

    public function test_admin_receives_200_on_admin_manage_api_group(): void
    {
        $site = Site::create([
            'name' => 'S',
            'slug' => 's-'.Str::random(6),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $this->actingAs($admin)->getJson('/api/admin/programs')->assertOk();
    }

    public function test_super_admin_receives_200_on_platform_manage_api_group(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'site_id' => null]);

        $this->actingAs($superAdmin)->getJson('/api/admin/integrations/elevenlabs')->assertOk();
    }

    public function test_admin_receives_403_on_platform_manage_api_group(): void
    {
        $site = Site::create([
            'name' => 'S',
            'slug' => 's-'.Str::random(6),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $this->actingAs($admin)->getJson('/api/admin/integrations/elevenlabs')->assertStatus(403);
    }

    public function test_staff_receives_200_on_staff_operations_api_when_assigned(): void
    {
        $site = Site::create([
            'name' => 'S',
            'slug' => 's-'.Str::random(6),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'P',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'St',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $staff = User::factory()->create([
            'role' => 'staff',
            'site_id' => $site->id,
            'assigned_station_id' => $station->id,
        ]);

        $this->actingAs($staff)->getJson('/api/stations')->assertOk();
    }
}
