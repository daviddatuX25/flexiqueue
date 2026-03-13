<?php

namespace Tests\Feature\Api;

use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Central+Edge Phase A — A.6.1:
 * Two programs active simultaneously; bind → call → serve → transfer → complete flows stay program-scoped.
 */
class MultiProgramSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Program $programA;

    private Program $programB;

    private ServiceTrack $trackA;

    private ServiceTrack $trackB;

    private Station $stationA1;

    private Station $stationA2;

    private Station $stationB1;

    private Station $stationB2;

    private User $staffA;

    private User $staffB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staffA = User::factory()->create(['role' => 'staff']);
        $this->staffB = User::factory()->create(['role' => 'staff']);

        // Program A
        $this->programA = Program::create([
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->staffA->id,
        ]);
        $this->stationA1 = Station::create([
            'program_id' => $this->programA->id,
            'name' => 'A - Station 1',
            'capacity' => 2,
            'is_active' => true,
        ]);
        $this->stationA2 = Station::create([
            'program_id' => $this->programA->id,
            'name' => 'A - Station 2',
            'capacity' => 2,
            'is_active' => true,
        ]);
        $processA1 = Process::create([
            'program_id' => $this->programA->id,
            'name' => 'A - Step 1',
            'description' => null,
        ]);
        $processA2 = Process::create([
            'program_id' => $this->programA->id,
            'name' => 'A - Step 2',
            'description' => null,
        ]);
        DB::table('station_process')->insert([
            ['station_id' => $this->stationA1->id, 'process_id' => $processA1->id],
            ['station_id' => $this->stationA2->id, 'process_id' => $processA2->id],
        ]);
        $this->trackA = ServiceTrack::create([
            'program_id' => $this->programA->id,
            'name' => 'Track A',
            'is_default' => true,
            'color_code' => '#333333',
        ]);
        TrackStep::create([
            'track_id' => $this->trackA->id,
            'process_id' => $processA1->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        TrackStep::create([
            'track_id' => $this->trackA->id,
            'process_id' => $processA2->id,
            'step_order' => 2,
            'is_required' => true,
        ]);
        $this->staffA->update(['assigned_station_id' => $this->stationA1->id]);

        // Program B
        $this->programB = Program::create([
            'name' => 'Program B',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->staffB->id,
        ]);
        $this->stationB1 = Station::create([
            'program_id' => $this->programB->id,
            'name' => 'B - Station 1',
            'capacity' => 2,
            'is_active' => true,
        ]);
        $this->stationB2 = Station::create([
            'program_id' => $this->programB->id,
            'name' => 'B - Station 2',
            'capacity' => 2,
            'is_active' => true,
        ]);
        $processB1 = Process::create([
            'program_id' => $this->programB->id,
            'name' => 'B - Step 1',
            'description' => null,
        ]);
        $processB2 = Process::create([
            'program_id' => $this->programB->id,
            'name' => 'B - Step 2',
            'description' => null,
        ]);
        DB::table('station_process')->insert([
            ['station_id' => $this->stationB1->id, 'process_id' => $processB1->id],
            ['station_id' => $this->stationB2->id, 'process_id' => $processB2->id],
        ]);
        $this->trackB = ServiceTrack::create([
            'program_id' => $this->programB->id,
            'name' => 'Track B',
            'is_default' => true,
            'color_code' => '#666666',
        ]);
        TrackStep::create([
            'track_id' => $this->trackB->id,
            'process_id' => $processB1->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        TrackStep::create([
            'track_id' => $this->trackB->id,
            'process_id' => $processB2->id,
            'step_order' => 2,
            'is_required' => true,
        ]);
        $this->staffB->update(['assigned_station_id' => $this->stationB1->id]);
    }

    public function test_multi_program_session_lifecycle_flows_are_isolated_per_program(): void
    {
        // Program A: bind 5 sessions via staff endpoint
        $aliasesA = [];
        for ($i = 1; $i <= 5; $i++) {
            $aliasesA[] = $this->bindSessionForProgram(
                actingUser: $this->staffA,
                program: $this->programA,
                track: $this->trackA,
                physicalId: 'A'.$i
            );
        }

        // Program B: bind 5 sessions via staff endpoint
        $aliasesB = [];
        for ($i = 1; $i <= 5; $i++) {
            $aliasesB[] = $this->bindSessionForProgram(
                actingUser: $this->staffB,
                program: $this->programB,
                track: $this->trackB,
                physicalId: 'B'.$i
            );
        }

        // Lifecycle for one session in Program A
        $sessionA = Session::where('program_id', $this->programA->id)
            ->where('alias', $aliasesA[0])
            ->firstOrFail();

        $this->actingAs($this->staffA)->postJson("/api/sessions/{$sessionA->id}/call")
            ->assertStatus(200);

        $this->actingAs($this->staffA)->postJson("/api/sessions/{$sessionA->id}/serve")
            ->assertStatus(200);

        $this->actingAs($this->staffA)->postJson("/api/sessions/{$sessionA->id}/transfer", [
            'mode' => 'standard',
        ])->assertStatus(200);

        $sessionA->refresh();
        $this->assertSame($this->stationA2->id, $sessionA->current_station_id);

        // Serve again at second station before completing (per SessionActions flow)
        $this->actingAs($this->staffA)->postJson("/api/sessions/{$sessionA->id}/serve", [
            'station_id' => $this->stationA2->id,
        ])->assertStatus(200);

        $this->actingAs($this->staffA)->postJson("/api/sessions/{$sessionA->id}/complete")
            ->assertStatus(200);

        $sessionA->refresh();
        $this->assertSame('completed', $sessionA->status);
        $this->assertSame($this->programA->id, $sessionA->program_id);

        // Lifecycle for one session in Program B
        $sessionB = Session::where('program_id', $this->programB->id)
            ->where('alias', $aliasesB[0])
            ->firstOrFail();

        $this->actingAs($this->staffB)->postJson("/api/sessions/{$sessionB->id}/call")
            ->assertStatus(200);

        $this->actingAs($this->staffB)->postJson("/api/sessions/{$sessionB->id}/serve")
            ->assertStatus(200);

        $this->actingAs($this->staffB)->postJson("/api/sessions/{$sessionB->id}/transfer", [
            'mode' => 'standard',
        ])->assertStatus(200);

        $sessionB->refresh();
        $this->assertSame($this->stationB2->id, $sessionB->current_station_id);

        // Serve again at second station before completing
        $this->actingAs($this->staffB)->postJson("/api/sessions/{$sessionB->id}/serve", [
            'station_id' => $this->stationB2->id,
        ])->assertStatus(200);

        $this->actingAs($this->staffB)->postJson("/api/sessions/{$sessionB->id}/complete")
            ->assertStatus(200);

        $sessionB->refresh();
        $this->assertSame('completed', $sessionB->status);
        $this->assertSame($this->programB->id, $sessionB->program_id);

        // Cross-contamination checks
        $this->assertSame(
            0,
            Session::where('program_id', $this->programA->id)
                ->whereIn('alias', $aliasesB)
                ->count(),
            'Program A must not contain Program B aliases.'
        );
        $this->assertSame(
            0,
            Session::where('program_id', $this->programB->id)
                ->whereIn('alias', $aliasesA)
                ->count(),
            'Program B must not contain Program A aliases.'
        );
    }

    public function test_staff_for_program_a_never_sees_program_b_sessions_in_queue(): void
    {
        // Create one waiting session at Program A station1
        $aliasA = $this->bindSessionForProgram(
            actingUser: $this->staffA,
            program: $this->programA,
            track: $this->trackA,
            physicalId: 'AQ1'
        );

        // Create one waiting session at Program B station1
        $aliasB = $this->bindSessionForProgram(
            actingUser: $this->staffB,
            program: $this->programB,
            track: $this->trackB,
            physicalId: 'BQ1'
        );

        // Queue for Program A station must only include Program A alias
        $response = $this->actingAs($this->staffA)->getJson("/api/stations/{$this->stationA1->id}/queue");

        $response->assertStatus(200);
        $waiting = $response->json('waiting');
        $this->assertIsArray($waiting);
        $aliases = array_column($waiting, 'alias');
        $this->assertContains($aliasA, $aliases);
        $this->assertNotContains($aliasB, $aliases);
    }

    private function bindSessionForProgram(User $actingUser, Program $program, ServiceTrack $track, string $physicalId): string
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).$physicalId);
        $token->physical_id = $physicalId;
        $token->status = 'available';
        $token->save();

        $response = $this->actingAs($actingUser)->postJson('/api/sessions/bind', [
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(201);
        $alias = $response->json('session.alias');

        $this->assertNotNull($alias);
        $this->assertDatabaseHas('queue_sessions', [
            'alias' => $alias,
            'program_id' => $program->id,
        ]);

        return $alias;
    }
}

