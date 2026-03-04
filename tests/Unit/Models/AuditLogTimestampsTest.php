<?php

namespace Tests\Unit\Models;

use App\Models\Program;
use App\Models\ProgramAuditLog;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TransactionLog;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ensure append-only audit models set created_at when not passed (SQLite NOT NULL).
 * Guards against removing the creating hook from TransactionLog / ProgramAuditLog.
 */
class AuditLogTimestampsTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_log_has_created_at_set_when_not_passed(): void
    {
        $this->createMinimalSession();
        $session = Session::first();
        $user = User::factory()->create(['role' => 'staff']);

        $log = TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => $session->current_station_id,
            'staff_user_id' => $user->id,
            'action_type' => 'bind',
        ]);

        $this->assertNotNull($log->created_at, 'TransactionLog created_at must be set by creating hook');
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $log->created_at);
    }

    public function test_program_audit_log_has_created_at_set_when_not_passed(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $program = Program::create([
            'name' => 'Test',
            'is_active' => false,
            'created_by' => $user->id,
        ]);

        $log = ProgramAuditLog::create([
            'program_id' => $program->id,
            'staff_user_id' => $user->id,
            'action' => 'session_start',
        ]);

        $this->assertNotNull($log->created_at, 'ProgramAuditLog created_at must be set by creating hook');
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $log->created_at);
    }

    private function createMinimalSession(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $program = Program::create([
            'name' => 'Test',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();
        Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'waiting',
        ]);
    }
}
