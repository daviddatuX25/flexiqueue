<?php

namespace Tests\Unit\Services;

use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\TransactionLog;
use App\Models\User;
use App\Services\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit tests for SessionService hold and resume-from-hold. Per station-holding-area plan.
 */
class SessionServiceHoldingTest extends TestCase
{
    use RefreshDatabase;

    private SessionService $service;

    private User $staff;

    private Program $program;

    private Station $station;

    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SessionService::class);
        $this->staff = User::factory()->create();
        $this->program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->staff->id,
        ]);
        $this->station = Station::create([
            'program_id' => $this->program->id,
            'name' => 'S1',
            'capacity' => 1,
            'client_capacity' => 2,
            'holding_capacity' => 3,
            'is_active' => true,
        ]);
        $process = Process::create(['program_id' => $this->program->id, 'name' => 'P1', 'description' => null]);
        DB::table('station_process')->insert([['station_id' => $this->station->id, 'process_id' => $process->id]]);
        $track = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'T1',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();
        $this->session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);
        $token->update(['current_session_id' => $this->session->id]);
    }

    public function test_move_to_holding_sets_fields_and_creates_hold_log(): void
    {
        $this->service->moveToHolding($this->session, $this->station, $this->staff->id, null);

        $this->session->refresh();
        $this->assertTrue($this->session->is_on_hold);
        $this->assertSame($this->station->id, $this->session->holding_station_id);
        $this->assertNotNull($this->session->held_at);
        $this->assertNotNull($this->session->held_order);
        $this->assertDatabaseHas('transaction_logs', [
            'session_id' => $this->session->id,
            'station_id' => $this->station->id,
            'action_type' => 'hold',
        ]);
    }

    public function test_move_to_holding_throws_when_holding_area_full(): void
    {
        $this->station->update(['holding_capacity' => 1]);
        $token2 = new Token;
        $token2->qr_code_hash = hash('sha256', Str::random(32).'A2');
        $token2->physical_id = 'A2';
        $token2->status = 'in_use';
        $token2->save();
        Session::create([
            'token_id' => $token2->id,
            'program_id' => $this->program->id,
            'track_id' => $this->session->track_id,
            'alias' => 'A2',
            'client_category' => 'Regular',
            'current_station_id' => $this->station->id,
            'current_step_order' => 1,
            'status' => 'serving',
            'is_on_hold' => true,
            'holding_station_id' => $this->station->id,
            'held_at' => now(),
            'held_order' => 1,
        ]);
        $token2->update(['current_session_id' => Session::where('alias', 'A2')->first()->id]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Holding area is full');
        $this->service->moveToHolding($this->session, $this->station, $this->staff->id, null);
    }

    public function test_move_to_holding_throws_when_session_not_serving(): void
    {
        $this->session->update(['status' => 'called']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not being served');
        $this->service->moveToHolding($this->session, $this->station, $this->staff->id, null);
    }

    public function test_resume_from_holding_clears_fields_and_creates_resume_log(): void
    {
        $this->session->update([
            'is_on_hold' => true,
            'holding_station_id' => $this->station->id,
            'held_at' => now(),
            'held_order' => 1,
        ]);

        $this->service->resumeFromHolding($this->session, $this->station, $this->staff->id, null);

        $this->session->refresh();
        $this->assertFalse($this->session->is_on_hold);
        $this->assertNull($this->session->holding_station_id);
        $this->assertNull($this->session->held_at);
        $this->assertNull($this->session->held_order);
        $this->assertSame('serving', $this->session->status);
        $this->assertDatabaseHas('transaction_logs', [
            'session_id' => $this->session->id,
            'station_id' => $this->station->id,
            'action_type' => 'resume_from_hold',
        ]);
    }

    public function test_resume_from_holding_throws_when_station_at_capacity(): void
    {
        $this->session->update([
            'is_on_hold' => true,
            'holding_station_id' => $this->station->id,
            'held_at' => now(),
            'held_order' => 1,
        ]);
        $this->station->update(['client_capacity' => 1]);
        $token2 = new Token;
        $token2->qr_code_hash = hash('sha256', Str::random(32).'A2');
        $token2->physical_id = 'A2';
        $token2->status = 'in_use';
        $token2->save();
        Session::create([
            'token_id' => $token2->id,
            'program_id' => $this->program->id,
            'track_id' => $this->session->track_id,
            'alias' => 'A2',
            'client_category' => 'Regular',
            'current_station_id' => $this->station->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);
        $token2->update(['current_session_id' => Session::where('alias', 'A2')->first()->id]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at capacity');
        $this->service->resumeFromHolding($this->session, $this->station, $this->staff->id, null);
    }

    public function test_finish_session_clears_holding_fields(): void
    {
        $this->session->update([
            'is_on_hold' => true,
            'holding_station_id' => $this->station->id,
            'held_at' => now(),
            'held_order' => 1,
        ]);

        $this->service->complete($this->session, $this->staff->id);

        $this->session->refresh();
        $this->assertSame('completed', $this->session->status);
        $this->assertFalse($this->session->is_on_hold);
        $this->assertNull($this->session->holding_station_id);
        $this->assertNull($this->session->held_at);
        $this->assertNull($this->session->held_order);
    }
}
