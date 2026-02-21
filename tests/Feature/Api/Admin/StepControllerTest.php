<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Process;
use App\Models\Program;
use App\Models\Session;
use App\Models\ServiceTrack;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per 08-API-SPEC-PHASE1 §5.4: Track Step CRUD + reorder.
 */
class StepControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Program $program;

    private ServiceTrack $track;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $this->track = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Regular',
            'is_default' => true,
        ]);
    }

    public function test_index_returns_steps_ordered(): void
    {
        $station1 = Station::create(['program_id' => $this->program->id, 'name' => 'S1', 'capacity' => 1]);
        $station2 = Station::create(['program_id' => $this->program->id, 'name' => 'S2', 'capacity' => 1]);
        $process1 = Process::create(['program_id' => $this->program->id, 'name' => 'S1', 'description' => null]);
        $process2 = Process::create(['program_id' => $this->program->id, 'name' => 'S2', 'description' => null]);
        DB::table('station_process')->insert([
            ['station_id' => $station1->id, 'process_id' => $process1->id],
            ['station_id' => $station2->id, 'process_id' => $process2->id],
        ]);
        TrackStep::create(['track_id' => $this->track->id, 'process_id' => $process2->id, 'step_order' => 2, 'is_required' => true]);
        TrackStep::create(['track_id' => $this->track->id, 'process_id' => $process1->id, 'step_order' => 1, 'is_required' => true]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/tracks/{$this->track->id}/steps");

        $response->assertStatus(200);
        $response->assertJsonPath('steps.0.step_order', 1);
        $response->assertJsonPath('steps.0.process_name', 'S1');
        $response->assertJsonPath('steps.1.step_order', 2);
        $response->assertJsonPath('steps.1.process_name', 'S2');
    }

    public function test_store_creates_step_returns_201(): void
    {
        $station = Station::create(['program_id' => $this->program->id, 'name' => 'Desk', 'capacity' => 1]);
        $process = Process::create(['program_id' => $this->program->id, 'name' => 'Desk', 'description' => null]);
        DB::table('station_process')->insert(['station_id' => $station->id, 'process_id' => $process->id]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/tracks/{$this->track->id}/steps", [
            'process_id' => $process->id,
            'is_required' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('step.process_id', $process->id);
        $response->assertJsonPath('step.step_order', 1);
        $this->assertDatabaseHas('track_steps', ['track_id' => $this->track->id, 'process_id' => $process->id]);
    }

    public function test_store_rejects_process_from_other_program(): void
    {
        $otherProgram = Program::create([
            'name' => 'Other',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $otherProcess = Process::create(['program_id' => $otherProgram->id, 'name' => 'Other', 'description' => null]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/tracks/{$this->track->id}/steps", [
            'process_id' => $otherProcess->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('process_id');
    }

    public function test_update_modifies_step(): void
    {
        $station1 = Station::create(['program_id' => $this->program->id, 'name' => 'S1', 'capacity' => 1]);
        $station2 = Station::create(['program_id' => $this->program->id, 'name' => 'S2', 'capacity' => 1]);
        $process1 = Process::create(['program_id' => $this->program->id, 'name' => 'S1', 'description' => null]);
        $process2 = Process::create(['program_id' => $this->program->id, 'name' => 'S2', 'description' => null]);
        DB::table('station_process')->insert([
            ['station_id' => $station1->id, 'process_id' => $process1->id],
            ['station_id' => $station2->id, 'process_id' => $process2->id],
        ]);
        $step = TrackStep::create(['track_id' => $this->track->id, 'process_id' => $process1->id, 'step_order' => 1, 'is_required' => true]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/steps/{$step->id}", [
            'process_id' => $process2->id,
            'is_required' => false,
            'estimated_minutes' => 5,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('step.process_id', $process2->id);
        $response->assertJsonPath('step.is_required', false);
        $response->assertJsonPath('step.estimated_minutes', 5);
        $step->refresh();
        $this->assertSame($process2->id, $step->process_id);
    }

    public function test_destroy_deletes_and_renumbers(): void
    {
        $station1 = Station::create(['program_id' => $this->program->id, 'name' => 'S1', 'capacity' => 1]);
        $station2 = Station::create(['program_id' => $this->program->id, 'name' => 'S2', 'capacity' => 1]);
        $process1 = Process::create(['program_id' => $this->program->id, 'name' => 'S1', 'description' => null]);
        $process2 = Process::create(['program_id' => $this->program->id, 'name' => 'S2', 'description' => null]);
        DB::table('station_process')->insert([
            ['station_id' => $station1->id, 'process_id' => $process1->id],
            ['station_id' => $station2->id, 'process_id' => $process2->id],
        ]);
        $step1 = TrackStep::create(['track_id' => $this->track->id, 'process_id' => $process1->id, 'step_order' => 1, 'is_required' => true]);
        TrackStep::create(['track_id' => $this->track->id, 'process_id' => $process2->id, 'step_order' => 2, 'is_required' => true]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/steps/{$step1->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('track_steps', ['id' => $step1->id]);
        $remaining = TrackStep::where('track_id', $this->track->id)->orderBy('step_order')->first();
        $this->assertSame(1, $remaining->step_order);
    }

    public function test_reorder_updates_step_order(): void
    {
        $station1 = Station::create(['program_id' => $this->program->id, 'name' => 'S1', 'capacity' => 1]);
        $station2 = Station::create(['program_id' => $this->program->id, 'name' => 'S2', 'capacity' => 1]);
        $process1 = Process::create(['program_id' => $this->program->id, 'name' => 'S1', 'description' => null]);
        $process2 = Process::create(['program_id' => $this->program->id, 'name' => 'S2', 'description' => null]);
        DB::table('station_process')->insert([
            ['station_id' => $station1->id, 'process_id' => $process1->id],
            ['station_id' => $station2->id, 'process_id' => $process2->id],
        ]);
        $step1 = TrackStep::create(['track_id' => $this->track->id, 'process_id' => $process1->id, 'step_order' => 1, 'is_required' => true]);
        $step2 = TrackStep::create(['track_id' => $this->track->id, 'process_id' => $process2->id, 'step_order' => 2, 'is_required' => true]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/tracks/{$this->track->id}/steps/reorder", [
            'step_ids' => [$step2->id, $step1->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('steps.0.id', $step2->id);
        $response->assertJsonPath('steps.0.step_order', 1);
        $response->assertJsonPath('steps.1.id', $step1->id);
        $response->assertJsonPath('steps.1.step_order', 2);
    }

    public function test_reorder_with_migrate_sessions_updates_active_sessions_step_order(): void
    {
        $station1 = Station::create(['program_id' => $this->program->id, 'name' => 'S1', 'capacity' => 1]);
        $station2 = Station::create(['program_id' => $this->program->id, 'name' => 'S2', 'capacity' => 1]);
        $process1 = Process::create(['program_id' => $this->program->id, 'name' => 'S1', 'description' => null]);
        $process2 = Process::create(['program_id' => $this->program->id, 'name' => 'S2', 'description' => null]);
        DB::table('station_process')->insert([
            ['station_id' => $station1->id, 'process_id' => $process1->id],
            ['station_id' => $station2->id, 'process_id' => $process2->id],
        ]);
        $step1 = TrackStep::create(['track_id' => $this->track->id, 'process_id' => $process1->id, 'step_order' => 1, 'is_required' => true]);
        $step2 = TrackStep::create(['track_id' => $this->track->id, 'process_id' => $process2->id, 'step_order' => 2, 'is_required' => true]);

        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32));
        $token->physical_id = 'T1';
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'T1',
            'current_station_id' => $station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $token->update(['current_session_id' => $session->id]);

        $this->actingAs($this->admin)->postJson("/api/admin/tracks/{$this->track->id}/steps/reorder", [
            'step_ids' => [$step2->id, $step1->id],
            'migrate_sessions' => true,
        ]);

        $session->refresh();
        $this->assertSame($station1->id, $session->current_station_id);
        $this->assertSame(2, $session->current_step_order, 'Session at S1; after reorder S1 is step 2');
    }

    public function test_reorder_without_migrate_leaves_sessions_unchanged(): void
    {
        $station1 = Station::create(['program_id' => $this->program->id, 'name' => 'S1', 'capacity' => 1]);
        $station2 = Station::create(['program_id' => $this->program->id, 'name' => 'S2', 'capacity' => 1]);
        $process1 = Process::create(['program_id' => $this->program->id, 'name' => 'S1', 'description' => null]);
        $process2 = Process::create(['program_id' => $this->program->id, 'name' => 'S2', 'description' => null]);
        DB::table('station_process')->insert([
            ['station_id' => $station1->id, 'process_id' => $process1->id],
            ['station_id' => $station2->id, 'process_id' => $process2->id],
        ]);
        $step1 = TrackStep::create(['track_id' => $this->track->id, 'process_id' => $process1->id, 'step_order' => 1, 'is_required' => true]);
        $step2 = TrackStep::create(['track_id' => $this->track->id, 'process_id' => $process2->id, 'step_order' => 2, 'is_required' => true]);

        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32));
        $token->physical_id = 'T1';
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'T1',
            'current_station_id' => $station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $token->update(['current_session_id' => $session->id]);

        $this->actingAs($this->admin)->postJson("/api/admin/tracks/{$this->track->id}/steps/reorder", [
            'step_ids' => [$step2->id, $step1->id],
            'migrate_sessions' => false,
        ]);

        $session->refresh();
        $this->assertSame(1, $session->current_step_order, 'Without migrate, sessions keep original step_order');
    }

    public function test_staff_cannot_access_steps_api_returns_403(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)->getJson("/api/admin/tracks/{$this->track->id}/steps");

        $response->assertStatus(403);
    }
}
