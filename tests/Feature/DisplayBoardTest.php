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
            ->has('programs')
            ->has('currentProgram')
            ->has('program_name')
            ->has('date')
            ->has('now_serving')
            ->has('waiting_by_station')
            ->has('total_in_queue')
            ->has('station_activity')
            ->has('staff_at_stations')
            ->has('staff_online')
            ->has('display_scan_timeout_seconds')
            ->has('program_is_paused')
            ->has('queueing_method_label')
            ->has('queue_mode_display')
            ->has('alternate_ratio')
            ->has('priority_first')
        );
        $props = $response->viewData('page')['props'];
        $this->assertFalse($props['program_is_paused']);
        $this->assertIsInt($props['display_scan_timeout_seconds']);
        $this->assertGreaterThanOrEqual(0, $props['display_scan_timeout_seconds']);
        $this->assertIsArray($props['station_activity']);
        $this->assertIsArray($props['staff_at_stations']);
        foreach ($props['staff_at_stations'] as $row) {
            $this->assertArrayHasKey('station_name', $row);
            $this->assertArrayHasKey('staff', $row);
            foreach ($row['staff'] as $staff) {
                $this->assertArrayHasKey('name', $staff);
                $this->assertArrayHasKey('availability_status', $staff);
                $this->assertContains($staff['availability_status'], ['available', 'on_break', 'away', 'offline']);
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

        $response = $this->get('/display?program='.$program->id);

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
        // Per flexiqueue-ui3: now_serving includes process_id and process_name for each client
        $this->assertArrayHasKey('process_id', $data['now_serving'][0]);
        $this->assertArrayHasKey('process_name', $data['now_serving'][0]);
        // Per flexiqueue-87p: display_scan_timeout_seconds from program settings (default 20)
        $this->assertSame(20, $data['display_scan_timeout_seconds']);
        $this->assertFalse($data['program_is_paused']);
        // Per flexiqueue-syam: queue mode display (default fifo)
        $this->assertSame('FIFO', $data['queue_mode_display']);
        $this->assertNull($data['alternate_ratio']);
        $this->assertTrue($data['priority_first']);
    }

    /** Display board returns program_is_paused true when active program is paused. */
    public function test_display_board_returns_program_is_paused_when_program_paused(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Paused Program',
            'description' => null,
            'is_active' => true,
            'is_paused' => true,
            'created_by' => $user->id,
        ]);
        $response = $this->get('/display?program='.$program->id);
        $response->assertStatus(200);
        $data = $response->viewData('page')['props'];
        $this->assertTrue($data['program_is_paused']);
        $this->assertSame('Paused Program', $data['program_name']);
    }

    /** Per flexiqueue-87p: display board returns display_scan_timeout_seconds from program settings. */
    public function test_display_board_returns_display_scan_timeout_from_program_settings(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['display_scan_timeout_seconds' => 120],
        ]);
        $response = $this->get('/display?program='.$program->id);
        $response->assertStatus(200);
        $data = $response->viewData('page')['props'];
        $this->assertSame(120, $data['display_scan_timeout_seconds']);
    }

    /** Per flexiqueue-ui3: display board waiting_by_station includes waiting_clients with alias and process_name */
    public function test_display_board_waiting_by_station_includes_waiting_clients_with_process(): void
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
            'status' => 'waiting',
        ]);
        $token->update(['current_session_id' => Session::first()->id]);

        $response = $this->get('/display?program='.$program->id);

        $response->assertStatus(200);
        $data = $response->viewData('page')['props'];
        $this->assertCount(1, $data['waiting_by_station']);
        $row = $data['waiting_by_station'][0];
        $this->assertArrayHasKey('waiting_clients', $row);
        $this->assertCount(1, $row['waiting_clients']);
        $this->assertSame('A1', $row['waiting_clients'][0]['alias']);
        $this->assertArrayHasKey('process_name', $row['waiting_clients'][0]);
    }

    public function test_display_board_includes_staff_availability_in_staff_at_stations(): void
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
        $staffAvailable = User::factory()->create([
            'name' => 'Jane Available',
            'assigned_station_id' => $station->id,
            'availability_status' => 'available',
        ]);
        $staffOnBreak = User::factory()->create([
            'name' => 'Bob On Break',
            'assigned_station_id' => $station->id,
            'availability_status' => 'on_break',
        ]);
        $staffAway = User::factory()->create([
            'name' => 'Ali Away',
            'assigned_station_id' => $station->id,
            'availability_status' => 'away',
        ]);

        $response = $this->get('/display?program='.$program->id);

        $response->assertStatus(200);
        $props = $response->viewData('page')['props'];
        $this->assertArrayHasKey('staff_at_stations', $props);
        $this->assertCount(1, $props['staff_at_stations']);
        $row = $props['staff_at_stations'][0];
        $this->assertSame('Desk A', $row['station_name']);
        $staffNames = array_column($row['staff'], 'name');
        $this->assertContains('Jane Available', $staffNames);
        $this->assertContains('Bob On Break', $staffNames);
        $this->assertContains('Ali Away', $staffNames);
        $byName = [];
        foreach ($row['staff'] as $s) {
            $byName[$s['name']] = $s['availability_status'];
        }
        $this->assertSame('available', $byName['Jane Available']);
        $this->assertSame('on_break', $byName['Bob On Break']);
        $this->assertSame('away', $byName['Ali Away']);
        $this->assertSame(1, $props['staff_online']);
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

        $response = $this->get('/display?program='.$program->id);

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

    /** Per ISSUES-ELABORATION §10: display board shows bind in activity and waiting list after triage bind */
    public function test_display_board_includes_bind_in_waiting_and_activity(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Queue Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Triage',
            'capacity' => 2,
            'is_active' => true,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
        ]);
        $token = new \App\Models\Token;
        $token->qr_code_hash = hash('sha256', Str::random(32));
        $token->physical_id = 'C1';
        $token->status = 'in_use';
        $token->save();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'C1',
            'client_category' => 'PWD',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'station_queue_position' => 1,
        ]);
        $token->update(['current_session_id' => $session->id]);

        TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => null,
            'next_station_id' => $station->id,
            'staff_user_id' => $user->id,
            'action_type' => 'bind',
        ]);

        $response = $this->get('/display?program='.$program->id);

        $response->assertStatus(200);
        $props = $response->viewData('page')['props'];
        $this->assertSame(1, $props['total_in_queue']);
        $this->assertCount(1, $props['waiting_by_station']);
        $this->assertSame('Triage', $props['waiting_by_station'][0]['station_name']);
        $this->assertSame(['C1'], $props['waiting_by_station'][0]['aliases']);
        $this->assertSame(1, $props['waiting_by_station'][0]['count']);
        $bindActivities = array_filter($props['station_activity'], fn ($a) => ($a['action_type'] ?? '') === 'bind');
        $this->assertNotEmpty($bindActivities, 'station_activity should include bind entry');
        $firstBind = reset($bindActivities);
        $this->assertStringContainsString('registered at triage', $firstBind['message']);
        $this->assertSame('C1', $firstBind['alias']);
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
            ->has('estimated_wait_minutes')
        );
        $props = $response->viewData('page')['props'];
        $this->assertArrayHasKey('estimated_wait_minutes', $props);
        $this->assertSame(0, $props['estimated_wait_minutes'], 'Single step track has no remaining steps');
    }

    public function test_display_status_in_use_includes_diagram_when_program_has_diagram(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Diagram Program',
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
        $process = \App\Models\Process::create([
            'program_id' => $program->id,
            'name' => 'Check-in',
        ]);
        $station->processes()->attach($process->id);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Main',
            'is_default' => true,
        ]);
        \App\Models\TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        \App\Models\ProgramDiagram::create([
            'program_id' => $program->id,
            'layout' => [
                'nodes' => [
                    ['id' => 'n1', 'type' => 'station', 'position' => ['x' => 0, 'y' => 0], 'data' => ['entityId' => $station->id]],
                ],
                'edges' => [],
            ],
        ]);
        $hash = hash('sha256', 'diagram-'.Str::random(8));
        $token = new \App\Models\Token;
        $token->qr_code_hash = $hash;
        $token->physical_id = 'D1';
        $token->status = 'in_use';
        $token->save();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'D1',
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'serving',
            'started_at' => now(),
        ]);
        $token->update(['current_session_id' => $session->id]);

        $response = $this->get('/display/status/'.$hash);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Display/Status')
            ->has('diagram')
            ->has('diagram_program')
            ->has('diagram_tracks')
            ->has('diagram_stations')
            ->has('diagram_processes')
            ->has('diagram_staff')
            ->where('diagram_track_id', $track->id)
        );
        $props = $response->viewData('page')['props'];
        $this->assertIsArray($props['diagram']['nodes']);
        $this->assertCount(1, $props['diagram']['nodes']);
        $this->assertSame(['id' => $program->id, 'name' => 'Diagram Program'], $props['diagram_program']);
        $this->assertCount(1, $props['diagram_tracks']);
        $this->assertSame($track->id, $props['diagram_tracks'][0]['id']);
    }

    /** Per plan: station display 404 for nonexistent station id. */
    public function test_display_station_returns_404_for_nonexistent_station(): void
    {
        $response = $this->get('/display/station/99999');

        $response->assertStatus(404);
    }

    /** Per plan: station display 404 when station is inactive. */
    public function test_display_station_returns_404_for_inactive_station(): void
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
            'name' => 'Inactive Desk',
            'capacity' => 1,
            'is_active' => false,
        ]);

        $response = $this->get('/display/station/'.$station->id);

        $response->assertStatus(404);
    }

    /** Per plan: station display 404 when no active program. */
    public function test_display_station_returns_404_when_no_active_program(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Inactive Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk 1',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $response = $this->get('/display/station/'.$station->id);

        $response->assertStatus(404);
    }

    /** Per plan: station display 404 when station belongs to different program than active. */
    public function test_display_station_returns_404_when_station_from_different_program(): void
    {
        $user = User::factory()->create();
        $activeProgram = Program::create([
            'name' => 'Active',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $otherProgram = Program::create([
            'name' => 'Other',
            'description' => null,
            'is_active' => false,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $otherProgram->id,
            'name' => 'Other Desk',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $response = $this->get('/display/station/'.$station->id);

        $response->assertStatus(404);
    }

    /** Per plan: station display 200 with correct Inertia component and props for valid active station. */
    public function test_display_station_returns_200_with_station_scoped_data(): void
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
            'name' => 'Verification Desk',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $response = $this->get('/display/station/'.$station->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Display/StationBoard')
            ->has('program_name')
            ->has('date')
            ->has('station_name')
            ->has('station_id')
            ->has('now_serving')
            ->has('waiting')
            ->has('holding')
            ->has('station_activity')
            ->has('max_no_show_attempts')
        );
        $props = $response->viewData('page')['props'];
        $this->assertSame('Cash Assistance', $props['program_name']);
        $this->assertSame('Verification Desk', $props['station_name']);
        $this->assertSame($station->id, $props['station_id']);
        $this->assertIsArray($props['now_serving']);
        $this->assertIsArray($props['waiting']);
        $this->assertIsArray($props['holding']);
        $this->assertIsArray($props['station_activity']);
        $this->assertIsInt($props['max_no_show_attempts']);
    }

    /** Per plan: station display includes now_serving and waiting shape when sessions at station. */
    public function test_display_station_includes_now_serving_and_waiting_with_sessions(): void
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
            'name' => 'Interview',
            'capacity' => 2,
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
        $token1 = new \App\Models\Token;
        $token1->qr_code_hash = hash('sha256', Str::random(16));
        $token1->physical_id = 'A1';
        $token1->status = 'in_use';
        $token1->save();
        $session1 = Session::create([
            'token_id' => $token1->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'called',
            'started_at' => now(),
        ]);
        $token1->update(['current_session_id' => $session1->id]);
        $token2 = new \App\Models\Token;
        $token2->qr_code_hash = hash('sha256', Str::random(16));
        $token2->physical_id = 'A2';
        $token2->status = 'in_use';
        $token2->save();
        Session::create([
            'token_id' => $token2->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A2',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'waiting',
            'started_at' => now(),
        ]);
        $token2->update(['current_session_id' => Session::where('alias', 'A2')->first()->id]);

        $response = $this->get('/display/station/'.$station->id);

        $response->assertStatus(200);
        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['now_serving']);
        $this->assertSame('A1', $props['now_serving'][0]['alias']);
        $this->assertSame('called', $props['now_serving'][0]['status']);
        $this->assertArrayHasKey('no_show_attempts', $props['now_serving'][0]);
        $this->assertCount(1, $props['waiting']);
        $this->assertSame('A2', $props['waiting'][0]['alias']);
        $this->assertSame(1, $props['waiting'][0]['position']);
        $this->assertArrayHasKey('no_show_attempts', $props['waiting'][0]);
        $this->assertIsArray($props['holding']);
        $this->assertSame([], $props['holding']);
    }

    /** Per plan: general display returns display_audio_muted and display_audio_volume from program settings. */
    public function test_display_board_includes_display_audio_settings_when_program_has_them(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => [
                'display_audio_muted' => true,
                'display_audio_volume' => 0.5,
            ],
        ]);

        $response = $this->get('/display?program='.$program->id);

        $response->assertStatus(200);
        $props = $response->viewData('page')['props'];
        $this->assertArrayHasKey('display_audio_muted', $props);
        $this->assertArrayHasKey('display_audio_volume', $props);
        $this->assertTrue($props['display_audio_muted']);
        $this->assertSame(0.5, $props['display_audio_volume']);
    }

    /** Per plan: display board returns display_tts_repeat_count and display_tts_repeat_delay_ms from program. */
    public function test_display_board_includes_tts_repeat_settings_when_program_has_them(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => [
                'display_tts_repeat_count' => 2,
                'display_tts_repeat_delay_ms' => 2500,
            ],
        ]);

        $response = $this->get('/display?program='.$program->id);

        $response->assertStatus(200);
        $props = $response->viewData('page')['props'];
        $this->assertArrayHasKey('display_tts_repeat_count', $props);
        $this->assertArrayHasKey('display_tts_repeat_delay_ms', $props);
        $this->assertSame(2, $props['display_tts_repeat_count']);
        $this->assertSame(2500, $props['display_tts_repeat_delay_ms']);
    }

    /** Per plan: general display returns default display_audio when no program. */
    public function test_display_board_returns_default_display_audio_when_no_program(): void
    {
        $response = $this->get('/display');

        $response->assertStatus(200);
        $props = $response->viewData('page')['props'];
        $this->assertArrayHasKey('display_audio_muted', $props);
        $this->assertArrayHasKey('display_audio_volume', $props);
        $this->assertFalse($props['display_audio_muted']);
        $this->assertSame(1.0, $props['display_audio_volume']);
        $this->assertArrayHasKey('display_tts_repeat_count', $props);
        $this->assertArrayHasKey('display_tts_repeat_delay_ms', $props);
        $this->assertSame(1, $props['display_tts_repeat_count']);
        $this->assertSame(2000, $props['display_tts_repeat_delay_ms']);
    }

    // --- A.2.4 Display board program resolution (query param ?program=, selector when absent) ---

    /** GET /display with no query returns programs list and no single program; empty board state. */
    public function test_display_board_without_program_param_returns_programs_and_selector_state(): void
    {
        $user = User::factory()->create();
        Program::create([
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        Program::create([
            'name' => 'Program B',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $response = $this->get('/display');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Display/Board')
            ->has('programs')
            ->has('currentProgram')
        );
        $props = $response->viewData('page')['props'];
        $this->assertNull($props['currentProgram']);
        $this->assertIsArray($props['programs']);
        $this->assertCount(2, $props['programs']);
        $names = array_column($props['programs'], 'name');
        $this->assertContains('Program A', $names);
        $this->assertContains('Program B', $names);
        foreach ($props['programs'] as $p) {
            $this->assertArrayHasKey('id', $p);
            $this->assertArrayHasKey('name', $p);
        }
        $this->assertNull($props['program_name']);
        $this->assertSame([], $props['now_serving']);
        $this->assertSame(0, $props['total_in_queue']);
    }

    /** GET /display?program=1 with active program returns board data and currentProgram. */
    public function test_display_board_with_program_param_returns_board_for_that_program(): void
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

        $response = $this->get('/display?program='.$program->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Display/Board')
            ->where('program_name', 'Cash Assistance')
            ->where('total_in_queue', 1)
            ->has('currentProgram')
        );
        $props = $response->viewData('page')['props'];
        $this->assertNotNull($props['currentProgram']);
        $this->assertSame($program->id, $props['currentProgram']['id']);
        $this->assertSame('Cash Assistance', $props['currentProgram']['name']);
        $this->assertCount(1, $props['now_serving']);
        $this->assertSame('A1', $props['now_serving'][0]['alias']);
    }

    /** GET /display?program=999 (invalid id) returns 200 with program_not_found and empty board. */
    public function test_display_board_with_invalid_program_param_returns_not_found_state(): void
    {
        $response = $this->get('/display?program=99999');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Display/Board')
            ->has('program_not_found')
        );
        $props = $response->viewData('page')['props'];
        $this->assertTrue($props['program_not_found']);
        $this->assertNull($props['program_name']);
        $this->assertSame([], $props['now_serving']);
        $this->assertSame(0, $props['total_in_queue']);
    }

    /** GET /display?program=X with inactive program returns program_not_found and empty board. */
    public function test_display_board_with_inactive_program_param_returns_not_found_state(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Inactive Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $user->id,
        ]);

        $response = $this->get('/display?program='.$program->id);

        $response->assertStatus(200);
        $props = $response->viewData('page')['props'];
        $this->assertTrue($props['program_not_found']);
        $this->assertNull($props['program_name']);
        $this->assertSame([], $props['now_serving']);
        $this->assertSame(0, $props['total_in_queue']);
    }

    /** A.6.2: When two programs are active, each display board view shows only its own program's sessions. */
    public function test_multi_program_display_board_is_isolated_per_program(): void
    {
        $user = User::factory()->create();

        // Program A with one serving session
        $programA = Program::create([
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $stationA = Station::create([
            'program_id' => $programA->id,
            'name' => 'A Station',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $trackA = ServiceTrack::create([
            'program_id' => $programA->id,
            'name' => 'Track A',
            'is_default' => true,
        ]);
        $tokenA = new \App\Models\Token;
        $tokenA->qr_code_hash = hash('sha256', Str::random(32));
        $tokenA->physical_id = 'A1';
        $tokenA->status = 'in_use';
        $tokenA->save();
        Session::create([
            'token_id' => $tokenA->id,
            'program_id' => $programA->id,
            'track_id' => $trackA->id,
            'alias' => 'A1',
            'current_station_id' => $stationA->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);
        $tokenA->update(['current_session_id' => Session::where('alias', 'A1')->first()->id]);

        // Program B with one serving session
        $programB = Program::create([
            'name' => 'Program B',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $stationB = Station::create([
            'program_id' => $programB->id,
            'name' => 'B Station',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $trackB = ServiceTrack::create([
            'program_id' => $programB->id,
            'name' => 'Track B',
            'is_default' => true,
        ]);
        $tokenB = new \App\Models\Token;
        $tokenB->qr_code_hash = hash('sha256', Str::random(32));
        $tokenB->physical_id = 'B1';
        $tokenB->status = 'in_use';
        $tokenB->save();
        Session::create([
            'token_id' => $tokenB->id,
            'program_id' => $programB->id,
            'track_id' => $trackB->id,
            'alias' => 'B1',
            'current_station_id' => $stationB->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);
        $tokenB->update(['current_session_id' => Session::where('alias', 'B1')->first()->id]);

        // Board for Program A: only A1 visible
        $responseA = $this->get('/display?program='.$programA->id);
        $responseA->assertStatus(200);
        $propsA = $responseA->viewData('page')['props'];
        $this->assertSame('Program A', $propsA['program_name']);
        $aliasesA = array_column($propsA['now_serving'], 'alias');
        $this->assertContains('A1', $aliasesA);
        $this->assertNotContains('B1', $aliasesA);

        // Board for Program B: only B1 visible
        $responseB = $this->get('/display?program='.$programB->id);
        $responseB->assertStatus(200);
        $propsB = $responseB->viewData('page')['props'];
        $this->assertSame('Program B', $propsB['program_name']);
        $aliasesB = array_column($propsB['now_serving'], 'alias');
        $this->assertContains('B1', $aliasesB);
        $this->assertNotContains('A1', $aliasesB);
    }
}
