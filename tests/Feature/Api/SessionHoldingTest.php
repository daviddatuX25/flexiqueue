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
 * Per station-holding-area plan: hold and resume-from-hold API and queue composition.
 */
class SessionHoldingTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $otherStaff;

    private Program $program;

    private ServiceTrack $track;

    private Station $station1;

    private Station $station2;

    private Token $token;

    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create();
        $this->otherStaff = User::factory()->create();
        $this->program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->staff->id,
        ]);
        $this->station1 = Station::create([
            'program_id' => $this->program->id,
            'name' => 'First Station',
            'capacity' => 1,
            'client_capacity' => 1,
            'holding_capacity' => 3,
            'is_active' => true,
        ]);
        $this->station2 = Station::create([
            'program_id' => $this->program->id,
            'name' => 'Second Station',
            'capacity' => 1,
            'client_capacity' => 1,
            'holding_capacity' => 3,
            'is_active' => true,
        ]);
        $process1 = Process::create(['program_id' => $this->program->id, 'name' => 'First', 'description' => null]);
        $process2 = Process::create(['program_id' => $this->program->id, 'name' => 'Second', 'description' => null]);
        DB::table('station_process')->insert([
            ['station_id' => $this->station1->id, 'process_id' => $process1->id],
            ['station_id' => $this->station2->id, 'process_id' => $process2->id],
        ]);
        $this->track = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        TrackStep::create([
            'track_id' => $this->track->id,
            'process_id' => $process1->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        TrackStep::create([
            'track_id' => $this->track->id,
            'process_id' => $process2->id,
            'step_order' => 2,
            'is_required' => true,
        ]);
        $this->token = new Token;
        $this->token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $this->token->physical_id = 'A1';
        $this->token->status = 'in_use';
        $this->token->save();
        $this->session = Session::create([
            'token_id' => $this->token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);
        $this->token->update(['current_session_id' => $this->session->id]);
        $this->staff->update(['assigned_station_id' => $this->station1->id]);
        $this->otherStaff->update(['assigned_station_id' => $this->station2->id]);
    }

    public function test_can_hold_serving_session_when_capacity_available(): void
    {
        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/hold", []);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Session moved to holding');
        $response->assertJsonPath('session_id', $this->session->id);
        $this->session->refresh();
        $this->assertTrue($this->session->is_on_hold);
        $this->assertSame($this->station1->id, $this->session->holding_station_id);
        $this->assertNotNull($this->session->held_at);
        $this->assertDatabaseHas('transaction_logs', [
            'session_id' => $this->session->id,
            'station_id' => $this->station1->id,
            'action_type' => 'hold',
        ]);
    }

    public function test_held_session_appears_in_holding_not_in_serving_or_waiting(): void
    {
        $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/hold", []);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertNotEmpty($data['holding']);
        $this->assertCount(1, $data['holding']);
        $this->assertSame($this->session->id, $data['holding'][0]['session_id']);
        $this->assertSame('A1', $data['holding'][0]['alias']);
        $servingIds = array_column($data['serving'] ?? [], 'session_id');
        $waitingIds = array_column($data['waiting'] ?? [], 'session_id');
        $this->assertNotContains($this->session->id, $servingIds);
        $this->assertNotContains($this->session->id, $waitingIds);
        $this->assertSame(3, $data['station']['holding_capacity']);
        $this->assertSame(1, $data['station']['holding_count']);
    }

    public function test_cannot_hold_when_holding_area_full(): void
    {
        $capacity = $this->station1->getHoldingCapacity();
        for ($i = 0; $i < $capacity; $i++) {
            $t = new Token;
            $t->qr_code_hash = hash('sha256', Str::random(32)."H{$i}");
            $t->physical_id = "H{$i}";
            $t->status = 'in_use';
            $t->save();
            Session::create([
                'token_id' => $t->id,
                'program_id' => $this->program->id,
                'track_id' => $this->track->id,
                'alias' => "H{$i}",
                'client_category' => 'Regular',
                'current_station_id' => $this->station1->id,
                'current_step_order' => 1,
                'status' => 'serving',
                'is_on_hold' => true,
                'holding_station_id' => $this->station1->id,
                'held_at' => now(),
                'held_order' => $i + 1,
            ]);
            $t->update(['current_session_id' => Session::where('alias', "H{$i}")->first()->id]);
        }

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/hold", []);

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'holding_full');
    }

    public function test_can_resume_from_hold_when_capacity_allows(): void
    {
        $this->session->update([
            'is_on_hold' => true,
            'holding_station_id' => $this->station1->id,
            'held_at' => now(),
            'held_order' => 1,
        ]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/resume-from-hold", []);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Session resumed');
        $this->session->refresh();
        $this->assertFalse($this->session->is_on_hold);
        $this->assertNull($this->session->holding_station_id);
        $this->assertSame('serving', $this->session->status);
        $this->assertDatabaseHas('transaction_logs', [
            'session_id' => $this->session->id,
            'station_id' => $this->station1->id,
            'action_type' => 'resume_from_hold',
        ]);
    }

    public function test_cannot_resume_from_hold_when_at_capacity(): void
    {
        $this->session->update([
            'is_on_hold' => true,
            'holding_station_id' => $this->station1->id,
            'held_at' => now(),
            'held_order' => 1,
        ]);
        $t2 = new Token;
        $t2->qr_code_hash = hash('sha256', Str::random(32).'A2');
        $t2->physical_id = 'A2';
        $t2->status = 'in_use';
        $t2->save();
        Session::create([
            'token_id' => $t2->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A2',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);
        $t2->update(['current_session_id' => Session::where('alias', 'A2')->first()->id]);
        $this->station1->update(['client_capacity' => 1]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/resume-from-hold", []);

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'at_capacity');
    }

    public function test_unauthorized_cannot_hold_or_resume(): void
    {
        $response = $this->actingAs($this->otherStaff)->postJson("/api/sessions/{$this->session->id}/hold", []);
        $response->assertStatus(403);

        $this->session->update([
            'is_on_hold' => true,
            'holding_station_id' => $this->station1->id,
            'held_at' => now(),
            'held_order' => 1,
        ]);
        $response2 = $this->actingAs($this->otherStaff)->postJson("/api/sessions/{$this->session->id}/resume-from-hold", []);
        $response2->assertStatus(403);
    }
}
