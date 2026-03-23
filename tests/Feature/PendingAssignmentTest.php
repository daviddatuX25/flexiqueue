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
 * Per HYBRID_AUTH_ADMIN_FIRST_PRD.md ONB-5 / H5: pending_assignment onboarding gate for staff.
 */
class PendingAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create([
            'name' => 'Default Site',
            'slug' => 'default',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->admin = User::factory()->admin()->create(['site_id' => $this->site->id]);
    }

    public function test_pending_staff_redirected_from_station_to_holding_page(): void
    {
        $staff = User::factory()->create([
            'site_id' => $this->site->id,
            'pending_assignment' => true,
            'assigned_station_id' => null,
        ]);

        $response = $this->actingAs($staff)->get('/station');

        $response->assertRedirect(route('pending-assignment'));
    }

    public function test_pending_staff_can_view_holding_page(): void
    {
        $staff = User::factory()->create([
            'site_id' => $this->site->id,
            'pending_assignment' => true,
        ]);

        $response = $this->actingAs($staff)->get('/pending-assignment');

        $response->assertOk();
    }

    public function test_pending_staff_can_view_profile(): void
    {
        $staff = User::factory()->create([
            'site_id' => $this->site->id,
            'pending_assignment' => true,
        ]);

        $response = $this->actingAs($staff)->get('/profile');

        $response->assertOk();
    }

    public function test_pending_staff_gets_403_on_staff_api(): void
    {
        $staff = User::factory()->create([
            'site_id' => $this->site->id,
            'pending_assignment' => true,
        ]);

        $response = $this->actingAs($staff)->getJson('/api/stations');

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'Your account is pending assignment by an administrator.']);
    }

    public function test_assign_station_clears_pending_assignment(): void
    {
        $program = Program::create([
            'site_id' => $this->site->id,
            'name' => 'P1',
            'slug' => 'p1-'.Str::random(6),
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $staff = User::factory()->create([
            'site_id' => $this->site->id,
            'pending_assignment' => true,
            'assigned_station_id' => null,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/users/{$staff->id}/assign-station", [
            'station_id' => $station->id,
        ]);

        $response->assertStatus(200);
        $staff->refresh();
        $this->assertFalse($staff->pending_assignment);
        $this->assertSame($station->id, $staff->assigned_station_id);
    }
}
