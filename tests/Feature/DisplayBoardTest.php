<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Session;
use App\Models\ServiceTrack;
use App\Models\Station;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per 09-UI-ROUTES: Client-facing display (no auth).
 */
class DisplayBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_board_returns_200_without_auth(): void
    {
        $response = $this->get('/display');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Display/Board')
            ->has('program_name')
            ->has('date')
            ->has('now_serving')
            ->has('waiting_by_station')
            ->has('total_in_queue')
            ->has('station_activity')
            ->has('staff_at_stations')
            ->has('staff_online')
        );
        $props = $response->viewData('page')['props'];
        $this->assertIsArray($props['station_activity']);
        $this->assertIsArray($props['staff_at_stations']);
        foreach ($props['staff_at_stations'] as $row) {
            $this->assertArrayHasKey('station_name', $row);
            $this->assertArrayHasKey('staff', $row);
            foreach ($row['staff'] as $staff) {
                $this->assertArrayHasKey('name', $staff);
                $this->assertArrayNotHasKey('id', $staff);
            }
        }
    }

    public function test_display_board_shows_program_and_serving_when_active(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Cash Assistance',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Interview',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Priority',
            'is_default' => true,
        ]);
        $token = new \App\Models\Token;
        $token->qr_code_hash = hash('sha256', Str::random(32));
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
            'status' => 'serving',
        ]);
        $token->update(['current_session_id' => Session::first()->id]);

        $response = $this->get('/display');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Display/Board')
            ->where('program_name', 'Cash Assistance')
            ->where('total_in_queue', 1)
        );
        $data = $response->viewData('page')['props'];
        $this->assertCount(1, $data['now_serving']);
        $this->assertSame('A1', $data['now_serving'][0]['alias']);
        $this->assertSame('Interview', $data['now_serving'][0]['station_name']);
    }

    public function test_display_board_includes_station_activity_from_transaction_logs(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk A',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
        ]);
        $token = new \App\Models\Token;
        $token->qr_code_hash = hash('sha256', Str::random(32));
        $token->physical_id = 'B1';
        $token->status = 'in_use';
        $token->save();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'B1',
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'completed',
        ]);
        $token->update(['current_session_id' => null]);

        TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => $station->id,
            'staff_user_id' => $user->id,
            'action_type' => 'call',
        ]);
        TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => $station->id,
            'staff_user_id' => $user->id,
            'action_type' => 'check_in',
        ]);

        $response = $this->get('/display');

        $response->assertStatus(200);
        $props = $response->viewData('page')['props'];
        $this->assertArrayHasKey('station_activity', $props);
        $activity = $props['station_activity'];
        $this->assertIsArray($activity);
        $this->assertGreaterThanOrEqual(1, count($activity));
        $first = $activity[0];
        $this->assertArrayHasKey('station_name', $first);
        $this->assertArrayHasKey('message', $first);
        $this->assertArrayHasKey('alias', $first);
        $this->assertArrayHasKey('action_type', $first);
        $this->assertArrayHasKey('created_at', $first);
        $this->assertSame('Desk A', $first['station_name']);
        $this->assertStringContainsString('B1', $first['message']);
    }

    public function test_display_status_returns_200_for_valid_qr_hash(): void
    {
        $token = new \App\Models\Token;
        $hash = hash('sha256', 'test-qr');
        $token->qr_code_hash = $hash;
        $token->physical_id = 'B2';
        $token->status = 'available';
        $token->save();

        $response = $this->get('/display/status/'.$hash);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Display/Status')
            ->where('alias', 'B2')
            ->where('status', 'available')
        );
    }

    public function test_display_status_returns_200_with_error_for_unknown_qr_hash(): void
    {
        $response = $this->get('/display/status/unknown-hash');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Display/Status')
            ->where('error', 'Token not found.')
        );
    }

    public function test_display_status_in_use_includes_client_category_and_progress(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk 1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
        ]);
        \App\Models\TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $hash = hash('sha256', 'status-'.Str::random(8));
        $token = new \App\Models\Token;
        $token->qr_code_hash = $hash;
        $token->physical_id = 'F6';
        $token->status = 'in_use';
        $token->save();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'F6',
            'client_category' => 'PWD',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'started_at' => now(),
        ]);
        $token->update(['current_session_id' => $session->id]);

        $response = $this->get('/display/status/'.$hash);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Display/Status')
            ->where('alias', 'F6')
            ->where('status', 'waiting')
            ->where('client_category', 'PWD')
            ->where('current_station', 'Desk 1')
            ->has('progress')
            ->where('progress.total_steps', 1)
        );
    }
}
