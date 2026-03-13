<?php

namespace Tests\Feature;

use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Station;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per central-edge A.2.2: Triage page resolves program from user.assignedStation.program;
 * returns 422 when staff has no assigned station.
 */
class TriagePageControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_with_assigned_station_can_load_triage_and_gets_active_program(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $program = Program::create([
            'name' => 'Triage Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Triage Station',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $staff->update(['assigned_station_id' => $station->id]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        $process = Process::create(['program_id' => $program->id, 'name' => 'Triage', 'description' => null]);
        TrackStep::create([
            'track_id' => $track->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);

        $response = $this->actingAs($staff)->get(route('triage'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Triage/Index')
            ->has('activeProgram')
            ->where('activeProgram.id', $program->id)
            ->where('activeProgram.name', 'Triage Program')
            ->has('activeProgram.tracks')
        );
    }

    public function test_staff_without_assigned_station_gets_422_when_visiting_triage(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->update(['assigned_station_id' => null]);

        $response = $this->actingAs($staff)->get(route('triage'));

        $response->assertStatus(422);
        $response->assertSessionHasErrors('station');
        $this->assertStringContainsString('No station assigned', $response->getSession()->get('errors')->first('station'));
    }

    /** Per follow-up: admin with no station can use triage; ?program= sets session and redirects (shared selector). */
    public function test_admin_with_no_station_can_load_triage_and_program_query_sets_session(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->update(['assigned_station_id' => null]);

        $programA = Program::create([
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $programB = Program::create([
            'name' => 'Program B',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        ServiceTrack::create(['program_id' => $programA->id, 'name' => 'Default', 'is_default' => true, 'color_code' => '#333']);
        ServiceTrack::create(['program_id' => $programB->id, 'name' => 'Default', 'is_default' => true, 'color_code' => '#333']);

        $response = $this->actingAs($admin)->get('/triage?program=' . $programB->id);
        $response->assertRedirect('/triage');
        $this->assertEquals($programB->id, $response->getSession()->get('staff_selected_program_id'));

        $response2 = $this->actingAs($admin)->get(route('triage'));
        $response2->assertStatus(200);
        $response2->assertInertia(fn ($page) => $page
            ->component('Triage/Index')
            ->where('activeProgram.id', $programB->id)
            ->where('activeProgram.name', 'Program B')
            ->where('canSwitchProgram', true)
            ->has('programs', 2)
        );
    }
}
