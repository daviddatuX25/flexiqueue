<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Program;
use App\Models\ProgramStationAssignment;
use App\Models\ServiceTrack;
use App\Models\Site;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramStaffControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Program $program;

    private Station $station1;

    private Station $station2;

    private User $staff1;

    private User $staff2;

    protected function setUp(): void
    {
        parent::setUp();
        $site = Site::create([
            'name' => 'Default Site',
            'slug' => 'default',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $this->staff1 = User::factory()->create(['role' => 'staff', 'site_id' => $site->id]);
        $this->staff2 = User::factory()->create(['role' => 'staff', 'site_id' => $site->id]);

        $this->program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $this->station1 = Station::create([
            'program_id' => $this->program->id,
            'name' => 'Interview',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $this->station2 = Station::create([
            'program_id' => $this->program->id,
            'name' => 'Cashier',
            'capacity' => 1,
            'is_active' => true,
        ]);
        ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
    }

    public function test_staff_assignments_returns_200_and_list(): void
    {
        $response = $this->actingAs($this->admin)->getJson("/api/admin/programs/{$this->program->id}/staff-assignments");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'assignments' => [['user_id', 'user' => ['id', 'name', 'email'], 'station_id', 'station']],
            'stations' => [['id', 'name', 'capacity']],
        ]);
        $stationNames = collect($response->json('stations'))->pluck('name')->all();
        $this->assertContains('Interview', $stationNames);
        $this->assertContains('Cashier', $stationNames);
        // Per flexiqueue-bci: stations include capacity for multiple staff-per-station UI
        $interview = collect($response->json('stations'))->firstWhere('name', 'Interview');
        $this->assertNotNull($interview);
        $this->assertArrayHasKey('capacity', $interview);
        $this->assertSame(1, $interview['capacity']);
    }

    public function test_assign_staff_to_station_returns_201(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/staff-assignments", [
            'user_id' => $this->staff1->id,
            'station_id' => $this->station1->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user_id', $this->staff1->id);
        $response->assertJsonPath('station_id', $this->station1->id);

        $this->assertDatabaseHas('program_station_assignments', [
            'program_id' => $this->program->id,
            'user_id' => $this->staff1->id,
            'station_id' => $this->station1->id,
        ]);
    }

    public function test_unassign_staff_removes_assignment(): void
    {
        ProgramStationAssignment::create([
            'program_id' => $this->program->id,
            'user_id' => $this->staff1->id,
            'station_id' => $this->station1->id,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/programs/{$this->program->id}/staff-assignments/{$this->staff1->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('station_id', null);

        $this->assertDatabaseMissing('program_station_assignments', [
            'program_id' => $this->program->id,
            'user_id' => $this->staff1->id,
        ]);
    }

    /** Per flexiqueue-bci: multiple staff can be assigned to the same station. */
    public function test_multiple_staff_can_be_assigned_to_same_station(): void
    {
        $this->station1->update(['capacity' => 2]);

        $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/staff-assignments", [
            'user_id' => $this->staff1->id,
            'station_id' => $this->station1->id,
        ])->assertStatus(201);

        $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/staff-assignments", [
            'user_id' => $this->staff2->id,
            'station_id' => $this->station1->id,
        ])->assertStatus(201);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/programs/{$this->program->id}/staff-assignments");
        $response->assertStatus(200);
        $atStation1 = collect($response->json('assignments'))->where('station_id', $this->station1->id)->all();
        $this->assertCount(2, $atStation1);
        $userIds = collect($atStation1)->pluck('user_id')->sort()->values()->all();
        $this->assertSame([$this->staff1->id, $this->staff2->id], $userIds);
    }

    public function test_supervisors_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->getJson("/api/admin/programs/{$this->program->id}/supervisors");

        $response->assertStatus(200);
        $response->assertJsonStructure(['supervisors', 'staff_with_pin']);
    }

    public function test_add_supervisor_requires_override_pin(): void
    {
        $staffNoPin = User::factory()->create([
            'role' => 'staff',
            'override_pin' => null,
            'site_id' => $this->program->site_id,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/supervisors", [
            'user_id' => $staffNoPin->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'User must have override PIN set to be a supervisor.');
    }

    public function test_add_supervisor_with_pin_returns_201(): void
    {
        $staffWithPin = User::factory()->supervisor()->withOverridePin('123456')->create([
            'site_id' => $this->program->site_id,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/supervisors", [
            'user_id' => $staffWithPin->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user_id', $staffWithPin->id);

        $this->assertDatabaseHas('program_supervisors', [
            'program_id' => $this->program->id,
            'user_id' => $staffWithPin->id,
        ]);
    }

    public function test_remove_supervisor_returns_200(): void
    {
        $staffWithPin = User::factory()->supervisor()->withOverridePin('123456')->create([
            'site_id' => $this->program->site_id,
        ]);
        $this->program->supervisedBy()->attach($staffWithPin->id);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/programs/{$this->program->id}/supervisors/{$staffWithPin->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('program_supervisors', [
            'program_id' => $this->program->id,
            'user_id' => $staffWithPin->id,
        ]);
    }

    public function test_staff_cannot_access_program_staff_apis_returns_403(): void
    {
        $response = $this->actingAs($this->staff1)->getJson("/api/admin/programs/{$this->program->id}/staff-assignments");
        $response->assertStatus(403);
    }

    /** Per central-edge follow-up: allow multi-program assignment; API returns warning when staff already in another program. */
    public function test_assign_staff_to_second_program_returns_201_with_warning_when_already_assigned_to_another(): void
    {
        ProgramStationAssignment::create([
            'program_id' => $this->program->id,
            'user_id' => $this->staff1->id,
            'station_id' => $this->station1->id,
        ]);

        $programB = Program::create([
            'site_id' => $this->program->site_id,
            'name' => 'Program B',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $stationB = Station::create([
            'program_id' => $programB->id,
            'name' => 'Station B',
            'capacity' => 1,
            'is_active' => true,
        ]);
        ServiceTrack::create([
            'program_id' => $programB->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$programB->id}/staff-assignments", [
            'user_id' => $this->staff1->id,
            'station_id' => $stationB->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user_id', $this->staff1->id);
        $response->assertJsonPath('station_id', $stationB->id);
        $response->assertJsonPath('warning', 'This staff is already assigned to another program. On the day, they can only work in one program at a time (they will choose or be assigned to one).');
        $this->assertDatabaseHas('program_station_assignments', [
            'program_id' => $programB->id,
            'user_id' => $this->staff1->id,
            'station_id' => $stationB->id,
        ]);
    }
}
