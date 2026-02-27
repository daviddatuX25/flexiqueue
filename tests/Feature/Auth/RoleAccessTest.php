<?php

namespace Tests\Feature\Auth;

use App\Models\Program;
use App\Models\ProgramStationAssignment;
use App\Models\ServiceTrack;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per 05-SECURITY-CONTROLS §3: RBAC — staff cannot access admin routes; admin can access admin routes.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_cannot_access_admin_dashboard_returns_403(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)->get(route('admin.dashboard'));

        $response->assertStatus(403);
    }

    public function test_staff_cannot_access_admin_programs_returns_403(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)->get(route('admin.programs'));

        $response->assertStatus(403);
    }

    public function test_supervisor_cannot_access_admin_dashboard_returns_403(): void
    {
        $supervisor = User::factory()->supervisor()->create();

        $response = $this->actingAs($supervisor)->get(route('admin.dashboard'));

        $response->assertStatus(403);
    }

    public function test_admin_can_access_admin_dashboard_returns_200(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertStatus(200);
    }

    public function test_admin_redirects_to_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertRedirect('/admin/dashboard');
    }

    public function test_admin_can_access_all_admin_routes(): void
    {
        $admin = User::factory()->admin()->create();

        $routes = [
            'admin.dashboard',
            'admin.programs',
            'admin.tokens',
            'admin.tokens.print',
            'admin.users',
            'admin.reports',
        ];

        foreach ($routes as $routeName) {
            $response = $this->actingAs($admin)->get(route($routeName));
            $response->assertStatus(200, "Failed for route: {$routeName}");
        }
    }

    public function test_staff_can_access_station_and_triage(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)->get(route('station'))->assertStatus(200);
        $this->actingAs($staff)->get(route('triage'))->assertStatus(200);
    }

    public function test_staff_with_program_station_assignment_sees_station_on_station_page(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => null]);
        $admin = User::factory()->admin()->create();
        $program = Program::create([
            'name' => 'Relief Distribution',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Verification',
            'capacity' => 1,
            'is_active' => true,
        ]);
        ProgramStationAssignment::create([
            'program_id' => $program->id,
            'user_id' => $staff->id,
            'station_id' => $station->id,
        ]);

        $response = $this->actingAs($staff)->get(route('station'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Station/Index')
            ->has('station')
            ->where('station.id', $station->id)
            ->where('station.name', 'Verification')
        );
    }

    public function test_admin_can_access_station_and_triage(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('station'))->assertStatus(200);
        $this->actingAs($admin)->get(route('triage'))->assertStatus(200);
    }

    public function test_admin_can_access_program_show_returns_200(): void
    {
        $admin = User::factory()->admin()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => false,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.programs.show', $program));

        $response->assertStatus(200);
    }

    public function test_staff_cannot_access_program_show_returns_403(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['role' => 'staff']);
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => false,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($staff)->get(route('admin.programs.show', $program));

        $response->assertStatus(403);
    }

    /** Per docs/plans/STAFF-DASHBOARD-PLAN.md: staff/supervisor see Staff Dashboard at /dashboard. */
    public function test_staff_dashboard_returns_200_with_metrics(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Staff/Dashboard')
            ->has('metrics')
            ->has('metrics.sessions_served_today')
            ->has('metrics.activity_counts_today')
        );
    }

    /** Admin visiting /dashboard is redirected to admin dashboard. */
    public function test_admin_visiting_dashboard_redirects_to_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertRedirect(route('admin.dashboard'));
    }
}
