<?php

namespace Tests\Unit\Models;

use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionLogTriggersTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_triggers_prevent_update_and_delete_via_raw_queries(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite trigger behavior only verified on sqlite driver.');
        }

        $log = $this->createTransactionLog();

        $this->expectException(QueryException::class);

        try {
            DB::table('transaction_logs')
                ->where('id', $log->id)
                ->update(['remarks' => 'should not be allowed']);
        } finally {
            $this->assertDatabaseHas('transaction_logs', [
                'id' => $log->id,
            ]);
        }

        $this->expectException(QueryException::class);

        try {
            DB::table('transaction_logs')
                ->where('id', $log->id)
                ->delete();
        } finally {
            $this->assertDatabaseHas('transaction_logs', [
                'id' => $log->id,
            ]);
        }
    }

    public function test_mysql_triggers_prevent_update_and_delete_via_raw_queries(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL trigger behavior only verified on mysql driver.');
        }

        $log = $this->createTransactionLog();

        $this->expectException(QueryException::class);

        try {
            DB::table('transaction_logs')
                ->where('id', $log->id)
                ->update(['remarks' => 'should not be allowed']);
        } finally {
            $this->assertDatabaseHas('transaction_logs', [
                'id' => $log->id,
            ]);
        }

        $this->expectException(QueryException::class);

        try {
            DB::table('transaction_logs')
                ->where('id', $log->id)
                ->delete();
        } finally {
            $this->assertDatabaseHas('transaction_logs', [
                'id' => $log->id,
            ]);
        }
    }

    private function createTransactionLog(): TransactionLog
    {
        $user = User::factory()->admin()->create();

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

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'waiting',
        ]);

        return TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => $session->current_station_id,
            'staff_user_id' => $user->id,
            'action_type' => 'bind',
        ]);
    }
}
