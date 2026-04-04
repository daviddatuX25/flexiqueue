<?php

namespace Tests\Feature\Edge;

use App\Models\EdgeDeviceState;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use App\Services\EdgeBatchSyncService;
use App\Services\EdgeModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EdgeBatchSyncTest extends TestCase
{
    use RefreshDatabase;

    private function setupEdgeState(string $syncMode = 'end_of_event', bool $sessionActive = false): EdgeDeviceState
    {
        $state = EdgeDeviceState::current();
        $state->update([
            'central_url' => 'https://central.test',
            'device_token' => 'test-token-abc',
            'sync_mode' => $syncMode,
            'session_active' => $sessionActive,
            'active_program_id' => 1,
            'site_id' => 1,
        ]);
        return $state;
    }

    private function createEdgeSessionData(): array
    {
        $user = User::factory()->admin()->create();
        $program = Program::create([
            'name' => 'Test Program',
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
        $token->qr_code_hash = hash('sha256', Str::random(32));
        $token->physical_id = 'A1-' . uniqid();
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
            'status' => 'completed',
        ]);

        // Bypass TransactionLog::create to avoid model hooks
        DB::table('transaction_logs')->insert([
            'session_id' => $session->id,
            'station_id' => $station->id,
            'staff_user_id' => $user->id,
            'action_type' => 'bind',
            'created_at' => now(),
        ]);

        DB::table('transaction_logs')->insert([
            'session_id' => $session->id,
            'station_id' => $station->id,
            'staff_user_id' => $user->id,
            'action_type' => 'complete',
            'created_at' => now(),
        ]);

        return [$session, $program, $token];
    }

    public function test_batch_sync_service_collects_unsynced_data(): void
    {
        $this->setupEdgeState('end_of_event', false);
        [$session, $program, $token] = $this->createEdgeSessionData();

        $service = new EdgeBatchSyncService();
        $payload = $service->collectUnsyncedData();

        $this->assertArrayHasKey('session_summary', $payload);
        $this->assertArrayHasKey('queue_sessions', $payload);
        $this->assertArrayHasKey('transaction_logs', $payload);
        $this->assertArrayHasKey('token_updates', $payload);

        $this->assertCount(1, $payload['queue_sessions']);
        $this->assertCount(2, $payload['transaction_logs']);
        $this->assertEquals($session->id, $payload['queue_sessions'][0]['id']);
    }

    public function test_batch_sync_service_returns_empty_when_nothing_to_sync(): void
    {
        $state = $this->setupEdgeState('end_of_event', false);
        $state->update(['last_synced_at' => now()]);

        $service = new EdgeBatchSyncService();
        $payload = $service->collectUnsyncedData();

        $this->assertEmpty($payload['queue_sessions']);
        $this->assertEmpty($payload['transaction_logs']);
    }
}
